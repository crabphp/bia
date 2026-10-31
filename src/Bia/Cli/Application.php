<?php

declare(strict_types=1);

namespace Bia\Cli;

use Bia\Agent\Agent;
use Bia\Config\Config;
use Bia\Model\OpenAiCompatibleModel;
use Bia\Session\SqliteStore;
use Bia\Terminal\AnsiRenderer;
use Bia\Terminal\Loop;
use Bia\Terminal\Prompt;
use Bia\Tool\ApplyPatchTool;
use Bia\Tool\GitDiffTool;
use Bia\Tool\ListFilesTool;
use Bia\Tool\ProcessRunner;
use Bia\Tool\ReadFileTool;
use Bia\Tool\Registry;
use Bia\Tool\SearchTool;
use Bia\Tool\ShellTool;
use Bia\Tool\Workspace;
use Bia\Tool\WriteFileTool;
use Crab\Engine\Async\CancelledException;
use Crab\HttpClient\Client;

/**
 * The small top-level command runner. It intentionally avoids Crab's web App.
 */
final class Application
{
    public const VERSION = '0.1.0-dev';

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
            if ($command === 'init') {
                return self::init();
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
              bia export <session-id>
              bia init

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
                                          permissions, tools)

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
        fwrite(STDOUT, sprintf("%-18s %s\n", 'model provider', (string) $config->get('model.provider')));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'model base URL', (string) $config->get('model.base_url')));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'API key', self::hasKey($config) ? 'configured' : 'missing'));
        fwrite(STDOUT, sprintf("%-18s %s\n", 'database', $config->databasePath()));
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
                "%s  %s  %s messages  %s\n",
                $session['id'],
                $session['model'],
                $session['message_count'],
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
        foreach ($store->messages($id) as $message) {
            fwrite(STDOUT, json_encode([
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
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
            throw new \RuntimeException('No API key is configured. Set BIA_API_KEY or add model.api_key to ~/.config/bia/config.php.');
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
        $modelName = (string) $config->get('model.name');
        $sessionId ??= $store->create(realpath(getcwd()) ?: getcwd(), $modelName);

        $model = new OpenAiCompatibleModel(
            new Client(),
            (string) $config->get('model.base_url'),
            $apiKey,
            $modelName,
            (string) $config->get('model.endpoint', 'chat/completions'),
            (float) $config->get('model.timeout'),
            (float) $config->get('model.idle_timeout'),
        );
        $loop = new Loop();
        $json = (bool) ($options['json'] ?? false);
        if ($json) {
            fwrite(STDOUT, json_encode(['type' => 'session', 'id' => $sessionId]) . PHP_EOL);
        } else {
            fwrite(STDERR, "session: {$sessionId}" . PHP_EOL);
        }
        $prompt = new Prompt($canPrompt);
        $workspace = new Workspace(realpath(getcwd()) ?: getcwd());
        $runner = new ProcessRunner([$apiKey]);
        $tools = (new Registry(self::approval($config, $options, $prompt, $canPrompt)))
            ->add(new ReadFileTool($workspace))
            ->add(new ListFilesTool($workspace, $runner))
            ->add(new SearchTool($workspace, $runner))
            ->add(new WriteFileTool($workspace))
            ->add(new ApplyPatchTool($workspace, $runner))
            ->add(new ShellTool($workspace, $runner, (float) $config->get('tools.shell_timeout')))
            ->add(new GitDiffTool($workspace, $runner));
        $renderer = new AnsiRenderer($json);
        $agent = new Agent(
            $model,
            $modelName,
            $tools,
            $store,
            $renderer,
            $workspace->root(),
            maxStoredToolBytes: (int) $config->get('tools.output_bytes'),
        );
        $system = (string) $config->get('prompt.system', '');
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
            try {
                $loop->execute(static fn ($cancellation) => $agent->run($sessionId, $question, $system, $cancellation));
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
        return $status;
    }

    /**
     * The approval asked before a tool runs, or null when every permission is
     * "auto", Bia's default, so a call costs nothing extra.
     *
     * @param array<string, string|bool> $options
     */
    private static function approval(Config $config, array $options, Prompt $prompt, bool $canPrompt): ?\Closure
    {
        $policies = (array) $config->get('permissions', []);
        if (array_filter($policies, static fn (mixed $policy): bool => $policy !== 'auto') === []) {
            return null;
        }
        $yes = ($options['yes'] ?? false) === true;
        return static function (\Bia\Contracts\ToolDefinition $definition, array $arguments) use ($policies, $yes, $prompt, $canPrompt): bool {
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
            $answer = $prompt->ask("Allow {$definition->name} ({$encoded})? [y/N] ");
            return in_array(strtolower(trim((string) $answer)), ['y', 'yes'], true);
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
            } elseif (is_string($key) && (str_contains(strtolower($key), 'key') || str_contains(strtolower($key), 'secret') || str_contains(strtolower($key), 'token'))) {
                $values[$key] = $value === null || $value === '' ? $value : '[configured]';
            }
        }
        return $values;
    }
}
