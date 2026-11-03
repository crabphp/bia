<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * Old tool results cut to their start and end to give the context room,
 * with no model asked: each by the entry it was stored in, and where its
 * whole text was saved for the model to read again.
 */
final readonly class Pruning
{
    /**
     * @param array<int, string|null> $entries The pruned results' entry ids, each with its saved copy's path.
     */
    public function __construct(
        public array $entries,
        public int $tokensBefore,
        public int $tokensAfter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $entries = [];
        foreach ($this->entries as $id => $path) {
            $entries[] = ['id' => $id, 'path' => $path];
        }
        return ['entries' => $entries, 'tokens_before' => $this->tokensBefore, 'tokens_after' => $this->tokensAfter];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $entries = [];
        foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $entries[(int) $entry['id']] = isset($entry['path']) ? (string) $entry['path'] : null;
            }
        }
        return new self($entries, (int) ($data['tokens_before'] ?? 0), (int) ($data['tokens_after'] ?? 0));
    }
}
