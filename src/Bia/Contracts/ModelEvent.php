<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * One normalized event from a model stream.
 */
final readonly class ModelEvent
{
    /**
     * @param 'text_delta'|'reasoning_delta'|'tool_call'|'usage'|'completed'|'failed' $type
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $type,
        public string $text = '',
        public array $data = [],
    ) {}

    public static function text(string $text): self
    {
        return new self('text_delta', $text);
    }

    public static function reasoning(string $text): self
    {
        return new self('reasoning_delta', $text);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function usage(array $data): self
    {
        return new self('usage', data: $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toolCall(array $data): self
    {
        return new self('tool_call', data: $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function completed(array $data = []): self
    {
        return new self('completed', data: $data);
    }
}
