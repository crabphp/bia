<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Reads a text file inside the workspace, whole or by a range of lines, so a
 * large file is read a part at a time instead of refused.
 */
final readonly class ReadFileTool implements Tool
{
    public function __construct(private Workspace $workspace, private int $maxBytes = 262_144) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('read_file', 'Read a text file in the workspace, optionally a range of its lines.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative path.'],
                'offset' => ['type' => 'integer', 'minimum' => 1, 'description' => 'First line to read, from 1.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'description' => 'How many lines to read.'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ]);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $relative = (string) ($arguments['path'] ?? '');
        $path = $this->workspace->path($relative);
        if (!is_file($path) || !is_readable($path)) {
            return new ToolResult("File {$relative} is not readable.", false);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return new ToolResult("Could not read {$relative}.", false);
        }
        try {
            $head = (string) fread($handle, 8192);
            if (str_contains($head, "\0")) {
                return new ToolResult("File {$relative} is binary.", false, ['path' => $relative, 'bytes' => filesize($path)]);
            }
            rewind($handle);
            $offset = max(1, (int) ($arguments['offset'] ?? 1));
            $limit = isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : null;
            $whole = $offset === 1 && $limit === null;
            $lines = [];
            $bytes = 0;
            $number = 0;
            $more = false;
            while (($line = fgets($handle)) !== false) {
                $number++;
                if ($number < $offset) {
                    continue;
                }
                if (($limit !== null && count($lines) >= $limit) || $bytes + strlen($line) > $this->maxBytes) {
                    $more = true;
                    break;
                }
                $lines[] = $line;
                $bytes += strlen($line);
            }
        } finally {
            fclose($handle);
        }
        $text = implode('', $lines);
        if ($more) {
            $last = $offset + count($lines) - 1;
            $text .= ($text === '' || str_ends_with($text, "\n") ? '' : "\n")
                . "[lines {$offset}-{$last} shown; read on with offset " . ($last + 1) . ']';
        } elseif (!$whole && $lines === []) {
            $text = "[{$relative} has no line {$offset}]";
        }
        return new ToolResult($text, true, ['path' => $relative, 'bytes' => $bytes]);
    }
}
