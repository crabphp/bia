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
    ) {
        if ($this->maxTurns < 1 || $this->maxOutputBytes < 1024 || $this->maxOutputLines < 10) {
            throw new \InvalidArgumentException('An agent needs a positive turn limit and room for tool output.');
        }
    }

    public function run(string $sessionId, string $prompt, string $system = '', ?Cancellation $cancellation = null): ModelResult
    {
        $entries = $this->sessions->path($sessionId);
        $entries[] = $this->sessions->append($sessionId, Message::user(self::clean($prompt)));
        $definitions = $this->tools->definitions();
        $retried = false;

        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $cancellation?->throwIfCancelled();
            $context = Context::build($entries);
            if ($this->compactor !== null) {
                $tokens = $this->compactor->tokens($context, $system, $definitions);
                if ($this->compactor->needed($tokens) && ($entry = $this->compactEntries($sessionId, $context, $tokens, $cancellation)) !== null) {
                    $entries[] = $entry;
                    $context = Context::build($entries);
                }
            }
            $messages = $context->messages();
            if ($system !== '') {
                array_unshift($messages, Message::system($system));
            }
            try {
                $result = $this->model->stream(
                    new ModelRequest($this->modelName, $messages, $definitions),
                    $this->renderer->event(...),
                    $cancellation,
                );
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
                meta: array_filter(['usage' => $result->usage, 'finish_reason' => $result->finishReason, 'model' => $this->modelName]),
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
        }

        throw new \RuntimeException("The agent reached its {$this->maxTurns}-turn limit.");
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
