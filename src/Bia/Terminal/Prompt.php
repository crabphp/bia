<?php

declare(strict_types=1);

namespace Bia\Terminal;

/**
 * Reads one line without requiring a terminal UI dependency.
 */
final class Prompt
{
    public function __construct(private readonly bool $interactive = true) {}

    public function ask(string $label): ?string
    {
        if (!$this->interactive) {
            return null;
        }
        if (function_exists('readline')) {
            $value = readline($label);
            if ($value === false) {
                return null;
            }
            if ($value !== '') {
                readline_add_history($value);
            }
            return $value;
        }
        fwrite(STDOUT, $label);
        $value = fgets(STDIN);
        return $value === false ? null : rtrim($value, "\r\n");
    }
}
