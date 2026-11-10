<?php

declare(strict_types=1);

namespace Bia\Plugin;

use Crab\Engine\Async\CancelledException;

/**
 * The plugins the user installed: each a folder under Bia's home,
 * `~/.bia/plugins`, and nowhere else, holding any of a `plugin.php`, a
 * `skills` folder and an `mcp.json`. A project never brings one: this is
 * the user's own code, run in Bia's process.
 *
 * A plugin.php is loaded for a conversation only, and again by `/reload`.
 * One that throws, as it loads or later, is off until the next load, and
 * the conversation goes on. The skills and the MCP servers are read where
 * the user's own are, by `Skill\Catalog` and with `~/.bia/mcp.json`.
 */
final class Plugins
{
    public const EVENTS = ['turn.start', 'tool.call', 'tool.result', 'turn.done'];

    /**
     * Every plugin.php this process has run, by path: PHP cannot run a file
     * that declares a class or a function a second time.
     *
     * @var array<string, array{hash: string, plugin: ?\Closure}>
     */
    private static array $files = [];

    /** @var array<string, array{commands: array<string, string>, tools: list<string>, events: list<string>, skills: list<string>, servers: list<string>, error: ?string}> By name. */
    private array $plugins = [];
    /** @var array<string, PluginTool> By the name the model calls it by. */
    private array $tools = [];
    /** @var array<string, array{plugin: string, handler: \Closure(string): void}> */
    private array $commands = [];
    /** @var array<string, list<array{plugin: string, listener: \Closure}>> */
    private array $listeners = [];
    /** @var list<array{string, string}> What failed before anyone could be told. */
    private array $failed = [];
    private ?\Closure $note = null;
    private ?\Closure $ask = null;
    private ?\Closure $fail = null;

    /**
     * @param string $directory Where plugins are installed.
     * @param string $workspace The project Bia runs in.
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $workspace = '',
    ) {}

    /**
     * Reads the plugins as they are now, in place of those read before.
     */
    public function load(): self
    {
        $this->plugins = $this->commands = $this->listeners = $this->tools = [];
        foreach (self::folders($this->directory) as $name => $folder) {
            $skills = [];
            foreach (is_dir("{$folder}/skills") ? (scandir("{$folder}/skills") ?: []) : [] as $skill) {
                if ($skill[0] !== '.' && is_file("{$folder}/skills/{$skill}/SKILL.md")) {
                    $skills[] = $skill;
                }
            }
            $servers = is_file("{$folder}/mcp.json") ? json_decode((string) file_get_contents("{$folder}/mcp.json"), true) : null;
            $this->plugins[$name] = [
                'commands' => [],
                'tools' => [],
                'events' => [],
                'skills' => $skills,
                'servers' => array_map(strval(...), array_keys(is_array($servers['mcpServers'] ?? null) ? $servers['mcpServers'] : [])),
                'error' => null,
            ];
            if (!is_file("{$folder}/plugin.php")) {
                continue;
            }
            try {
                (self::read("{$folder}/plugin.php"))(new Host($name, $folder, $this->workspace, $this));
            } catch (\Throwable $error) {
                $this->disable($name, $error->getMessage());
            }
        }
        return $this;
    }

    /**
     * The plugins installed in a directory: each folder holding a
     * plugin.php, skills or an mcp.json, by name.
     *
     * @return array<string, string>
     */
    public static function folders(string $directory): array
    {
        $folders = [];
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $entry) {
            $folder = "{$directory}/{$entry}";
            if (preg_match('/^[a-z0-9][\w.-]*$/i', $entry) === 1 && self::is($folder)) {
                $folders[$entry] = $folder;
            }
        }
        return $folders;
    }

    /**
     * Whether a folder is a plugin: it holds at least one of its parts.
     */
    public static function is(string $folder): bool
    {
        return is_file("{$folder}/plugin.php") || is_dir("{$folder}/skills") || is_file("{$folder}/mcp.json");
    }

    /**
     * Says where a plugin's notes, messages and failures go: the
     * conversation's. A failure from before is told now.
     *
     * @param \Closure(string): void $note
     * @param \Closure(string): void $ask
     * @param \Closure(string, string): void $fail Given the plugin's name and what went wrong.
     */
    public function attach(\Closure $note, \Closure $ask, \Closure $fail): void
    {
        [$this->note, $this->ask, $this->fail] = [$note, $ask, $fail];
        $this->told();
    }

    /**
     * Tells the failures not told yet, as after a load.
     */
    public function told(): void
    {
        if ($this->fail === null) {
            return;
        }
        foreach ($this->failed as [$plugin, $message]) {
            ($this->fail)($plugin, $message);
        }
        $this->failed = [];
    }

    /**
     * @return array<string, array{commands: array<string, string>, tools: list<string>, events: list<string>, skills: list<string>, servers: list<string>, error: ?string}> By name.
     */
    public function all(): array
    {
        return $this->plugins;
    }

    /**
     * The tools plugins add, as they are now: a plugin turned off has none.
     *
     * @return list<PluginTool>
     */
    public function tools(): array
    {
        return array_values($this->tools);
    }

    /**
     * Whether any plugin listens: without one, nothing needs telling.
     */
    public function listening(): bool
    {
        return $this->listeners !== [];
    }

    /**
     * The slash commands plugins add, each with its description.
     *
     * @return array<string, string>
     */
    public function commands(): array
    {
        $commands = [];
        foreach ($this->plugins as $plugin) {
            $commands += $plugin['commands'];
        }
        return $commands;
    }

    /**
     * Runs a plugin's command.
     *
     * @return bool False when no plugin has one of that name.
     */
    public function run(string $name, string $arguments): bool
    {
        $command = $this->commands[strtolower($name)] ?? null;
        if ($command === null) {
            return false;
        }
        $this->guarded($command['plugin'], static fn () => ($command['handler'])($arguments));
        return true;
    }

    /**
     * Tells each plugin listening of something the main agent did.
     *
     * @param array<string, mixed> $event
     */
    public function emit(string $name, array $event): void
    {
        foreach ($this->listeners[$name] ?? [] as $listener) {
            if ($this->plugins[$listener['plugin']]['error'] === null) {
                $this->guarded($listener['plugin'], static fn () => ($listener['listener'])($event));
            }
        }
    }

    /**
     * @internal A plugin's, through its Host.
     * @param \Closure(string): void $handler
     */
    public function register(string $plugin, string $name, string $description, \Closure $handler): void
    {
        $name = strtolower(ltrim($name, '/'));
        if (preg_match('/^[a-z][\w-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("/{$name} is not a command's name.");
        }
        if (isset($this->commands[$name])) {
            throw new \InvalidArgumentException("/{$name} is already {$this->commands[$name]['plugin']}'s.");
        }
        $this->commands[$name] = ['plugin' => $plugin, 'handler' => $handler];
        $this->plugins[$plugin]['commands'][$name] = trim($description);
    }

    /**
     * @internal A plugin's, through its Host.
     * @param \Closure(array<string, mixed>): mixed $handler
     * @param array<string, mixed> $schema
     */
    public function tool(string $plugin, string $name, string $description, \Closure $handler, array $schema): void
    {
        $tool = new PluginTool($plugin, $name, $description, $handler, $schema);
        $called = $tool->definition()->name;
        if (isset($this->tools[$called])) {
            throw new \InvalidArgumentException("{$plugin} has a tool {$name} already.");
        }
        $this->tools[$called] = $tool;
        $this->plugins[$plugin]['tools'][] = $name;
    }

    /**
     * @internal A plugin's, through its Host.
     */
    public function listen(string $plugin, string $event, \Closure $listener): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("There is no event {$event}: one of " . implode(', ', self::EVENTS) . '.');
        }
        $this->listeners[$event][] = ['plugin' => $plugin, 'listener' => $listener];
        if (!in_array($event, $this->plugins[$plugin]['events'], true)) {
            $this->plugins[$plugin]['events'][] = $event;
        }
    }

    /**
     * @internal A plugin's, through its Host.
     */
    public function say(string $text): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text !== '' && $this->note !== null) {
            ($this->note)($text);
        }
    }

    /**
     * @internal A plugin's, through its Host.
     */
    public function send(string $text): void
    {
        if (trim($text) !== '' && $this->ask !== null) {
            ($this->ask)(trim($text));
        }
    }

    private function guarded(string $plugin, \Closure $run): void
    {
        try {
            $run();
        } catch (CancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            $this->disable($plugin, $error->getMessage());
        }
    }

    /**
     * Turns a plugin off until the next load, and says why.
     */
    private function disable(string $plugin, string $message): void
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message)) ?: 'it failed';
        $this->plugins[$plugin]['error'] = $message;
        foreach ($this->plugins[$plugin]['commands'] as $name => $_) {
            unset($this->commands[$name]);
        }
        $this->plugins[$plugin]['commands'] = [];
        $this->tools = array_filter($this->tools, static fn (PluginTool $tool): bool => $tool->plugin !== $plugin);
        $this->plugins[$plugin]['tools'] = [];
        $this->failed[] = [$plugin, $message];
        $this->told();
    }

    /**
     * The closure a plugin.php returns. A file unchanged since it was run is
     * not run again; one changed is, unless it declares a class or a
     * function, which PHP would end Bia over.
     */
    private static function read(string $file): \Closure
    {
        $source = (string) file_get_contents($file);
        $hash = hash('xxh3', $source);
        $known = self::$files[$file] ?? null;
        if ($known === null || $known['hash'] !== $hash) {
            if ($known !== null && self::declares($source)) {
                throw new \RuntimeException('it changed and declares a class or a function: start a new bia to load it');
            }
            self::$files[$file] = ['hash' => $hash, 'plugin' => null];
            if ($known !== null && function_exists('opcache_invalidate')) {
                // A process that stays up never looks at a file's time again: the opcache would run the old one.
                @opcache_invalidate($file, true);
            }
            $plugin = require $file;
            self::$files[$file]['plugin'] = $plugin instanceof \Closure ? $plugin : null;
        }
        return self::$files[$file]['plugin'] ?? throw new \RuntimeException('plugin.php must return a closure');
    }

    /**
     * Whether PHP source declares a named class, interface, trait or enum,
     * or a function of its own: what cannot be declared twice. Closures and
     * anonymous classes, and their methods, can.
     */
    private static function declares(string $source): bool
    {
        try {
            $tokens = \PhpToken::tokenize($source);
        } catch (\Throwable) {
            return false; // it does not parse: running it throws, and declares nothing
        }
        $tokens = array_values(array_filter($tokens, static fn (\PhpToken $token): bool => !$token->isIgnorable()));
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is('}')) {
                $depth--;
            } elseif ($token->is([T_INTERFACE, T_TRAIT, T_ENUM])) {
                return true;
            } elseif ($token->is(T_CLASS)) {
                // `new class`, `new readonly class` and `Name::class` declare no name.
                $before = $index;
                do {
                    $previous = $tokens[--$before] ?? null;
                } while ($previous?->is([T_READONLY, T_FINAL]) === true);
                if ($previous?->is([T_NEW, T_DOUBLE_COLON]) !== true) {
                    return true;
                }
            } elseif ($depth === 0 && $token->is(T_FUNCTION)) {
                $next = $tokens[$index + 1] ?? null;
                if ($next?->is(['&', T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG]) === true) {
                    $next = $tokens[$index + 2] ?? null;
                }
                if ($next?->is(T_STRING) === true) {
                    return true;
                }
            }
        }
        return false;
    }
}
