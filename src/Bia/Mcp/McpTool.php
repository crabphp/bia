<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
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
        $this->definition = new ToolDefinition(
            self::name($server, (string) $tool['name']),
            trim((string) ($tool['description'] ?? '')) ?: "The {$tool['name']} tool of the {$server} MCP server.",
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
            $result = $this->connection->call($this->tool, $arguments, $this->timeout, $cancellation);
        } catch (CancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            return new ToolResult("The {$this->server} MCP server failed: {$error->getMessage()}", false);
        }
        return new ToolResult(self::text($result), ($result['isError'] ?? false) !== true);
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
