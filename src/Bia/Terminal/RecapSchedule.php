<?php

declare(strict_types=1);

namespace Bia\Terminal;

/**
 * When a recap is due while the user is away: idle seconds after they went
 * and the last turn ended, once three turns are done, and two more since
 * the last recap. Away is the terminal unfocused, or, until the terminal
 * says it has focus to tell, no key pressed.
 */
final class RecapSchedule
{
    public const MIN_TURNS = 3;
    public const TURNS_BETWEEN = 2;

    /** Turns asked for in the session. */
    public int $turns = 0;
    /** How many there were at the last recap. */
    public ?int $recappedAt = null;
    private bool $focusKnown = false;
    private ?float $unfocusedSince = null;
    /** The last key pressed, or turn ended. */
    private float $active;

    public function __construct(float $now)
    {
        $this->active = $now;
    }

    /**
     * Starts over for a session: its turns, and whether its last recap is still current.
     */
    public function seed(int $turns, bool $recapped): void
    {
        $this->turns = $turns;
        $this->recappedAt = $recapped ? $turns : null;
    }

    public function focus(bool $in, float $now): void
    {
        $this->focusKnown = true;
        $this->unfocusedSince = $in ? null : ($this->unfocusedSince ?? $now);
    }

    /**
     * A key pressed, or a turn ended.
     */
    public function active(float $now): void
    {
        $this->active = $now;
    }

    public function turnDone(): void
    {
        $this->turns++;
    }

    public function recapped(int $turns): void
    {
        $this->recappedAt = $turns;
    }

    /**
     * Whether the last recap is of the session as it is now.
     */
    public function current(): bool
    {
        return $this->recappedAt === $this->turns;
    }

    /**
     * When the next recap is due, or null while none is.
     */
    public function due(float $idle): ?float
    {
        if ($this->turns < self::MIN_TURNS || ($this->recappedAt !== null && $this->turns - $this->recappedAt < self::TURNS_BETWEEN)) {
            return null;
        }
        if ($this->focusKnown && $this->unfocusedSince === null) {
            return null;
        }
        return max($this->unfocusedSince ?? 0.0, $this->active) + $idle;
    }
}
