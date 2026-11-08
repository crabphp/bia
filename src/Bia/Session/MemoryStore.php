<?php

declare(strict_types=1);

namespace Bia\Session;

use Bia\Contracts\Compaction;
use Bia\Contracts\ExecutionStore;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\Pruning;
use Bia\Contracts\SessionStore;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolExecution;
use Bia\Contracts\ToolResult;

/**
 * Sessions kept in memory only, as a subagent's: they end with Bia, and
 * never fill the list of sessions to resume.
 */
final class MemoryStore implements SessionStore, ExecutionStore
{
    /** @var array<string, list<Entry>> */
    private array $sessions = [];
    private int $next = 1;
    /** @var array<string, ToolExecution> */
    private array $executions = [];

    public function create(string $workspace, string $model, ?string $id = null, ?int $fromEntry = null): string
    {
        $id ??= bin2hex(random_bytes(12));
        $this->sessions[$id] = [];
        return $id;
    }

    public function append(string $sessionId, Message|Compaction|Pruning $payload): Entry
    {
        $parent = $this->sessions[$sessionId] === [] ? null : end($this->sessions[$sessionId])->id;
        return $this->sessions[$sessionId][] = new Entry($this->next++, $parent, $payload, gmdate('c'));
    }

    public function path(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? [];
    }

    public function exists(string $sessionId): bool
    {
        return isset($this->sessions[$sessionId]);
    }

    public function list(int $limit = 50, ?string $workspace = null): array
    {
        return [];
    }

    public function resolve(string $id): ?string
    {
        return isset($this->sessions[$id]) ? $id : null;
    }

    public function start(string $sessionId, ToolCall $call): string
    {
        $id = bin2hex(random_bytes(12));
        $this->executions[$id] = new ToolExecution(
            $id,
            $sessionId,
            $call->id,
            $call->name,
            $call->arguments,
            'running',
            gmdate('c'),
        );
        return $id;
    }

    public function finish(string $executionId, ToolResult $result): void
    {
        $execution = $this->executions[$executionId] ?? throw new \RuntimeException("Tool execution {$executionId} does not exist.");
        $this->executions[$executionId] = new ToolExecution(
            $execution->id,
            $execution->sessionId,
            $execution->callId,
            $execution->name,
            $execution->arguments,
            $result->ok ? 'completed' : 'failed',
            $execution->startedAt,
            gmdate('c'),
            $result->ok,
            $result->text,
            $result->data,
        );
    }

    public function recover(string $sessionId): array
    {
        $found = [];
        foreach ($this->executions as $id => $execution) {
            if ($execution->sessionId !== $sessionId) {
                continue;
            }
            if ($execution->status === 'running') {
                $execution = new ToolExecution(
                    $execution->id,
                    $execution->sessionId,
                    $execution->callId,
                    $execution->name,
                    $execution->arguments,
                    'unknown',
                    $execution->startedAt,
                    gmdate('c'),
                    false,
                    '',
                );
                $this->executions[$id] = $execution;
            }
            $found[] = $execution;
        }
        return $found;
    }

    public function recordExecution(string $executionId): void
    {
        unset($this->executions[$executionId]);
    }
}
