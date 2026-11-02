<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * The working indicator: a small beer, what is happening and for how long.
 *
 * The beer is three half blocks, each white foam over amber beer, and a
 * grey handle; a bubble steps through it. Without colour the bubble is a
 * lower half block, so it still moves.
 */
final class Spinner
{
    private const CELLS = 3;

    /** Steps a second: the bubble through three cells, then a rest. */
    private const RATE = 4.0;

    private const FOAM = 255;
    private const BEER = 214;
    private const BUBBLE = 222;
    private const GLASS = 245;

    /**
     * What "Thinking" is shown as, the way Claude Code has its verbs: Bia's
     * beer, and a bit of cooking.
     */
    public const WORDS = [
        'Drinking', 'Brewing', 'Fermenting', 'Frothing', 'Pouring', 'Hopping', 'Malting',
        'Clinking', 'Mulling', 'Distilling', 'Bubbling', 'Foaming', 'Chilling', 'Cooking',
    ];

    /** Seconds a word stays before the next. */
    private const WORD_SECONDS = 10.0;

    private float $since;
    private int $word;

    public function __construct(
        private string $label = 'Working',
        ?float $since = null,
        ?int $word = null,
    ) {
        $this->since = $since ?? microtime(true);
        $this->word = $word ?? random_int(0, count(self::WORDS) - 1);
    }

    public function label(string $label): void
    {
        $this->label = $label;
    }

    public function render(int $width, ?float $now = null, string $hint = ''): string
    {
        $now ??= microtime(true);
        $elapsed = max(0, $now - $this->since);
        $time = $elapsed < 60 ? (int) $elapsed . 's' : intdiv((int) $elapsed, 60) . 'm ' . ((int) $elapsed % 60) . 's';
        $tail = $time . ($hint !== '' ? " · {$hint}" : '');
        $label = $this->label === 'Thinking'
            ? self::WORDS[($this->word + (int) ($elapsed / self::WORD_SECONDS)) % count(self::WORDS)]
            : $this->label;
        // The beer is four columns, then a space before the label and one after it.
        $label = Style::truncate($label . '…', max(1, $width - self::CELLS - 3 - mb_strwidth($tail, 'UTF-8')));
        return self::beer((int) ($elapsed * self::RATE) % (self::CELLS + 1)) . ' ' . $label . ' ' . Style::dim($tail);
    }

    /**
     * @param int $bubble The cell the bubble is in; past the last, none.
     */
    private static function beer(int $bubble): string
    {
        if (!Style::color()) {
            $cells = '';
            for ($i = 0; $i < self::CELLS; $i++) {
                $cells .= $i === $bubble ? '▄' : '▀';
            }
            return $cells . ')';
        }
        $cells = '';
        for ($i = 0; $i < self::CELLS; $i++) {
            $cells .= "\e[38;5;" . self::FOAM . ';48;5;' . ($i === $bubble ? self::BUBBLE : self::BEER) . 'm▀';
        }
        return $cells . "\e[0m\e[38;5;" . self::GLASS . "m)\e[0m";
    }
}
