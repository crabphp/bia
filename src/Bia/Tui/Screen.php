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
    /** @var array<int, string> Finished lines, by number. */
    private array $done = [];
    /** @var array<int, list<string>> Finished lines wrapped at $width, by number. */
    private array $wrapped = [];
    /** Number of the oldest line kept, and of the next to finish. */
    private int $start = 0;
    private int $next = 0;
    private string $partial = '';
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
        $parts = explode("\n", str_replace("\r", '', $this->partial . $text));
        $this->partial = (string) array_pop($parts);
        foreach ($parts as $line) {
            $this->done[$this->next++] = $line;
        }
        while ($this->next - $this->start > $this->keep) {
            unset($this->done[$this->start], $this->wrapped[$this->start]);
            $this->start++;
        }
        if ($this->top !== null && $this->top[0] < $this->start) {
            $this->top = [$this->start, 0];
        }
        $this->schedule();
    }

    public function atLineStart(): bool
    {
        return $this->tail === "\n";
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
        foreach ($this->done as $line) {
            $out .= $line . "\r\n";
        }
        if ($this->partial !== '') {
            $out .= $this->partial . "\r\n";
        }
        $this->terminal->write($out . "\e[?25h");
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
        $this->start = $this->next;
        $this->top = null;
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
            return $this->partial === '' ? [] : Style::wrapAnsi($this->partial, $this->width);
        }
        return $this->wrapped[$line] ??= Style::wrapAnsi($this->done[$line], $this->width);
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
        [$line, $row] = $top;
        $rows = [];
        for (; $line <= $this->next && count($rows) < $limit; $line++, $row = 0) {
            array_push($rows, ...array_slice($this->rows($line), $row, $limit - count($rows)));
        }
        return $rows;
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
        $columns = $this->terminal->columns();
        $screenRows = $this->terminal->rows();
        if ($columns !== $this->width) {
            $this->width = $columns;
            $this->wrapped = [];
            $this->shown = [];
            if ($this->top !== null) {
                $this->top = [$this->top[0], 0];
            }
        }
        $lines = array_slice($this->lines, -max(0, $screenRows - 1));
        $cut = count($this->lines) - count($lines);
        $height = $screenRows - count($lines);

        if ($this->top === null) {
            $output = $this->from($this->bottomTop($height), $height);
        } else {
            $output = $this->from($this->top, $height);
            $below = $this->countFrom($this->top, $height + 1);
            if ($below <= $height) {
                $this->top = null;
            } else {
                $output[$height - 1] = Style::dim('↓');
            }
        }
        $frame = array_merge(array_pad($output, $height, ''), $lines);

        $out = '';
        foreach ($frame as $index => $row) {
            if (($this->shown[$index] ?? null) !== $row) {
                // Cleared before it is written: erasing after would take a full row's last character.
                $out .= "\e[" . ($index + 1) . ";1H\e[2K" . $row . "\e[0m";
            }
        }
        $cursor = '';
        if ($this->cursor !== null && $lines !== []) {
            [$row, $column] = $this->cursor;
            $row = min(count($lines) - 1, max(0, $row - $cut));
            $cursor = "\e[" . ($height + $row + 1) . ';' . ($column + 1) . "H\e[?25h";
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
    }
}
