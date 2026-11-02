<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * The minimal theme, and text measured as the terminal shows it: by display
 * columns, with colour codes taking none.
 *
 * Colour is off when NO_COLOR is set or the output is not a terminal; every
 * style then returns its text as it is.
 */
final class Style
{
    private static ?bool $color = null;

    public static function enable(bool $color): void
    {
        self::$color = $color;
    }

    public static function dim(string $text): string
    {
        return self::paint('2', $text);
    }

    public static function accent(string $text): string
    {
        return self::paint('36', $text);
    }

    public static function ok(string $text): string
    {
        return self::paint('32', $text);
    }

    public static function error(string $text): string
    {
        return self::paint('31', $text);
    }

    public static function warn(string $text): string
    {
        return self::paint('33', $text);
    }

    /**
     * A line on a dark grey background, just above a dark terminal's own, as
     * wide as the terminal: erasing to the end of the row fills it with the
     * background.
     */
    public static function bar(string $text): string
    {
        return self::color() ? "\e[48;5;236m{$text}\e[K\e[0m" : $text;
    }

    public static function bold(string $text): string
    {
        return self::paint('1', $text);
    }

    public static function inverse(string $text): string
    {
        return self::paint('7', $text);
    }

    public static function strip(string $text): string
    {
        return str_contains($text, "\e") ? (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) : $text;
    }

    /**
     * Columns the text takes on screen.
     */
    public static function width(string $text): int
    {
        return mb_strwidth(self::strip($text), 'UTF-8');
    }

    /**
     * Plain text cut to the given columns, ending in … when cut.
     */
    public static function truncate(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        return mb_strwidth($text, 'UTF-8') <= $width ? $text : mb_strimwidth($text, 0, $width, '…', 'UTF-8');
    }

    /**
     * Plain text split into rows of at most the given columns, by character,
     * as the terminal itself wraps it. An empty text is one empty row.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        if (strlen($text) <= $width && !preg_match('/[^\x20-\x7e]/', $text)) {
            return [$text]; // ASCII that fits: the common case, measured for free
        }
        $rows = [];
        $row = '';
        $used = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $w = mb_strwidth($char, 'UTF-8');
            if ($used + $w > $width) {
                $rows[] = $row;
                $row = '';
                $used = 0;
            }
            $row .= $char;
            $used += $w;
        }
        $rows[] = $row;
        return $rows;
    }

    /**
     * A line of styled text split into rows of at most the given columns,
     * between words: a row ends at its last space, and only a word longer
     * than a whole row is cut. The space a row ends at is not kept, nor are
     * spaces at the start of the row after it.
     *
     * Colour carries across a split: a row that ends inside a style resets
     * it, and the next row starts it again. A line that erases to its end
     * (`\e[K`) has each of its rows filled with its background to the full
     * width, with spaces, so that it ends where the rows end. Tabs become
     * spaces; other control characters and escape codes that move the
     * cursor are dropped. An empty line is one empty row.
     *
     * @param int $indent Columns each row after the first starts in by, as under a bullet; inside the line's style, so a shaded line's indent is shaded too.
     * @return list<string>
     */
    public static function wrapAnsi(string $line, int $width, int $indent = 0): array
    {
        $width = max(1, $width);
        $indent = max(0, min($indent, $width - 1));
        if (strlen($line) <= $width && !preg_match('/[^\x20-\x7e]/', $line)) {
            return [$line]; // short plain text: the common case, measured for free
        }
        preg_match_all('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)?|\e.?|./us', $line, $tokens);
        $fill = str_contains($line, "\e[K");
        $rows = [];
        // The row being made, as cells: [text, columns, kind]; and the style open where it starts.
        $row = [];
        $start = '';
        $used = 0;
        $leading = false;
        foreach ($tokens[0] as $token) {
            if ($token[0] === "\e") {
                if (preg_match('/^\e\[[0-9;]*m$/', $token) === 1) {
                    $row[] = [$token, 0, self::STYLE];
                } elseif ($token === "\e[K" && $used < $width) {
                    $row[] = [str_repeat(' ', $width - $used), $width - $used, self::FILL];
                    $used = $width;
                }
                continue;
            }
            $count = 1;
            if ($token === "\t") {
                [$token, $count] = [' ', 8 - $used % 8];
            } elseif (ord($token) < 0x20 || $token === "\x7f") {
                continue;
            }
            $space = $token === ' ';
            $w = mb_strwidth($token, 'UTF-8');
            for ($i = 0; $i < $count; $i++) {
                if ($space && $leading) {
                    continue; // no row starts with the spaces it was broken at
                }
                $leading = false;
                if ($used + $w > $width && $used > 0) {
                    // Back to the row's last space, unless this is one, or the word fills the row.
                    $split = null;
                    for ($k = count($row) - 1; !$space && $k > 0; $k--) {
                        if ($row[$k][2] === self::SPACE) {
                            $split = $k;
                            break;
                        }
                    }
                    $left = $split === null ? $row : array_slice($row, 0, $split);
                    $right = $split === null ? [] : array_slice($row, $split + 1);
                    while ($left !== [] && $left[array_key_last($left)][2] === self::SPACE) {
                        array_pop($left); // a row broken between words does not end in spaces either
                    }
                    $rows[] = self::row($start, $left, $width, $fill);
                    $start = self::open($start, $left);
                    $row = $indent > 0 ? [[str_repeat(' ', $indent), $indent, self::INDENT], ...$right] : $right;
                    $used = array_sum(array_column($row, 1));
                    if ($space) {
                        $leading = true;
                        continue;
                    }
                }
                $row[] = [$token, $w, $space ? self::SPACE : self::TEXT];
                $used += $w;
            }
        }
        $rows[] = self::row($start, $row, $width, $fill);
        return $rows;
    }

    private const TEXT = 0;
    private const SPACE = 1;
    private const STYLE = 2;
    private const FILL = 3;
    /** The hanging indent a row after the first starts with: not a break between words. */
    private const INDENT = 4;

    /**
     * A row made of its cells, after the style open where it starts; filled
     * to the width when its line is, and reset when it ends inside a style.
     *
     * @param list<array{string, int, int}> $cells
     */
    private static function row(string $start, array $cells, int $width, bool $fill): string
    {
        $text = $start . implode('', array_column($cells, 0));
        $open = self::open($start, $cells);
        if ($open === '') {
            return $text;
        }
        $used = array_sum(array_column($cells, 1));
        return $text . ($fill && $used < $width ? str_repeat(' ', $width - $used) : '') . "\e[0m";
    }

    /**
     * The style open after some cells, from the one open before them.
     *
     * @param list<array{string, int, int}> $cells
     */
    private static function open(string $start, array $cells): string
    {
        foreach ($cells as [$text, , $kind]) {
            if ($kind === self::STYLE) {
                $start = $text === "\e[0m" || $text === "\e[m" ? '' : $start . $text;
            }
        }
        return $start;
    }

    /**
     * The part of plain text from one display column up to, not with, another.
     */
    public static function columns(string $text, int $from, int $to): string
    {
        $out = '';
        $at = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if ($at >= $to) {
                break;
            }
            if ($at >= $from) {
                $out .= $char;
            }
            $at += mb_strwidth($char, 'UTF-8');
        }
        return $out;
    }

    /**
     * A styled row with one column of it drawn over by a sprite: the
     * character there, or the space past the row's end, gives way, and the
     * style around it carries on after. The sprite is one column wide.
     */
    public static function place(string $row, int $column, string $sprite): string
    {
        preg_match_all('/\e\[[0-9;?]*[ -\/]*[@-~]|./us', $row, $tokens);
        $before = '';
        $after = '';
        $active = '';
        $resume = '';
        $at = 0;
        foreach ($tokens[0] as $token) {
            if ($token[0] === "\e") {
                if (preg_match('/^\e\[([0-9;]*)m$/', $token, $sgr) === 1) {
                    $active = $sgr[1] === '' || $sgr[1] === '0' ? '' : $active . $token;
                }
                if ($at <= $column) {
                    $before .= $token;
                } else {
                    $after .= $token;
                }
                continue;
            }
            $width = mb_strwidth($token, 'UTF-8');
            if ($at + $width <= $column) {
                $before .= $token;
            } elseif ($at > $column) {
                $after .= $token;
            } else {
                // The character under the sprite; a wide one leaves a space for its other half.
                $before .= $at < $column ? ' ' : '';
                $resume = $active . ($at + $width > $column + 1 ? ' ' : '');
            }
            $at += $width;
        }
        if ($at < $column) {
            $before .= str_repeat(' ', $column - $at);
        }
        return $before . "\e[0m" . $sprite . "\e[0m" . $resume . $after;
    }

    /**
     * A styled row with the columns from one up to another drawn in inverse,
     * as selected; the rest keeps its style.
     */
    public static function invert(string $row, int $from, int $to): string
    {
        preg_match_all('/\e\[[0-9;?]*[ -\/]*[@-~]|./us', $row, $tokens);
        $before = '';
        $inside = '';
        $after = '';
        $active = '';
        $at = 0;
        foreach ($tokens[0] as $token) {
            if ($token[0] === "\e") {
                if (preg_match('/^\e\[([0-9;]*)m$/', $token, $sgr) === 1) {
                    $active = $sgr[1] === '' || $sgr[1] === '0' ? '' : $active . $token;
                }
                if ($at < $from) {
                    $before .= $token;
                } elseif ($at >= $to) {
                    $after .= $token;
                }
                continue;
            }
            $width = mb_strwidth($token, 'UTF-8');
            if ($at < $from) {
                $before .= $token;
            } elseif ($at < $to) {
                $inside .= $token;
                if ($at + $width >= $to) {
                    $after = $active; // what follows takes up the style where the selection ends
                }
            } else {
                $after .= $token;
            }
            $at += $width;
        }
        if ($inside === '') {
            return $row;
        }
        return $before . "\e[0m\e[7m" . $inside . "\e[0m" . $after;
    }

    private static function paint(string $code, string $text): string
    {
        return self::color() && $text !== '' ? "\e[{$code}m{$text}\e[0m" : $text;
    }

    /**
     * Whether styles are drawn: not with NO_COLOR, nor when not on a terminal.
     */
    public static function color(): bool
    {
        return self::$color ??= getenv('NO_COLOR') === false && function_exists('stream_isatty') && stream_isatty(STDOUT);
    }
}
