<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Renders a model's markdown for the terminal a line at a time, as it
 * streams: headings and bold in bold, lists with a bullet, quotes dim,
 * inline code in the accent, code blocks on a dark panel and highlighted,
 * and tables with their columns lined up.
 *
 * A table is held back until it ends, as its columns' widths depend on all
 * its rows; until then preview() draws it as far as it has come. One
 * renderer per answer: it keeps whether a code block or a table is open.
 */
final class Markdown
{
    /** A code block's panel: a dark background, as wide as the terminal. */
    private const PANEL = "\e[48;5;235m";

    private ?string $fence = null;
    /** The open code block's highlighter, when its language is known. */
    private ?Highlight $code = null;
    /**
     * The table being read: its first row as written; its rows, each cell
     * rendered once with its width; the widest cell of each column so far;
     * and its columns' alignments once a separator row has confirmed it.
     *
     * @var array{raw: string, rows: list<list<array{string, int}>>, widths: list<int>, align: list<string>|null}|null
     */
    private ?array $table = null;

    /**
     * Renders one finished line, and moves past it.
     *
     * @return list<string> The lines finished now: none while a table is still coming, several when one ends.
     */
    public function line(string $line): array
    {
        if ($this->fence === null && self::isRow($line)) {
            return $this->row($line);
        }
        $out = $this->flushTable();
        if (preg_match('/^\s*(`{3,}|~{3,})\s*([\w+#.-]*)/', $line, $match) === 1
            && ($this->fence === null || str_starts_with(ltrim($line), $this->fence))) {
            $opening = $this->fence === null;
            $this->fence = $opening ? str_repeat($match[1][0], 3) : null;
            $this->code = $opening && $match[2] !== '' ? Highlight::for($match[2]) : null;
            // The fences are not shown: an opening one only names its language.
            if ($opening && $match[2] !== '') {
                $out[] = Style::dim($match[2]);
            }
            return $out;
        }
        $out[] = $this->render($line, $this->code !== null ? $this->code->line(...) : null);
        return $out;
    }

    /**
     * What is not finished yet, drawn as far as it has come: an open table,
     * and the line still streaming, without moving past it.
     */
    public function preview(string $line): string
    {
        $lines = [];
        if ($this->table !== null) {
            [$rows, $widths] = [$this->table['rows'], $this->table['widths']];
            if ($this->table['align'] !== null && self::isRow($line)) {
                // The row arriving takes its place in the table; only it is rendered now.
                $rows[] = $arriving = self::renderRow(self::cells($line), false);
                $widths = self::widen($widths, $arriving);
                $line = '';
            }
            $lines = $this->table['align'] === null ? [self::inline($this->table['raw'])] : self::drawTable($rows, $widths, $this->table['align']);
        }
        if ($line !== '') {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line) !== 1) { // a fence is never shown
                $lines[] = $this->render($line, $this->code !== null ? $this->code->preview(...) : null);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Ends the answer: a table still open is finished as it is.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        $out = $this->flushTable();
        $this->fence = null;
        $this->code = null;
        return $out;
    }

    /**
     * @return list<string>
     */
    private function row(string $line): array
    {
        $cells = self::cells($line);
        if ($this->table === null) {
            $this->table = self::start($line, $cells);
            return [];
        }
        if ($this->table['align'] === null) {
            $align = self::separator($cells);
            if ($align !== null) {
                $this->table['align'] = $align;
                return [];
            }
            // No separator under the first row: it was not a table, but this line may start one.
            $out = [self::inline($this->table['raw'])];
            $this->table = self::start($line, $cells);
            return $out;
        }
        $row = self::renderRow($cells, false);
        $this->table['rows'][] = $row;
        $this->table['widths'] = self::widen($this->table['widths'], $row);
        return [];
    }

    /**
     * @return list<string>
     */
    private function flushTable(): array
    {
        if ($this->table === null) {
            return [];
        }
        $table = $this->table;
        $this->table = null;
        return $table['align'] === null ? [self::inline($table['raw'])] : self::drawTable($table['rows'], $table['widths'], $table['align']);
    }

    /**
     * A table's first row: its header, rendered, and the widths it sets.
     *
     * @param list<string> $cells
     * @return array{raw: string, rows: list<list<array{string, int}>>, widths: list<int>, align: null}
     */
    private static function start(string $raw, array $cells): array
    {
        $header = self::renderRow($cells, true);
        return ['raw' => $raw, 'rows' => [$header], 'widths' => self::widen([], $header), 'align' => null];
    }

    /**
     * A row's cells rendered, each with its width: the header's in bold.
     *
     * @param list<string> $cells
     * @return list<array{string, int}>
     */
    private static function renderRow(array $cells, bool $header): array
    {
        $row = [];
        foreach ($cells as $cell) {
            $text = self::inline($cell);
            $row[] = [$header ? Style::bold($text) : $text, Style::width($text)];
        }
        return $row;
    }

    /**
     * Column widths made wide enough for a row.
     *
     * @param list<int> $widths
     * @param list<array{string, int}> $row
     * @return list<int>
     */
    private static function widen(array $widths, array $row): array
    {
        foreach ($row as $column => [, $width]) {
            $widths[$column] = max($widths[$column] ?? 0, $width);
        }
        return $widths;
    }

    /**
     * Rows lined up in columns: the first in bold, a dim rule under it, and
     * dim bars between columns, each aligned as its separator asked. Each
     * cell comes rendered already, with its width, so nothing is rendered twice.
     *
     * @param list<list<array{string, int}>> $rows
     * @param list<int> $widths
     * @param list<string> $align
     * @return list<string>
     */
    private static function drawTable(array $rows, array $widths, array $align): array
    {
        $count = max(count($align), count($widths));
        $widths = array_pad($widths, $count, 0);
        $bar = Style::dim(' │ ');
        $lines = [];
        foreach ($rows as $index => $row) {
            $padded = [];
            foreach (array_pad($row, $count, ['', 0]) as $column => [$cell, $width]) {
                $space = $widths[$column] - $width;
                $padded[] = match ($align[$column] ?? 'left') {
                    'right' => str_repeat(' ', $space) . $cell,
                    'center' => str_repeat(' ', intdiv($space, 2)) . $cell . str_repeat(' ', $space - intdiv($space, 2)),
                    default => $cell . str_repeat(' ', $space),
                };
            }
            $lines[] = rtrim(implode($bar, $padded));
            if ($index === 0) {
                $lines[] = Style::dim(implode('─┼─', array_map(static fn (int $w): string => str_repeat('─', $w), $widths)));
            }
        }
        return $lines;
    }

    /**
     * A line of a table: one that starts with a bar.
     */
    private static function isRow(string $line): bool
    {
        return str_starts_with(ltrim($line), '|');
    }

    /**
     * A row's cells, without the outer bars; an escaped \| stays in its cell.
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = substr($line, 1);
        if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) {
            $line = substr($line, 0, -1);
        }
        return array_map(
            static fn (string $cell): string => str_replace('\\|', '|', trim($cell)),
            preg_split('/(?<!\\\\)\|/', $line) ?: [],
        );
    }

    /**
     * The alignments a separator row asks for, or null when the row is not one.
     *
     * @param list<string> $cells
     * @return list<string>|null
     */
    private static function separator(array $cells): ?array
    {
        $align = [];
        foreach ($cells as $cell) {
            if (preg_match('/^(:?)-+(:?)$/', $cell, $m) !== 1) {
                return null;
            }
            $align[] = match (true) {
                $m[1] !== '' && $m[2] !== '' => 'center',
                $m[2] !== '' => 'right',
                default => 'left',
            };
        }
        return $align;
    }

    /**
     * @param \Closure(string): string|null $highlight The open code block's highlighter.
     */
    private function render(string $line, ?\Closure $highlight = null): string
    {
        if ($this->fence !== null) {
            // A code block, on its panel; colours inside end only themselves, so the panel stays.
            $code = $highlight !== null ? $highlight($line) : (Style::color() ? "\e[36m{$line}\e[39m" : $line);
            return Style::color() ? self::PANEL . $code . "\e[K\e[0m" : $line;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $match) === 1) {
            return Style::bold(self::inline($match[2], bold: false));
        }
        if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
            return Style::dim('───');
        }
        if (preg_match('/^(\s*)>\s?(.*)$/', $line, $match) === 1) {
            return $match[1] . Style::dim('│ ' . $match[2]);
        }
        if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $match) === 1) {
            return $match[1] . '• ' . self::inline($match[2]);
        }
        return self::inline($line);
    }

    /**
     * Inline code in the accent and **bold** in bold; code is left as it is inside.
     */
    private static function inline(string $text, bool $bold = true): string
    {
        $parts = preg_split('/(`+)(.+?)\1/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }
        $out = '';
        for ($i = 0, $n = count($parts); $i < $n; $i += 3) {
            $plain = $parts[$i];
            if ($bold) {
                $plain = (string) preg_replace_callback(
                    // Only **: __ is too often a name's own, as in __init__.
                    '/\*\*(?=\S)(.+?)(?<=\S)\*\*/',
                    static fn (array $m): string => Style::bold($m[1]),
                    $plain,
                );
            }
            $out .= $plain;
            if (isset($parts[$i + 2])) {
                $out .= Style::accent($parts[$i + 2]);
            }
        }
        return $out;
    }
}
