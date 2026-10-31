<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Writes one bounded file after the registry's write approval.
 */
final readonly class WriteFileTool implements Tool
{
    public function __construct(private Workspace $workspace, private int $maxBytes = 1_048_576) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('write_file', 'Write text to a workspace file.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ], 'write');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $path = $this->workspace->path((string) ($arguments['path'] ?? ''), allowMissingParents: true);
        $content = (string) ($arguments['content'] ?? '');
        if (strlen($content) > $this->maxBytes) {
            return new ToolResult("File content exceeds {$this->maxBytes} bytes.", false);
        }
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            return new ToolResult("Could not create the parent directory for {$arguments['path']}.", false);
        }
        $temporary = tempnam($parent, '.bia-write-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            if ($temporary !== false) {
                unlink($temporary);
            }
            return new ToolResult("Could not write {$arguments['path']}.", false);
        }
        // Keep an existing file's mode (an executable stays executable); a new one gets the umask's.
        $mode = is_file($path) ? fileperms($path) & 0o7777 : 0o666 & ~umask();
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            unlink($temporary);
            return new ToolResult("Could not replace {$arguments['path']}.", false);
        }
        return new ToolResult("Wrote {$arguments['path']}.", true, ['path' => $arguments['path'], 'bytes' => strlen($content)]);
    }
}
