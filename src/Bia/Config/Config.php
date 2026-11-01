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
 * limits. It may pick a model of a provider the user configured, never
 * define one.
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

        $environment = [];
        foreach (['BIA_PROVIDER' => 'provider', 'BIA_BASE_URL' => 'base_url', 'BIA_MODEL' => 'name', 'BIA_API_KEY' => 'api_key'] as $name => $key) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $environment['model'][$key] = $value;
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
        // What the environment or command line set for the model beats a provider's settings.
        $explicit = self::merge($environment['model'] ?? [], is_array($overrides['model'] ?? null) ? $overrides['model'] : []);
        unset($explicit['name'], $explicit['provider']);
        $values = self::resolve($values, $explicit);

        if (!isset($explicit['api_key'])) {
            foreach (self::keyVariables($values) as $variable) {
                if (($value = getenv($variable)) !== false && $value !== '') {
                    $values['model']['api_key'] = $value;
                    if (!in_array('environment', $sources, true)) {
                        $sources[] = 'environment';
                    }
                    break;
                }
            }
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
            // Named model servers, picked as provider/model; users add their own.
            'providers' => [
                'openai' => [
                    'base_url' => 'https://api.openai.com/v1',
                    'api_key_env' => 'OPENAI_API_KEY',
                    'context_window' => 400_000,
                ],
                'anthropic' => [
                    // Anthropic's OpenAI-compatible endpoint.
                    'base_url' => 'https://api.anthropic.com/v1',
                    'api_key_env' => 'ANTHROPIC_API_KEY',
                    'context_window' => 200_000,
                ],
                'cliproxyapi' => [
                    // CLIProxyAPI on this machine, at its default port.
                    'base_url' => 'http://127.0.0.1:8317/v1',
                    'api_key_env' => 'CLIPROXYAPI_API_KEY',
                    'context_window' => 200_000,
                ],
            ],
            'model' => [
                'provider' => 'openai-compatible',
                'base_url' => 'https://api.openai.com/v1',
                'endpoint' => 'chat/completions',
                'api_key' => null,
                // The environment variable holding the key, when it is not <PROVIDER>_API_KEY.
                'api_key_env' => null,
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

            /*
            |--------------------------------------------------------------------------
            | Bia Configuration
            |--------------------------------------------------------------------------
            |
            | Your settings for Bia in every project. Each one is shown commented out
            | at its default, so this file changes nothing until you uncomment a line.
            | A project's .bia/config.json, the environment and command-line options
            | override what is set here. Run `bia config` to see the result.
            |
            */

            return [

                /*
                |--------------------------------------------------------------------------
                | Providers
                |--------------------------------------------------------------------------
                |
                | A provider is a model server you set up once. Pick any of its models
                | by naming it provider/model in model.name, BIA_MODEL or --model, such
                | as anthropic/claude-sonnet-4-5. These three are built in; change one,
                | or add your own under a new name.
                |
                | A provider takes any model setting below. Paste its key into api_key,
                | or leave that out and Bia reads the variable named by api_key_env,
                | else <NAME>_API_KEY; a variable that is set wins. Only a
                | model that differs from its provider needs an entry under "models",
                | e.g. 'models' => ['gemini-2.5-pro' => ['context_window' => 1000000]].
                |
                */

                // 'providers' => [
                //     'openai' => [
                //         'base_url' => 'https://api.openai.com/v1',
                //         'api_key_env' => 'OPENAI_API_KEY',
                //         'context_window' => 400000,
                //     ],
                //     'anthropic' => [
                //         'base_url' => 'https://api.anthropic.com/v1',
                //         'api_key_env' => 'ANTHROPIC_API_KEY',
                //         'context_window' => 200000,
                //     ],
                //     'cliproxyapi' => [
                //         'base_url' => 'http://127.0.0.1:8317/v1',
                //         'api_key_env' => 'CLIPROXYAPI_API_KEY',  // or 'api_key' => 'sk-...'
                //         'context_window' => 200000,
                //     ],
                // ],

                /*
                |--------------------------------------------------------------------------
                | Model
                |--------------------------------------------------------------------------
                |
                | The model Bia talks to. The name may be provider/model, which takes
                | the server, key and context window from that provider. Otherwise
                | base_url may point at any OpenAI-compatible API.
                |
                | The key may be pasted into api_key here, in this file only you can
                | read; BIA_API_KEY or the provider's variable overrides it when set.
                | The timeout is how long a whole reply
                | may take; a server silent for idle_timeout seconds is given up on.
                | The context window is your model's, in tokens; compaction keeps the
                | conversation below it.
                |
                */

                // 'model' => [
                //     'name' => 'gpt-5',
                //     'base_url' => 'https://api.openai.com/v1',
                //     'api_key' => null,
                //     'api_key_env' => null,
                //     'provider' => 'openai-compatible',
                //     'endpoint' => 'chat/completions',
                //     'timeout' => 3600.0,
                //     'idle_timeout' => 120.0,
                //     'context_window' => 128000,
                // ],

                /*
                |--------------------------------------------------------------------------
                | System Prompt
                |--------------------------------------------------------------------------
                |
                | Who Bia is. Your AGENTS.md files and the project's are added after it.
                |
                */

                // 'prompt' => [
                //     'system' => 'You are Bia, a fast coding assistant. Be precise and concise.',
                // ],

                /*
                |--------------------------------------------------------------------------
                | Permissions
                |--------------------------------------------------------------------------
                |
                | Bia runs every tool without asking. Set a kind of tool to "ask" to be
                | asked first, or to "deny" to refuse it. Supported: "auto", "ask",
                | "deny".
                |
                */

                // 'permissions' => [
                //     'read' => 'auto',
                //     'write' => 'auto',
                //     'shell' => 'auto',
                // ],

                /*
                |--------------------------------------------------------------------------
                | Tools
                |--------------------------------------------------------------------------
                |
                | The shell timeout is the default number of seconds a command may run.
                | Tool output past output_bytes or output_lines is cut to its first and
                | last lines for the model, and saved in full under the cache path.
                |
                */

                // 'tools' => [
                //     'shell_timeout' => 120.0,
                //     'output_bytes' => 51200,
                //     'output_lines' => 2000,
                // ],

                /*
                |--------------------------------------------------------------------------
                | Compaction
                |--------------------------------------------------------------------------
                |
                | When a conversation nears the context window, its older part is
                | summarized. reserve_tokens is the room left for the reply, and
                | keep_tokens is how much recent history is kept word for word.
                |
                */

                // 'compaction' => [
                //     'enabled' => true,
                //     'reserve_tokens' => 16384,
                //     'keep_tokens' => 20000,
                // ],

                /*
                |--------------------------------------------------------------------------
                | Storage & Cache
                |--------------------------------------------------------------------------
                |
                | Sessions are kept in this SQLite database. The cache holds the full
                | output of tools whose output was cut.
                |
                */

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
     * Applies the provider a model belongs to. `model.name` may name one of
     * `providers` as a prefix, `tdaproxy/gpt-6-astra`; otherwise
     * `model.provider` may. The provider's settings then fill in the model's,
     * and `models.<name>` overrides them for that one model. Whatever was set
     * explicitly in the environment or on the command line still wins.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $explicit
     * @return array<string, mixed>
     */
    private static function resolve(array $values, array $explicit): array
    {
        $providers = $values['providers'] ?? [];
        if (!is_array($providers)) {
            throw new \InvalidArgumentException('providers must be an array of named providers.');
        }
        $name = $values['model']['name'] ?? null;
        $provider = null;
        if (is_string($name) && ($slash = strpos($name, '/')) !== false && isset($providers[substr($name, 0, $slash)])) {
            $provider = substr($name, 0, $slash);
            $name = substr($name, $slash + 1);
        } elseif (is_string($values['model']['provider'] ?? null) && isset($providers[$values['model']['provider']])) {
            $provider = $values['model']['provider'];
        }
        if ($provider === null) {
            return $values;
        }
        $settings = $providers[$provider];
        if (!is_array($settings)) {
            throw new \InvalidArgumentException("providers.{$provider} must be an array of settings.");
        }
        $models = $settings['models'] ?? [];
        if (!is_array($models)) {
            throw new \InvalidArgumentException("providers.{$provider}.models must map model names to settings.");
        }
        $model = $models[$name] ?? [];
        if (!is_array($model)) {
            throw new \InvalidArgumentException("providers.{$provider}.models.{$name} must be an array of settings.");
        }
        unset($settings['models'], $settings['name'], $model['name']);
        $values['model'] = self::merge(self::merge(self::merge($values['model'], $settings), $model), $explicit);
        $values['model']['provider'] = $provider;
        $values['model']['name'] = $name;
        return $values;
    }

    /**
     * The environment variables that may hold the model's API key, first found wins.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private static function keyVariables(array $values): array
    {
        $provider = (string) ($values['model']['provider'] ?? 'openai-compatible');
        $variables = [];
        $named = $values['model']['api_key_env'] ?? null;
        if (is_string($named) && $named !== '') {
            $variables[] = $named;
        }
        $variables[] = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $provider)) . '_API_KEY';
        if ($provider === 'openai-compatible' || $provider === 'openai') {
            $variables[] = 'OPENAI_API_KEY';
        }
        if ($provider === 'tdaproxy') {
            $variables[] = 'TDA_PROXY_API_KEY';
        }
        return array_values(array_unique($variables));
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
        if (isset($model['api_key_env']) && (!is_string($model['api_key_env']) || $model['api_key_env'] === '')) {
            throw new \InvalidArgumentException('api_key_env must name an environment variable.');
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
