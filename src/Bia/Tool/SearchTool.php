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
    /** A matching line is cut past this many columns, to the part around its match. */
    private const COLUMNS = 300;

    public function __construct(private Workspace $workspace, private ProcessRunner $process) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('search', 'Search the workspace\'s files with a regular expression (ripgrep), as path:line:text. .git and vendor are left out.', [
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
        // Models often send an optional path as "": that is the workspace too.
        $relative = trim((string) ($arguments['path'] ?? '')) === '' ? '.' : (string) $arguments['path'];
        $directory = $relative === '.' ? $this->workspace->root() : $this->workspace->path($relative);
        if (!is_dir($directory) && !is_file($directory)) {
            return new ToolResult("Search path {$relative} does not exist.", false);
        }
        $limit = max(1, min(1000, (int) ($arguments['limit'] ?? 200)));
        // A long line (minified code, a lock file) is cut to its match, and ripgrep is stopped once it
        // has written about what is kept: a broad pattern in a big tree costs no more than a narrow one.
        $command = ['rg', '--line-number', '--no-heading', '--color=never', '--hidden', '--max-columns=' . self::COLUMNS, '--max-columns-preview', '-g', '!.git', '-g', '!vendor', '--', $pattern, $relative];
        $result = $this->process->run($command, $this->workspace->root(), 20.0, ($limit + 1) * (self::COLUMNS + 64), cancellation: $cancellation);
        if ($result->timedOut) {
            return new ToolResult('Search timed out: narrow the pattern or the path.', false);
        }
        if (!$result->truncated && $result->exitCode !== 0 && $result->exitCode !== 1) {
            return new ToolResult(trim($result->output) ?: 'Search failed.', false);
        }
        $found = array_values(array_filter(explode("\n", $result->stdout), static fn (string $line): bool => $line !== ''));
        if ($result->truncated) {
            array_pop($found); // stopped partway through it
        }
        $lines = array_slice($found, 0, $limit);
        $cut = $result->truncated || count($found) > $limit;
        if ($lines === []) {
            return new ToolResult('No matches.', true, ['count' => 0]);
        }
        return new ToolResult(
            implode("\n", $lines) . ($cut ? "\n[the first {$limit} matches; there are more: narrow the pattern or the path]" : ''),
            true,
            ['count' => count($lines)],
        );
    }
}
