<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Where a conversation is drawn: finished output above, and below it the
 * live lines (spinner, input, menu, status), drawn again as they change.
 *
 * Region draws inline, into the terminal's own scrollback. Screen takes the
 * whole terminal and scrolls the output itself, so the live lines stay put.
 */
interface Surface
{
    /**
     * Adds output above the live lines; a line is finished by its newline.
     */
    public function print(string $text): void;

    /**
     * Shows the unfinished line as given, after the output: each draft takes
     * the place of the last, and printing anything keeps the draft as it is.
     * A line that streams in can so be drawn again as more of it arrives; a
     * draft may hold several lines, as a table does until it is whole.
     */
    public function draft(string $text): void;

    /**
     * Adds a finished line that opens: shown as given closed, as a tool's
     * call with a summary, and in full once opened, as by a click on it.
     * Either may hold several lines.
     */
    public function fold(string $closed, string $open): void;

    /**
     * Opens every line that opens, or closes them all; runs collapsed open
     * with them, and collapse again when they close.
     */
    public function folds(bool $open): void;

    /**
     * Where the next finished line goes, to collapse from later. Where the
     * output is the terminal's own, 0.
     */
    public function here(): int;

    /**
     * Changes a finished line, by its number from here(), as it is shown:
     * a small animation of something kept, as a blink. A line no longer
     * kept, or one that opens, is left alone; where the output is the
     * terminal's own, nothing changes.
     */
    public function change(int $line, string $text): void;

    /**
     * Shows finished lines, from one mark up to another, as one line, as a
     * finished turn's run of tool calls: they come back with a click on it
     * or with folds(true). Where the output is the terminal's own, what
     * was printed stays as it is.
     */
    public function collapse(int $from, int $to, string $summary): void;

    /**
     * Characters that just appeared at the end of the streaming line, for
     * power mode to drop into place. Where the
     * output is the terminal's own, nothing.
     */
    public function burst(string $chars): void;

    /**
     * Sets what comes next apart by one blank line: none at the very top,
     * and never a second. Blocks (the user's message, the answer, a run of
     * tool calls) are spaced so, and nothing inside one.
     */
    public function gap(): void;

    /**
     * Whether the output printed so far ends a line, with no draft after it.
     */
    public function atLineStart(): bool;

    /**
     * Sets the live lines, each already fitting the width.
     *
     * @param list<string> $lines
     * @param array{0: int, 1: int}|null $cursor Where to show the cursor, or null to hide it.
     */
    public function set(array $lines, ?array $cursor = null): void;

    /**
     * Draws now what is waiting.
     */
    public function flush(): void;

    /**
     * Draws what is left and gives the terminal back as a program leaves it.
     */
    public function close(): void;

    /**
     * Draws everything again on the next frame, as after a resize.
     */
    public function redraw(): void;

    /**
     * Clears the screen, and its scrollback when asked, and forgets the output so far.
     */
    public function clear(bool $scrollback = false): void;

    /**
     * Scrolls the output by rows: negative up, to older output, positive down.
     */
    public function scroll(int $rows): void;

    /**
     * Scrolls back to the newest output, and keeps following it.
     */
    public function follow(): void;

    /**
     * Rows a page scroll moves; 0 where the terminal does the scrolling itself.
     */
    public function page(): int;

    /**
     * Lines shown over the top right of the output, as live statistics are;
     * none to show nothing. Where the output is the terminal's own, inline,
     * there is no corner to keep, and they are not drawn.
     *
     * @param list<string> $lines
     */
    public function overlay(array $lines): void;

    /**
     * How it draws: the frames written in the last second, and the time the
     * last one took, in milliseconds.
     *
     * @return array{fps: int, frame: float}
     */
    public function metrics(): array;

    /**
     * The left mouse button, at a column and row counted from 1: "down"
     * starts a selection, "drag" extends it, "up" ends it and copies it.
     *
     * @return string|null The text copied, on an "up" that ends a selection.
     */
    public function pointer(string $action, int $column, int $row): ?string;
}
