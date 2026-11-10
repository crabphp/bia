<?php

declare(strict_types=1);

namespace Bia\Plugin;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;

/**
 * A tool a plugin adds: a closure of its own, run in Bia's process. The
 * model calls it as `plugin__<plugin>__<tool>`, as it calls an MCP
 * server's `mcp__<server>__<tool>`.
 */
final readonly class PluginTool implements Tool
{
    private ToolDefinition $definition;

    /**
     * @param \Closure(array<string, mixed>): mixed $handler
     * @param array<string, mixed> $schema The arguments, as a JSON Schema object; none without one.
     */
    public function __construct(
        public string $plugin,
        string $name,
        string $description,
        private \Closure $handler,
        array $schema = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/i', $name) !== 1) {
            throw new \InvalidArgumentException("{$name} is not a tool's name: letters, digits and _.");
        }
        if (trim($description) === '') {
            throw new \InvalidArgumentException("The tool {$name} needs a description, for the model to know when to call it.");
        }
        $clean = static fn (string $part): string => (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $part);
        $this->definition = new ToolDefinition(
            substr('plugin__' . $clean($plugin) . '__' . $name, 0, 64),
            trim($description),
            ['type' => 'object', 'properties' => [], ...$schema],
            'write',
        );
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        try {
            $result = ($this->handler)($arguments);
        } catch (CancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            return new ToolResult(trim($error->getMessage()) ?: $error::class, false);
        }
        if ($result instanceof ToolResult) {
            return $result;
        }
        if (!is_string($result)) {
            $result = $result === null ? '' : (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        return new ToolResult($result);
    }
}
