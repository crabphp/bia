<?php

declare(strict_types=1);

namespace Bia\Config;

/**
 * Loads Bia's small, layered configuration without booting a Crab app.
 *
 * The user's own `~/.config/bia/config.php` is trusted PHP. A project's
 * `.agents/bia.json` comes with whatever repository Bia is run in, so it is
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
     * @param array<string, mixed> $merged Every layer merged, before the model's provider was applied.
     * @param array<string, mixed> $explicit Model settings the environment or command line set.
     */
    private function __construct(
        private array $values,
        private array $sources,
        private array $merged,
        private array $explicit,
    ) {}

    /** Settings chosen inside a conversation, kept in the user's config.php for the next ones. */
    public const REMEMBERED_KEYS = ['model.name', 'model.reasoning_effort', 'tui.render'];

    /** How hard a reasoning model thinks; default sends none and leaves it to the provider. */
    public const EFFORTS = ['default', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    /**
     * Settings a project's `.agents/bia.json` may set, as dotted prefixes.
     */
    public const PROJECT_KEYS = ['model.name', 'model.context_window', 'model.reasoning_effort', 'prompt.system', 'permissions', 'tools', 'compaction'];

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
            $project = $projectRoot . '/.agents/bia.json';
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
        return self::make($values, $explicit, $sources);
    }

    /**
     * The same configuration with another model, `provider/model` or a bare
     * name, as `/model` switches to.
     */
    public function withModel(string $name): self
    {
        $merged = $this->merged;
        $merged['model']['name'] = $name;
        return self::make($merged, $this->explicit, $this->sources);
    }

    /**
     * The same configuration with another reasoning effort, as `/effort`
     * sets it: it beats a provider's, and stays when the model changes.
     */
    public function withEffort(string $effort): self
    {
        $merged = $this->merged;
        $merged['model']['reasoning_effort'] = $effort;
        $explicit = $this->explicit;
        $explicit['reasoning_effort'] = $effort;
        return self::make($merged, $explicit, $this->sources);
    }

    /**
     * The reasoning effort to ask for, or null to send none.
     */
    public function effort(): ?string
    {
        $effort = $this->get('model.reasoning_effort');
        return is_string($effort) && $effort !== 'default' ? $effort : null;
    }

    /**
     * Each provider as a model of it would use it: its base_url, endpoint,
     * api_key and the models listed for it.
     *
     * @return array<string, array{base_url: string, endpoint: string, api_key: ?string, models: list<string>}>
     */
    public function providers(): array
    {
        $providers = [];
        foreach ((array) ($this->merged['providers'] ?? []) as $name => $settings) {
            if (!is_string($name) || !is_array($settings)) {
                continue;
            }
            try {
                $model = $this->withModel("{$name}/-")->get('model');
            } catch (\InvalidArgumentException) {
                continue;
            }
            $key = $model['api_key'] ?? null;
            $providers[$name] = [
                'base_url' => (string) $model['base_url'],
                'endpoint' => (string) ($model['endpoint'] ?? 'chat/completions'),
                'api_key' => is_string($key) && $key !== '' ? $key : null,
                'models' => array_values(array_map('strval', array_keys((array) ($settings['models'] ?? [])))),
            ];
        }
        return $providers;
    }

    /**
     * The model as the user names it: `provider/model` when it is a provider's.
     */
    public function modelLabel(): string
    {
        $provider = (string) $this->get('model.provider');
        $name = (string) $this->get('model.name');
        return isset($this->values['providers'][$provider]) ? "{$provider}/{$name}" : $name;
    }

    /**
     * Every API key configured for the model or a provider, so subprocesses
     * never inherit any of them.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $secrets = [(string) ($this->get('model.api_key') ?? '')];
        foreach ($this->providers() as $provider) {
            $secrets[] = (string) ($provider['api_key'] ?? '');
        }
        foreach ((array) ($this->merged['providers'] ?? []) as $settings) {
            if (is_array($settings) && is_string($settings['api_key'] ?? null)) {
                $secrets[] = $settings['api_key'];
            }
        }
        return array_values(array_unique(array_filter($secrets, static fn (string $secret): bool => $secret !== '')));
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $explicit
     * @param list<string> $sources
     */
    private static function make(array $merged, array $explicit, array $sources): self
    {
        $values = self::resolve($merged, $explicit);
        // <PROVIDER>_API_KEY stands in for a key not given; it does not replace one.
        if (!isset($explicit['api_key']) && (string) ($values['model']['api_key'] ?? '') === '') {
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
        return new self($values, $sources, $merged, $explicit);
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
                    'context_window' => 1_050_000,
                ],
                'anthropic' => [
                    // Anthropic's OpenAI-compatible endpoint.
                    'base_url' => 'https://api.anthropic.com/v1',
                    'context_window' => 1_000_000,
                ],
                'cliproxyapi' => [
                    // CLIProxyAPI on this machine, at its default port.
                    'base_url' => 'http://127.0.0.1:8317/v1',
                    'context_window' => 1_000_000,
                ],
            ],
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
                // How hard a reasoning model thinks: none, minimal, low, medium, high, xhigh; default sends none.
                'reasoning_effort' => 'default',
            ],
            'fallback' => [
                // Models, provider/model, asked in order when the chosen one's provider fails.
                'models' => [],
                // Seconds a failed model rests before it is asked first again.
                'cooldown' => 300.0,
            ],
            'prompt' => [
                'system' => 'You are Bia, a fast coding assistant. Be precise and concise.',
            ],
            'permissions' => [
                'read' => 'auto',
                'write' => 'auto',
                'shell' => 'auto',
                'mcp' => 'auto',
            ],
            'tools' => [
                'shell_timeout' => 120.0,
                // Seconds a question from the model waits for an answer, so a long run goes on without you; 0 waits.
                'ask_timeout' => 120,
                // Tool output past either limit is cut, and saved in full under cache.path.
                'output_bytes' => 51_200,
                'output_lines' => 2000,
            ],
            'compaction' => [
                'enabled' => true,
                'reserve_tokens' => 16_384,
                'keep_tokens' => 20_000,
                // Compact past this many tokens too, before the window; null waits for the window.
                'at_tokens' => 400_000,
                // Idle this long, the prompt cache is gone: compact first when over idle_tokens.
                'idle_seconds' => 3600,
                'idle_tokens' => 64_000,
            ],
            'mcp' => [
                // Seconds an MCP tool call may take, and a server to start and list its tools.
                // The servers themselves are in mcp.json beside this file, and a project's .mcp.json.
                'timeout' => 120.0,
                'startup_timeout' => 30.0,
            ],
            'tui' => [
                // The whole terminal, scrolled by Bia with the input kept at the bottom; false draws inline.
                'fullscreen' => true,
                // How answers appear: normal, as they arrive; smooth, a steady flow; power, dropping into place.
                'render' => 'normal',
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
            | A project's .agents/bia.json, the environment and command-line options
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
                | A provider takes any model setting below. Give its key as api_key,
                | pasted in or read with env(), e.g. 'api_key' => env('MY_KEY').
                | Without one, Bia reads <NAME>_API_KEY, e.g. CLIPROXYAPI_API_KEY.
                |
                | Only a model that differs from its provider needs an entry under
                | "models", e.g. 'models' => ['gemini-2.5-pro' => ['context_window' => 1000000]].
                |
                */

                // 'providers' => [
                //     'openai' => [
                //         'base_url' => 'https://api.openai.com/v1',
                //         'context_window' => 1050000,
                //     ],
                //     'anthropic' => [
                //         'base_url' => 'https://api.anthropic.com/v1',
                //         'context_window' => 1000000,
                //     ],
                //     'cliproxyapi' => [
                //         'base_url' => 'http://127.0.0.1:8317/v1',
                //         'context_window' => 1000000,
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
                | Give the key as api_key, pasted in (only you can read this file) or
                | read with env(), e.g. env('OPENAI_API_KEY'). BIA_API_KEY overrides
                | it. The timeout is how long a whole reply may take; a server silent
                | for idle_timeout seconds is given up on.
                | The context window is your model's, in tokens; compaction keeps the
                | conversation below it.
                |
                | reasoning_effort is how hard a reasoning model thinks: none, minimal,
                | low, medium, high or xhigh; default leaves it to the provider.
                | /effort switches it.
                |
                */

                // 'model' => [
                //     'name' => 'gpt-5',
                //     'base_url' => 'https://api.openai.com/v1',
                //     'api_key' => null,
                //     'provider' => 'openai-compatible',
                //     'endpoint' => 'chat/completions',
                //     'timeout' => 3600.0,
                //     'idle_timeout' => 120.0,
                //     'context_window' => 128000,
                //     'reasoning_effort' => 'default',
                // ],

                /*
                |--------------------------------------------------------------------------
                | Fallback
                |--------------------------------------------------------------------------
                |
                | Models to fall back on, in order, when the chosen model's provider
                | is rate limited, down or refuses its key. The request goes to the
                | next at once; a failed model rests for the cooldown, then is asked
                | first again. A model without a key is left out. For example:
                | 'models' => ['anthropic/claude-sonnet-4-5', 'openai/gpt-5'].
                |
                */

                // 'fallback' => [
                //     'models' => [],
                //     'cooldown' => 300.0,
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
                //     'mcp' => 'auto',
                // ],

                /*
                |--------------------------------------------------------------------------
                | Tools
                |--------------------------------------------------------------------------
                |
                | The shell timeout is the default number of seconds a command may run.
                | A question from the model is skipped after ask_timeout seconds without
                | an answer (0 waits), and the model decides itself.
                | Tool output past output_bytes or output_lines is cut to its first and
                | last lines for the model, and saved in full under the cache path.
                |
                */

                // 'tools' => [
                //     'shell_timeout' => 120.0,
                //     'ask_timeout' => 120,
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
                | at_tokens compacts sooner, in a model with a large window: answers get
                | worse in a very long context. null waits for the window.
                |
                | After idle_seconds without a reply, the provider's prompt cache is gone
                | and the next request pays for all of it anyway: a conversation over
                | idle_tokens is compacted first, for about the same cost. Set
                | idle_seconds to your provider's cache lifetime; null never does.
                |
                */

                // 'compaction' => [
                //     'enabled' => true,
                //     'reserve_tokens' => 16384,
                //     'keep_tokens' => 20000,
                //     'at_tokens' => 400000,
                //     'idle_seconds' => 3600,
                //     'idle_tokens' => 64000,
                // ],

                /*
                |--------------------------------------------------------------------------
                | MCP
                |--------------------------------------------------------------------------
                |
                | MCP servers give Bia more tools. List them in mcp.json beside this file,
                | in the "mcpServers" format other agents use; a project's own .mcp.json
                | is used too, once you allow it. These are how long a tool call, and a
                | server's start, may take. /mcp shows how each server is doing.
                |
                */

                // 'mcp' => [
                //     'timeout' => 120.0,
                //     'startup_timeout' => 30.0,
                // ],

                /*
                |--------------------------------------------------------------------------
                | Terminal
                |--------------------------------------------------------------------------
                |
                | A conversation takes the whole terminal and keeps the input at the
                | bottom while the output scrolls. false draws inline instead, in the
                | terminal's own scrollback.
                |
                | render is how answers appear: normal, as the text arrives; smooth,
                | evened out to a steady flow; power, each character falling into
                | place with a flash (fullscreen only). /render switches it.
                |
                */

                // 'tui' => [
                //     'fullscreen' => true,
                //     'render' => 'normal',
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

    /**
     * Keeps a setting chosen in a conversation, as /model and /render, in
     * the user's config.php for the next ones: that one value is set, the
     * rest of the file left as written. Only REMEMBERED_KEYS.
     */
    public static function remember(string $key, string|int|float|bool $value): void
    {
        if (!in_array($key, self::REMEMBERED_KEYS, true)) {
            throw new \InvalidArgumentException("{$key} is not a setting Bia keeps.");
        }
        (new ConfigFile(self::globalPath()))->set(explode('.', $key), $value);
    }

    /**
     * Where Bia looked for the model's key, for an error that says what to set.
     */
    public function keyHelp(): string
    {
        $provider = (string) $this->get('model.provider');
        $where = isset($this->values['providers'][$provider])
            ? "set 'api_key' in providers.{$provider} in " . self::globalPath()
            : 'set model.api_key in ' . self::globalPath();
        return "No API key for {$provider} model " . $this->get('model.name') . ": {$where}, or export "
            . implode(' or ', [...self::keyVariables($this->values), 'BIA_API_KEY'])
            . '. To use one of your providers instead, run bia --model <provider>/<model> or set model.name. `bia doctor` shows what Bia read.';
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
        foreach (['timeout', 'idle_timeout'] as $key) {
            if (isset($model[$key]) && (!is_int($model[$key]) && !is_float($model[$key]) || $model[$key] <= 0)) {
                throw new \InvalidArgumentException("model.{$key} must be a positive number.");
            }
        }
        $effort = $model['reasoning_effort'] ?? 'default';
        if ($effort !== null && !in_array($effort, self::EFFORTS, true)) {
            throw new \InvalidArgumentException('model.reasoning_effort must be one of ' . implode(', ', self::EFFORTS) . '.');
        }
        $render = $values['tui']['render'] ?? 'normal';
        if (!in_array($render, ['normal', 'smooth', 'power'], true)) {
            throw new \InvalidArgumentException('tui.render must be normal, smooth, or power.');
        }
        $fallback = $values['fallback'] ?? [];
        if (!is_array($fallback) || !array_is_list($fallback['models'] ?? []) || array_filter((array) ($fallback['models'] ?? []), static fn (mixed $name): bool => !is_string($name) || $name === '') !== []) {
            throw new \InvalidArgumentException('fallback.models must be a list of model names.');
        }
        if (isset($fallback['cooldown']) && (!is_int($fallback['cooldown']) && !is_float($fallback['cooldown']) || $fallback['cooldown'] < 0)) {
            throw new \InvalidArgumentException('fallback.cooldown must be a number of seconds, zero or more.');
        }
        $tools = $values['tools'] ?? [];
        if (!is_array($tools)) {
            throw new \InvalidArgumentException('tools must be an object in Bia configuration.');
        }
        if (isset($tools['ask_timeout']) && (!is_int($tools['ask_timeout']) && !is_float($tools['ask_timeout']) || $tools['ask_timeout'] < 0)) {
            throw new \InvalidArgumentException('tools.ask_timeout must be a number of seconds, zero or more.');
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
        foreach (['reserve_tokens', 'keep_tokens', 'idle_tokens'] as $key) {
            if (isset($compaction[$key]) && (!is_int($compaction[$key]) || $compaction[$key] < 1)) {
                throw new \InvalidArgumentException("compaction.{$key} must be a positive whole number.");
            }
        }
        foreach (['at_tokens', 'idle_seconds'] as $key) {
            if (isset($compaction[$key]) && (!is_int($compaction[$key]) || $compaction[$key] < 1)) {
                throw new \InvalidArgumentException("compaction.{$key} must be a positive whole number, or null.");
            }
        }
        $permissions = $values['permissions'] ?? [];
        if (!is_array($permissions)) {
            throw new \InvalidArgumentException('permissions must be an object in Bia configuration.');
        }
        foreach (['read', 'write', 'shell', 'mcp'] as $permission) {
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

    /**
     * The nearest directory with a project's `.agents/bia.json` or a
     * `composer.json`. Never the home directory: its `.agents` holds the
     * user's own skills, and it is not a project.
     */
    private static function findProjectRoot(string $directory): ?string
    {
        $directory = realpath($directory) ?: $directory;
        $home = realpath((string) getenv('HOME')) ?: null;
        for (; ; $directory = dirname($directory)) {
            if ($directory !== $home && (is_file($directory . '/.agents/bia.json') || is_file($directory . '/composer.json'))) {
                return $directory;
            }
            if ($directory === dirname($directory)) {
                return null;
            }
        }
    }
}
