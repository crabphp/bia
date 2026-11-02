<?php

declare(strict_types=1);

namespace Bia\Mcp;

/**
 * What OAuth leaves to keep, per server: the client Bia registered as and
 * the tokens it was given. One file, readable by the user alone; a server
 * is known by its name and its URL, so tokens never go to another one.
 */
final readonly class AuthStore
{
    public function __construct(private string $path) {}

    public static function key(Server $server): string
    {
        return $server->name . ' ' . $server->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $entry = $this->load()[$key] ?? [];
        return is_array($entry) ? $entry : [];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function put(string $key, array $entry): void
    {
        $all = $this->load();
        $all[$key] = $entry;
        $this->save($all);
    }

    public function forget(string $key): void
    {
        $all = $this->load();
        unset($all[$key]);
        $this->save($all);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $data = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private function save(array $all): void
    {
        if (!is_dir(dirname($this->path))) {
            @mkdir(dirname($this->path), 0700, true);
        }
        // Made unreadable to others before anything secret is in it.
        if (!is_file($this->path)) {
            touch($this->path);
        }
        @chmod($this->path, 0600);
        file_put_contents($this->path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
