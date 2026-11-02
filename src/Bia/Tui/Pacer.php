<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Evens out text that arrives in bursts, as a model's tokens do, into a
 * steady flow of characters: given the time since it was last asked, it
 * gives the characters due by now. It speeds up as text waits, so what
 * arrived is shown within about a quarter of a second, however fast it came.
 */
final class Pacer
{
    /** Characters a second when little is waiting. */
    private const RATE = 120.0;

    /** Seconds the waiting text is caught up in, at most. */
    private const CATCH_UP = 0.25;

    private string $waiting = '';
    private float $owed = 0.0;

    public function push(string $text): void
    {
        $this->waiting .= $text;
    }

    public function empty(): bool
    {
        return $this->waiting === '';
    }

    /**
     * The characters due after this many seconds, whole characters only.
     */
    public function take(float $seconds): string
    {
        if ($this->waiting === '') {
            $this->owed = 0.0;
            return '';
        }
        $left = mb_strlen($this->waiting, 'UTF-8');
        $this->owed += max(self::RATE, $left / self::CATCH_UP) * max(0.0, $seconds);
        $count = min($left, (int) floor($this->owed));
        if ($count === 0) {
            return '';
        }
        $this->owed -= $count;
        $due = mb_substr($this->waiting, 0, $count, 'UTF-8');
        $this->waiting = (string) substr($this->waiting, strlen($due));
        return $due;
    }

    /**
     * Everything waiting, at once.
     */
    public function drain(): string
    {
        $all = $this->waiting;
        $this->waiting = '';
        $this->owed = 0.0;
        return $all;
    }
}
