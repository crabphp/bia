<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Turns the bytes a terminal sends in raw mode into keys.
 */
final class Keys
{
    private const PASTE_START = "\e[200~";
    private const PASTE_END = "\e[201~";

    private const SEQUENCES = [
        "\e[A" => 'up', "\e[B" => 'down', "\e[C" => 'right', "\e[D" => 'left',
        "\eOA" => 'up', "\eOB" => 'down', "\eOC" => 'right', "\eOD" => 'left',
        "\e[H" => 'home', "\e[F" => 'end', "\eOH" => 'home', "\eOF" => 'end',
        "\e[1~" => 'home', "\e[4~" => 'end', "\e[7~" => 'home', "\e[8~" => 'end',
        "\e[3~" => 'delete', "\e[5~" => 'pageup', "\e[6~" => 'pagedown', "\e[Z" => 'shift-tab',
        "\e[1;5C" => 'ctrl-right', "\e[1;5D" => 'ctrl-left', "\e[1;3C" => 'ctrl-right', "\e[1;3D" => 'ctrl-left',
        "\e[1;5A" => 'up', "\e[1;5B" => 'down',
        "\e[I" => 'focus-in', "\e[O" => 'focus-out', // the terminal's window gained or lost focus
        "\e[13;2u" => 'alt-enter', "\e[27;2;13~" => 'alt-enter', // shift-enter, where the terminal tells it apart
    ];

    private const CONTROLS = [
        "\r" => 'enter', "\n" => 'ctrl-j', "\t" => 'tab', "\x7f" => 'backspace', "\x08" => 'backspace',
        "\x01" => 'ctrl-a', "\x02" => 'left', "\x03" => 'ctrl-c', "\x04" => 'ctrl-d', "\x05" => 'ctrl-e',
        "\x06" => 'right', "\x0b" => 'ctrl-k', "\x0c" => 'ctrl-l', "\x0e" => 'down', "\x10" => 'up',
        "\x15" => 'ctrl-u', "\x17" => 'ctrl-w', "\x1a" => 'ctrl-z', "\x00" => 'ctrl-space',
    ];

    /**
     * Takes the first key off the front of the buffer.
     *
     * @return Key|null Null when the buffer is empty or holds only the start
     *                  of a key or a paste, to wait for the rest.
     */
    public static function take(string &$buffer): ?Key
    {
        if ($buffer === '') {
            return null;
        }
        if (str_starts_with($buffer, self::PASTE_START)) {
            $end = strpos($buffer, self::PASTE_END);
            if ($end === false) {
                return null;
            }
            $text = substr($buffer, strlen(self::PASTE_START), $end - strlen(self::PASTE_START));
            $buffer = (string) substr($buffer, $end + strlen(self::PASTE_END));
            return new Key('paste', str_replace(["\r\n", "\r"], "\n", $text));
        }
        $first = $buffer[0];
        if ($first === "\e") {
            $mouse = self::mouse($buffer);
            if ($mouse !== null) {
                return $mouse->is('pending') ? null : $mouse;
            }
            return self::escape($buffer);
        }
        if (isset(self::CONTROLS[$first])) {
            $buffer = (string) substr($buffer, 1);
            return new Key(self::CONTROLS[$first]);
        }
        if (ord($first) < 0x20) {
            $buffer = (string) substr($buffer, 1);
            return new Key('ctrl-' . chr(ord($first) + 96));
        }
        $length = match (true) {
            ord($first) >= 0xf0 => 4,
            ord($first) >= 0xe0 => 3,
            ord($first) >= 0xc0 => 2,
            default => 1,
        };
        if (strlen($buffer) < $length) {
            return null;
        }
        $char = substr($buffer, 0, $length);
        $buffer = (string) substr($buffer, $length);
        return new Key('char', mb_check_encoding($char, 'UTF-8') ? $char : '?');
    }

    /**
     * A mouse report, as SGR (`ESC [ < b ; x ; y M`, `m` on release) or the
     * older X10 form (`ESC [ M` and three bytes): the wheel as "wheel-up" and
     * "wheel-down"; the left button as "mouse-down", "mouse-drag" and
     * "mouse-up", with "column,row" (from 1) as the text; any other as
     * "mouse". A report not yet whole is "pending", to wait for the rest;
     * null when the buffer holds none.
     */
    private static function mouse(string &$buffer): ?Key
    {
        if (str_starts_with($buffer, "\e[<")) {
            if (preg_match('/^\e\[<(\d+);(\d+);(\d+)([Mm])/', $buffer, $match) !== 1) {
                return preg_match('/^\e\[<[\d;]*$/', $buffer) === 1 ? new Key('pending') : null;
            }
            $buffer = (string) substr($buffer, strlen($match[0]));
            return self::button((int) $match[1], (int) $match[2], (int) $match[3], $match[4] === 'm');
        }
        if (str_starts_with($buffer, "\e[M")) {
            if (strlen($buffer) < 6) {
                return new Key('pending');
            }
            $button = ord($buffer[3]) - 32;
            [$column, $row] = [ord($buffer[4]) - 32, ord($buffer[5]) - 32];
            $buffer = (string) substr($buffer, 6);
            // X10 says a release as button 3, without which button.
            return self::button($button, $column, $row, ($button & 3) === 3 && ($button & 64) === 0);
        }
        return null;
    }

    private static function button(int $button, int $column, int $row, bool $release): Key
    {
        // Shift, alt and ctrl add 4, 8 and 16; motion adds 32; the wheel is 64 and 65.
        $plain = $button & ~(4 | 8 | 16);
        if ($plain === 64 || $plain === 65) {
            return new Key($plain === 64 ? 'wheel-up' : 'wheel-down');
        }
        $at = "{$column},{$row}";
        return match (true) {
            $release && ($plain & 3) !== 1 && ($plain & 3) !== 2 => new Key('mouse-up', $at),
            ($plain & ~32) === 0 && ($plain & 32) !== 0 => new Key('mouse-drag', $at),
            $plain === 0 => new Key('mouse-down', $at),
            default => new Key('mouse'),
        };
    }

    private static function escape(string &$buffer): Key
    {
        if ($buffer === "\e") {
            $buffer = '';
            return new Key('escape');
        }
        foreach (self::SEQUENCES as $sequence => $name) {
            if (str_starts_with($buffer, $sequence)) {
                $buffer = (string) substr($buffer, strlen($sequence));
                return new Key($name);
            }
        }
        $second = $buffer[1];
        if ($second === '[' || $second === 'O') {
            // An unknown sequence: skip it whole, up to its final byte.
            if (preg_match('/^\e[\[O][0-9;?]*[\x40-\x7e]/', $buffer, $match) === 1) {
                $buffer = (string) substr($buffer, strlen($match[0]));
                return new Key('unknown');
            }
            $buffer = (string) substr($buffer, 2);
            return new Key('unknown');
        }
        if (!in_array($second, ["\r", "\n", "\x7f", "\x08", "\e"], true) && (ord($second) < 0x20 || ord($second) > 0x7e)) {
            // Esc, then a key of its own (Ctrl-D, a letter beyond ASCII): not an Alt chord.
            $buffer = (string) substr($buffer, 1);
            return new Key('escape');
        }
        $buffer = (string) substr($buffer, 2);
        return match ($second) {
            "\r", "\n" => new Key('alt-enter'),
            "\x7f", "\x08" => new Key('ctrl-w'),
            'b' => new Key('ctrl-left'),
            'f' => new Key('ctrl-right'),
            "\e" => new Key('escape'),
            default => new Key('alt-' . $second),
        };
    }
}
