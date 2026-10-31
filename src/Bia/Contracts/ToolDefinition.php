<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * A JSON-Schema description of a model tool.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public string $permission = 'read',
    ) {}

    /**
     * @return array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public function openAi(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->inputSchema,
            ],
        ];
    }
}
