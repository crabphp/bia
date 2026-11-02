<?php

declare(strict_types=1);

namespace Bia\Mcp;

/**
 * Which projects' `.mcp.json` the user has allowed to start servers. The
 * file comes with the project, from whoever wrote the repository, and
 * starts programs: it runs only once the user has said yes, and only as it
 * was when they did; a changed file is asked about again.
 */
final readonly class Trust
{
    public function __construct(private string $path) {}

    public function trusted(string $file): bool
    {
        $known = $this->load();
        return is_file($file) && ($known[self::key($file)] ?? null) === hash_file('sha256', $file);
    }

    public function trust(string $file): void
    {
        $known = $this->load();
        $known[self::key($file)] = (string) hash_file('sha256', $file);
        if (!is_dir(dirname($this->path))) {
            @mkdir(dirname($this->path), 0700, true);
        }
        file_put_contents($this->path, json_encode($known, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($this->path, 0600);
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        $data = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    private static function key(string $file): string
    {
        return realpath($file) ?: $file;
    }
}
