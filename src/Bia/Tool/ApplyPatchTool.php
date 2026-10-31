<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Checks and applies a unified patch atomically through git.
 */
final readonly class ApplyPatchTool implements Tool
{
    public function __construct(private Workspace $workspace, private ProcessRunner $process) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('apply_patch', 'Apply a unified diff to the workspace.', [
            'type' => 'object',
            'properties' => [
                'patch' => ['type' => 'string'],
            ],
            'required' => ['patch'],
            'additionalProperties' => false,
        ], 'write');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $patch = (string) ($arguments['patch'] ?? '');
        if ($patch === '') {
            return new ToolResult('Patch must not be empty.', false);
        }
        $check = $this->process->run(['git', 'apply', '--check', '--whitespace=nowarn', '-'], $this->workspace->root(), 20.0, 512_000, $patch, $cancellation);
        if (!$check->ok()) {
            return new ToolResult(trim($check->output) ?: 'The patch does not apply.', false);
        }
        $applied = $this->process->run(['git', 'apply', '--whitespace=nowarn', '-'], $this->workspace->root(), 20.0, 512_000, $patch, $cancellation);
        if (!$applied->ok()) {
            return new ToolResult(trim($applied->output) ?: 'The patch could not be applied.', false);
        }
        return new ToolResult('Patch applied.', true);
    }
}
