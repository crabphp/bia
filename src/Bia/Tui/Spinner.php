<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * The working indicator: a turning frame, what is happening and for how long.
 */
final class Spinner
{
    private const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

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
        $frame = self::FRAMES[(int) ($elapsed * 12.5) % count(self::FRAMES)];
        $time = $elapsed < 60 ? (int) $elapsed . 's' : intdiv((int) $elapsed, 60) . 'm ' . ((int) $elapsed % 60) . 's';
        $tail = $time . ($hint !== '' ? " · {$hint}" : '');
        $label = Style::truncate($this->label . '…', max(1, $width - 3 - mb_strwidth($tail, 'UTF-8')));
        return Style::accent($frame) . ' ' . $label . ' ' . Style::dim($tail);
    }
}
