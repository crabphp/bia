<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Reads a text file inside the workspace, whole or by a range of lines, at
 * most a page (2000 lines, 50 KB) per call, so a large file is read a part at
 * a time instead of refused. It also reads the full outputs Bia saved when a
 * tool's output was too long to send.
 */
final readonly class ReadFileTool implements Tool
{
    public function __construct(
        private Workspace $workspace,
        private int $maxBytes = 51_200,
        private int $maxLines = 2000,
        private ?string $outputs = null,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('read_file', "Read a text file, at most {$this->maxLines} lines or " . intdiv($this->maxBytes, 1024) . ' KB per call; use offset and limit for more.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative path, or the path of a saved tool output.'],
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
        $path = $this->savedOutput($relative) ?? $this->workspace->path($relative);
        if (!is_file($path) || !is_readable($path)) {
            return new ToolResult("File {$relative} is not readable.", false);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return new ToolResult("Could not read {$relative}.", false);
        }
        try {
            if (str_contains((string) fread($handle, 8192), "\0")) {
                return new ToolResult("File {$relative} is binary.", false, ['path' => $relative, 'bytes' => filesize($path)]);
            }
            rewind($handle);
            $offset = max(1, (int) ($arguments['offset'] ?? 1));
            $limit = min($this->maxLines, isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : $this->maxLines);
            $lines = [];
            $bytes = 0;
            $number = 0;
            $more = false;
            while (($line = fgets($handle)) !== false) {
                $number++;
                if ($number < $offset) {
                    continue;
                }
                if (count($lines) >= $limit || ($bytes + strlen($line) > $this->maxBytes && $lines !== [])) {
                    $more = true;
                    break;
                }
                if (strlen($line) > $this->maxBytes) {
                    $line = mb_strcut($line, 0, $this->maxBytes, 'UTF-8') . "… [line cut]\n";
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
        } elseif ($lines === [] && $offset > 1) {
            $text = "[{$relative} has no line {$offset}]";
        }
        return new ToolResult($text, true, ['path' => $relative, 'bytes' => $bytes]);
    }

    /**
     * The real path of a saved tool output, or null when the path is not one.
     */
    private function savedOutput(string $path): ?string
    {
        if ($this->outputs === null || !str_starts_with($path, $this->outputs . '/')) {
            return null;
        }
        $real = realpath($path);
        $root = realpath($this->outputs);
        return $real !== false && $root !== false && str_starts_with($real, $root . '/') ? $real : null;
    }
}
