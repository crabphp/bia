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
        $paths = self::paths($patch);
        if ($paths === []) {
            return new ToolResult('Patch does not name any file.', false);
        }
        foreach ($paths as $relative) {
            $this->workspace->path($relative, allowMissingParents: true);
        }
        // One run: git apply checks every hunk before it writes any, and on a failure leaves the files as they were.
        $applied = $this->process->run(['git', 'apply', '--whitespace=nowarn', '-'], $this->workspace->root(), 20.0, 512_000, $patch, $cancellation);
        if (!$applied->ok()) {
            return new ToolResult(trim($applied->output) ?: 'The patch does not apply.', false);
        }
        return new ToolResult('Patch applied.', true, ['diff' => Diff::ofPatch($patch)]);
    }

    /**
     * Every file path named by a patch's headers.
     *
     * @return list<string>
     */
    private static function paths(string $patch): array
    {
        $paths = [];
        foreach (preg_split('/\r?\n/', $patch) ?: [] as $line) {
            if (preg_match('/^(?:---|\+\+\+) ([^\t]+)/', $line, $match) === 1) {
                $path = self::unquote(trim($match[1]));
                if ($path !== '/dev/null') {
                    $paths[] = (string) preg_replace('#^[ab]/#', '', $path);
                }
            } elseif (preg_match('/^(?:rename|copy) (?:from|to) (.+)$/', $line, $match) === 1) {
                $paths[] = self::unquote(trim($match[1]));
            } elseif (preg_match('/^diff --git ("[^"]+"|\S+) ("[^"]+"|\S+)$/', $line, $match) === 1) {
                // The header names both sides even when nothing else does, as for a change of mode alone.
                foreach ([$match[1], $match[2]] as $side) {
                    $paths[] = (string) preg_replace('#^[ab]/#', '', self::unquote($side));
                }
            }
        }
        return array_values(array_unique($paths));
    }

    private static function unquote(string $path): string
    {
        if (strlen($path) >= 2 && $path[0] === '"' && $path[-1] === '"') {
            return stripcslashes(substr($path, 1, -1));
        }
        return $path;
    }
}
