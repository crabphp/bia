<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
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

    /** The longest wait a provider's Retry-After is followed for. */
    private const RETRY_AFTER_LIMIT = 60.0;

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
        private int $maxTurns = 100,
        private int $maxOutputBytes = 51_200,
        private int $maxOutputLines = 2000,
        private ?string $outputs = null,
        private array $retryDelays = self::RETRY_DELAYS,
        private array $options = [],
        private ?Inspection $inspection = null,
    ) {
        if ($this->maxTurns < 1 || $this->maxOutputBytes < 1024 || $this->maxOutputLines < 10) {
            throw new \InvalidArgumentException('An agent needs a positive turn limit and room for tool output.');
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
        // How long the conversation rested before this prompt: past the cache's life, compacting is nearly free.
        $idle = $this->compactor?->idle($entries) ?? 0.0;
        $entries[] = $this->sessions->append($sessionId, Message::user(self::clean($prompt), $images));
        $retried = false;
        $stepped = false;
        $this->inspection?->turn();

        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $cancellation?->throwIfCancelled();
            // Asked each step: an MCP server that connected meanwhile brings its tools to the next one.
            $definitions = $this->tools->definitions();
            if ($stepped && $steer !== null) {
                // After the tool results, where a user message may follow them: the user steers.
                foreach ($steer() as $message) {
                    $entries[] = $this->sessions->append($sessionId, $message instanceof Message
                        ? Message::user(self::clean($message->text), $message->images)
                        : Message::user(self::clean($message)));
                }
            }
            $stepped = false;
            $context = Context::build($entries);
            if ($this->compactor !== null) {
                $tokens = $this->compactor->tokens($context, $system, $definitions);
                if ($this->compactor->needed($tokens, $turn === 0 ? $idle : 0.0) && ($entry = $this->compactEntries($sessionId, $context, $tokens, $cancellation)) !== null) {
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
                $result = $this->request(new ModelRequest($this->modelName, $messages, $definitions, $this->options), $cancellation);
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
                meta: array_filter(['usage' => $result->usage, 'finish_reason' => $result->finishReason, 'model' => $result->model !== '' ? $result->model : $this->modelName]),
            ));
            if ($result->toolCalls === []) {
                $this->renderer->finish($result);
                return $result;
            }

            $toolContext = new ToolContext($this->workspace, $sessionId);
            foreach ($this->batches($result->toolCalls) as $batch) {
                foreach ($this->execute($batch, $toolContext, $cancellation) as $index => $toolResult) {
                    $call = $batch[$index];
                    $this->renderer->tool($call, $toolResult);
                    $entries[] = $this->sessions->append($sessionId, Message::toolResult(
                        $call,
                        $this->bound($toolResult->text, $sessionId, $call),
                        $toolResult->ok,
                        $toolResult->data === [] ? [] : ['data' => $toolResult->data],
                    ));
                }
            }
            $stepped = true;
        }

        throw new \RuntimeException("The agent reached its {$this->maxTurns}-turn limit.");
    }

    /**
     * Streams one request, trying again after a failure that may pass: a
     * rate limit, an overloaded or failing provider, a lost connection. A
     * request is tried again only while nothing of its answer has arrived,
     * so no text is ever shown twice.
     */
    private function request(ModelRequest $request, ?Cancellation $cancellation): ModelResult
    {
        try {
            $result = $this->attempts($request, $cancellation);
        } catch (\Throwable $error) {
            $this->inspection?->failed($error);
            throw $error;
        }
        $this->inspection?->answered($result);
        return $result;
    }

    private function attempts(ModelRequest $request, ?Cancellation $cancellation): ModelResult
    {
        for ($attempt = 0; ; $attempt++) {
            $started = false;
            try {
                return $this->model->stream(
                    $request,
                    function ($event) use (&$started): void {
                        if (!$started) {
                            $this->inspection?->firstToken();
                        }
                        $started = true;
                        $this->renderer->event($event);
                    },
                    $cancellation,
                );
            } catch (ModelException | NetworkException $error) {
                $transient = $error instanceof NetworkException || $error->transient();
                if ($started || !$transient || $attempt >= count($this->retryDelays)) {
                    throw $error;
                }
                $wait = $this->retryDelays[$attempt];
                if ($error instanceof ModelException && $error->retryAfter !== null) {
                    $wait = min(max($wait, $error->retryAfter), self::RETRY_AFTER_LIMIT);
                }
                $why = $error instanceof ModelException ? (string) $error->status : 'connection failed';
                $tries = count($this->retryDelays);
                $notice = "{$why} · retry " . ($attempt + 1) . "/{$tries} in " . self::seconds($wait);
                $this->inspection?->event($notice);
                $this->renderer->notice($notice);
                self::wait($wait, $cancellation);
            }
        }
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
     * @return list<ToolResult>
     */
    private function execute(array $batch, ToolContext $context, ?Cancellation $cancellation): array
    {
        if (count($batch) === 1) {
            return [$this->call($batch[0], $context, $cancellation)];
        }
        $loop = EventLoop::current();
        $tasks = array_map(
            fn (ToolCall $call): Task => Task::spawn($loop, fn (): ToolResult => $this->call($call, $context, $cancellation)),
            $batch,
        );
        return array_map(static fn (Task $task): ToolResult => $task->await(), $tasks);
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
            );
            return $this->tools->execute($call->name, $call->arguments, $context, $cancellation);
        } catch (\Throwable $error) {
            return new ToolResult($error->getMessage(), false, ['error' => $error::class]);
        }
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
        $saved = $this->save($text, $sessionId, $call);
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

    private function save(string $text, string $sessionId, ToolCall $call): ?string
    {
        if ($this->outputs === null) {
            return null;
        }
        $directory = $this->outputs . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $sessionId);
        $path = $directory . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $call->id) . '.txt';
        if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) || @file_put_contents($path, $text) === false) {
            return null;
        }
        return $path;
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
