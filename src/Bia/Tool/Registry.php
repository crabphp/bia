<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Validates and dispatches the tools visible to an agent.
 */
final class Registry
{
    /**
     * @var array<string, Tool>
     */
    private array $tools = [];
    /** @var list<\Closure(): list<Tool>> Tools that come and go, as MCP servers' do; asked each time. */
    private array $sources = [];

    /**
     * @param \Closure(ToolDefinition, array<string, mixed>): bool|null $approve Asked before every call, reads included; null allows everything.
     */
    public function __construct(private readonly ?\Closure $approve = null) {}

    public function add(Tool $tool): self
    {
        $name = $tool->definition()->name;
        if (isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Tool {$name} is already registered.");
        }
        $this->tools[$name] = $tool;
        return $this;
    }

    /**
     * Adds tools that may change between one step and the next.
     *
     * @param \Closure(): list<Tool> $tools
     */
    public function from(\Closure $tools): self
    {
        $this->sources[] = $tools;
        return $this;
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        return array_map(static fn (Tool $tool): ToolDefinition => $tool->definition(), array_values($this->all()));
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * The permission a tool needs, or null for a tool that is not registered.
     */
    public function permission(string $name): ?string
    {
        return ($this->all()[$name] ?? null)?->definition()->permission;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(string $name, array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $tool = $this->all()[$name] ?? null;
        if ($tool === null) {
            return new ToolResult("There is no tool named {$name}.", false);
        }
        if ($this->approve !== null && !($this->approve)($tool->definition(), $arguments)) {
            return new ToolResult('The user declined this tool call.', false);
        }
        return $tool->execute($arguments, $context, $cancellation);
    }

    /**
     * Every tool now: the built-in ones first, which a changing one cannot replace.
     *
     * @return array<string, Tool>
     */
    private function all(): array
    {
        $all = $this->tools;
        foreach ($this->sources as $source) {
            foreach ($source() as $tool) {
                $all[$tool->definition()->name] ??= $tool;
            }
        }
        return $all;
    }
}
