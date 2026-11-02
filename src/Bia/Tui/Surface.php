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
     * The left mouse button, at a column and row counted from 1: "down"
     * starts a selection, "drag" extends it, "up" ends it and copies it.
     *
     * @return string|null The text copied, on an "up" that ends a selection.
     */
    public function pointer(string $action, int $column, int $row): ?string;
}
