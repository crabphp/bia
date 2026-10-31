<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * The bounded, serializable result of one tool call.
 */
final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $text,
        public bool $ok = true,
        public array $data = [],
    ) {}
}
