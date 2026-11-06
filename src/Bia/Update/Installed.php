<?php

declare(strict_types=1);

namespace Bia\Update;

/**
 * Where this Bia runs from, and what replaces it: a release's binary, its
 * bia.phar run by a PHP of the user's own, or a checkout, which `git pull`
 * updates instead.
 */
final class Installed
{
    /**
     * The file this Bia runs from when it is a release's, the binary or
     * bia.phar, with links followed; null when it runs from source.
     */
    public static function file(): ?string
    {
        $file = \Phar::running(false);
        return $file === '' ? null : $file;
    }

    /** Whether it is the binary, with PHP inside, rather than bia.phar. */
    public static function binary(): bool
    {
        return PHP_SAPI === 'micro';
    }

    /**
     * The release file that replaces it: bia.phar, or the binary built for
     * this system; null for a system no binary is built for.
     */
    public static function asset(?bool $binary = null, string $os = PHP_OS_FAMILY, ?string $machine = null): ?string
    {
        if (!($binary ?? self::binary())) {
            return 'bia.phar';
        }
        $os = match ($os) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            default => null,
        };
        $machine = match (strtolower($machine ?? php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => null,
        };
        return $os === null || $machine === null ? null : "bia-{$os}-{$machine}.tar.gz";
    }

    /**
     * Stops a Bia whose file was replaced while it ran, as `bia update`
     * does, with a message to start it again. It reads its code from that
     * file as it first needs it, by the file's path, so what it would read
     * from the new one would be code from the wrong places.
     */
    public static function guard(): void
    {
        $file = self::file();
        $was = $file === null ? false : @stat($file);
        if ($was === false) {
            return;
        }
        $was = [$was['ino'], $was['size'], $was['mtime']];
        spl_autoload_register(static function (string $class) use ($file, $was): void {
            clearstatcache(true, $file);
            $now = @stat($file);
            if ($now === false || [$now['ino'], $now['size'], $now['mtime']] !== $was) {
                throw new \RuntimeException("Bia was updated while it ran, so it cannot go on: start it again ({$class} could not be loaded).");
            }
        }, true, true);
    }
}
