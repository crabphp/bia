<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * One entry of a session's append-only log. Entries form a tree through
 * $parentId; a session's context is the path from its head back to the root,
 * so a fork is a new head on an old entry and nothing is copied or rewritten.
 */
final readonly class Entry
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public Message|Compaction $payload,
        public string $createdAt = '',
    ) {}

    public function message(): ?Message
    {
        return $this->payload instanceof Message ? $this->payload : null;
    }
}
