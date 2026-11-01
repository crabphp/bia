<?php

declare(strict_types=1);

namespace Bia\Config;

/**
 * Loads Bia's small, layered configuration without booting a Crab app.
 *
 * The user's own `~/.config/bia/config.php` is trusted PHP. A project's
 * `.bia/config.json` comes with whatever repository Bia is run in, so it is
 * read as data and may only set what cannot send a key or a prompt
 * somewhere else: the model name, the system prompt, permissions and tool
 * limits.
 */
final readonly class Config
{
    /**
     * @param array<string, mixed> $values
     * @param list<string> $sources
     */
    private function __construct(
        private array $values,
        private array $sources,
    ) {}

    /**
     * Settings a project's `.bia/config.json` may set, as dotted prefixes.
     */
    public const PROJECT_KEYS = ['model.name', 'model.context_window', 'prompt.system', 'permissions', 'tools', 'compaction'];

    public static function load(?string $projectRoot = null, array $overrides = []): self
    {
        $projectRoot ??= self::findProjectRoot(getcwd());
        $defaults = self::defaults();
        $sources = [];
        $values = $defaults;

        $global = self::globalPath();
        if (is_file($global)) {
            $values = self::merge($values, self::file($global));
            $sources[] = $global;
        }
        if ($projectRoot !== null) {
            $project = $projectRoot . '/.bia/config.json';
            if (is_file($project)) {
                $values = self::merge($values, self::project($project));
                $sources[] = $project;
            }
        }

        $provider = (string) self::read($values, 'model.provider', 'openai-compatible');
        $environment = [];
        if (($value = getenv('BIA_PROVIDER')) !== false && $value !== '') {
            $environment['model']['provider'] = $value;
            $provider = $value;
        }
        foreach (['BIA_BASE_URL' => 'base_url', 'BIA_MODEL' => 'name', 'BIA_API_KEY' => 'api_key'] as $name => $key) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $environment['model'][$key] = $value;
            }
        }
        $providerKey = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $provider)) . '_API_KEY';
        $providerKeys = [$providerKey];
        if ($provider === 'openai-compatible' || $provider === 'openai') {
            $providerKeys[] = 'OPENAI_API_KEY';
        }
        if ($provider === 'tdaproxy') {
            $providerKeys[] = 'TDAPROXY_API_KEY';
            $providerKeys[] = 'TDA_PROXY_API_KEY';
        }
        foreach ($providerKeys as $providerKey) {
            if (($value = getenv($providerKey)) !== false && $value !== '') {
                $environment['model']['api_key'] = $value;
                break;
            }
        }
        if ($environment !== []) {
            $values = self::merge($values, $environment);
            $sources[] = 'environment';
        }
        if ($overrides !== []) {
            $values = self::merge($values, $overrides);
            $sources[] = 'command line';
        }

        self::check($values);
        return new self($values, $sources);
    }

    /**
     * Every setting and its built-in value.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $home = self::home();
        $stateHome = self::directory('XDG_STATE_HOME', $home . '/.local/state');
        $cacheHome = self::directory('XDG_CACHE_HOME', $home . '/.cache');
        return [
            'model' => [
                'provider' => 'openai-compatible',
                'base_url' => 'https://api.openai.com/v1',
                'endpoint' => 'chat/completions',
                'api_key' => null,
                'name' => 'gpt-5',
                // A whole reply may take this long; a server silent for idle_timeout seconds is given up on.
                'timeout' => 3600.0,
                'idle_timeout' => 120.0,
                // Tokens the model can take in one request; compaction keeps below it.
                'context_window' => 128_000,
            ],
            'prompt' => [
                'system' => 'You are Bia, a fast coding assistant. Be precise and concise.',
            ],
            'permissions' => [
                'read' => 'auto',
                'write' => 'auto',
                'shell' => 'auto',
            ],
            'tools' => [
                'shell_timeout' => 120.0,
                // Tool output past either limit is cut, and saved in full under cache.path.
                'output_bytes' => 51_200,
                'output_lines' => 2000,
            ],
            'compaction' => [
                'enabled' => true,
                'reserve_tokens' => 16_384,
                'keep_tokens' => 20_000,
            ],
            'storage' => [
                'path' => $stateHome . '/bia/bia.sqlite',
            ],
            'cache' => [
                'path' => $cacheHome . '/bia',
            ],
        ];
    }

    /**
     * The user's own configuration file.
     */
    public static function globalPath(): string
    {
        return self::directory('XDG_CONFIG_HOME', self::home() . '/.config') . '/bia/config.php';
    }

    /**
     * The file `bia init --global` writes: every setting, commented out at
     * its default, so the file changes nothing until a line is uncommented.
     */
    public static function template(): string
    {
        return <<<'PHP'
            <?php

            // Bia's configuration for you, in every project. Uncomment a line to change it.
            // A project's .bia/config.json, the environment and command-line options
            // override what is set here. `bia config` shows the result.

            return [
                // 'model' => [
                //     'base_url' => 'https://api.openai.com/v1',  // any OpenAI-compatible API
                //     'name' => 'gpt-5',
                //     'api_key' => null,                // better: BIA_API_KEY or <PROVIDER>_API_KEY in the environment
                //     'provider' => 'openai-compatible',  // names the <PROVIDER>_API_KEY variable, e.g. 'tdaproxy'
                //     'endpoint' => 'chat/completions',
                //     'timeout' => 3600.0,              // seconds a whole reply may take
                //     'idle_timeout' => 120.0,          // give up on a server silent this long
                //     'context_window' => 128000,       // your model's window, in tokens
                // ],
                // 'prompt' => [
                //     'system' => 'You are Bia, a fast coding assistant. Be precise and concise.',
                // ],
                // 'permissions' => [                    // auto | ask | deny
                //     'read' => 'auto',
                //     'write' => 'auto',
                //     'shell' => 'auto',
                // ],
                // 'tools' => [
                //     'shell_timeout' => 120.0,         // default seconds for a shell command
                //     'output_bytes' => 51200,          // tool output sent to the model per call
                //     'output_lines' => 2000,
                // ],
                // 'compaction' => [
                //     'enabled' => true,
                //     'reserve_tokens' => 16384,        // room left for the reply
                //     'keep_tokens' => 20000,           // recent history kept word for word
                // ],
                // 'storage' => [
                //     'path' => '~/.local/state/bia/bia.sqlite',
                // ],
                // 'cache' => [
                //     'path' => '~/.cache/bia',
                // ],
            ];

            PHP;
    }

    /**
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return self::read($this->values, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    public function projectRoot(): ?string
    {
        return self::findProjectRoot(getcwd());
    }

    /**
     * Where the user's own config.php and global AGENTS.md live.
     */
    public function configDirectory(): string
    {
        return self::directory('XDG_CONFIG_HOME', self::home() . '/.config') . '/bia';
    }

    public function cachePath(): string
    {
        return self::expand((string) $this->get('cache.path'));
    }

    public function databasePath(): string
    {
        return self::expand((string) $this->get('storage.path'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function check(array $values): void
    {
        $model = $values['model'] ?? null;
        if (!is_array($model)) {
            throw new \InvalidArgumentException('model must be an object in Bia configuration.');
        }
        foreach (['provider', 'base_url', 'name'] as $key) {
            if (!isset($model[$key]) || !is_string($model[$key]) || $model[$key] === '') {
                throw new \InvalidArgumentException("model.{$key} must be a non-empty string.");
            }
        }
        if (isset($model['endpoint']) && (!is_string($model['endpoint']) || $model['endpoint'] === '')) {
            throw new \InvalidArgumentException('model.endpoint must be a non-empty string.');
        }
        if (isset($model['api_key']) && $model['api_key'] !== null && !is_string($model['api_key'])) {
            throw new \InvalidArgumentException('model.api_key must be a string or null.');
        }
        foreach (['timeout', 'idle_timeout'] as $key) {
            if (isset($model[$key]) && (!is_int($model[$key]) && !is_float($model[$key]) || $model[$key] <= 0)) {
                throw new \InvalidArgumentException("model.{$key} must be a positive number.");
            }
        }
        $tools = $values['tools'] ?? [];
        if (!is_array($tools)) {
            throw new \InvalidArgumentException('tools must be an object in Bia configuration.');
        }
        foreach (['shell_timeout', 'output_bytes', 'output_lines'] as $key) {
            if (isset($tools[$key]) && (!is_int($tools[$key]) && !is_float($tools[$key]) || $tools[$key] <= 0)) {
                throw new \InvalidArgumentException("tools.{$key} must be a positive number.");
            }
        }
        if (!isset($values['storage']['path']) || !is_string($values['storage']['path']) || $values['storage']['path'] === '') {
            throw new \InvalidArgumentException('storage.path must be a non-empty string.');
        }
        if (isset($model['context_window']) && (!is_int($model['context_window']) || $model['context_window'] < 4096)) {
            throw new \InvalidArgumentException('model.context_window must be a whole number of tokens, at least 4096.');
        }
        $compaction = $values['compaction'] ?? [];
        if (!is_array($compaction)) {
            throw new \InvalidArgumentException('compaction must be an object in Bia configuration.');
        }
        if (isset($compaction['enabled']) && !is_bool($compaction['enabled'])) {
            throw new \InvalidArgumentException('compaction.enabled must be true or false.');
        }
        foreach (['reserve_tokens', 'keep_tokens'] as $key) {
            if (isset($compaction[$key]) && (!is_int($compaction[$key]) || $compaction[$key] < 1)) {
                throw new \InvalidArgumentException("compaction.{$key} must be a positive whole number.");
            }
        }
        $permissions = $values['permissions'] ?? [];
        if (!is_array($permissions)) {
            throw new \InvalidArgumentException('permissions must be an object in Bia configuration.');
        }
        foreach (['read', 'write', 'shell'] as $permission) {
            $value = $permissions[$permission] ?? 'auto';
            if (!is_string($value) || !in_array($value, ['auto', 'ask', 'deny'], true)) {
                throw new \InvalidArgumentException("permissions.{$permission} must be auto, ask, or deny.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function file(string $path): array
    {
        if (trim((string) file_get_contents($path)) === '') {
            return []; // A blank file sets nothing.
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \InvalidArgumentException("{$path} must return a configuration array.");
        }
        return $values;
    }

    /**
     * Reads a project's JSON settings and refuses any outside PROJECT_KEYS,
     * naming them, rather than quietly dropping them.
     *
     * @return array<string, mixed>
     */
    private static function project(string $path): array
    {
        try {
            $values = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException("{$path} is not valid JSON: {$error->getMessage()}.");
        }
        if (!is_array($values)) {
            throw new \InvalidArgumentException("{$path} must hold a JSON object.");
        }
        $refused = array_filter(
            self::keys($values),
            static function (string $key): bool {
                foreach (self::PROJECT_KEYS as $allowed) {
                    if ($key === $allowed || str_starts_with($key, $allowed . '.')) {
                        return false;
                    }
                }
                return true;
            },
        );
        if ($refused !== []) {
            throw new \InvalidArgumentException(
                "{$path} may not set " . implode(', ', $refused) . '; set them in ~/.config/bia/config.php or the environment.',
            );
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string> The dotted path of every leaf.
     */
    private static function keys(array $values, string $prefix = ''): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                array_push($keys, ...self::keys($value, $path . '.'));
            } else {
                $keys[] = $path;
            }
        }
        return $keys;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private static function merge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = self::merge($left[$key], $value);
                continue;
            }
            $left[$key] = $value;
        }
        return $left;
    }

    private static function read(array $values, string $key, mixed $default): mixed
    {
        $current = $values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private static function home(): string
    {
        $home = getenv('HOME');
        return is_string($home) && $home !== '' ? rtrim($home, '/') : getcwd();
    }

    private static function directory(string $name, string $fallback): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? rtrim($value, '/') : $fallback;
    }

    private static function expand(string $path): string
    {
        if ($path === '~') {
            return self::home();
        }
        if (str_starts_with($path, '~/')) {
            return self::home() . substr($path, 1);
        }
        return $path;
    }

    private static function findProjectRoot(string $directory): ?string
    {
        $directory = realpath($directory) ?: $directory;
        while ($directory !== dirname($directory)) {
            if (is_dir($directory . '/.bia') || is_file($directory . '/composer.json')) {
                return $directory;
            }
            $directory = dirname($directory);
        }
        return is_dir($directory . '/.bia') ? $directory : null;
    }
}
