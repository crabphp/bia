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
     * @param list<Image> $images Images attached to the user's current request.
     * @param (\Closure(): ?float)|null $steered Once the user has written since, or asked for it, the seconds a
     *     long tool may still take before it gives way (a command going on in the background); null until then.
     */
    public function __construct(
        public string $workspace,
        public string $sessionId,
        public array $metadata = [],
        public ?\Closure $output = null,
        public array $images = [],
        public ?\Closure $steered = null,
    ) {}
}
