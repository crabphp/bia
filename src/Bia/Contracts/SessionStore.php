<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * Durable local conversation storage.
 */
interface SessionStore
{
    public function create(string $workspace, string $model, ?string $id = null): string;

    public function append(string $sessionId, Message $message): int;

    /**
     * @return list<Message>
     */
    public function messages(string $sessionId): array;

    public function exists(string $sessionId): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50): array;
}
