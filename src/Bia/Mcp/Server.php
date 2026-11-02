<?php

declare(strict_types=1);

namespace Bia\Mcp;

/**
 * An MCP server as configured: one started here and talked to over its
 * standard input and output, or one reached over HTTP.
 *
 * The configuration is the `mcpServers` object other agents read too, in
 * the user's `mcp.json` beside their config and a project's `.mcp.json`.
 * `${VAR}` and `${VAR:-default}` in a command, an argument, a variable, a
 * URL or a header are taken from the environment, so a key need not be
 * written in the file.
 */
final readonly class Server
{
    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @param array<string, string> $headers
     * @param bool $project Whether it came with the project, and is so to be trusted before it is started.
     * @param array{clientId?: string, clientSecret?: string, scopes?: list<string>, callbackPort?: int} $oauth For logging in to a server
     *        whose authorization server does not let Bia register itself, or that wants scopes or a fixed callback port.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $command = '',
        public array $args = [],
        public array $env = [],
        public string $url = '',
        public array $headers = [],
        public bool $project = false,
        public array $oauth = [],
    ) {}

    /**
     * The servers in an `mcp.json` file, and what was wrong with any that could not be read.
     *
     * @param array<string, string> $environment Where `${VAR}` is looked up.
     * @return array{servers: list<self>, errors: list<string>}
     */
    public static function load(string $path, bool $project, array $environment): array
    {
        if (!is_file($path)) {
            return ['servers' => [], 'errors' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return ['servers' => [], 'errors' => [basename($path) . ' is not valid JSON']];
        }
        $servers = [];
        $errors = [];
        foreach ((array) ($data['mcpServers'] ?? []) as $name => $entry) {
            $name = (string) $name;
            if (!is_array($entry) || preg_match('/^[\w.-]{1,40}$/', $name) !== 1) {
                $errors[] = "{$name}: not a server entry";
                continue;
            }
            if (($entry['disabled'] ?? false) === true) {
                continue;
            }
            $expand = static fn (mixed $value): string => self::expand((string) $value, $environment);
            $url = $expand($entry['url'] ?? '');
            $type = (string) ($entry['type'] ?? ($url !== '' ? 'http' : 'stdio'));
            if ($type === 'streamable-http') {
                $type = 'http';
            }
            if ($type === 'stdio' && trim((string) ($entry['command'] ?? '')) !== '') {
                $servers[] = new self(
                    $name,
                    'stdio',
                    $expand($entry['command']),
                    array_values(array_map($expand, (array) ($entry['args'] ?? []))),
                    array_map($expand, array_filter((array) ($entry['env'] ?? []), 'is_scalar')),
                    project: $project,
                );
            } elseif ($type === 'http' && preg_match('#^https?://#', $url) === 1) {
                $oauth = is_array($entry['oauth'] ?? null) ? $entry['oauth'] : [];
                $servers[] = new self(
                    $name,
                    'http',
                    url: $url,
                    headers: array_map($expand, array_filter((array) ($entry['headers'] ?? []), 'is_scalar')),
                    project: $project,
                    oauth: array_filter([
                        'clientId' => isset($oauth['clientId']) ? $expand($oauth['clientId']) : null,
                        'clientSecret' => isset($oauth['clientSecret']) ? $expand($oauth['clientSecret']) : null,
                        'scopes' => isset($oauth['scopes']) ? array_values(array_map('strval', (array) $oauth['scopes'])) : null,
                        'callbackPort' => isset($oauth['callbackPort']) ? (int) $oauth['callbackPort'] : null,
                    ], static fn ($value): bool => $value !== null && $value !== ''),
                );
            } elseif ($type === 'sse') {
                $errors[] = "{$name}: the old SSE transport is not supported; use the server's HTTP endpoint";
            } else {
                $errors[] = "{$name}: needs a command, or an http(s) url";
            }
        }
        return ['servers' => $servers, 'errors' => $errors];
    }

    /**
     * @param array<string, string> $environment
     */
    private static function expand(string $value, array $environment): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/',
            static fn (array $m): string => ($environment[$m[1]] ?? '') !== '' ? $environment[$m[1]] : ($m[2] ?? ''),
            $value,
        );
    }
}
