<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Shows the current bounded workspace diff.
 */
final readonly class GitDiffTool implements Tool
{
    public function __construct(private Workspace $workspace, private ProcessRunner $process) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('git_diff', 'Show the current git diff.', [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ]);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $result = $this->process->run(['git', 'diff', '--no-ext-diff', '--no-color'], $this->workspace->root(), 20.0, 1_048_576, cancellation: $cancellation);
        if ($result->truncated) {
            return new ToolResult($result->stdout . "\n[diff truncated; ask for a path]", true);
        }
        if (!$result->ok()) {
            return new ToolResult(trim($result->output) ?: 'Could not read the git diff.', false);
        }
        return new ToolResult($result->stdout === '' ? '(no unstaged changes)' : $result->stdout, true);
    }
}
