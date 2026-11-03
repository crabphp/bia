<?php

declare(strict_types=1);

namespace Bia\Session;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\Pruning;
use Bia\Contracts\SessionStore;

/**
 * Sessions kept in memory only, as a subagent's: they end with Bia, and
 * never fill the list of sessions to resume.
 */
final class MemoryStore implements SessionStore
{
    /** @var array<string, list<Entry>> */
    private array $sessions = [];
    private int $next = 1;

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
}
