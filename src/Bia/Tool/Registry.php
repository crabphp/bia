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
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(static fn (Tool $tool): array => $tool->definition()->openAi(), array_values($this->tools));
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * The permission a tool needs, or null for a tool that is not registered.
     */
    public function permission(string $name): ?string
    {
        return isset($this->tools[$name]) ? $this->tools[$name]->definition()->permission : null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(string $name, array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return new ToolResult("There is no tool named {$name}.", false);
        }
        if ($this->approve !== null && !($this->approve)($tool->definition(), $arguments)) {
            return new ToolResult('The user declined this tool call.', false);
        }
        return $tool->execute($arguments, $context, $cancellation);
    }
}
