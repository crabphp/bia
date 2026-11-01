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
final class Region
{
    /** @var list<string> */
    private array $lines = [];
    /** @var array{0: int, 1: int}|null Row and column of the cursor within $lines. */
    private ?array $cursor = null;
    private string $pending = '';
    private string $partial = '';
    /** Row of the region the terminal's cursor is on, counted from its top. */
    private int $at = 0;
    private bool $drawn = false;
    private ?string $frame = null;
    private string $last = '';
    private string $tail = "\n";

    public function __construct(
        private readonly Terminal $terminal,
        private readonly float $interval = 1 / 60,
    ) {}

    /**
     * Adds finished output above the region.
     */
    public function print(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->pending .= $text;
        $this->tail = $text[-1];
        $this->schedule();
    }

    /**
     * Whether the output printed so far ends a line.
     */
    public function atLineStart(): bool
    {
        return $this->tail === "\n";
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
        $this->render();
    }

    /**
     * Prints what is left, removes the region and shows the cursor: the
     * screen as a program leaves it.
     */
    public function close(): void
    {
        $this->lines = [];
        $this->cursor = null;
        if ($this->partial !== '' || ($this->pending !== '' && !str_ends_with($this->pending, "\n"))) {
            $this->pending .= "\n";
        }
        $this->flush();
        $this->terminal->write("\e[?25h");
    }

    /**
     * Draws everything again from where the region starts, as after a resize.
     */
    public function redraw(): void
    {
        $this->last = '';
        $this->schedule();
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

    private function render(): void
    {
        $columns = $this->terminal->columns();
        $text = $this->partial . $this->pending;
        $this->pending = '';
        $end = strrpos($text, "\n");
        $finished = $end === false ? '' : substr($text, 0, $end + 1);
        $this->partial = $end === false ? $text : substr($text, $end + 1);

        $rows = [];
        if ($this->partial !== '') {
            $rows = Style::wrap($this->partial, $columns);
        }
        $offset = count($rows);
        array_push($rows, ...$this->lines);
        // A region taller than the screen could not be erased again: keep its bottom.
        $limit = max(1, $this->terminal->rows() - 1);
        $cut = max(0, count($rows) - $limit);
        $rows = array_slice($rows, $cut);
        $frame = implode("\n", $rows) . "\0" . json_encode($this->cursor) . "\0" . $columns;
        if ($finished === '' && $frame === $this->last) {
            return;
        }
        $this->last = $frame;

        $out = "\e[?2026h";
        if ($this->drawn) {
            $out .= "\r" . ($this->at > 0 ? "\e[{$this->at}A" : '') . "\e[J";
        }
        if ($finished !== '') {
            $out .= str_replace("\n", "\r\n", $finished);
        }
        $out .= implode("\r\n", $rows);
        $this->drawn = $rows !== [];
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
    }
}
