<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * Context available to one tool invocation.
 */
final readonly class ToolContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $workspace,
        public string $sessionId,
        public array $metadata = [],
    ) {}
}
