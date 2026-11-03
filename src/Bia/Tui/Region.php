<?php

declare(strict_types=1);

namespace Bia\Tui;

use Crab\Engine\EventLoop;

/**
 * Inline rendering: finished output scrolls up like any program's, and only
 * the few lines at the bottom, the live region, are drawn again.
 *
 * Changes are gathered and drawn at most once a frame, in one write wrapped
 * in synchronized output, so a fast stream of model tokens costs a redraw per
 * frame, not per token. Text printed without its newline yet stays in the
 * region until the line is finished.
 */
final class Region implements Surface
{
    /** @var list<string> */
    private array $lines = [];
    /** @var array{0: int, 1: int}|null Row and column of the cursor within $lines. */
    private ?array $cursor = null;
    private string $pending = '';
    private string $partial = '';
    private string $draft = '';
    /** @var list<float> When the frames of the last second were written. */
    private array $frames = [];
    private float $frameMs = 0.0;
    /** Row of the region the terminal's cursor is on, counted from its top. */
    private int $at = 0;
    /** The width the region was last drawn at; 0 before the first frame. */
    private int $columns = 0;
    /** The end of the finished output, to print again when a resize makes the screen be drawn afresh. */
    private string $transcript = '';
    /** The width a resize is settling at, and the timer that draws afresh when it has. */
    private int $settling = 0;
    private ?string $settle = null;
    private bool $drawn = false;
    private ?string $frame = null;
    private string $last = '';
    private string $tail = "\n";
    /** The last two characters printed, to know a blank line ends the output. */
    private string $end = '';

    /**
     * @param int $keep Bytes of finished output kept to print again after a resize.
     */
    public function __construct(
        private readonly Terminal $terminal,
        private readonly float $interval = 1 / 60,
        private readonly int $keep = 512 * 1024,
        private readonly float $settleDelay = 0.1,
    ) {}

    /**
     * Adds finished output above the region.
     */
    public function print(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->pending .= $this->draft . $text;
        $this->end = substr($this->end . $this->draft . $text, -2);
        $this->draft = '';
        $this->tail = $text[-1];
        $this->schedule();
    }

    /**
     * The terminal keeps what was printed: a line is shown closed, and stays
     * so, each of its lines on one row, cut with … at the width there is now.
     */
    public function fold(string $closed, string $open): void
    {
        if (!$this->atLineStart()) {
            $this->print("\n");
        }
        $width = max(1, $this->terminal->columns() - 2 * Screen::MARGIN);
        $lines = array_map(static fn (string $line): string => Style::cut($line, $width), explode("\n", $closed));
        $this->print(implode("\n", $lines) . "\n");
    }

    public function folds(bool $open): void {}

    public function here(): int
    {
        return 0;
    }

    public function change(int $line, string $text): void
    {
        // Inline, what was printed is the terminal's scrollback: it stays as it was.
    }

    public function collapse(int $from, int $to, string $summary): void
    {
        // Inline, what was printed is the terminal's scrollback: it stays as it was.
    }

    public function burst(string $chars): void
    {
        // Inline, the rows above belong to the terminal's scrollback: nothing may be drawn over them.
    }

    public function gap(): void
    {
        if (!$this->atLineStart()) {
            $this->print("\n");
        }
        if ($this->end !== '' && $this->end !== "\n\n") {
            $this->print("\n");
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

    /**
     * Sets the region's lines, each already fitting the width.
     *
     * @param list<string> $lines
     * @param array{0: int, 1: int}|null $cursor Where to show the cursor, or null to hide it.
     */
    public function set(array $lines, ?array $cursor = null): void
    {
        $this->lines = $lines;
        $this->cursor = $cursor;
        $this->schedule();
    }

    /**
     * Draws now what is waiting.
     */
    public function flush(): void
    {
        if ($this->frame !== null) {
            EventLoop::current()->cancel($this->frame);
            $this->frame = null;
        }
        $this->render(true);
    }

    /**
     * Prints what is left, removes the region and shows the cursor: the
     * screen as a program leaves it.
     */
    public function close(): void
    {
        $this->lines = [];
        $this->cursor = null;
        $this->pending .= $this->draft;
        $this->draft = '';
        if ($this->partial !== '' || ($this->pending !== '' && !str_ends_with($this->pending, "\n"))) {
            $this->pending .= "\n";
        }
        $this->flush();
        $this->terminal->write("\e[?25h");
    }

    /**
     * Draws the region again on the next frame. After a resize that changed
     * the width, the whole screen is drawn afresh: the terminal has rewrapped
     * the old region, each terminal its own way, into rows that cannot be
     * found again to erase.
     */
    public function width(): int
    {
        return max(1, $this->terminal->columns() - 2 * Screen::MARGIN);
    }

    public function redraw(): void
    {
        $this->last = '';
        $this->schedule();
    }

    /**
     * Clears the screen, and its scrollback too when asked, and forgets the
     * output printed so far, then draws the region at the top.
     */
    public function clear(bool $scrollback = false): void
    {
        $this->flush();
        $this->transcript = '';
        $this->end = '';
        $this->drawn = false;
        $this->terminal->write("\e[H\e[2J" . ($scrollback ? "\e[3J" : ''));
        $this->redraw();
    }

    public function scroll(int $rows): void
    {
        // The terminal scrolls its own scrollback.
    }

    public function follow(): void {}

    public function pointer(string $action, int $column, int $row): ?string
    {
        return null; // the terminal selects its own scrollback
    }

    public function overlay(array $lines): void
    {
        // Inline, the output scrolls away in the terminal's own scrollback: no corner stays.
    }

    public function metrics(): array
    {
        $now = microtime(true);
        $this->frames = array_values(array_filter($this->frames, static fn (float $at): bool => $now - $at < 1.0));
        return ['fps' => count($this->frames), 'frame' => $this->frameMs];
    }

    public function page(): int
    {
        return 0;
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
     * @param bool $now Draw even while a resize is settling, as on leaving.
     */
    private function render(bool $now = false): void
    {
        $began = hrtime(true);
        $columns = $this->terminal->columns();
        $fresh = $this->drawn && $this->columns !== 0 && $columns !== $this->columns;
        if ($fresh && !$now) {
            // A drag resizes many times: draw afresh once, when the width has settled.
            if ($columns !== $this->settling) {
                $this->settling = $columns;
                if ($this->settle !== null) {
                    EventLoop::current()->cancel($this->settle);
                }
                $this->settle = EventLoop::current()->delay($this->settleDelay, function (): void {
                    $this->settle = null;
                    $this->render(true);
                });
            }
            return;
        }
        if ($this->settle !== null) {
            EventLoop::current()->cancel($this->settle);
            $this->settle = null;
        }
        $this->settling = 0;
        $text = $this->partial . $this->pending;
        $this->pending = '';
        $end = strrpos($text, "\n");
        $finished = $end === false ? '' : self::margin(substr($text, 0, $end + 1));
        $this->partial = $end === false ? $text : substr($text, $end + 1);

        $rows = [];
        foreach ($this->partial . $this->draft === '' ? [] : explode("\n", $this->partial . $this->draft) as $part) {
            array_push($rows, ...Style::wrapAnsi(self::margin($part), $columns)); // a draft may span lines
        }
        $offset = count($rows);
        array_push($rows, ...$this->lines);
        // A region taller than the screen could not be erased again: keep its bottom.
        $limit = max(1, $this->terminal->rows() - 1);
        $cut = max(0, count($rows) - $limit);
        $rows = array_slice($rows, $cut);
        $frame = implode("\n", $rows) . "\0" . json_encode($this->cursor) . "\0" . $columns;
        if (!$fresh && $finished === '' && $frame === $this->last) {
            return;
        }
        $this->last = $frame;

        $out = "\e[?2026h";
        if ($fresh) {
            // The terminal has rewrapped the old region its own way, into rows
            // that cannot be found again: clear everything and print it again.
            $out .= "\e[H\e[2J\e[3J" . str_replace("\n", "\r\n", $this->transcript);
        } elseif ($this->drawn) {
            $out .= "\r" . ($this->at > 0 ? "\e[{$this->at}A" : '') . "\e[J";
        }
        if ($finished !== '') {
            $out .= str_replace("\n", "\r\n", $finished);
            $this->keep($finished);
        }
        $out .= implode("\r\n", $rows);
        $this->drawn = $rows !== [];
        $this->columns = $columns;
        $this->at = max(0, count($rows) - 1);
        if ($this->cursor !== null && $rows !== []) {
            [$row, $column] = $this->cursor;
            $row = min(count($rows) - 1, max(0, $offset + $row - $cut));
            $up = count($rows) - 1 - $row;
            $out .= ($up > 0 ? "\e[{$up}A" : '') . "\r" . ($column > 0 ? "\e[{$column}C" : '') . "\e[?25h";
            $this->at = $row;
        } else {
            $out .= "\e[?25l";
        }
        $this->terminal->write($out . "\e[?2026l");
        $this->frameMs = (hrtime(true) - $began) / 1e6;
        $this->frames[] = microtime(true);
    }

    /**
     * Whole lines set in by the margin, as the Screen sets them: a plain line
     * after a column of space, a shaded one with that space inside its colour.
     */
    private static function margin(string $lines): string
    {
        $space = str_repeat(' ', Screen::MARGIN);
        return (string) preg_replace_callback(
            '/^((?:\e\[[0-9;]*m)*)(?=[^\n])/m',
            static fn (array $m): string => $m[1] . $space,
            $lines,
        );
    }

    /**
     * Keeps finished output, at most $keep bytes of its end, cut at a line
     * start so that no character or colour code is split.
     */
    private function keep(string $finished): void
    {
        $this->transcript .= $finished;
        if (strlen($this->transcript) <= $this->keep) {
            return;
        }
        $start = strpos($this->transcript, "\n", strlen($this->transcript) - $this->keep);
        $this->transcript = $start === false ? '' : substr($this->transcript, $start + 1);
    }
}
