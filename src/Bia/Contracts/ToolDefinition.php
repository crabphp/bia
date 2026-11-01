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
                'parameters' => self::objects($this->inputSchema),
            ],
        ];
    }

    /**
     * A schema whose empty `properties` would encode as a JSON list (`[]`),
     * which providers refuse; they become objects (`{}`) at any depth.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function objects(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (($key === 'properties' || $key === 'patternProperties') && $value === []) {
                $schema[$key] = new \stdClass();
            } elseif (is_array($value) && $value !== [] && !array_is_list($value)) {
                $schema[$key] = self::objects($value);
            } elseif ($key === 'items' && is_array($value)) {
                $schema[$key] = self::objects($value);
            }
        }
        return $schema;
    }
}
