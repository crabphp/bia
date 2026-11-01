<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Lists files using ripgrep when present, with a bounded PHP fallback.
 */
final readonly class ListFilesTool implements Tool
{
    public function __construct(private Workspace $workspace, private ProcessRunner $process) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('list_files', 'List files below a workspace directory.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Optional workspace-relative directory.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        // Models often send an optional path as "": that is the workspace too.
        $relative = trim((string) ($arguments['path'] ?? '')) === '' ? '.' : (string) $arguments['path'];
        $directory = $relative === '.' ? $this->workspace->root() : $this->workspace->path($relative);
        if (!is_dir($directory)) {
            return new ToolResult("Directory {$relative} does not exist.", false);
        }
        $limit = max(1, min(2000, (int) ($arguments['limit'] ?? 500)));
        try {
            $rg = $this->process->run(['rg', '--files', '--hidden', '-g', '!.git', '-g', '!vendor'], $directory, 10.0, 256_000, cancellation: $cancellation);
        } catch (\RuntimeException) {
            $rg = null;
        }
        if ($rg !== null && ($rg->truncated || $rg->exitCode === 0 || ($rg->exitCode === 1 && $rg->stdout === ''))) {
            $lines = array_slice(array_values(array_filter(explode("\n", trim($rg->stdout)))), 0, $limit);
            if ($relative !== '.') {
                $lines = array_map(static function (string $line) use ($relative): string {
                    return rtrim($relative, '/') . '/' . $line;
                }, $lines);
            }
            return new ToolResult(implode("\n", $lines), true, ['count' => count($lines)]);
        }
        $lines = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $iterator->getSubPathname());
            if (str_starts_with($path, '.git/') || str_starts_with($path, 'vendor/')) {
                continue;
            }
            if ($relative !== '.') {
                $path = rtrim($relative, '/') . '/' . $path;
            }
            $lines[] = $path;
            if (count($lines) >= $limit) {
                break;
            }
        }
        return new ToolResult(implode("\n", $lines), true, ['count' => count($lines)]);
    }
}
