<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * The completed result of one model turn.
 */
final readonly class ModelResult
{
    /**
     * @param array{input?: int, output?: int, cache_read?: int, total?: int} $usage Tokens, normalized.
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed> $raw
     * @param string $model The model that answered, when another than the one asked for did.
     * @param string $reasoningField The field the provider sent its reasoning in, to send it back in.
     */
    public function __construct(
        public string $text,
        public string $finishReason = 'stop',
        public array $usage = [],
        public array $toolCalls = [],
        public array $raw = [],
        public string $reasoning = '',
        public string $model = '',
        public string $reasoningField = '',
    ) {}
}
