<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * The working indicator: a beer and two clinking, in turn, what is
 * happening and for how long.
 */
final class Spinner
{
    private const FRAMES = ['🍺', '🍻'];

    /** Frames a second: a slow clink, calm beside the counting seconds. */
    private const RATE = 2.0;

    private float $since;

    public function __construct(
        private string $label = 'Working',
        ?float $since = null,
    ) {
        $this->since = $since ?? microtime(true);
    }

    public function label(string $label): void
    {
        $this->label = $label;
    }

    public function render(int $width, ?float $now = null, string $hint = ''): string
    {
        $now ??= microtime(true);
        $elapsed = max(0, $now - $this->since);
        $frame = self::FRAMES[(int) ($elapsed * self::RATE) % count(self::FRAMES)];
        $time = $elapsed < 60 ? (int) $elapsed . 's' : intdiv((int) $elapsed, 60) . 'm ' . ((int) $elapsed % 60) . 's';
        $tail = $time . ($hint !== '' ? " · {$hint}" : '');
        // The frame is two columns wide, then a space before the label and one after it.
        $room = $width - mb_strwidth($frame, 'UTF-8') - 2 - mb_strwidth($tail, 'UTF-8');
        $label = Style::truncate($this->label . '…', max(1, $room));
        return $frame . ' ' . $label . ' ' . Style::dim($tail);
    }
}
