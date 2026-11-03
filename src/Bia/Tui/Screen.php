<?php

declare(strict_types=1);

namespace Bia\Tui;

use Crab\Engine\EventLoop;

/**
 * Fullscreen rendering on the alternate screen: the output scrolls in the
 * rows above, by the mouse wheel and by page, while the live lines stay at
 * the bottom.
 *
 * The output is kept as lines and wrapped here, a line at a time and only
 * as far back as is shown, so a long conversation costs no more to draw
 * than a short one. A frame writes only the rows that changed, in one
 * write wrapped in synchronized output. On closing, the output is printed
 * on the main screen, so the conversation stays in the terminal after.
 */
final class Screen implements Surface
{
    /** Columns kept clear on each side of the output, so text does not touch the window's edges. */
    public const MARGIN = 1;

    /** @var array<int, string> Finished lines, by number. */
    private array $done = [];
    /** @var array<int, array{0: string, 1: string}> Lines that open, by number: as shown closed, and open. */
    private array $folds = [];
    /** @var array<int, array{to: int, summary: string, open: bool}> Runs of lines collapsed into one, by their first line. */
    private array $runs = [];
    /** @var array<int, int> Lines inside a run, after its first, to the run's first line. */
    private array $inRun = [];
    /** @var array<int, list<string>> Finished lines wrapped at $width, by number. */
    private array $wrapped = [];
    /** Number of the oldest line kept, and of the next to finish. */
    private int $start = 0;
    private int $next = 0;
    private string $partial = '';
    private string $draft = '';
    private string $tail = "\n";
    private int $width = 0;
    /** @var list<string> */
    private array $lines = [];
    /** @var array{0: int, 1: int}|null */
    private ?array $cursor = null;
    /** @var array{0: int, 1: int}|null The line and row shown at the top while scrolled; null to follow the newest. */
    private ?array $top = null;
    /** @var list<string> Rows on the screen now. */
    private array $shown = [];
    private string $shownCursor = '';
    private bool $entered = false;
    private ?string $frame = null;
    /** @var list<array{0: int, 1: int}|null> The line and row on each output row of the screen, as last drawn. */
    private array $onScreen = [];
    private int $outputHeight = 0;
    /** @var array{0: array{0: int, 1: int, 2: int}, 1: array{0: int, 1: int, 2: int}}|null Where a selection starts and where it is dragged to: line, row, column. */
    private ?array $selection = null;
    private bool $dragging = false;
    /** Power mode's characters dropping into place; null until the first burst. */
    private ?Effects $effects = null;
    /** The timer that clears a selection once copied. */
    private ?string $fade = null;
    /** @var list<string> Lines over the top right, as asked and as last drawn. */
    private array $overlay = [];
    private array $overlayShown = [];
    /** @var list<float> When the frames of the last second were written. */
    private array $frames = [];
    private float $frameMs = 0.0;

    /**
     * @param int $keep Finished lines kept to scroll back to and to print on closing.
     */
    public function __construct(
        private readonly Terminal $terminal,
        private readonly float $interval = 1 / 60,
        private readonly int $keep = 10_000,
    ) {}

    public function print(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->tail = $text[-1];
        $parts = explode("\n", str_replace("\r", '', $this->partial . $this->draft . $text));
        $this->draft = '';
        $this->partial = (string) array_pop($parts);
        foreach ($parts as $line) {
            $this->done[$this->next++] = $line;
        }
        $this->trim();
        $this->schedule();
    }

    public function burst(string $chars): void
    {
        ($this->effects ??= new Effects())->burst($chars, microtime(true));
        $this->schedule();
    }

    public function gap(): void
    {
        if ($this->partial . $this->draft !== '') {
            $this->print("\n");
        }
        if ($this->next > $this->start && $this->done[$this->next - 1] !== '') {
            $this->print("\n");
        }
    }

    public function fold(string $closed, string $open): void
    {
        if ($this->partial . $this->draft !== '') {
            $this->print("\n");
        }
        $this->folds[$this->next] = [$closed, $open];
        $this->done[$this->next++] = $closed;
        $this->tail = "\n";
        $this->trim();
        $this->schedule();
    }

    public function folds(bool $open): void
    {
        foreach ($this->folds as $line => $fold) {
            $this->done[$line] = $fold[$open ? 1 : 0];
            unset($this->wrapped[$line]);
        }
        foreach ($this->runs as $line => $run) {
            $this->runs[$line]['open'] = $open;
            $this->forgetRun($line);
        }
        $this->keepPlace();
        $this->schedule();
    }

    public function here(): int
    {
        return $this->next + ($this->partial . $this->draft !== '' ? 1 : 0);
    }

    public function change(int $line, string $text): void
    {
        if (!isset($this->done[$line]) || isset($this->folds[$line]) || $this->done[$line] === $text) {
            return;
        }
        $this->done[$line] = $text;
        unset($this->wrapped[$line]);
        $this->schedule();
    }

    public function collapse(int $from, int $to, string $summary): void
    {
        $from = max($from, $this->start);
        $to = min($to, $this->next);
        if ($to <= $from || isset($this->inRun[$from])) {
            return;
        }
        // A run inside this one is taken into it.
        foreach ($this->runs as $line => $run) {
            if ($line >= $from && $line < $to) {
                unset($this->runs[$line]);
            }
        }
        $this->runs[$from] = ['to' => $to, 'summary' => $summary, 'open' => false];
        for ($line = $from + 1; $line < $to; $line++) {
            $this->inRun[$line] = $from;
        }
        $this->forgetRun($from);
        $this->keepPlace();
        $this->schedule();
    }

    /**
     * Forgets how a run's lines were wrapped, as it opens or closes.
     */
    private function forgetRun(int $from): void
    {
        for ($line = $from; $line < $this->runs[$from]['to']; $line++) {
            unset($this->wrapped[$line]);
        }
    }

    /**
     * Whether a line is hidden inside a collapsed run.
     */
    private function hidden(int $line): bool
    {
        $from = $this->inRun[$line] ?? null;
        return $from !== null && isset($this->runs[$from]) && !$this->runs[$from]['open'];
    }

    /**
     * Opens a line that opens, or closes it, keeping it where it is on the
     * screen while scrolled back.
     */
    private function toggle(int $line): void
    {
        $this->done[$line] = $this->done[$line] === $this->folds[$line][0] ? $this->folds[$line][1] : $this->folds[$line][0];
        unset($this->wrapped[$line]);
        $this->keepPlace();
        $this->schedule();
    }

    /**
     * After lines changed their rows, a top inside one starts at its first row.
     */
    private function keepPlace(): void
    {
        if ($this->top !== null) {
            $this->top = [$this->top[0], 0];
        }
        $this->selection = null;
    }

    /**
     * Forgets the oldest lines past what is kept.
     */
    private function trim(): void
    {
        while ($this->next - $this->start > $this->keep) {
            unset($this->done[$this->start], $this->wrapped[$this->start], $this->folds[$this->start], $this->inRun[$this->start]);
            if (isset($this->runs[$this->start])) {
                // A run whose first line is gone: what is left of it shows as it is.
                $run = $this->runs[$this->start];
                unset($this->runs[$this->start]);
                for ($line = $this->start + 1; $line < $run['to']; $line++) {
                    unset($this->inRun[$line], $this->wrapped[$line]);
                }
            }
            $this->start++;
        }
        if ($this->top !== null && $this->top[0] < $this->start) {
            $this->top = [$this->start, 0];
        }
        if ($this->selection !== null && min($this->selection[0][0], $this->selection[1][0]) < $this->start) {
            $this->selection = null; // its lines are no longer kept
        }
    }

    public function draft(string $text): void
    {
        $this->draft = $text;
        $this->schedule();
    }

    public function atLineStart(): bool
    {
        return $this->tail === "\n" && $this->draft === '';
    }

    public function set(array $lines, ?array $cursor = null): void
    {
        $this->lines = $lines;
        $this->cursor = $cursor;
        $this->schedule();
    }

    public function flush(): void
    {
        if ($this->frame !== null) {
            EventLoop::current()->cancel($this->frame);
            $this->frame = null;
        }
        $this->render();
    }

    public function close(): void
    {
        if ($this->frame !== null) {
            EventLoop::current()->cancel($this->frame);
            $this->frame = null;
        }
        $this->terminal->alternate(false);
        $this->entered = false;
        $out = '';
        foreach ($this->done as $number => $line) {
            // As it was shown: a collapsed run as its one line.
            if ($this->hidden($number)) {
                continue;
            }
            $run = $this->runs[$number] ?? null;
            if ($run !== null) {
                $out .= $run['summary'] . "\r\n";
                if (!$run['open']) {
                    continue;
                }
            }
            $out .= str_replace("\n", "\r\n", $line) . "\r\n";
        }
        if ($this->partial . $this->draft !== '') {
            $out .= str_replace("\n", "\r\n", $this->partial . $this->draft) . "\r\n";
        }
        $this->terminal->write($out . "\e[?25h");
    }

    public function width(): int
    {
        return max(1, $this->terminal->columns() - 2 * self::MARGIN);
    }

    public function redraw(): void
    {
        $this->shown = [];
        $this->shownCursor = '';
        $this->schedule();
    }

    public function clear(bool $scrollback = false): void
    {
        $this->done = [];
        $this->wrapped = [];
        $this->folds = [];
        $this->runs = [];
        $this->inRun = [];
        $this->start = $this->next;
        $this->top = null;
        $this->selection = null;
        $this->redraw();
    }

    public function scroll(int $rows): void
    {
        if ($rows === 0 || ($rows > 0 && $this->top === null)) {
            return;
        }
        $height = $this->height();
        $top = $this->top ?? $this->bottomTop($height);
        $top = $rows < 0 ? $this->up($top, -$rows) : $this->down($top, $rows);
        // Scrolled back down to the newest: follow it again.
        $this->top = $this->countFrom($top, $height + 1) <= $height ? null : $top;
        $this->schedule();
    }

    public function follow(): void
    {
        if ($this->top !== null) {
            $this->top = null;
            $this->schedule();
        }
    }

    public function page(): int
    {
        return max(1, $this->height() - 1);
    }

    private function schedule(): void
    {
        if ($this->frame !== null) {
            return;
        }
        $this->frame = EventLoop::current()->delay($this->interval, function (): void {
            $this->frame = null;
            $this->render();
        });
    }

    /**
     * Rows for the output, above the live lines.
     */
    private function height(): int
    {
        $rows = $this->terminal->rows();
        return max(1, $rows - min(count($this->lines), $rows - 1));
    }

    /**
     * The rows a line takes at the current width; the line after the last
     * finished one is the unfinished line.
     *
     * @return list<string>
     */
    private function rows(int $line): array
    {
        if ($line >= $this->next) {
            // The unfinished line, and a draft that may span lines, as a table being drawn does.
            $text = $this->partial . $this->draft;
            $rows = [];
            foreach ($text === '' ? [] : explode("\n", $text) as $part) {
                array_push($rows, ...$this->wrap($part));
            }
            return $rows;
        }
        if (!isset($this->wrapped[$line])) {
            // A line that opens may hold several, as a tool's output does. Closed, each
            // of them takes one row, cut with …; opened, each wraps in full.
            $closed = isset($this->folds[$line]) && $this->done[$line] === $this->folds[$line][0];
            $space = str_repeat(' ', self::MARGIN);
            $rows = [];
            if ($this->hidden($line)) {
                return $this->wrapped[$line] = [];
            }
            $run = $this->runs[$line] ?? null;
            if ($run !== null) {
                // A collapsed run: its one line, and above its lines when opened, to close it by.
                $rows[] = $space . Style::cut($run['summary'], max(1, $this->width - 2 * self::MARGIN));
                if (!$run['open']) {
                    return $this->wrapped[$line] = $rows;
                }
            }
            foreach (explode("\n", $this->done[$line]) as $part) {
                if ($closed) {
                    $rows[] = $part === '' ? '' : $space . Style::cut($part, max(1, $this->width - 2 * self::MARGIN));
                    continue;
                }
                array_push($rows, ...$this->wrap($part));
            }
            $this->wrapped[$line] = $rows;
        }
        return $this->wrapped[$line];
    }

    /**
     * A line as rows: text inside the margins, wrapped to the width between
     * them and set in from the left; a shaded line, one that fills its row
     * with its background, from edge to edge, its padding inside its colour.
     *
     * @return list<string>
     */
    private function wrap(string $line): array
    {
        $space = str_repeat(' ', self::MARGIN);
        if (str_contains($line, "\e[K")) {
            // Shaded: the whole width, the margin's space inside the colour, on every row it takes.
            preg_match('/^(?:\e\[[0-9;]*m)*/', $line, $style);
            $padded = $style[0] . $space . substr($line, strlen($style[0]));
            return Style::wrapAnsi($padded, max(1, $this->width), max(self::MARGIN, self::hang($padded)));
        }
        return array_map(
            static fn (string $row): string => $row === '' ? '' : $space . $row,
            Style::wrapAnsi($line, max(1, $this->width - 2 * self::MARGIN), self::hang($line)),
        );
    }

    /**
     * How far a line's rows after the first are set in: under the text
     * after a leading marker, as a message's ›, a bullet, a number or a
     * quote's bar; nothing for plain text.
     */
    private static function hang(string $line): int
    {
        // After a marker, under its text; a line set in, as a tool's output, under where it starts.
        return preg_match('/^(\s*(?:› |• |└ |\d+\. |│ )|\s+(?=\S))/u', Style::strip($line), $m) === 1 ? mb_strwidth($m[1], 'UTF-8') : 0;
    }

    /**
     * Where the top is when the newest output fills the rows to the bottom.
     *
     * @return array{0: int, 1: int}
     */
    private function bottomTop(int $height): array
    {
        $line = $this->next;
        $need = $height;
        while (true) {
            $count = count($this->rows($line));
            if ($count >= $need) {
                return [$line, $count - $need];
            }
            $need -= $count;
            if ($line <= $this->start) {
                return [$this->start, 0];
            }
            $line--;
        }
    }

    /**
     * @param array{0: int, 1: int} $top
     * @return array{0: int, 1: int}
     */
    private function up(array $top, int $rows): array
    {
        [$line, $row] = $top;
        $row -= $rows;
        while ($row < 0 && $line > $this->start) {
            $line--;
            $row += count($this->rows($line));
        }
        return $row < 0 ? [$this->start, 0] : [$line, $row];
    }

    /**
     * @param array{0: int, 1: int} $top
     * @return array{0: int, 1: int}
     */
    private function down(array $top, int $rows): array
    {
        [$line, $row] = $top;
        $row += $rows;
        while ($line < $this->next && $row >= count($this->rows($line))) {
            $row -= count($this->rows($line));
            $line++;
        }
        return [$line, $row];
    }

    /**
     * Up to $limit rows from a place down to the newest output.
     *
     * @param array{0: int, 1: int} $top
     * @return list<string>
     */
    private function from(array $top, int $limit): array
    {
        return array_column($this->placed($top, $limit), 2);
    }

    /**
     * As from(), each row with the line and the row of it that it is.
     *
     * @param array{0: int, 1: int} $top
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function placed(array $top, int $limit): array
    {
        [$line, $row] = $top;
        $placed = [];
        for (; $line <= $this->next && count($placed) < $limit; $line++, $row = 0) {
            foreach (array_slice($this->rows($line), $row, $limit - count($placed)) as $offset => $text) {
                $placed[] = [$line, $row + $offset, $text];
            }
        }
        return $placed;
    }

    public function pointer(string $action, int $column, int $row): ?string
    {
        [$x, $y] = [$column - 1, $row - 1];
        if ($action === 'down') {
            if ($this->fade !== null) {
                EventLoop::current()->cancel($this->fade);
                $this->fade = null;
            }
            $this->selection = null;
            $this->dragging = false;
            if ($y < count($this->onScreen) && $this->onScreen[$y] !== null) {
                $at = [...$this->onScreen[$y], max(0, $x)];
                $this->selection = [$at, $at];
                $this->dragging = true;
            }
            $this->schedule();
            return null;
        }
        if ($this->selection === null || !$this->dragging) {
            return null;
        }
        if ($action === 'drag') {
            // Dragging past an edge scrolls, to select more than a screen.
            if ($y <= 0) {
                $this->scroll(-1);
            } elseif ($y >= $this->outputHeight - 1 && $this->top !== null) {
                $this->scroll(1);
            }
            $y = max(0, min($y, count($this->onScreen) - 1));
            for (; $y > 0 && ($this->onScreen[$y] ?? null) === null; $y--);
            if (($this->onScreen[$y] ?? null) !== null) {
                $this->selection[1] = [...$this->onScreen[$y], max(0, $x)];
                $this->schedule();
            }
            return null;
        }
        // Released: a click without a drag selects nothing; a drag is copied, to where it was let go.
        $this->dragging = false;
        $y = max(0, min($y, count($this->onScreen) - 1));
        for (; $y > 0 && ($this->onScreen[$y] ?? null) === null; $y--);
        if (($this->onScreen[$y] ?? null) !== null && $this->selection[0] !== [...$this->onScreen[$y], max(0, $x)]) {
            $this->selection[1] = [...$this->onScreen[$y], max(0, $x)];
        }
        [$anchor, $focus] = $this->selection;
        if ($anchor === $focus) {
            // A click: on a line that opens, it opens or closes.
            $this->selection = null;
            $run = $this->runs[$anchor[0]] ?? null;
            if ($run !== null && (!$run['open'] || $anchor[1] === 0)) {
                // A collapsed run opens; its first row, opened, closes it again.
                $this->runs[$anchor[0]]['open'] = !$run['open'];
                $this->forgetRun($anchor[0]);
                $this->keepPlace();
            } elseif (isset($this->folds[$anchor[0]])) {
                $this->toggle($anchor[0]);
            }
            $this->schedule();
            return null;
        }
        $text = $this->selected();
        if (trim($text) === '') {
            return null; // only blank space: nothing worth the clipboard
        }
        $this->terminal->copy($text);
        // Copied on letting go: the selection shows a moment, then clears, so it is plain nothing more is needed.
        $this->fade = EventLoop::current()->delay(0.35, function (): void {
            $this->fade = null;
            $this->selection = null;
            $this->schedule();
        });
        return $text;
    }

    /**
     * The selected text, plain: rows a line was wrapped into are joined
     * again, and each line loses the spaces at its end.
     */
    private function selected(): string
    {
        if ($this->selection === null) {
            return '';
        }
        [$start, $end] = $this->ordered();
        $lines = [];
        for ($line = $start[0]; $line <= $end[0]; $line++) {
            if ($this->hidden($line)) {
                continue;
            }
            $text = '';
            [$rows, $joins, $margin] = $this->plain($line);
            $first = $line === $start[0] ? $start[1] : 0;
            $last = $line === $end[0] ? min($end[1], count($rows) - 1) : count($rows) - 1;
            for ($row = $first; $row <= $last; $row++) {
                // Mouse columns count the margin; the rows' text does not.
                $from = [$line, $row] === [$start[0], $start[1]] ? max(0, $start[2] - $margin) : 0;
                $to = [$line, $row] === [$end[0], $end[1]] ? $end[2] + 1 - $margin : PHP_INT_MAX;
                $piece = Style::columns($rows[$row], $from, $to);
                // A row after the first starts with the wrap's hanging indent, not the line's own text.
                $text .= $row > 0 ? ltrim($piece, ' ') : $piece;
                if ($row < $last) {
                    $text .= $joins[$row]; // the space a word wrap took away, given back
                }
            }
            $lines[] = rtrim($text);
        }
        return implode("\n", $lines);
    }

    /**
     * A line's rows as plain text, without the margin and the background's
     * fill; and what was between each row and the next in the line itself:
     * the space a row was broken at, a line break in a draft, or nothing
     * where a word was cut; and the margin its rows have.
     *
     * @return array{list<string>, list<string>, int}
     */
    private function plain(int $line): array
    {
        $raw = $line >= $this->next ? $this->partial . $this->draft : $this->done[$line];
        $margin = self::MARGIN; // outside a plain row, inside a shaded row's colour: the same columns either way
        $rows = array_map(static fn (string $row): string => rtrim((string) substr(Style::strip($row), $margin)), $this->rows($line));
        $original = Style::strip($raw);
        $joins = [];
        $at = 0;
        foreach ($rows as $index => $row) {
            $row = $index > 0 ? ltrim($row, ' ') : $row; // matched without the hanging indent
            $found = $row === '' ? $at : strpos($original, $row, $at);
            $end = $found === false ? $at : $found + strlen($row);
            $next = isset($rows[$index + 1]) ? ltrim($rows[$index + 1], ' ') : null;
            $following = $next === null || $next === '' ? false : strpos($original, $next, $end);
            $joins[] = $following === false ? '' : substr($original, $end, $following - $end);
            $at = $following === false ? $end : $following;
        }
        return [$rows, $joins, $margin];
    }

    /**
     * The selection's two ends, first one first.
     *
     * @return array{0: array{0: int, 1: int, 2: int}, 1: array{0: int, 1: int, 2: int}}
     */
    private function ordered(): array
    {
        [$a, $b] = $this->selection;
        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    /**
     * A row with the selected part of it in inverse.
     */
    private function mark(int $line, int $row, string $text): string
    {
        if ($this->selection === null) {
            return $text;
        }
        [$start, $end] = $this->ordered();
        $here = [$line, $row];
        if ($here < [$start[0], $start[1]] || $here > [$end[0], $end[1]]) {
            return $text;
        }
        $from = $here === [$start[0], $start[1]] ? $start[2] : 0;
        $to = $here === [$end[0], $end[1]] ? $end[2] + 1 : $this->width;
        return Style::invert($text, $from, $to);
    }

    /**
     * @param array{0: int, 1: int} $top
     */
    private function countFrom(array $top, int $limit): int
    {
        return count($this->from($top, $limit));
    }

    private function render(): void
    {
        $began = hrtime(true);
        $columns = $this->terminal->columns();
        $screenRows = $this->terminal->rows();
        if ($columns !== $this->width) {
            $this->width = $columns;
            $this->wrapped = [];
            $this->shown = [];
            // Rows are wrapped afresh, so where a selection was is gone.
            $this->selection = null;
            if ($this->top !== null) {
                $this->top = [$this->top[0], 0];
            }
        }
        $lines = array_slice($this->lines, -max(0, $screenRows - 1));
        $cut = count($this->lines) - count($lines);
        $height = $screenRows - count($lines);

        $placed = $this->placed($this->top ?? $this->bottomTop($height), $height);
        if ($this->top !== null && $this->countFrom($this->top, $height + 1) <= $height) {
            $this->top = null;
        }
        $output = [];
        $this->onScreen = [];
        foreach ($placed as [$line, $row, $text]) {
            $output[] = $this->mark($line, $row, $text);
            $this->onScreen[] = [$line, $row];
        }
        if ($this->top !== null && count($output) >= $height) {
            $output[$height - 1] = Style::dim('↓');
            $this->onScreen[$height - 1] = null;
        }
        $this->outputHeight = $height;
        $moving = false;
        if ($this->effects !== null && $this->effects->active($now = microtime(true))) {
            // Where the text streams: the last row of the unfinished line, when the newest output is shown.
            $at = null;
            $end = 0;
            if ($this->top === null) {
                for ($index = count($placed) - 1; $index >= 0; $index--) {
                    if ($placed[$index][0] >= $this->next) {
                        $at = $index;
                        $end = Style::width($placed[$index][2]);
                        break;
                    }
                }
            }
            $output = $this->effects->apply(array_pad($output, $height, ''), $at, $end, $now);
            $moving = true;
        }
        $frame = array_merge(array_pad($output, $height, ''), $lines);

        if ($this->overlay !== $this->overlayShown) {
            // The rows it covered, or covers now, are drawn again under it.
            for ($index = 0; $index < max(count($this->overlay), count($this->overlayShown)); $index++) {
                unset($this->shown[$index]);
            }
            $this->overlayShown = $this->overlay;
        }
        $out = '';
        foreach ($frame as $index => $row) {
            if (($this->shown[$index] ?? null) !== $row) {
                // Cleared before it is written: erasing after would take a full row's last character.
                $out .= "\e[" . ($index + 1) . ";1H\e[2K" . $row . "\e[0m";
            }
        }
        if ($out !== '' && $this->overlay !== []) {
            // Over the top right of the output, after the rows, so a row drawn again does not hide it.
            foreach (array_slice($this->overlay, 0, $height) as $index => $line) {
                $column = max(1, $columns - Style::width($line) - 1);
                $out .= "\e[" . ($index + 1) . ";{$column}H\e[2m " . $line . " \e[0m";
            }
        }
        $cursor = '';
        if ($this->cursor !== null && $lines !== []) {
            [$row, $column] = $this->cursor;
            $row = min(count($lines) - 1, max(0, $row - $cut));
            $cursor = "\e[" . ($height + $row + 1) . ';' . ($column + 1) . "H\e[?25h";
        }
        if ($moving) {
            $this->schedule(); // still moving: the next frame too
        }
        if ($out === '' && $cursor === $this->shownCursor && $this->entered) {
            return;
        }
        if (!$this->entered) {
            $this->terminal->alternate(true);
            $this->entered = true;
        }
        $this->shown = $frame;
        $this->shownCursor = $cursor;
        $this->terminal->write("\e[?2026h\e[?25l" . $out . ($cursor !== '' ? $cursor : '') . "\e[?2026l");
        $this->frameMs = (hrtime(true) - $began) / 1e6;
        $this->frames[] = microtime(true);
    }

    public function overlay(array $lines): void
    {
        if ($lines !== $this->overlay) {
            $this->overlay = $lines;
            $this->schedule();
        }
    }

    public function metrics(): array
    {
        $now = microtime(true);
        $this->frames = array_values(array_filter($this->frames, static fn (float $at): bool => $now - $at < 1.0));
        return ['fps' => count($this->frames), 'frame' => $this->frameMs];
    }
}
