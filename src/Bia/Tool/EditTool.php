<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Changes an existing file by replacing exact text. Models get this right far
 * more often than a unified diff.
 *
 * Every old_text is looked up in the file as it was before the call, must be
 * found exactly once (unless replace_all), and must not overlap another. When
 * there is no exact match, a match that differs only in typographic quotes,
 * dashes, unusual spaces or trailing whitespace is accepted. The file's line
 * endings are kept.
 */
final readonly class EditTool implements Tool
{
    private const SUBSTITUTES = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"', "\u{2033}" => '"',
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
        "\u{00A0}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2007}" => ' ', "\u{2009}" => ' ', "\u{200A}" => ' ',
        "\u{202F}" => ' ', "\u{205F}" => ' ', "\u{3000}" => ' ', "\u{2026}" => '...',
    ];

    public function __construct(private Workspace $workspace, private int $maxBytes = 4_194_304) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('edit', 'Change an existing file by replacing exact text. Read the file first. Each old_text must match the current file exactly once, with enough surrounding lines to be unique; set replace_all to change every occurrence. Several edits to one file go in one call.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative path of an existing file.'],
                'edits' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'old_text' => ['type' => 'string', 'description' => 'Exact text to replace, copied from the file.'],
                            'new_text' => ['type' => 'string', 'description' => 'The replacement.'],
                            'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence.'],
                        ],
                        'required' => ['old_text', 'new_text'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['path', 'edits'],
            'additionalProperties' => false,
        ], 'write');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $relative = (string) ($arguments['path'] ?? '');
        $path = $this->workspace->path($relative);
        if (!is_file($path)) {
            return new ToolResult("{$relative} does not exist; create it with write_file.", false);
        }
        $edits = $arguments['edits'] ?? null;
        if (!is_array($edits) || $edits === []) {
            return new ToolResult('edits must list at least one {old_text, new_text}.', false);
        }
        $original = file_get_contents($path);
        if ($original === false || strlen($original) > $this->maxBytes) {
            return new ToolResult("Could not read {$relative}, or it is larger than {$this->maxBytes} bytes.", false);
        }
        $crlf = str_contains($original, "\r\n");
        $content = $crlf ? str_replace("\r\n", "\n", $original) : $original;

        $ranges = [];
        foreach (array_values($edits) as $number => $edit) {
            $label = count($edits) > 1 ? 'Edit ' . ($number + 1) . ': ' : '';
            if (!is_array($edit)) {
                return new ToolResult("{$label}each edit is an object with old_text and new_text.", false);
            }
            $old = str_replace("\r\n", "\n", (string) ($edit['old_text'] ?? ''));
            $new = str_replace("\r\n", "\n", (string) ($edit['new_text'] ?? ''));
            if ($old === '') {
                return new ToolResult("{$label}old_text is empty; to write a whole file use write_file.", false);
            }
            if ($old === $new) {
                return new ToolResult("{$label}old_text and new_text are the same.", false);
            }
            $found = self::exact($content, $old);
            if ($found === []) {
                $found = self::fuzzy($content, $old);
            }
            if ($found === []) {
                return new ToolResult("{$label}old_text was not found in {$relative}. Read the file again and copy the text exactly.", false);
            }
            if (count($found) > 1 && !($edit['replace_all'] ?? false)) {
                return new ToolResult("{$label}old_text matches " . count($found) . " places in {$relative}; include more surrounding lines, or set replace_all.", false);
            }
            foreach ($found as [$start, $end]) {
                $ranges[] = [$start, $end, $new, $number];
            }
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1, $n = count($ranges); $i < $n; $i++) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                return new ToolResult('Edits ' . ($ranges[$i - 1][3] + 1) . ' and ' . ($ranges[$i][3] + 1) . ' overlap; combine them into one.', false);
            }
        }
        $result = $content;
        $lines = [];
        foreach (array_reverse($ranges) as [$start, $end, $new]) {
            $result = substr($result, 0, $start) . $new . substr($result, $end);
        }
        $shift = 0;
        foreach ($ranges as [$start, $end, $new]) {
            $lines[] = substr_count($result, "\n", 0, $start + $shift) + 1;
            $shift += strlen($new) - ($end - $start);
        }
        AtomicFile::write($path, $crlf ? str_replace("\n", "\r\n", $result) : $result);
        $count = count($ranges);
        return new ToolResult(
            "Edited {$relative}: {$count} " . ($count === 1 ? 'replacement' : 'replacements') . ' at line ' . implode(', ', array_unique($lines)) . '.',
            true,
            ['path' => $relative, 'replacements' => $count, 'lines' => $lines],
        );
    }

    /**
     * @return list<array{int, int}> Byte ranges of every occurrence.
     */
    private static function exact(string $content, string $old): array
    {
        $found = [];
        $offset = 0;
        while (($position = strpos($content, $old, $offset)) !== false) {
            $found[] = [$position, $position + strlen($old)];
            $offset = $position + strlen($old);
        }
        return $found;
    }

    /**
     * Occurrences that differ only in typographic characters or trailing
     * whitespace, as byte ranges of the original content.
     *
     * @return list<array{int, int}>
     */
    private static function fuzzy(string $content, string $old): array
    {
        if (!mb_check_encoding($content, 'UTF-8') || !mb_check_encoding($old, 'UTF-8')) {
            return [];
        }
        [$haystack, $map] = self::normalize($content);
        [$needle] = self::normalize($old);
        $needle = rtrim($needle, " \t");
        if ($needle === '') {
            return [];
        }
        $found = [];
        foreach (self::exact($haystack, $needle) as [$start, $end]) {
            $found[] = [$map[$start], $map[$end]];
        }
        return $found;
    }

    /**
     * @return array{string, list<int>} The normalized text, and for each of its byte offsets (and its end) the original offset.
     */
    private static function normalize(string $text): array
    {
        $trailing = [];
        if (preg_match_all('/[ \t\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+(?=\n|$)/u', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$spaces, $offset]) {
                $trailing[$offset] = $offset + strlen($spaces);
            }
        }
        $normalized = '';
        $map = [];
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            if (isset($trailing[$offset])) {
                $offset = $trailing[$offset];
                continue;
            }
            $byte = ord($text[$offset]);
            $size = $byte < 0x80 ? 1 : ($byte < 0xE0 ? 2 : ($byte < 0xF0 ? 3 : 4));
            $char = substr($text, $offset, $size);
            $out = self::SUBSTITUTES[$char] ?? $char;
            for ($i = 0, $n = strlen($out); $i < $n; $i++) {
                $map[] = $offset;
            }
            $normalized .= $out;
            $offset += $size;
        }
        $map[] = $length;
        return [$normalized, $map];
    }
}
