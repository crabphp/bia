<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * A summary that stands in for the conversation before $firstKeptId. The
 * summarized entries stay in the log; only the context sent to the model
 * changes.
 */
final readonly class Compaction
{
    public function __construct(
        public string $summary,
        public ?int $firstKeptId,
        public int $tokensBefore,
        public int $tokensAfter,
    ) {}

    /**
     * @return array{summary: string, first_kept_id: int|null, tokens_before: int, tokens_after: int}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'first_kept_id' => $this->firstKeptId,
            'tokens_before' => $this->tokensBefore,
            'tokens_after' => $this->tokensAfter,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['summary'] ?? ''),
            isset($data['first_kept_id']) ? (int) $data['first_kept_id'] : null,
            (int) ($data['tokens_before'] ?? 0),
            (int) ($data['tokens_after'] ?? 0),
        );
    }
}
