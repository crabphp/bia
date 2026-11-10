<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\ExecutionStore;
use Bia\Contracts\Message;
use Bia\Contracts\Pruning;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\SessionStore;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolResult;
use Bia\Model\ModelException;
use Bia\Tool\Registry;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\HttpClient\NetworkException;

/**
 * Runs the model/tool turn loop and appends every message and tool result to
 * the session's log as it completes, never a streamed token.
 *
 * Every tool call gets a stored result, even when a tool fails or the turn
 * is cancelled. Before a request would overflow the context window, the
 * older part of the conversation is compacted.
 */
final readonly class Agent
{
    /**
     * Seconds to wait before each new try of a request that failed for a
     * passing reason, doubling: 31 seconds over five tries in all.
     */
    public const RETRY_DELAYS = [1.0, 2.0, 4.0, 8.0, 16.0];

    /**
     * How many times a call may give the same result in a turn before the
     * model is reminded that it is going round: on the third, fifth and eighth.
     */
    public const REPEATS = [3, 5, 8];

    /** The longest wait a provider's Retry-After is followed for. */
    private const RETRY_AFTER_LIMIT = 60.0;

    /**
     * Seconds of waiting, in all, that a turn with tool results behind it
     * keeps trying a failing provider: abandoning the turn throws that work
     * away, so it is tried at the last delay long past a fresh prompt.
     */
    private const PATIENCE = 300.0;

    /**
     * @param list<float> $retryDelays Waits before each new try of a request that failed for a passing reason.
     * @param array<string, mixed> $options Sent with every request, as the reasoning effort.
     */
    public function __construct(
        private Model $model,
        private string $modelName,
        private Registry $tools,
        private SessionStore $sessions,
        private Renderer $renderer,
        private string $workspace,
        private ?Compactor $compactor = null,
        private int $maxOutputBytes = 51_200,
        private int $maxOutputLines = 2000,
        private ?string $outputs = null,
        private array $retryDelays = self::RETRY_DELAYS,
        private array $options = [],
        private ?Inspection $inspection = null,
        private ?Recap $recap = null,
        private float $patience = self::PATIENCE,
        private ?ExecutionStore $executions = null,
        /** @var (\Closure(string): ?string)|null What a user's message asks beyond its words: sent with it, never shown. */
        private ?\Closure $remind = null,
    ) {
        if ($this->maxOutputBytes < 1024 || $this->maxOutputLines < 10) {
            throw new \InvalidArgumentException('An agent needs room for tool output.');
        }
    }

    /**
     * @param \Closure(): list<string|Message>|null $steer Asked after each tool step for what the user wrote
     *        meanwhile: each message joins the conversation there, before the model's next step.
     * @param list<\Bia\Contracts\Image> $images The prompt's images.
     */
    public function run(string $sessionId, string $prompt, string $system = '', ?Cancellation $cancellation = null, ?\Closure $steer = null, array $images = []): ModelResult
    {
        $entries = $this->sessions->path($sessionId);
        $this->recoverExecutions($sessionId, $entries);
        // How long the conversation rested before this prompt: past the cache's life, compacting is nearly free.
        $idle = $this->compactor?->idle($entries) ?? 0.0;
        $entries[] = $this->sessions->append($sessionId, $this->user(self::clean($prompt), $images));
        return $this->loop($sessionId, $entries, $idle, $system, $cancellation, $steer, $images, continued: false);
    }

    /**
     * Picks a turn back up where it stopped: the conversation ends on tool
     * results or a user message the model never answered, as after a failed
     * request, and the model is asked again with nothing new added.
     *
     * @param \Closure(): list<string|Message>|null $steer As in run().
     */
    public function resume(string $sessionId, string $system = '', ?Cancellation $cancellation = null, ?\Closure $steer = null): ModelResult
    {
        $entries = $this->sessions->path($sessionId);
        $idle = $this->compactor?->idle($entries) ?? 0.0;
        return $this->loop($sessionId, $entries, $idle, $system, $cancellation, $steer, [], continued: true);
    }

    /**
     * @param list<Entry> $entries
     * @param \Closure(): list<string|Message>|null $steer
     * @param list<\Bia\Contracts\Image> $images
     * @param bool $continued Whether this turn continues work already done, worth more patience with a failing provider.
     */
    private function loop(string $sessionId, array $entries, float $idle, string $system, ?Cancellation $cancellation, ?\Closure $steer, array $images, bool $continued): ModelResult
    {
        $this->recoverExecutions($sessionId, $entries);
        // A short follow-up such as "do it" still refers to the image in the
        // preceding user message. Keep that attachment available to an MCP
        // upload tool until a newer user message carries another one.
        $attachments = $images !== [] ? $images : self::lastImages($entries);
        $retried = false;
        $stepped = false;
        $this->inspection?->turn();
        /** @var array<string, int> How often each call has given each result, this turn. */
        $repeats = [];

        // No step cap: keep working until the model finishes, cancellation or an error.
        for ($turn = 0; ; $turn++) {
            $cancellation?->throwIfCancelled();
            // Asked each step: an MCP server that connected meanwhile brings its tools to the next one.
            $definitions = $this->tools->definitions();
            if ($stepped && $steer !== null) {
                // After the tool results, where a user message may follow them: the user steers.
                foreach ($steer() as $message) {
                    $repeats = []; // a word from the user is a new direction: nothing repeats it
                    $user = $message instanceof Message
                        ? $this->user(self::clean($message->text), $message->images)
                        : $this->user(self::clean($message));
                    if ($user->images !== []) {
                        $attachments = $user->images;
                    }
                    $entries[] = $this->sessions->append($sessionId, $user);
                }
            }
            $stepped = false;
            $context = Context::build($entries);
            if ($this->compactor !== null) {
                $tokens = $this->compactor->tokens($context, $system, $definitions);
                $rested = $turn === 0 ? $idle : 0.0;
                // Room is made by pruning old tool results when that is enough, which asks no model; else by a summary.
                if ($this->compactor->needed($tokens, $rested)
                    && ($entry = $this->prune($sessionId, $context, $tokens, $rested) ?? $this->compactEntries($sessionId, $context, $tokens, $cancellation)) !== null) {
                    $entries[] = $entry;
                    $context = Context::build($entries);
                }
            }
            $messages = $context->messages();
            if ($system !== '') {
                array_unshift($messages, Message::system($system));
            }
            $this->inspection?->request($this->modelName, $this->options, $system, $definitions, $context->messages());
            try {
                $result = $this->request(new ModelRequest($this->modelName, $messages, $definitions, $this->options), $cancellation, invested: $continued || $turn > 0);
            } catch (ModelException $error) {
                // Our estimate was short: compact once and try the same turn again.
                if (!$error->contextOverflow || $retried || $this->compactor === null) {
                    throw $error;
                }
                $retried = true;
                $entry = $this->compactEntries($sessionId, $context, $this->compactor->tokens($context, $system, $definitions), $cancellation);
                if ($entry === null) {
                    throw $error;
                }
                $entries[] = $entry;
                $turn--;
                continue;
            }
            $entries[] = $this->sessions->append($sessionId, new Message(
                'assistant',
                $result->text,
                $result->toolCalls,
                $result->reasoning,
                meta: array_filter([
                    'usage' => $result->usage,
                    'finish_reason' => $result->finishReason,
                    'model' => $result->model !== '' ? $result->model : $this->modelName,
                    'reasoning_field' => $result->reasoningField,
                    'responses_output' => $result->raw['_bia_responses_output'] ?? null,
                ]),
            ));
            if ($result->toolCalls === []) {
                $this->renderer->finish($result);
                return $result;
            }

            $toolContext = new ToolContext($this->workspace, $sessionId, images: $attachments);
            foreach ($this->batches($result->toolCalls) as $batch) {
                foreach ($this->execute($batch, $toolContext, $cancellation) as $index => $execution) {
                    $call = $batch[$index];
                    $toolResult = $execution['result'];
                    $text = $toolResult->text;
                    $key = hash('xxh128', $call->name . "\0" . self::canonical($call->arguments) . "\0" . $toolResult->text);
                    $times = $repeats[$key] = ($repeats[$key] ?? 0) + 1;
                    if (in_array($times, self::REPEATS, true)) {
                        $text .= "\n\n[Bia: this exact call has now given this exact result {$times} times in this turn."
                            . ' Nothing it reads has changed. Use what it says, try another way, or finish.]';
                        $notice = "Same call, same result {$times}× · reminded the model";
                        $this->inspection?->event($notice);
                        $this->renderer->notice($notice);
                    }
                    $entries[] = $this->sessions->append($sessionId, Message::toolResult(
                        $call,
                        $text,
                        $toolResult->ok,
                        $toolResult->data === [] ? [] : ['data' => $toolResult->data],
                    ));
                    if ($execution['id'] !== null) {
                        $this->executions?->recordExecution($execution['id']);
                    }
                }
            }
            $stepped = true;
        }
    }

    /**
     * The user's message as it is stored: what they wrote, and beside it
     * what Bia reminds the model of on reading it, which Context sends
     * after the text and no one is shown.
     *
     * @param list<\Bia\Contracts\Image> $images
     */
    private function user(string $text, array $images = []): Message
    {
        $reminder = $this->remind !== null ? ($this->remind)($text) : null;
        return new Message('user', $text, meta: $reminder !== null ? ['reminder' => $reminder] : [], images: $images);
    }

    /**
     * Streams one request, trying again after a failure that may pass: a
     * rate limit, an overloaded or failing provider, a lost connection. A
     * stream that fails midway is tried again too: any answer text already
     * shown is closed under a notice, and the new answer starts afresh.
     * Only the completed answer is ever stored.
     */
    private function request(ModelRequest $request, ?Cancellation $cancellation, bool $invested = false): ModelResult
    {
        try {
            $result = $this->attempts($request, $cancellation, $invested);
        } catch (\Throwable $error) {
            $this->inspection?->failed($error);
            throw $error;
        }
        $this->inspection?->answered($result);
        return $result;
    }

    private function attempts(ModelRequest $request, ?Cancellation $cancellation, bool $invested): ModelResult
    {
        $delays = $this->delays($invested);
        for ($attempt = 0; ; $attempt++) {
            $started = false;
            $shown = false;
            try {
                return $this->model->stream(
                    $request,
                    function ($event) use (&$started, &$shown): void {
                        if (!$started) {
                            $this->inspection?->firstToken();
                        }
                        $started = true;
                        if ($event->type === 'text_delta' && $event->text !== '') {
                            $shown = true;
                        }
                        $this->renderer->event($event);
                    },
                    $cancellation,
                );
            } catch (ModelException | NetworkException $error) {
                $transient = $error instanceof NetworkException || $error->transient();
                if (!$transient || $attempt >= count($delays)) {
                    throw $error;
                }
                $wait = $delays[$attempt];
                if ($error instanceof ModelException && $error->retryAfter !== null) {
                    $wait = min(max($wait, $error->retryAfter), self::RETRY_AFTER_LIMIT);
                }
                $why = $error instanceof ModelException ? (string) $error->status : 'connection failed';
                $notice = "{$why} · retry " . ($attempt + 1) . '/' . count($delays) . ' in ' . self::seconds($wait)
                    . ($shown ? ' · the answer starts over' : '');
                $this->inspection?->event($notice);
                $this->renderer->notice($notice);
                self::wait($wait, $cancellation);
            }
        }
    }

    /**
     * The waits before each new try. A turn that already has tool results
     * behind it keeps trying at the last delay until the patience is spent,
     * rather than losing that work to a provider failing for a minute.
     *
     * @return list<float>
     */
    private function delays(bool $invested): array
    {
        $delays = $this->retryDelays;
        $last = (float) end($delays);
        if (!$invested || $last <= 0.0) {
            return $delays;
        }
        for ($left = $this->patience - array_sum($delays); $left > 0; $left -= $last) {
            $delays[] = $last;
        }
        return $delays;
    }

    /**
     * Waits on the event loop, cut short by the cancellation.
     */
    private static function wait(float $seconds, ?Cancellation $cancellation): void
    {
        $cancellation?->throwIfCancelled();
        $loop = EventLoop::current();
        $suspension = $loop->getSuspension();
        $timer = $loop->delay($seconds, static fn () => $suspension->resume());
        $unsubscribe = $cancellation?->subscribe(static fn (\Throwable $error) => $suspension->throw($error));
        try {
            $suspension->suspend();
        } finally {
            $loop->cancel($timer);
            if ($unsubscribe !== null) {
                $unsubscribe();
            }
        }
    }

    private static function seconds(float $seconds): string
    {
        return $seconds >= 10 || $seconds === floor($seconds) ? round($seconds) . 's' : number_format($seconds, 1) . 's';
    }

    /**
     * Compacts the session now, whatever its size, as `/compact` asks.
     *
     * @return Compaction|null Null when there was nothing old enough to summarize.
     */
    public function compact(string $sessionId, ?Cancellation $cancellation = null): ?Compaction
    {
        if ($this->compactor === null) {
            return null;
        }
        $context = Context::build($this->sessions->path($sessionId));
        $entry = $this->compactEntries($sessionId, $context, $this->compactor->tokens($context, '', $this->tools->definitions()), $cancellation);
        $payload = $entry?->payload;
        return $payload instanceof Compaction ? $payload : null;
    }

    /**
     * A short catch-up on the session, for a user coming back to it.
     *
     * @return array{summary: string, next: ?string}|null Null when there is nothing to recap.
     */
    public function recap(string $sessionId, ?Cancellation $cancellation = null): ?array
    {
        return $this->recap?->write($this->sessions->path($sessionId), $cancellation);
    }

    /**
     * Cuts old tool results to their start and end, each whole one saved for
     * the model to read again, when that alone gives the conversation room.
     */
    private function prune(string $sessionId, Context $context, int $tokens, float $idle): ?Entry
    {
        $picked = $this->compactor?->prunable($context, $tokens, $idle);
        if ($picked === null) {
            return null;
        }
        [$results, $after] = $picked;
        $saved = [];
        foreach ($results as [$id, $message]) {
            $saved[$id] = $this->save($message->text, $sessionId, ($message->toolCallId ?? (string) $id) . '-whole');
        }
        $count = count($results);
        $notice = "Pruned {$count} old tool " . ($count === 1 ? 'output' : 'outputs') . ' · ' . self::thousands($tokens) . ' → ' . self::thousands($after) . ' tokens';
        $this->inspection?->event($notice);
        $this->renderer->notice($notice);
        return $this->sessions->append($sessionId, new Pruning($saved, $tokens, $after));
    }

    private function compactEntries(string $sessionId, Context $context, int $tokens, ?Cancellation $cancellation): ?Entry
    {
        $this->renderer->notice('Compacting the conversation (about ' . self::thousands($tokens) . ' tokens)…');
        $compaction = $this->compactor?->compact($context, $tokens, $cancellation);
        if ($compaction === null) {
            $this->renderer->notice('Nothing old enough to compact.');
            return null;
        }
        $kept = array_search($compaction->firstKeptId, array_column($context->items, 0), true);
        $this->inspection?->compacted($compaction, $kept === false ? 0 : max(0, $kept - ($context->compaction !== null ? 1 : 0)));
        $this->renderer->notice('Compacted: about ' . self::thousands($compaction->tokensBefore) . ' → ' . self::thousands($compaction->tokensAfter) . ' tokens.');
        return $this->sessions->append($sessionId, $compaction);
    }

    /**
     * Groups calls so that consecutive read-only calls run together and any
     * other call runs alone, in the order the model gave.
     *
     * @param list<ToolCall> $calls
     * @return list<list<ToolCall>>
     */
    private function batches(array $calls): array
    {
        $batches = [];
        $reads = [];
        foreach ($calls as $call) {
            if ($call->error === null && $this->tools->permission($call->name) === 'read') {
                $reads[] = $call;
                continue;
            }
            if ($reads !== []) {
                $batches[] = $reads;
                $reads = [];
            }
            $batches[] = [$call];
        }
        if ($reads !== []) {
            $batches[] = $reads;
        }
        return $batches;
    }

    /**
     * @param list<ToolCall> $batch
     * @return list<array{result: ToolResult, id: string|null}>
     */
    private function execute(array $batch, ToolContext $context, ?Cancellation $cancellation): array
    {
        // Each is shown as it ends: a read done is not kept waiting on a subagent that takes an hour.
        $run = function (ToolCall $call) use ($context, $cancellation): array {
            $id = $this->executions?->start($context->sessionId, $call);
            $result = $this->call($call, $context, $cancellation);
            // Save the bounded form so a crash after the tool returns can be
            // healed without retaining an unbounded duplicate in the journal.
            $result = new ToolResult($this->bound($result->text, $context->sessionId, $call), $result->ok, $result->data);
            $this->executions?->finish($id, $result);
            $this->renderer->tool($call, $result);
            return ['result' => $result, 'id' => $id];
        };
        if (count($batch) === 1) {
            return [$run($batch[0])];
        }
        $loop = EventLoop::current();
        $tasks = array_map(
            static fn (ToolCall $call): Task => Task::spawn($loop, static fn (): array => $run($call)),
            $batch,
        );
        return array_map(static fn (Task $task): array => $task->await(), $tasks);
    }

    /**
     * Heals a tool boundary left unfinished by a process crash. A saved result
     * is replayed into the session; an invocation that was still running is
     * represented as uncertain so the model can inspect before repeating it.
     *
     * @param list<Entry> $entries
     */
    private function recoverExecutions(string $sessionId, array &$entries): void
    {
        $recoveries = $this->executions?->recover($sessionId) ?? [];
        if ($recoveries === []) {
            return;
        }
        $calls = [];
        $results = [];
        foreach ($entries as $entry) {
            $message = $entry->message();
            if ($message === null) {
                continue;
            }
            foreach ($message->toolCalls as $call) {
                $calls[$call->id] = true;
            }
            if ($message->role === 'tool' && $message->toolCallId !== null) {
                $results[$message->toolCallId] = true;
            }
        }
        foreach ($recoveries as $execution) {
            if (isset($results[$execution->callId])) {
                // The session append won, but the journal acknowledgement did
                // not; do not duplicate the result on the next resume.
                $this->executions?->recordExecution($execution->id);
                continue;
            }
            if (!isset($calls[$execution->callId])) {
                $this->executions?->recordExecution($execution->id);
                continue;
            }
            $result = match ($execution->status) {
                'completed', 'failed' => new ToolResult($execution->result, $execution->ok ?? false, $execution->data),
                default => new ToolResult(
                    "The {$execution->name} call was interrupted before Bia recorded its result. "
                    . 'Its side effects may have happened; inspect the current state before repeating it.',
                    false,
                    ['execution' => 'unknown'],
                ),
            };
            $entries[] = $this->sessions->append($sessionId, Message::toolResult(
                $execution->call(),
                $result->text,
                $result->ok,
                $result->data === [] ? [] : ['data' => $result->data],
            ));
            $this->executions?->recordExecution($execution->id);
            $notice = $execution->status === 'unknown'
                ? "Recovered interrupted {$execution->name} call · inspect before repeating"
                : "Recovered {$execution->name} result after interruption";
            $this->inspection?->event($notice);
            $this->renderer->notice($notice);
        }
    }

    /**
     * Runs one call; whatever goes wrong becomes its result, for the model to see.
     */
    private function call(ToolCall $call, ToolContext $context, ?Cancellation $cancellation): ToolResult
    {
        if ($call->error !== null) {
            return new ToolResult($call->error, false);
        }
        try {
            $cancellation?->throwIfCancelled();
            $this->renderer->running($call, '');
            $context = new ToolContext(
                $context->workspace,
                $context->sessionId,
                $context->metadata,
                fn (string $output) => $this->renderer->running($call, $output),
                $context->images,
            );
            return $this->tools->execute($call->name, $call->arguments, $context, $cancellation);
        } catch (\Throwable $error) {
            return new ToolResult($error->getMessage(), false, ['error' => $error::class]);
        }
    }

    /**
     * @param list<Entry> $entries
     * @return list<\Bia\Contracts\Image>
     */
    private static function lastImages(array $entries): array
    {
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $message = $entries[$i]->message();
            if ($message !== null && $message->role === 'user' && $message->images !== []) {
                return $message->images;
            }
        }
        return [];
    }

    /**
     * Makes tool output storable and sendable: valid UTF-8, and when longer
     * than the limit, its first and last lines with the full output saved to
     * a file the model can page through with read_file.
     */
    private function bound(string $text, string $sessionId, ToolCall $call): string
    {
        $text = self::clean($text);
        $lines = explode("\n", $text);
        if (strlen($text) <= $this->maxOutputBytes && count($lines) <= $this->maxOutputLines) {
            return $text;
        }
        $saved = $this->save($text, $sessionId, $call->id);
        $budget = intdiv($this->maxOutputBytes, 2) - 128;
        $head = self::take($lines, $budget, intdiv($this->maxOutputLines, 2));
        $tail = array_reverse(self::take(array_reverse(array_slice($lines, count($head))), $budget, intdiv($this->maxOutputLines, 2)));
        $omitted = count($lines) - count($head) - count($tail);
        $note = "[… {$omitted} lines omitted; the output had " . count($lines) . ' lines, ' . strlen($text) . ' bytes'
            . ($saved !== null ? "; the full output is saved at {$saved}: page through it with read_file offset/limit" : '')
            . ' …]';
        return implode("\n", $head) . "\n{$note}\n" . implode("\n", $tail);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function take(array $lines, int $bytes, int $count): array
    {
        $taken = [];
        $used = 0;
        foreach ($lines as $line) {
            if (count($taken) >= $count || $used + strlen($line) + 1 > $bytes) {
                if ($taken === [] && $line !== '') {
                    $taken[] = mb_strcut($line, 0, $bytes, 'UTF-8') . ' […]';
                }
                break;
            }
            $taken[] = $line;
            $used += strlen($line) + 1;
        }
        return $taken;
    }

    private function save(string $text, string $sessionId, string $name): ?string
    {
        if ($this->outputs === null) {
            return null;
        }
        $directory = $this->outputs . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $sessionId);
        $path = $directory . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.txt';
        if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) || @file_put_contents($path, $text) === false) {
            return null;
        }
        return $path;
    }

    /**
     * Arguments as one string whatever their keys' order, so that the same
     * call is known as the same.
     *
     * @param array<mixed> $arguments
     */
    private static function canonical(array $arguments): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($sort, $value);
        };
        return (string) json_encode($sort($arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function clean(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    private static function thousands(int $tokens): string
    {
        return $tokens >= 1000 ? round($tokens / 1000) . 'k' : (string) $tokens;
    }
}
