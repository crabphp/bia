<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Searches text with ripgrep and never interpolates a pattern into a shell.
 */
final readonly class SearchTool implements Tool
{
    public function __construct(private Workspace $workspace, private ProcessRunner $process) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('search', 'Search workspace text with a regular expression.', [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string'],
                'path' => ['type' => 'string', 'description' => 'Optional workspace-relative path.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
            ],
            'required' => ['pattern'],
            'additionalProperties' => false,
        ]);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $pattern = (string) ($arguments['pattern'] ?? '');
        if ($pattern === '') {
            return new ToolResult('Search pattern must not be empty.', false);
        }
        $relative = (string) ($arguments['path'] ?? '.');
        $directory = $relative === '.' ? $this->workspace->root() : $this->workspace->path($relative);
        if (!is_dir($directory) && !is_file($directory)) {
            return new ToolResult("Search path {$relative} does not exist.", false);
        }
        $limit = max(1, min(1000, (int) ($arguments['limit'] ?? 200)));
        $command = ['rg', '--line-number', '--no-heading', '--color=never', '--hidden', '-g', '!.git', '-g', '!vendor', '--', $pattern, $relative];
        $result = $this->process->run($command, $this->workspace->root(), 20.0, 1_048_576, cancellation: $cancellation);
        if ($result->timedOut) {
            return new ToolResult('Search timed out.', false);
        }
        if (!$result->truncated && $result->exitCode !== 0 && $result->exitCode !== 1) {
            return new ToolResult(trim($result->output) ?: 'Search failed.', false);
        }
        $lines = array_slice(array_values(array_filter(explode("\n", trim($result->stdout)))), 0, $limit);
        return new ToolResult(implode("\n", $lines), true, ['count' => count($lines)]);
    }
}
