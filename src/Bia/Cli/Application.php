<?php

declare(strict_types=1);

namespace Bia\Cli;

use Bia\Agent\Agent;
use Bia\Agent\Compactor;
use Bia\Agent\SystemPrompt;
use Bia\Config\Config;
use Bia\Contracts\Renderer;
use Bia\Model\ModelCatalog;
use Bia\Model\OpenAiCompatibleModel;
use Bia\Session\SqliteStore;
use Bia\Terminal\AnsiRenderer;
use Bia\Terminal\Chat;
use Bia\Terminal\Files;
use Bia\Terminal\History;
use Bia\Terminal\Loop;
use Bia\Terminal\Prompt;
use Bia\Terminal\TuiRenderer;
use Bia\Tool\ApplyPatchTool;
use Bia\Tool\EditTool;
use Bia\Tool\GitDiffTool;
use Bia\Tool\ListFilesTool;
use Bia\Tool\ProcessRunner;
use Bia\Tool\ReadFileTool;
use Bia\Tool\Registry;
use Bia\Tool\SearchTool;
use Bia\Tool\ShellTool;
use Bia\Tool\Workspace;
use Bia\Tool\WriteFileTool;
use Bia\Tui\Region;
use Bia\Tui\Style;
use Bia\Tui\Terminal;
use Crab\Engine\Async\CancelledException;
use Crab\HttpClient\Client;

/**
 * The small top-level command runner. It intentionally avoids Crab's web App.
 */
final class Application
{
    public const VERSION = '0.1.0-beta.2';

    /**
     * @param list<string> $arguments
     */
    public static function run(array $arguments): int
    {
        try {
            [$options, $words] = self::options($arguments);
            if ($options['version'] ?? false) {
                fwrite(STDOUT, self::VERSION . PHP_EOL);
                return 0;
            }
            if (($options['help'] ?? false) || (($words[0] ?? '') === 'help')) {
                self::help();
                return 0;
            }
            $command = $words[0] ?? '';
            if ($command === 'doctor') {
                return self::doctor();
            }
            if ($command === 'config') {
                return self::config($options);
            }
            if ($command === 'sessions' || $command === 'session:list') {
                return self::sessions($options);
            }
            if ($command === 'export') {
                array_shift($words);
                return self::exportSession($words);
            }
            if ($command === 'fork') {
                array_shift($words);
                return self::fork($words);
            }
            if ($command === 'init') {
                return ($options['global'] ?? false) === true ? self::initGlobal() : self::init();
            }
            if ($command === 'resume') {
                array_shift($words);
                $id = array_shift($words) ?? throw new \InvalidArgumentException('resume needs a session id.');
                $options['resume'] = $id;
            } elseif ($command === 'ask') {
                array_shift($words);
            }
            return self::ask($words, $options);
        } catch (\Throwable $error) {
            fwrite(STDERR, 'bia: ' . $error->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private static function help(): void
    {
        fwrite(STDOUT, <<<'TEXT'
            Bia - a fast coding agent on the Crab engine

            Usage:
              bia [options] [prompt]
              bia ask [options] <prompt>
              bia resume <session-id> [prompt]
              bia doctor
              bia config [--json]
              bia sessions
              bia fork <session-id>
              bia export <session-id>
              bia init [--global]

            Options:
              --model NAME       Select the model
              --base-url URL     Select an OpenAI-compatible API base URL
              --api-key KEY      Supply the API key for this invocation
              --resume ID        Continue an existing session
              --yes              Approve tools whose permission is "ask"
              --json             Emit JSONL events
              --version          Show the Bia version
              --help             Show this help

            Tools run without asking by default. Set permissions to "ask" or
            "deny" in ~/.config/bia/config.php or .bia/config.json to change it.

            Configuration:
              ~/.config/bia/config.php   (yours: any setting)
              .bia/config.json           (the project's: model.name, prompt.system,
                                          permissions, tools, compaction)

            Providers: --model anthropic/NAME, openai/NAME or cliproxyapi/NAME
            uses a built-in provider; add your own under "providers" in
            ~/.config/bia/config.php (bia init --global shows how).

            State:
              ~/.local/state/bia/bia.sqlite
            TEXT . PHP_EOL);
    }

    private static function doctor(): int
    {
        $config = Config::load();
        $required = [
            'PHP 8.4+' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'mbstring' => extension_loaded('mbstring'),
        ];
        foreach ($required as $name => $ok) {
            fwrite(STDOUT, sprintf("%-18s %s\n", $name, $ok ? 'ok' : 'missing'));
        }
        fwrite(STDOUT, sprintf("%-18s %s\n", 'readline', extension_loaded('readline') ? 'ok' : 'fallback'));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'git', self::which('git') ? 'ok' : 'missing (apply_patch, git_diff)'));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'ripgrep', self::which('rg') ? 'ok' : 'missing (search; list_files falls back to PHP)'));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'process groups', function_exists('posix_kill') && self::which('setsid') ? 'ok' : 'off (background children may outlive a command)'));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'permissions', json_encode($config->get('permissions'))));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'providers', implode(', ', array_keys((array) $config->get('providers', [])))));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'model', (string) $config->get('model.name')));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'model provider', (string) $config->get('model.provider')));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'model base URL', (string) $config->get('model.base_url')));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'API key', self::hasKey($config) ? 'configured' : 'missing'));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'database', $config->databasePath()));
        $instructions = SystemPrompt::files(realpath(getcwd()) ?: getcwd(), $config->configDirectory());
        fwrite(STDOUT, sprintf("%-18s %s\n", 'instructions', $instructions === [] ? 'none' : implode(', ', $instructions)));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'context window', $config->get('model.context_window') . ' tokens' . ($config->get('compaction.enabled') ? ', compaction on' : ', compaction off')));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'config sources', $config->sources() === [] ? 'defaults' : implode(', ', $config->sources())) . PHP_EOL);
        foreach ($required as $ok) {
            if (!$ok) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private static function config(array $options): int
    {
        $config = Config::load();
        $values = self::redact($config->all());
        $flags = ($options['json'] ?? false) ? JSON_UNESCAPED_SLASHES : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        fwrite(STDOUT, json_encode($values, $flags) . PHP_EOL);
        return 0;
    }

    private static function init(): int
    {
        $directory = getcwd() . '/.bia';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
        $path = $directory . '/config.json';
        if (is_file($path)) {
            fwrite(STDOUT, "Bia config already exists at {$path}" . PHP_EOL);
            return 0;
        }
        file_put_contents($path, json_encode([
            'prompt' => ['system' => 'You are Bia, a fast coding assistant. Be precise and concise.'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fwrite(STDOUT, "Created {$path}" . PHP_EOL);
        return 0;
    }

    /**
     * Writes ~/.config/bia/config.php with every setting commented out at its
     * default. A file with settings in it is left alone; a blank one is filled.
     */
    private static function initGlobal(): int
    {
        $path = Config::globalPath();
        if (is_file($path) && trim((string) file_get_contents($path)) !== '') {
            fwrite(STDOUT, "Your Bia config already exists at {$path}; it was left as it is." . PHP_EOL);
            return 0;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
        // It may come to hold an API key: readable by you alone.
        $previous = umask(0077);
        try {
            if (file_put_contents($path, Config::template()) === false) {
                throw new \RuntimeException("Cannot write {$path}.");
            }
        } finally {
            umask($previous);
        }
        chmod($path, 0600);
        fwrite(STDOUT, "Created {$path}" . PHP_EOL);
        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private static function sessions(array $options): int
    {
        $config = Config::load();
        $sessions = (new SqliteStore($config->databasePath()))->list();
        if (($options['json'] ?? false) === true) {
            fwrite(STDOUT, json_encode($sessions, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return 0;
        }
        foreach ($sessions as $session) {
            fwrite(STDOUT, sprintf(
                "%s  %s  %s entries  %s\n",
                $session['id'],
                $session['model'],
                $session['entry_count'],
                $session['updated_at'],
            ));
        }
        return 0;
    }

    /**
     * @param list<string> $words
     */
    private static function exportSession(array $words): int
    {
        $id = $words[0] ?? '';
        if ($id === '') {
            throw new \InvalidArgumentException('export needs a session id.');
        }
        $config = Config::load();
        $store = new SqliteStore($config->databasePath());
        if (!$store->exists($id)) {
            throw new \RuntimeException("Session {$id} does not exist.");
        }
        foreach ($store->path($id) as $entry) {
            fwrite(STDOUT, json_encode([
                'id' => $entry->id,
                'parent_id' => $entry->parentId,
                'type' => $entry->payload instanceof \Bia\Contracts\Message ? 'message' : 'compaction',
                'created_at' => $entry->createdAt,
                'data' => $entry->payload->toArray(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        }
        return 0;
    }

    /**
     * Starts a new session that continues from another's latest entry; the
     * original is left as it was.
     *
     * @param list<string> $words
     */
    private static function fork(array $words): int
    {
        $id = $words[0] ?? throw new \InvalidArgumentException('fork needs a session id.');
        $config = Config::load();
        $store = new SqliteStore($config->databasePath());
        if (!$store->exists($id)) {
            throw new \RuntimeException("Session {$id} does not exist.");
        }
        $fork = $store->create(realpath(getcwd()) ?: getcwd(), (string) $config->get('model.name'), fromEntry: $store->head($id));
        fwrite(STDOUT, $fork . PHP_EOL);
        return 0;
    }

    /**
     * @param list<string> $words
     * @param array<string, string|bool> $options
     */
    private static function ask(array $words, array $options): int
    {
        $overrides = [];
        foreach (['model' => 'name', 'base-url' => 'base_url', 'api-key' => 'api_key'] as $option => $key) {
            if (isset($options[$option])) {
                $overrides['model'][$key] = $options[$option];
            }
        }
        $config = Config::load(overrides: $overrides);
        $apiKey = (string) ($config->get('model.api_key') ?? '');
        if ($apiKey === '') {
            throw new \RuntimeException($config->keyHelp());
        }
        $store = new SqliteStore($config->databasePath());
        $sessionId = isset($options['resume']) ? (string) $options['resume'] : null;
        if ($sessionId !== null && !$store->exists($sessionId)) {
            throw new \RuntimeException("Session {$sessionId} does not exist.");
        }
        // Whether someone is there to answer is not whether a prompt was given:
        // `bia "fix it"` in a terminal can still be asked about a tool.
        $canPrompt = self::isTty(STDIN);
        $repl = $words === [] && $canPrompt;
        if ($words === [] && !$repl) {
            throw new \RuntimeException('Give Bia a prompt, or run it from an interactive terminal.');
        }
        $json = (bool) ($options['json'] ?? false);
        $root = realpath(getcwd()) ?: getcwd();
        $sessionId ??= $store->create($root, $config->modelLabel());
        $workspace = new Workspace($root);
        $runner = new ProcessRunner([$apiKey]);
        $outputs = $config->cachePath() . '/outputs';
        $system = SystemPrompt::build((string) $config->get('prompt.system', ''), $workspace->root(), $config->configDirectory());
        $http = new Client();
        $loop = new Loop();

        if ($repl && !$json && Terminal::interactive()) {
            $terminal = new Terminal();
            $region = new Region($terminal);
            $renderer = new TuiRenderer($region);
            $chat = null;
            $ask = static function (string $question) use (&$chat): bool {
                return $chat instanceof Chat && $chat->confirm($question);
            };
            $tools = self::tools($config, $options, $ask, true, $workspace, $runner, $outputs);
            $makeAgent = static fn (Config $config): Agent => self::agent($config, $http, $tools, $store, $renderer, $workspace, $outputs);
            $chat = new Chat(
                $terminal,
                $region,
                $renderer,
                $config,
                $makeAgent,
                $store,
                $sessionId,
                $system,
                $workspace->root(),
                new Files($runner, $workspace->root()),
                new ModelCatalog($http),
                new History(dirname($config->databasePath()) . '/history'),
                self::VERSION,
            );
            $loop->execute($chat->run(...));
            return 0;
        }

        if ($json) {
            fwrite(STDOUT, json_encode(['type' => 'session', 'id' => $sessionId]) . PHP_EOL);
        } else {
            fwrite(STDERR, Style::dim("session: {$sessionId} · " . $config->modelLabel()) . PHP_EOL);
        }
        $prompt = new Prompt($canPrompt);
        $ask = static function (string $question) use ($prompt): bool {
            return in_array(strtolower(trim((string) $prompt->ask($question . ' [y/N] '))), ['y', 'yes'], true);
        };
        $tools = self::tools($config, $options, $ask, $canPrompt, $workspace, $runner, $outputs);
        $agent = self::agent($config, $http, $tools, $store, new AnsiRenderer($json), $workspace, $outputs);
        $status = 0;
        do {
            $question = $words === [] ? $prompt->ask('> ') : implode(' ', $words);
            $words = [];
            if ($question === null) {
                break;
            }
            $question = trim($question);
            if (in_array(strtolower($question), ['/exit', '/quit'], true)) {
                break;
            }
            if ($question === '') {
                continue;
            }
            if (strtolower($question) === '/help') {
                fwrite(STDERR, "/compact  summarize the conversation so far\n/exit     leave (Ctrl-D works too)\nCtrl-C cancels a running turn; twice quits.\n");
                continue;
            }
            $command = strtolower($question) === '/compact'
                ? static fn ($cancellation) => $agent->compact($sessionId, $cancellation)
                : static fn ($cancellation) => $agent->run($sessionId, $question, $system, $cancellation);
            try {
                $loop->execute($command);
                $status = 0;
            } catch (CancelledException) {
                fwrite(STDERR, PHP_EOL . 'bia: cancelled' . PHP_EOL);
                $status = 130;
            } catch (\Throwable $error) {
                // One failed turn (a 429, a dropped connection) does not end the conversation.
                fwrite(STDERR, PHP_EOL . 'bia: ' . $error->getMessage() . PHP_EOL);
                $status = 1;
            }
        } while ($repl);
        // A conversation left on purpose ended well; a one-shot run reports its turn.
        return $repl ? 0 : $status;
    }

    /**
     * @param array<string, string|bool> $options
     * @param \Closure(string): bool $ask Asks the user a yes/no question.
     */
    private static function tools(Config $config, array $options, \Closure $ask, bool $canPrompt, Workspace $workspace, ProcessRunner $runner, string $outputs): Registry
    {
        return (new Registry(self::approval($config, $options, $ask, $canPrompt)))
            ->add(new ReadFileTool($workspace, outputs: $outputs))
            ->add(new ListFilesTool($workspace, $runner))
            ->add(new SearchTool($workspace, $runner))
            ->add(new EditTool($workspace))
            ->add(new WriteFileTool($workspace))
            ->add(new ApplyPatchTool($workspace, $runner))
            ->add(new ShellTool($workspace, $runner, (float) $config->get('tools.shell_timeout')))
            ->add(new GitDiffTool($workspace, $runner));
    }

    /**
     * The agent for the configured model; /model builds another.
     */
    private static function agent(Config $config, Client $http, Registry $tools, SqliteStore $store, Renderer $renderer, Workspace $workspace, string $outputs): Agent
    {
        $modelName = (string) $config->get('model.name');
        $model = new OpenAiCompatibleModel(
            $http,
            (string) $config->get('model.base_url'),
            (string) $config->get('model.api_key'),
            $modelName,
            (string) $config->get('model.endpoint', 'chat/completions'),
            (float) $config->get('model.timeout'),
            (float) $config->get('model.idle_timeout'),
        );
        $compactor = $config->get('compaction.enabled') === false ? null : new Compactor(
            $model,
            $modelName,
            (int) $config->get('model.context_window'),
            (int) $config->get('compaction.reserve_tokens'),
            (int) $config->get('compaction.keep_tokens'),
        );
        return new Agent(
            $model,
            $modelName,
            $tools,
            $store,
            $renderer,
            $workspace->root(),
            $compactor,
            maxOutputBytes: (int) $config->get('tools.output_bytes'),
            maxOutputLines: (int) $config->get('tools.output_lines'),
            outputs: $outputs,
        );
    }

    /**
     * The approval asked before a tool runs, or null when every permission is
     * "auto", Bia's default, so a call costs nothing extra.
     *
     * @param array<string, string|bool> $options
     */
    private static function approval(Config $config, array $options, \Closure $ask, bool $canPrompt): ?\Closure
    {
        $policies = (array) $config->get('permissions', []);
        if (array_filter($policies, static fn (mixed $policy): bool => $policy !== 'auto') === []) {
            return null;
        }
        $yes = ($options['yes'] ?? false) === true;
        return static function (\Bia\Contracts\ToolDefinition $definition, array $arguments) use ($policies, $yes, $ask, $canPrompt): bool {
            $policy = (string) ($policies[$definition->permission] ?? 'auto');
            if ($policy === 'auto') {
                return true;
            }
            if ($policy === 'deny') {
                return false;
            }
            if ($yes) {
                return true;
            }
            if (!$canPrompt) {
                return false;
            }
            $encoded = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return $ask("Allow {$definition->name} ({$encoded})?");
        };
    }

    /**
     * @param list<string> $arguments
     * @return array{0: array<string, string|bool>, 1: list<string>}
     */
    private static function options(array $arguments): array
    {
        $options = [];
        $words = [];
        $optionNames = ['model', 'base-url', 'api-key', 'resume'];
        $after = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($after || $argument === null || !str_starts_with($argument, '--')) {
                $words[] = (string) $argument;
                continue;
            }
            if ($argument === '--') {
                $after = true;
                continue;
            }
            if ($argument === '--help') {
                $options['help'] = true;
                continue;
            }
            if ($argument === '--version') {
                $options['version'] = true;
                continue;
            }
            if ($argument === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($argument === '--yes') {
                $options['yes'] = true;
                continue;
            }
            if ($argument === '--global') {
                $options['global'] = true;
                continue;
            }
            [$name, $value] = str_contains($argument, '=')
                ? explode('=', substr($argument, 2), 2)
                : [substr($argument, 2), null];
            if (!in_array($name, $optionNames, true)) {
                throw new \InvalidArgumentException("Unknown option --{$name}.");
            }
            if ($value === null) {
                $value = array_shift($arguments);
                if ($value === null || str_starts_with($value, '--')) {
                    throw new \InvalidArgumentException("Option --{$name} needs a value.");
                }
            }
            $options[$name] = $value;
        }
        return [$options, $words];
    }

    private static function which(string $program): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable($directory . '/' . $program)) {
                return true;
            }
        }
        return false;
    }

    private static function hasKey(Config $config): bool
    {
        return (string) ($config->get('model.api_key') ?? '') !== '';
    }

    private static function isTty(mixed $stream): bool
    {
        return function_exists('stream_isatty') && stream_isatty($stream);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_string($key) && preg_match('/(^|_)(api_?key|key|secret|password|token)$/i', $key) === 1) {
                $values[$key] = $value === null || $value === '' ? $value : '[configured]';
            }
        }
        return $values;
    }
}
