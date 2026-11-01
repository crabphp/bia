<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * A provider-neutral model request.
 */
final readonly class ModelRequest
{
    /**
     * @param list<Message> $messages
     * @param list<ToolDefinition> $tools
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $model,
        public array $messages,
        public array $tools = [],
        public array $options = [],
    ) {}
}
