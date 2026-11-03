<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Hands a task to a subagent and gives back its answer. Its permission is
 * `read`, as its subagent only reads: several in one step run together.
 */
final readonly class AgentTool implements Tool
{
    public function __construct(private Subagents $subagents) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('agent', <<<'TEXT'
            Hand a self-contained task to a subagent and get back only its final answer. It works with a context of its own and read-only tools (read files, list, search, the diff); it cannot edit files or run commands. Use it for broad searches, or for questions that would fill your context with many reads. Several calls in one step run at the same time, up to 4 at once. Calling the same name again continues that subagent with a follow-up, its earlier work kept.
            TEXT, [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'A short lowercase name for it, one or two words: "tests", "auth-flow".'],
                'task' => ['type' => 'string', 'description' => 'Everything it needs to know: it does not see this conversation. Say what to find out and what to answer with.'],
            ],
            'required' => ['name', 'task'],
            'additionalProperties' => false,
        ]);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        return $this->subagents->ask((string) ($arguments['name'] ?? ''), (string) ($arguments['task'] ?? ''), $cancellation);
    }
}
