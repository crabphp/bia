<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * Durable local conversation storage: an append-only log of entries per
 * session, each pointing at its parent.
 */
interface SessionStore
{
    /**
     * @param int|null $fromEntry An entry to continue from, for a fork; null starts empty.
     */
    public function create(string $workspace, string $model, ?string $id = null, ?int $fromEntry = null): string;

    /**
     * Appends after the session's head and makes the new entry its head.
     *
     * @return Entry The stored entry, with its id.
     */
    public function append(string $sessionId, Message|Compaction|Pruning $payload): Entry;

    /**
     * The entries from the root to the session's head, in order.
     *
     * @return list<Entry>
     */
    public function path(string $sessionId): array;

    public function exists(string $sessionId): bool;

    /**
     * The most recently used sessions first, each with its id, workspace,
     * model, created_at, updated_at, entry_count, and title: its first
     * prompt, or null.
     *
     * @param string|null $workspace Only the sessions of this workspace.
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50, ?string $workspace = null): array;

    /**
     * The session with this id, or the one session whose id starts with it.
     *
     * @return string|null Null when none does, or more than one.
     */
    public function resolve(string $id): ?string;
}
