<?php

declare(strict_types=1);

namespace Bia\Share;

/** Display names are unverified labels, never identifiers or markup. */
final class Nickname
{
    public static function valid(mixed $name): ?string
    {
        if (!is_string($name) || strlen($name) > 128 || !mb_check_encoding($name, 'UTF-8')) { return null; }
        $name = trim($name, ' ');
        return preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{M}\\p{N} ._'’-]{0,31}$/uD", $name) ? $name : null;
    }
}
