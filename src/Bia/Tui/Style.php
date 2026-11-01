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

    private static function paint(string $code, string $text): string
    {
        self::$color ??= getenv('NO_COLOR') === false && function_exists('stream_isatty') && stream_isatty(STDOUT);
        return self::$color && $text !== '' ? "\e[{$code}m{$text}\e[0m" : $text;
    }
}
