<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * One key press, or one paste.
 *
 * A printable character has the name "char" and the character as its text;
 * a paste has the name "paste" and everything pasted as its text. Any other
 * key is named: "enter", "up", "ctrl-c", "alt-enter", "ctrl-left"...
 */
final readonly class Key
{
    public function __construct(
        public string $name,
        public string $text = '',
    ) {}

    public function is(string ...$names): bool
    {
        return in_array($this->name, $names, true);
    }
}
