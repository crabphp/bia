<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Power mode, drawn over the output's rows a frame at a time: each new
 * character drops from rows above, faster as it falls, a dim trail behind
 * it, and lands with a little shake and a flash before it settles into the
 * text. Only the character that lands shakes; the text around never moves,
 * so it stays readable. It changes nothing kept: given the rows about to be
 * drawn, it gives them back with this frame's effects on them, and while any
 * are moving there is another frame to draw.
 */
final class Effects
{
    /** Seconds a character takes to fall, and the rows it falls from. */
    private const FALL = 0.22;
    private const HEIGHT = 6;

    /** Seconds a character glows where it landed, the first of them shaking. */
    private const FLASH = 0.12;
    private const SHAKE = 0.06;

    /** Where a landed character is drawn, a column each way, each frame of its shake. */
    private const JIGGLE = [1, -1, 1];

    /** The most characters falling or glowing at once. */
    private const MOST = 64;

    /** @var list<array{char: string, born: float}> The newest last. */
    private array $glyphs = [];

    /**
     * Characters that just appeared at the end of the streaming line.
     */
    public function burst(string $chars, float $now): void
    {
        foreach (mb_str_split($chars, 1, 'UTF-8') as $char) {
            if ($char !== "\n") {
                $this->glyphs[] = ['char' => $char, 'born' => $now];
            }
        }
        $this->glyphs = array_slice($this->glyphs, -self::MOST);
    }

    /**
     * Whether anything is still falling or glowing, so another frame is wanted.
     */
    public function active(float $now): bool
    {
        $this->forget($now);
        return $this->glyphs !== [];
    }

    /**
     * @param list<string> $rows The output's rows about to be drawn.
     * @param int|null $row The row the text streams into, or null when it is not on the screen.
     * @param int $end The column after that row's last character.
     * @return list<string>
     */
    public function apply(array $rows, ?int $row, int $end, float $now): array
    {
        $this->forget($now);
        if ($row === null || $row >= count($rows)) {
            return $rows;
        }
        $count = count($this->glyphs);
        $shaking = [];
        foreach ($this->glyphs as $index => $glyph) {
            $column = $end - ($count - $index);
            $char = $glyph['char'];
            if ($column < 0 || $char === ' ' || mb_strwidth($char, 'UTF-8') !== 1) {
                continue;
            }
            $age = $now - $glyph['born'];
            if ($age >= self::FALL) {
                // Landed: it shakes a column each way, then glows where it hit, then is the text as it is.
                $glow = Style::color() ? "\e[1;38;5;214m{$char}" : $char;
                $since = $age - self::FALL;
                if ($since < self::SHAKE) {
                    $offset = self::JIGGLE[min(count(self::JIGGLE) - 1, (int) ($since / self::SHAKE * count(self::JIGGLE)))];
                    $shaking[] = [$column, $column + $offset, $glow];
                    continue;
                }
                $rows[$row] = Style::place($rows[$row], $column, $glow);
                continue;
            }
            // Falling, faster as it goes, as under gravity: its place is empty until it lands.
            $progress = $age / self::FALL;
            $above = $row - (int) ceil(self::HEIGHT * (1 - $progress * $progress));
            $rows[$row] = Style::place($rows[$row], $column, ' ');
            if ($above >= 0) {
                $rows[$above] = Style::place($rows[$above], $column, Style::bold($char));
            }
            if ($above - 1 >= 0 && $above < $row) {
                $rows[$above - 1] = Style::place($rows[$above - 1], $column, Style::dim($char)); // its trail
            }
        }
        // What landed together shakes together: all their places emptied first, then each drawn aside.
        foreach ($shaking as [$column]) {
            $rows[$row] = Style::place($rows[$row], $column, ' ');
        }
        foreach ($shaking as [, $at, $glow]) {
            $rows[$row] = Style::place($rows[$row], max(0, $at), $glow);
        }
        return $rows;
    }

    private function forget(float $now): void
    {
        $this->glyphs = array_values(array_filter($this->glyphs, static fn (array $glyph): bool => $now - $glyph['born'] < self::FALL + self::FLASH));
    }
}
