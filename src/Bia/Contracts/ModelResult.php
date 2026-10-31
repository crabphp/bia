<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * The completed result of one model turn.
 */
final readonly class ModelResult
{
    /**
     * @param array<string, mixed> $usage
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, error?: string}> $toolCalls A call with an error had arguments that could not be read; it is answered with the error, never run.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $text,
        public string $finishReason = 'stop',
        public array $usage = [],
        public array $toolCalls = [],
        public array $raw = [],
    ) {}
}
