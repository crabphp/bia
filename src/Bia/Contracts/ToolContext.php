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
     * @param (\Closure(string): void)|null $output Told output as the tool makes it, to show it while it runs.
     */
    public function __construct(
        public string $workspace,
        public string $sessionId,
        public array $metadata = [],
        public ?\Closure $output = null,
    ) {}
}
