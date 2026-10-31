<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\SessionStore;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolResult;
use Bia\Tool\Registry;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/**
 * Runs the model/tool turn loop and persists only message and tool boundaries.
 *
 * Every tool call the model makes gets a stored result, even when a tool
 * fails or the turn is cancelled, so a session can always be resumed; one
 * left inconsistent by a crash is repaired as it is loaded.
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
        private int $maxTurns = 50,
        private int $maxStoredToolBytes = 65_536,
    ) {
        if ($this->maxTurns < 1 || $this->maxStoredToolBytes < 256) {
            throw new \InvalidArgumentException('An agent needs a positive turn limit and room for tool output.');
        }
    }

    public function run(string $sessionId, string $prompt, string $system = '', ?Cancellation $cancellation = null): ModelResult
    {
        $this->sessions->append($sessionId, new Message('user', self::clean($prompt)));
        $messages = self::repair($this->sessions->messages($sessionId));
        if ($system !== '') {
            array_unshift($messages, new Message('system', $system));
        }

        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $cancellation?->throwIfCancelled();
            $result = $this->model->stream(
                new ModelRequest($this->modelName, $messages, $this->tools->definitions()),
                $this->renderer->event(...),
                $cancellation,
            );
            $assistantMetadata = [];
            if ($result->toolCalls !== []) {
                $assistantMetadata['tool_calls'] = array_map(
                    static fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => isset($call['error']) ? '{}' : self::encode($call['arguments']),
                        ],
                    ],
                    $result->toolCalls,
                );
            }
            $assistantMetadata['finish_reason'] = $result->finishReason;
            $assistantMetadata['usage'] = $result->usage;
            $assistant = new Message('assistant', $result->text, $assistantMetadata);
            $this->sessions->append($sessionId, $assistant);
            $messages[] = $assistant;
            if ($result->toolCalls === []) {
                $this->renderer->finish($result);
                return $result;
            }

            $context = new ToolContext($this->workspace, $sessionId);
            foreach ($this->batches($result->toolCalls) as $batch) {
                foreach ($this->execute($batch, $context, $cancellation) as $index => $toolResult) {
                    $call = $batch[$index];
                    $this->renderer->tool($call, $toolResult);
                    $toolMessage = new Message('tool', $this->bound($toolResult->text), [
                        'tool_call_id' => $call['id'],
                        'name' => $call['name'],
                        'ok' => $toolResult->ok,
                        'data' => $toolResult->data,
                    ]);
                    $this->sessions->append($sessionId, $toolMessage);
                    $messages[] = $toolMessage;
                }
            }
        }

        throw new \RuntimeException("The agent reached its {$this->maxTurns}-turn limit.");
    }

    /**
     * Gives every assistant tool call a result. A session cut off between a
     * call and its result (a crash, a kill) would otherwise be refused by
     * every OpenAI-style API from then on.
     *
     * @param list<Message> $messages
     * @return list<Message>
     */
    public static function repair(array $messages): array
    {
        $repaired = [];
        $pending = [];
        $flush = static function () use (&$repaired, &$pending): void {
            foreach ($pending as $id => $name) {
                $repaired[] = new Message('tool', '[no result: the turn was interrupted]', [
                    'tool_call_id' => $id,
                    'name' => $name,
                    'ok' => false,
                ]);
            }
            $pending = [];
        };
        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                unset($pending[(string) ($message->metadata['tool_call_id'] ?? '')]);
                $repaired[] = $message;
                continue;
            }
            $flush();
            $repaired[] = $message;
            if ($message->role === 'assistant') {
                foreach ($message->metadata['tool_calls'] ?? [] as $call) {
                    $pending[(string) ($call['id'] ?? '')] = (string) ($call['function']['name'] ?? '');
                }
            }
        }
        $flush();
        return $repaired;
    }

    /**
     * Groups calls so that consecutive read-only calls run together and any
     * other call runs alone, in the order the model gave.
     *
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, error?: string}> $calls
     * @return list<list<array{id: string, name: string, arguments: array<string, mixed>, error?: string}>>
     */
    private function batches(array $calls): array
    {
        $batches = [];
        $reads = [];
        foreach ($calls as $call) {
            if ($this->tools->permission($call['name']) === 'read' && !isset($call['error'])) {
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
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, error?: string}> $batch
     * @return list<ToolResult>
     */
    private function execute(array $batch, ToolContext $context, ?Cancellation $cancellation): array
    {
        if (count($batch) === 1) {
            return [$this->call($batch[0], $context, $cancellation)];
        }
        $loop = EventLoop::current();
        $tasks = array_map(
            fn (array $call): Task => Task::spawn($loop, fn (): ToolResult => $this->call($call, $context, $cancellation)),
            $batch,
        );
        return array_map(static fn (Task $task): ToolResult => $task->await(), $tasks);
    }

    /**
     * Runs one call; whatever goes wrong becomes its result, for the model to see.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>, error?: string} $call
     */
    private function call(array $call, ToolContext $context, ?Cancellation $cancellation): ToolResult
    {
        if (isset($call['error'])) {
            return new ToolResult($call['error'], false);
        }
        try {
            $cancellation?->throwIfCancelled();
            return $this->tools->execute($call['name'], $call['arguments'], $context, $cancellation);
        } catch (\Throwable $error) {
            return new ToolResult($error->getMessage(), false, ['error' => $error::class]);
        }
    }

    /**
     * Makes tool output storable and sendable: valid UTF-8, and when too long
     * its start and its end, where errors and summaries usually are.
     */
    private function bound(string $text): string
    {
        $text = self::clean($text);
        if (strlen($text) <= $this->maxStoredToolBytes) {
            return $text;
        }
        $half = intdiv($this->maxStoredToolBytes, 2) - 64;
        $head = mb_strcut($text, 0, $half, 'UTF-8');
        $tail = mb_strcut($text, strlen($text) - $half, null, 'UTF-8');
        $omitted = strlen($text) - strlen($head) - strlen($tail);
        return "{$head}\n[... {$omitted} bytes omitted ...]\n{$tail}";
    }

    private static function clean(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function encode(array $arguments): string
    {
        // An empty PHP array is a JSON list; tool arguments are always an object.
        return $arguments === []
            ? '{}'
            : json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
