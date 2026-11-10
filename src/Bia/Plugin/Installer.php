<?php

declare(strict_types=1);

namespace Bia\Plugin;

use Bia\Tool\ProcessRunner;
use Crab\Engine\Async\Cancellation;

/**
 * `bia plugin add|rm`: puts a plugin under Bia's home, from a folder or a
 * git repository, and takes one away. Nothing is installed any other way.
 */
final readonly class Installer
{
    public function __construct(
        private string $directory,
        private ProcessRunner $runner,
    ) {}

    /**
     * Installs the plugin at a folder's path or a git address, under the
     * name given or the source's own.
     *
     * @return string The name it was installed under.
     */
    public function add(string $source, ?string $name = null, ?Cancellation $cancellation = null): string
    {
        $local = !str_contains($source, '://') && is_dir($source);
        $name ??= strtolower((string) preg_replace('/\.git$/', '', basename(rtrim($local ? (string) realpath($source) : $source, '/'))));
        if (preg_match('/^[a-z0-9][\w.-]*$/i', $name) !== 1) {
            throw new \InvalidArgumentException("{$name} is not a name a plugin can have; give one: bia plugin add <source> <name>.");
        }
        $target = "{$this->directory}/{$name}";
        if (file_exists($target)) {
            throw new \RuntimeException("{$name} is installed already; bia plugin rm {$name} first.");
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Cannot create {$this->directory}.");
        }
        // Put together beside its place, hidden from a load, and moved in whole.
        $temporary = "{$this->directory}/.adding-" . bin2hex(random_bytes(4));
        try {
            if ($local) {
                self::copy((string) realpath($source), $temporary);
            } else {
                $result = $this->runner->run(['git', 'clone', '--quiet', '--depth', '1', '--', $source, $temporary], $this->directory, 120.0, cancellation: $cancellation, environment: ['GIT_TERMINAL_PROMPT' => '0']);
                if ($result->exitCode !== 0) {
                    throw new \RuntimeException("git could not clone {$source}: " . (trim($result->stderr) ?: 'exit ' . $result->exitCode));
                }
            }
            if (!Plugins::is($temporary)) {
                throw new \RuntimeException("{$source} is not a Bia plugin: it has no plugin.php, skills or mcp.json.");
            }
            if (!rename($temporary, $target)) {
                throw new \RuntimeException("Cannot write {$target}.");
            }
        } finally {
            self::delete($temporary);
        }
        return $name;
    }

    public function remove(string $name): void
    {
        $target = "{$this->directory}/{$name}";
        if (preg_match('/^[a-z0-9][\w.-]*$/i', $name) !== 1 || (!is_dir($target) && !is_link($target))) {
            throw new \RuntimeException("No plugin is named {$name}; bia plugin list shows them.");
        }
        self::delete($target);
    }

    private static function copy(string $from, string $to): void
    {
        if (!mkdir($to, 0700) && !is_dir($to)) {
            throw new \RuntimeException("Cannot create {$to}.");
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git') {
                continue;
            }
            $path = "{$from}/{$entry}";
            if (is_link($path)) {
                continue; // what it points at is not the plugin's
            }
            if (is_dir($path)) {
                self::copy($path, "{$to}/{$entry}");
            } elseif (!copy($path, "{$to}/{$entry}")) {
                throw new \RuntimeException("Cannot copy {$path}.");
            }
        }
    }

    /**
     * Deletes a folder and what is in it; a link is removed, never followed.
     */
    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete("{$path}/{$entry}");
            }
        }
        @rmdir($path);
    }
}
