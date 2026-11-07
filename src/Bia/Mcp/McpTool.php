<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Bia\Contracts\Image;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;

/**
 * One tool of an MCP server, as the model sees any other: named
 * `mcp__<server>__<tool>`, as other agents name them too.
 */
final readonly class McpTool implements Tool
{
    private ToolDefinition $definition;
    /** Its name on the server. */
    private string $tool;

    /**
     * @param array<string, mixed> $tool The tool as the server listed it.
     */
    public function __construct(
        private string $server,
        array $tool,
        private Connection $connection,
        private float $timeout,
    ) {
        $schema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
        $schema['type'] = 'object';
        $schema['properties'] ??= [];
        $description = trim((string) ($tool['description'] ?? '')) ?: "The {$tool['name']} tool of the {$server} MCP server.";
        $description .= ' Follow the input schema exactly: include required fields, omit optional fields you do not use, and do not send placeholder values.';
        if (self::acceptsImages($schema)) {
            $description .= ' User-attached images are supplied automatically in the images argument; do not read image paths or copy base64 bytes. Set alt text when useful.';
        }
        $this->definition = new ToolDefinition(
            self::name($server, (string) $tool['name']),
            $description,
            $schema,
            'mcp',
        );
        $this->tool = (string) $tool['name'];
    }

    /**
     * The name a model calls it by: letters, digits, _ and - only, and at
     * most 64 of them, as providers require.
     */
    public static function name(string $server, string $tool): string
    {
        $clean = static fn (string $part): string => (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $part);
        $name = 'mcp__' . $clean($server) . '__' . $clean($tool);
        return strlen($name) <= 64 ? $name : substr($name, 0, 55) . '_' . substr(md5($name), 0, 8);
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        try {
            $arguments = $this->arguments($arguments, $context);
        } catch (\InvalidArgumentException $error) {
            return new ToolResult("Invalid arguments for {$this->server} MCP tool {$this->tool}: {$error->getMessage()}", false);
        }
        try {
            $result = $this->connection->call($this->tool, $arguments, $this->timeout, $cancellation);
        } catch (CancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            return new ToolResult("The {$this->server} MCP server failed: {$error->getMessage()}", false);
        }
        return new ToolResult(self::text($result), ($result['isError'] ?? false) !== true);
    }

    /**
     * Adds kept user images to a tool's declared upload field, then checks the
     * final arguments against the server's JSON schema before making a request.
     * The model may provide image metadata such as alt text; it never needs to
     * copy a local path or the image bytes itself.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function arguments(array $arguments, ToolContext $context): array
    {
        $schema = $this->definition->inputSchema;
        if ($context->images !== [] && self::acceptsImages($schema)) {
            $arguments = $this->attachImages($arguments, $context->images, $schema['properties']['images']);
        }
        if (($error = self::schemaError($arguments, $schema, 'arguments')) !== null) {
            throw new \InvalidArgumentException($error);
        }
        return $arguments;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function acceptsImages(array $schema): bool
    {
        $images = $schema['properties']['images'] ?? null;
        $items = is_array($images) ? ($images['items'] ?? null) : null;
        return is_array($images)
            && ($images['type'] ?? null) === 'array'
            && is_array($items)
            && ($items['type'] ?? null) === 'object'
            && is_array($items['properties'] ?? null)
            && array_key_exists('data', $items['properties']);
    }

    /**
     * @param list<Image> $images
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function attachImages(array $arguments, array $images, array $schema): array
    {
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
        $properties = is_array($items['properties'] ?? null) ? $items['properties'] : [];
        $dataKey = 'data';
        $mimeKey = array_key_exists('mimeType', $properties) ? 'mimeType' : null;
        $altKey = array_key_exists('alt', $properties) ? 'alt' : null;
        $maxItems = isset($schema['maxItems']) && is_numeric($schema['maxItems']) ? (int) $schema['maxItems'] : null;
        if ($maxItems !== null && count($images) > $maxItems) {
            throw new \InvalidArgumentException("{$maxItems} attached image" . ($maxItems === 1 ? '' : 's') . ' maximum; received ' . count($images) . '.');
        }
        $existing = $arguments['images'] ?? [];
        if (!is_array($existing) || !array_is_list($existing)) {
            throw new \InvalidArgumentException('images must be a list of image objects.');
        }
        foreach ($images as $index => $image) {
            $bytes = $image->bytes();
            if ($bytes === null) {
                throw new \InvalidArgumentException('attached image #' . ($index + 1) . ' is no longer available.');
            }
            $data = base64_encode($bytes);
            $dataSchema = is_array($properties[$dataKey] ?? null) ? $properties[$dataKey] : [];
            if (isset($dataSchema['maxLength']) && is_numeric($dataSchema['maxLength']) && strlen($data) > (int) $dataSchema['maxLength']) {
                throw new \InvalidArgumentException('attached image #' . ($index + 1) . ' is too large for this tool.');
            }
            $entry = $existing[$index] ?? [];
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw new \InvalidArgumentException('images must contain objects.');
            }
            // The bytes and their type are Bia's to give: whatever the model wrote there, a
            // placeholder such as "[user-attached-image-1]" or a path, is not the image.
            $entry[$dataKey] = $data;
            if ($mimeKey !== null) {
                $entry[$mimeKey] = $image->mime;
            }
            if ($altKey !== null && !array_key_exists($altKey, $entry)) {
                $entry[$altKey] = 'Attached image.';
            }
            $existing[$index] = $entry;
        }
        $arguments['images'] = $existing;
        return $arguments;
    }

    /**
     * A deliberately small JSON-Schema check for MCP's tool arguments. It
     * covers the object/array/string/number constraints MCP tools commonly
     * use, including additionalProperties=false and required fields.
     *
     * @param array<string, mixed> $schema
     */
    private static function schemaError(mixed $value, array $schema, string $path): ?string
    {
        if (isset($schema['const']) && $value !== $schema['const']) {
            return "{$path} must equal " . json_encode($schema['const'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '.';
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return "{$path} must be one of " . implode(', ', array_map(static fn (mixed $item): string => (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $schema['enum'])) . '.';
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $matches = false;
            foreach ($types as $type) {
                if (is_string($type) && self::typeMatches($value, $type)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return "{$path} must be " . implode(' or ', array_map('strval', $types)) . '.';
            }
        }
        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < (int) $schema['minLength']) {
                return "{$path} is too short.";
            }
            if (isset($schema['maxLength']) && strlen($value) > (int) $schema['maxLength']) {
                return "{$path} is too long.";
            }
            if (isset($schema['pattern']) && is_string($schema['pattern']) && @preg_match('~' . $schema['pattern'] . '~', $value) !== 1) {
                return "{$path} has an invalid format.";
            }
        }
        if (is_array($value) && self::typeMatches($value, 'array')) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                return "{$path} needs at least {$schema['minItems']} item" . ((int) $schema['minItems'] === 1 ? '' : 's') . '.';
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                return "{$path} allows at most {$schema['maxItems']} items.";
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $item) {
                    if (($error = self::schemaError($item, $schema['items'], "{$path}[{$index}]")) !== null) {
                        return $error;
                    }
                }
            }
        }
        if (is_array($value) && self::typeMatches($value, 'object')) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (is_string($required) && !array_key_exists($required, $value)) {
                    return "{$path}.{$required} is required.";
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                $unknown = array_values(array_diff(array_keys($value), array_keys($properties)));
                if ($unknown !== []) {
                    return "{$path} contains unknown field" . (count($unknown) === 1 ? '' : 's') . ': ' . implode(', ', $unknown) . '.';
                }
            }
            foreach ($properties as $name => $child) {
                if (array_key_exists($name, $value) && is_array($child) && ($error = self::schemaError($value[$name], $child, "{$path}.{$name}")) !== null) {
                    return $error;
                }
            }
        }
        return null;
    }

    private static function typeMatches(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * What a result says, as text: its text parts, a note for each image,
     * sound or file it gave, or its structured content when it has nothing else.
     *
     * @param array<string, mixed> $result
     */
    public static function text(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['content'] ?? []) as $part) {
            if (!is_array($part)) {
                continue;
            }
            $parts[] = match ($part['type'] ?? '') {
                'text' => (string) ($part['text'] ?? ''),
                'image' => '[an image, ' . ($part['mimeType'] ?? 'of some kind') . ']',
                'audio' => '[a sound, ' . ($part['mimeType'] ?? 'of some kind') . ']',
                'resource' => (string) ($part['resource']['text'] ?? '[a file: ' . ($part['resource']['uri'] ?? '') . ']'),
                'resource_link' => '[' . ($part['name'] ?? 'a link') . ': ' . ($part['uri'] ?? '') . ']',
                default => '',
            };
        }
        $text = trim(implode("\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
        if ($text === '' && isset($result['structuredContent'])) {
            $text = (string) json_encode($result['structuredContent'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $text !== '' ? $text : '(no output)';
    }
}
