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
final class ReadFileTool implements Tool
{
    /** Bytes counted at a time when passing lines before an offset. */
    private const BLOCK = 1 << 20;

    /** Files whose page end is remembered, at most. */
    private const MARKS = 32;

    /**
     * Where the last page of a file ended, so the next starts there instead
     * of counting from the top again: by path, with the size and time the
     * file had then, the line that follows and the byte it starts at.
     *
     * @var array<string, array{size: int, time: int, line: int, byte: int}>
     */
    private array $marks = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly int $maxBytes = 51_200,
        private readonly int $maxLines = 2000,
        private readonly ?string $outputs = null,
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
            // The lines before the offset are counted a block at a time, not read one by one,
            // and from where the last page ended when it was before them.
            clearstatcache(true, $path);
            $stat = ['size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            $mark = $this->marks[$path] ?? null;
            $number = 0;
            if ($mark !== null && $mark['size'] === $stat['size'] && $mark['time'] === $stat['time'] && $mark['line'] <= $offset) {
                fseek($handle, $mark['byte']);
                $number = $mark['line'] - 1;
            }
            if ($offset - 1 > $number) {
                $number += self::skip($handle, $offset - 1 - $number);
            }
            $more = false;
            // Where each line starts, for the mark of the one a page stops before.
            while (($at = (int) ftell($handle)) >= 0 && ($line = fgets($handle)) !== false) {
                $number++;
                if ($number < $offset) {
                    continue;
                }
                if (count($lines) >= $limit || ($bytes + strlen($line) > $this->maxBytes && $lines !== [])) {
                    $more = true;
                    unset($this->marks[$path]);
                    $this->marks[$path] = [...$stat, 'line' => $number, 'byte' => $at];
                    if (count($this->marks) > self::MARKS) {
                        unset($this->marks[array_key_first($this->marks)]);
                    }
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
            $text = "[{$relative} has {$number} lines: there is no line {$offset}]";
        }
        return new ToolResult($text, true, ['path' => $relative, 'bytes' => $bytes]);
    }

    /**
     * The real path of a saved tool output, or null when the path is not one.
     */
    /**
     * Moves past the first $count lines, reading blocks and counting their
     * line ends, and stops at the start of the next line; the lines passed,
     * fewer when the file ends first.
     *
     * @param resource $handle
     */
    private static function skip($handle, int $count): int
    {
        $passed = 0;
        while ($passed < $count) {
            $start = (int) ftell($handle);
            $block = fread($handle, self::BLOCK);
            if ($block === false || $block === '') {
                break;
            }
            $ends = substr_count($block, "\n");
            if ($passed + $ends < $count) {
                $passed += $ends;
                continue;
            }
            // The line wanted starts in this block: just after the end of the line before it.
            $at = -1;
            for ($left = $count - $passed; $left > 0; $left--) {
                $at = (int) strpos($block, "\n", $at + 1);
            }
            fseek($handle, $start + $at + 1);
            return $count;
        }
        // The file ended first: a last line without a line end is a line too.
        if (fseek($handle, -1, SEEK_END) === 0 && fread($handle, 1) !== "\n" && ftell($handle) > 0) {
            $passed++;
        }
        return $passed;
    }

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
