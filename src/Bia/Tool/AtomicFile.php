<?php

declare(strict_types=1);

namespace Bia\Tool;

/**
 * Replaces a file in one step, so a reader never sees it half written, and
 * keeps an existing file's mode: an executable stays executable.
 */
final class AtomicFile
{
    public static function write(string $path, string $content): void
    {
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException("Could not create the directory {$parent}.");
        }
        $temporary = tempnam($parent, '.bia-write-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new \RuntimeException("Could not write {$path}.");
        }
        $mode = is_file($path) ? fileperms($path) & 0o7777 : 0o666 & ~umask();
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException("Could not replace {$path}.");
        }
    }
}
