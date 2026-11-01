<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Writes a whole file: for new files and full rewrites. Changes to an
 * existing file go through the edit tool.
 */
final readonly class WriteFileTool implements Tool
{
    public function __construct(private Workspace $workspace, private int $maxBytes = 1_048_576) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('write_file', 'Create a file, or replace a file\'s whole content. To change part of an existing file, use edit.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative path.'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ], 'write');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $relative = (string) ($arguments['path'] ?? '');
        $path = $this->workspace->path($relative, allowMissingParents: true);
        $content = (string) ($arguments['content'] ?? '');
        if (strlen($content) > $this->maxBytes) {
            return new ToolResult("File content exceeds {$this->maxBytes} bytes.", false);
        }
        $existed = is_file($path);
        $before = $existed && filesize($path) <= $this->maxBytes ? (string) @file_get_contents($path) : '';
        AtomicFile::write($path, $content);
        $lines = substr_count($content, "\n") + ($content !== '' && !str_ends_with($content, "\n") ? 1 : 0);
        return new ToolResult(
            ($existed ? 'Replaced' : 'Created') . " {$relative} ({$lines} lines).",
            true,
            ['path' => $relative, 'bytes' => strlen($content), 'diff' => Diff::between($before, $content)],
        );
    }
}
