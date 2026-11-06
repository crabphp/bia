<?php

declare(strict_types=1);

namespace Bia\Cli;

use Bia\Agent\Agent;
use Bia\Agent\Inspection;
use Bia\Agent\AgentTool;
use Bia\Agent\Subagents;
use Bia\Session\MemoryStore;
use Bia\Terminal\Watched;
use Bia\Tui\Transcript;
use Bia\Agent\Compactor;
use Bia\Agent\Recap;
use Bia\Agent\SystemPrompt;
use Bia\Config\Config;
use Bia\Contracts\Renderer;
use Bia\Mcp\AuthStore;
use Bia\Mcp\Manager;
use Bia\Mcp\Server;
use Bia\Mcp\Trust;
use Bia\Contracts\Model;
use Bia\Model\Fallback;
use Bia\Model\Lenient;
use Bia\Model\Metered;
use Bia\Model\ModelCatalog;
use Bia\Model\OpenAiCompatibleModel;
use Bia\Session\SqliteStore;
use Bia\Skill\Catalog;
use Bia\Skill\SkillTool;
use Bia\Terminal\AnsiRenderer;
use Bia\Terminal\Chat;
use Bia\Terminal\Files;
use Bia\Terminal\History;
use Bia\Terminal\Loop;
use Bia\Terminal\Prompt;
use Bia\Terminal\TuiRenderer;
use Bia\Terminal\UsageReport;
use Bia\Tool\ApplyPatchTool;
use Bia\Tool\EditTool;
use Bia\Tool\AskTool;
use Bia\Tool\GitDiffTool;
use Bia\Tool\ListFilesTool;
use Bia\Tool\ProcessRunner;
use Bia\Tool\ReadFileTool;
use Bia\Tool\Registry;
use Bia\Tool\SearchTool;
use Bia\Tool\ShellOutputTool;
use Bia\Tool\ShellTool;
use Bia\Tool\Shells;
use Bia\Tool\Workspace;
use Bia\Tool\WriteFileTool;
use Bia\Tui\Region;
use Bia\Tui\Screen;
use Bia\Tui\Style;
use Bia\Tui\Terminal;
use Bia\Update\Check;
use Bia\Update\Installed;
use Bia\Update\Releases;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\HttpClient\Client;

/**
 * The small top-level command runner. It intentionally avoids Crab's web App.
 */
final class Application
{
    public const VERSION = '0.1.0';

    /**
     * @param list<string> $arguments
     */
    public static function run(array $arguments): int
    {
        try {
            Installed::guard();
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
            if ($command === 'update') {
                return self::update();
            }
            if ($command === 'config') {
                return self::config($options);
            }
            if ($command === 'sessions' || $command === 'session:list') {
                return self::sessions($options);
            }
            if ($command === 'usage') {
                return self::usage($options);
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
                // Without an id, a conversation opens on the session picker.
                $options['resume'] = array_shift($words) ?? '';
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
              bia resume [session-id] [prompt]
              bia doctor
              bia config [--json]
              bia sessions
              bia usage [--json]
              bia fork <session-id>
              bia export <session-id>
              bia init [--global]
              bia update

            Options:
              --model NAME       Select the model
              --base-url URL     Select an OpenAI-compatible API base URL
              --api-key KEY      Supply the API key for this invocation
              --effort LEVEL     Reasoning effort: none, minimal, low, medium, high, xhigh, max
              --resume ID        Continue an existing session
              --yes              Approve tools whose permission is "ask"
              --json             Emit JSONL events
              --version          Show the Bia version
              --help             Show this help

            Tools run without asking by default. Set permissions to "ask" or
            "deny" in ~/.bia/config.php or .agents/bia.json to change it.

            Configuration:
              ~/.bia/config.php          (yours: any setting)
              .agents/bia.json           (the project's: model.name, prompt.system,
                                          permissions, tools, compaction)

            Providers: --model anthropic/NAME, openai/NAME or cliproxyapi/NAME
            uses a built-in provider; add your own under "providers" in
            ~/.bia/config.php (bia init --global shows how).

            Files:
              ~/.bia, or $BIA_HOME     (config.php, AGENTS.md, mcp.json,
                                        bia.sqlite, cache/)
            TEXT . PHP_EOL);
    }

    private static function doctor(): int
    {
        Style::enable(self::isTty(STDOUT) && getenv('NO_COLOR') === false);
        $http = new Client();
        $root = realpath((string) getcwd()) ?: (string) getcwd();
        return (new Doctor(
            static fn (Config $config): Model => self::model($config, $http),
            static fn (Config $config): array => self::mcp($config, new ProcessRunner($config->secrets()), $http, $root),
        ))->run();
    }

    /**
     * `bia update`: a release's Bia, the binary or bia.phar, replaced by the
     * latest release's, downloaded, checked against its SHA256SUMS and run
     * once before it takes this one's place.
     */
    private static function update(): int
    {
        $file = Installed::file() ?? throw new \RuntimeException('This Bia runs from source: update it with git pull and composer install.');
        $asset = Installed::asset() ?? throw new \RuntimeException('No Bia binary is built for ' . PHP_OS_FAMILY . ' ' . php_uname('m') . '; bia.phar runs on a PHP 8.4 of your own: ' . Releases::REPOSITORY . '/releases');
        if (!is_writable(dirname($file))) {
            throw new \RuntimeException('Bia cannot write in ' . dirname($file) . ': run sudo bia update, or install Bia where you can write.');
        }
        $releases = new Releases(new Client(redirects: 0), new ProcessRunner(), self::VERSION);
        return Loop::run(static function (Cancellation $cancellation) use ($releases, $file, $asset): int {
            try {
                $latest = $releases->latest($cancellation);
            } catch (CancelledException $cancelled) {
                throw $cancelled;
            } catch (\Throwable $error) {
                throw new \RuntimeException('GitHub could not be asked for the latest Bia: ' . preg_replace('#^HEAD \S+ failed:\s*#', '', $error->getMessage()));
            }
            if (!Releases::newer($latest, self::VERSION)) {
                fwrite(STDOUT, 'Bia ' . self::VERSION . ' is up to date' . PHP_EOL);
                return 0;
            }
            fwrite(STDOUT, 'Updating Bia ' . self::VERSION . " to {$latest}…" . PHP_EOL);
            $new = $releases->download($latest, $asset, $file, Installed::binary() ? null : PHP_BINARY, $cancellation);
            if (!@rename($new, $file)) {
                @unlink($new);
                throw new \RuntimeException("Bia could not replace {$file}.");
            }
            // From here this process loads nothing more from its file, which is the new Bia's now.
            fwrite(STDOUT, "Bia {$latest} is installed: {$file}" . PHP_EOL . 'What changed: ' . Releases::REPOSITORY . "/releases/tag/v{$latest}" . PHP_EOL);
            return 0;
        });
    }

    /**
     * What a conversation runs in the background, once its first screen is
     * drawn, to say a newer Bia is out: for a release's Bia only, as a
     * checkout is updated with git, and unless update.check is off. Its
     * classes load when it runs, not at the start.
     *
     * @return (\Closure(Cancellation): ?string)|null
     */
    private static function updates(Config $config): ?\Closure
    {
        if ($config->get('update.check') === false || Installed::file() === null || Installed::asset() === null) {
            return null;
        }
        $file = $config->cachePath() . '/update.json';
        return static function (Cancellation $cancellation) use ($file): ?string {
            $check = new Check(
                new Releases(new Client(redirects: 0), new ProcessRunner(), self::VERSION),
                $file,
                self::VERSION,
                static function (?Cancellation $cancellation): void {
                    // Found over UDP only: the system's resolver, the fallback, would hold the whole conversation.
                    (new DnsResolver(fallback: false))->resolve(EventLoop::current(), Releases::HOST, $cancellation);
                },
            );
            return $check->newer($cancellation);
        };
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
        $directory = getcwd() . '/.agents';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
        $path = $directory . '/bia.json';
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
     * Writes ~/.bia/config.php with every setting commented out at its
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
    /**
     * @param array<string, string|bool> $options
     */
    private static function usage(array $options): int
    {
        $store = new SqliteStore(Config::load()->databasePath());
        if (($options['json'] ?? false) === true) {
            $since = microtime(true) - UsageReport::SPAN;
            fwrite(STDOUT, json_encode(['days' => $store->usage($since, 'day'), 'models' => $store->usage($since, 'model'), 'providers' => $store->usage($since, 'provider')], JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return 0;
        }
        Style::enable(self::isTty(STDOUT) && getenv('NO_COLOR') === false);
        fwrite(STDOUT, implode(PHP_EOL, UsageReport::lines($store)) . PHP_EOL);
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
            if ($session['entry_count'] === 0) {
                continue;
            }
            $title = trim((string) preg_replace('/\s+/', ' ', (string) ($session['title'] ?? '')));
            fwrite(STDOUT, sprintf(
                "%s  %s  %3d entries  %s  %s\n",
                $session['id'],
                substr((string) $session['updated_at'], 0, 16),
                $session['entry_count'],
                $session['model'],
                mb_strimwidth($title, 0, 60, '…', 'UTF-8'),
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
        $id = $store->resolve($id) ?? throw new \RuntimeException("No single session starts with {$id}; bia sessions lists them.");
        foreach ($store->path($id) as $entry) {
            fwrite(STDOUT, json_encode([
                'id' => $entry->id,
                'parent_id' => $entry->parentId,
                'type' => match (true) {
                    $entry->payload instanceof \Bia\Contracts\Message => 'message',
                    $entry->payload instanceof \Bia\Contracts\Pruning => 'pruning',
                    default => 'compaction',
                },
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
        $id = $store->resolve($id) ?? throw new \RuntimeException("No single session starts with {$id}; bia sessions lists them.");
        $fork = $store->create(realpath(getcwd()) ?: getcwd(), $config->modelLabel(), fromEntry: $store->head($id));
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
        foreach (['model' => 'name', 'base-url' => 'base_url', 'api-key' => 'api_key', 'effort' => 'reasoning_effort'] as $option => $key) {
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
        $resume = isset($options['resume']) ? (string) $options['resume'] : null;
        $sessionId = null;
        if ($resume !== null && $resume !== '') {
            $sessionId = $store->resolve($resume) ?? throw new \RuntimeException("No single session starts with {$resume}; bia sessions lists them.");
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
        $workspace = new Workspace($root);
        $runner = new ProcessRunner($config->secrets());
        $outputs = $config->cachePath() . '/outputs';
        $http = new Client();
        $loop = new Loop();
        // Each request to a model, counted for /usage: counts and times only.
        $record = static fn (array $request) => $store->record($request);

        if ($repl && !$json && Terminal::interactive()) {
            $terminal = new Terminal();
            $region = $config->get('tui.fullscreen') === false ? new Region($terminal) : new Screen($terminal);
            $renderer = new TuiRenderer($region);
            $renderer->mode((string) $config->get('tui.render', 'normal'));
            $chat = null;
            $ask = static function (string $question) use (&$chat): bool {
                return $chat instanceof Chat && $chat->confirm($question);
            };
            $choose = static function (string $question, array $choices) use (&$chat): string|false|null {
                return $chat instanceof Chat ? $chat->choose($question, $choices) : null;
            };
            $shells = new Shells($runner);
            $inspection = new Inspection();
            $reads = null;
            $subagents = new Subagents(
                static function (string $name, \Closure $doing, \Closure $called) use (&$chat, &$reads, $config, $terminal, $region, $http, $workspace, $outputs, $record): array {
                    // Fullscreen, a page of its own, hidden until viewed; inline, its run kept to print.
                    $surface = $region instanceof Screen ? new Screen($terminal) : new Transcript($terminal);
                    if ($surface instanceof Screen) {
                        $surface->show(false);
                    }
                    $view = new TuiRenderer($surface);
                    $store = new MemoryStore();
                    $current = $chat instanceof Chat ? $chat->config() : $config;
                    $session = $store->create($workspace->root(), $current->modelLabel());
                    $agent = self::agent($current, $http, $reads, $store, new Watched($view, $doing, $called), $workspace, $outputs, record: $record);
                    return [$agent, $session, ['surface' => $surface, 'renderer' => $view]];
                },
                static function () use (&$chat): void {
                    if ($chat instanceof Chat) {
                        $chat->agentsChanged();
                    }
                },
                '',
                static function (mixed $view, string $task): void {
                    if (is_array($view)) {
                        Chat::echoOn($view['surface'], $task);
                    }
                },
            );
            // What the config and instructions make: built at the start, and again by /reload,
            // which keeps the process, the session, the screen and the background commands.
            $setup = static function (Config $config) use (&$reads, $options, $ask, $choose, $workspace, $outputs, $shells, $subagents, $http, $store, $renderer, $inspection, $record): array {
                $runner = new ProcessRunner($config->secrets());
                $system = SystemPrompt::build((string) $config->get('prompt.system', ''), $workspace->root(), $config->configDirectory());
                $mcp = self::mcp($config, $runner, $http, $workspace->root());
                $shells->use($runner);
                $tools = self::tools($config, $options, $ask, true, $workspace, $runner, $outputs, $shells, $choose);
                if ($mcp['manager'] !== null) {
                    $tools->from($mcp['manager']->tools(...));
                }
                $tools->add(new AgentTool($subagents));
                // Subagents only read: what they find goes back to the main agent, which does the work.
                $reads = (new Registry())
                    ->add(new ReadFileTool($workspace, outputs: $outputs))
                    ->add(new ListFilesTool($workspace, $runner))
                    ->add(new SearchTool($workspace, $runner))
                    ->add(new GitDiffTool($workspace, $runner));
                $subagents->system($system);
                return [
                    'system' => $system,
                    'mcp' => $mcp,
                    'agent' => static fn (Config $config): Agent => self::agent($config, $http, $tools, $store, $renderer, $workspace, $outputs, $inspection, $record),
                ];
            };
            $built = $setup($config);
            $chat = new Chat(
                $terminal,
                $region,
                $renderer,
                $config,
                $built['agent'],
                $store,
                $sessionId,
                $built['system'],
                $workspace->root(),
                new Files($runner, $workspace->root()),
                new ModelCatalog($http),
                new History(dirname($config->databasePath()) . '/history'),
                self::VERSION,
                pickSession: $resume === '',
                mcp: $built['mcp'],
                skills: Catalog::for((string) getenv('HOME'), $workspace->root()),
                images: new \Bia\Terminal\Images($runner, dirname($config->databasePath()) . '/images', $workspace->root()),
                inspection: $inspection,
                subagents: $subagents,
                update: self::updates($config),
                reload: static function (Config $current) use ($overrides, $setup): array {
                    // Read again as at the start, on the model and effort chosen since.
                    $config = Config::load(overrides: $overrides);
                    try {
                        $config = $config->withModel($current->modelLabel());
                    } catch (\Throwable) {
                        // That model is no longer configured: the config's own is used.
                    }
                    $config = $config->withEffort((string) $current->get('model.reasoning_effort', 'default'));
                    return ['config' => $config, ...$setup($config)];
                },
            );
            $loop->execute($chat->run(...));
            return 0;
        }
        $system = SystemPrompt::build((string) $config->get('prompt.system', ''), $workspace->root(), $config->configDirectory());
        $mcp = self::mcp($config, $runner, $http, $workspace->root());
        if ($resume === '') {
            throw new \RuntimeException('Give bia resume a session id; bia sessions lists them.');
        }
        $sessionId ??= $store->create($root, $config->modelLabel());

        if ($json) {
            fwrite(STDOUT, json_encode(['type' => 'session', 'id' => $sessionId]) . PHP_EOL);
        } else {
            fwrite(STDERR, Style::dim("session: {$sessionId} · " . $config->modelLabel()) . PHP_EOL);
        }
        $prompt = new Prompt($canPrompt);
        $ask = static function (string $question) use ($prompt): bool {
            return in_array(strtolower(trim((string) $prompt->ask($question . ' [y/N] '))), ['y', 'yes'], true);
        };
        // In a plain terminal, the model's question is asked as a numbered list; piped, no one is there.
        $choose = static function (string $question, array $choices) use ($prompt, $canPrompt, $json, $config): string|false|null {
            if (!$canPrompt || $json) {
                return null;
            }
            $list = $question;
            foreach ($choices as $index => $choice) {
                $list .= "\n  " . ($index + 1) . '. ' . $choice['label'] . ($choice['detail'] !== '' ? ' · ' . $choice['detail'] : '');
            }
            $timeout = (float) $config->get('tools.ask_timeout', 120);
            if ($timeout > 0) {
                // Waited for, but not past the timeout: a long run goes on without the user.
                fwrite(STDOUT, $list . "\n(skipped in " . (int) $timeout . "s without an answer)\n");
                $read = [STDIN];
                $none = [];
                if (@stream_select($read, $none, $none, (int) $timeout) === 0) {
                    fwrite(STDOUT, "No answer: going on.\n");
                    return false;
                }
                $list = '';
            }
            $answer = trim((string) $prompt->ask(($list !== '' ? $list . "\n" : '') . 'Number, your own answer, or Enter to skip: '));
            return ctype_digit($answer) ? ($choices[(int) $answer - 1]['label'] ?? null) : ($answer === '' ? null : $answer);
        };
        $tools = self::tools($config, $options, $ask, $canPrompt, $workspace, $runner, $outputs, choose: $choose);
        $agent = self::agent($config, $http, $tools, $store, new AnsiRenderer($json), $workspace, $outputs, record: $record);
        $manager = $mcp['manager'];
        if ($manager !== null) {
            $tools->from($manager->tools(...));
            if ($mcp['untrusted'] !== null && !$json) {
                fwrite(STDERR, Style::dim("bia: this project's .mcp.json is not trusted yet; open bia here once to allow it") . PHP_EOL);
            }
            // A prompt given once has no next step to pick tools up at: the servers are waited for, briefly.
            $loop->execute(static function () use ($manager, $config, $json): void {
                $manager->start(static function (string $name, string $status) use ($manager, $json): void {
                    if ($status === 'failed' && !$json) {
                        $error = array_column($manager->status(), 'error', 'name')[$name] ?? '';
                        fwrite(STDERR, Style::dim("bia: MCP {$name} failed: {$error}") . PHP_EOL);
                    }
                });
                $manager->ready((float) $config->get('mcp.startup_timeout'));
            });
        }
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
        if (!$json && $store->path($sessionId) !== []) {
            fwrite(STDERR, "Resume this session with: bia resume {$sessionId}" . PHP_EOL);
        }
        if ($manager !== null) {
            $loop->execute(static fn () => $manager->close()); // the servers Bia started end with it
        }
        // A conversation left on purpose ended well; a one-shot run reports its turn.
        return $repl ? 0 : $status;
    }

    /**
     * The MCP servers configured: the user's own, and the project's when it
     * has been trusted as it is now. A project file not trusted yet is
     * given back to be asked about.
     *
     * @return array{manager: ?Manager, untrusted: ?string, project: list<Server>, errors: list<string>, trust: Trust, open: \Closure(string): void}
     */
    private static function mcp(Config $config, ProcessRunner $runner, Client $http, string $root): array
    {
        $environment = getenv();
        $user = Server::load($config->configDirectory() . '/mcp.json', false, $environment);
        $projectFile = $root . '/.mcp.json';
        $project = Server::load($projectFile, true, $environment);
        $trust = new Trust(dirname($config->databasePath()) . '/mcp-trust.json');
        $trusted = $project['servers'] !== [] && $trust->trusted($projectFile);
        $servers = [...$user['servers'], ...($trusted ? $project['servers'] : [])];
        $manager = null;
        if ($servers !== [] || $project['servers'] !== []) {
            $manager = new Manager($runner, $http, $root, self::VERSION, (float) $config->get('mcp.timeout'), (float) $config->get('mcp.startup_timeout'), new AuthStore(dirname($config->databasePath()) . '/mcp-auth.json'));
            foreach ($servers as $server) {
                $manager->add($server);
            }
        }
        return [
            'manager' => $manager,
            'untrusted' => $project['servers'] !== [] && !$trusted ? $projectFile : null,
            'project' => $project['servers'],
            'errors' => [...$user['errors'], ...$project['errors']],
            'trust' => $trust,
            // Opens a login page in the browser; the address is shown too, for a browser elsewhere.
            'open' => static function (string $url) use ($runner, $root): void {
                $opener = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
                try {
                    $runner->run([$opener, $url], $root, 10.0);
                } catch (\Throwable) {
                    // No browser here, as over SSH: the address shown is opened by hand.
                }
            },
        ];
    }

    /**
     * @param array<string, string|bool> $options
     * @param \Closure(string): bool $ask Asks the user a yes/no question.
     */
    private static function tools(Config $config, array $options, \Closure $ask, bool $canPrompt, Workspace $workspace, ProcessRunner $runner, string $outputs, ?Shells $shells = null, ?\Closure $choose = null): Registry
    {
        return (new Registry(self::approval($config, $options, $ask, $canPrompt)))
            ->add(new AskTool($choose ?? static fn (): ?string => null))
            ->add(new ReadFileTool($workspace, outputs: $outputs))
            ->add(new ListFilesTool($workspace, $runner))
            ->add(new SearchTool($workspace, $runner))
            ->add(new EditTool($workspace))
            ->add(new WriteFileTool($workspace))
            ->add(new ApplyPatchTool($workspace, $runner))
            ->add(new ShellTool($workspace, $runner, (float) $config->get('tools.shell_timeout'), $shells ??= new Shells($runner)))
            ->add(new ShellOutputTool($shells))
            ->add(new GitDiffTool($workspace, $runner))
            // Skills, read again each step; no tool at all while there are none.
            ->from(static function () use ($workspace): array {
                $skills = Catalog::for((string) getenv('HOME'), $workspace->root())->all();
                return $skills === [] ? [] : [new SkillTool($skills)];
            });
    }

    /**
     * The agent for the configured model; /model builds another.
     */
    private static function agent(Config $config, Client $http, Registry $tools, \Bia\Contracts\SessionStore $store, Renderer $renderer, Workspace $workspace, string $outputs, ?Inspection $inspection = null, ?\Closure $record = null): Agent
    {
        $metered = static fn (Config $config): Model => self::model($config, $http, $record);
        // Shown as it happens, and kept for /inspect.
        $notice = static function (string $message) use ($renderer, $inspection): void {
            $inspection?->event($message);
            $renderer->notice($message);
        };
        $modelName = (string) $config->get('model.name');
        $model = $metered($config);
        $fallbacks = self::fallbacks($config, $metered);
        if ($fallbacks !== []) {
            $model = new Fallback(
                [['name' => $modelName, 'label' => $config->modelLabel(), 'model' => $model], ...$fallbacks],
                $notice,
                (float) $config->get('fallback.cooldown'),
            );
        }
        $effort = $config->effort();
        // Whatever the provider refuses (an effort, reasoning sent back, or none of it) is mended once.
        $model = new Lenient($model, $notice);
        // A summary needs no deep thought: at most low, and quicker for it.
        $light = $effort === null ? [] : ['reasoning_effort' => in_array($effort, ['none', 'minimal'], true) ? $effort : 'low'];
        $compactor = $config->get('compaction.enabled') === false ? null : new Compactor(
            $model,
            $modelName,
            (int) $config->get('model.context_window'),
            (int) $config->get('compaction.reserve_tokens'),
            (int) $config->get('compaction.keep_tokens'),
            $light,
            ($at = $config->get('compaction.at_tokens')) === null ? null : (int) $at,
            ($idle = $config->get('compaction.idle_seconds')) === null ? null : (int) $idle,
            (int) $config->get('compaction.idle_tokens', 64_000),
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
            options: $effort !== null ? ['reasoning_effort' => $effort] : [],
            inspection: $inspection,
            recap: new Recap($model, $modelName, $light),
        );
    }

    private static function model(Config $config, Client $http, ?\Closure $record = null): Model
    {
        $model = new OpenAiCompatibleModel(
            $http,
            (string) $config->get('model.base_url'),
            (string) $config->get('model.api_key'),
            (string) $config->get('model.name'),
            (string) $config->get('model.endpoint', 'chat/completions'),
            (float) $config->get('model.timeout'),
            (float) $config->get('model.idle_timeout'),
        );
        return $record === null ? $model : new Metered($model, $config->modelLabel(), $record);
    }

    /**
     * The models to fall back on, each with its provider's server and key,
     * leaving out the chosen one and any without a key.
     *
     * @param \Closure(Config): Model $model Makes a provider's model.
     * @return list<array{name: string, label: string, model: Model}>
     */
    private static function fallbacks(Config $config, \Closure $model): array
    {
        $models = [];
        $seen = [$config->modelLabel() => true];
        foreach ((array) $config->get('fallback.models', []) as $name) {
            try {
                $other = $config->withModel((string) $name);
                $label = $other->modelLabel();
                if (isset($seen[$label])) {
                    continue;
                }
                $models[] = ['name' => (string) $other->get('model.name'), 'label' => $label, 'model' => $model($other)];
                $seen[$label] = true;
            } catch (\InvalidArgumentException) {
                // No key for it, or no such provider: left out, as `bia doctor` shows.
            }
        }
        return $models;
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
        $optionNames = ['model', 'base-url', 'api-key', 'effort', 'resume'];
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
