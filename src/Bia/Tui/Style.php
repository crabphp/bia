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
     * A line of styled text split into rows of at most the given columns.
     * Colour carries across a split: a row that ends inside a style resets
     * it, and the next row starts it again. Tabs become spaces, and other
     * control characters and non-colour escape codes are dropped, as they
     * would move the cursor. An empty line is one empty row.
     *
     * @return list<string>
     */
    public static function wrapAnsi(string $line, int $width): array
    {
        $width = max(1, $width);
        if (!preg_match('/[^\x20-\x7e]/', $line)) {
            return strlen($line) <= $width ? [$line] : str_split($line, $width);
        }
        preg_match_all('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)?|\e.?|./us', $line, $tokens);
        $rows = [];
        $row = '';
        $used = 0;
        $active = '';
        // A line that erases to its end fills its rows with its background: each row it wraps onto too.
        $fill = str_contains($line, "\e[K") ? "\e[K" : '';
        foreach ($tokens[0] as $token) {
            if ($token[0] === "\e") {
                if (preg_match('/^\e\[([0-9;]*)m$/', $token, $sgr) === 1) {
                    $active = $sgr[1] === '' || $sgr[1] === '0' ? '' : $active . $token;
                    $row .= $token;
                } elseif ($token === "\e[K") {
                    $row .= $token;
                }
                continue;
            }
            if ($token === "\t") {
                $token = ' ';
                $count = 8 - $used % 8;
            } elseif (ord($token) < 0x20 || $token === "\x7f") {
                continue;
            } else {
                $count = 1;
            }
            $w = mb_strwidth($token, 'UTF-8');
            for ($i = 0; $i < $count; $i++) {
                if ($used + $w > $width && $used > 0) {
                    $rows[] = $active !== '' ? $row . $fill . "\e[0m" : $row;
                    $row = $active;
                    $used = 0;
                    if ($count > 1) {
                        break; // a tab ends at the row's end
                    }
                }
                $row .= $token;
                $used += $w;
            }
        }
        $rows[] = $row;
        return $rows;
    }

    private static function paint(string $code, string $text): string
    {
        return self::color() && $text !== '' ? "\e[{$code}m{$text}\e[0m" : $text;
    }

    private static function color(): bool
    {
        return self::$color ??= getenv('NO_COLOR') === false && function_exists('stream_isatty') && stream_isatty(STDOUT);
    }
}
