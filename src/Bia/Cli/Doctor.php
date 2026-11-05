<?php

declare(strict_types=1);

namespace Bia\Cli;

use Bia\Agent\SystemPrompt;
use Bia\Config\Config;
use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Mcp\Manager;
use Bia\Model\Lenient;
use Bia\Session\SqliteStore;
use Bia\Terminal\Loop;
use Bia\Tui\Style;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/**
 * `bia doctor`: whether Bia can work here, a row for each thing it needs,
 * and for what is wrong, what to do. The model, each fallback and each MCP
 * server is tried for real, all at once; without a key, none is.
 */
final class Doctor
{
    /** Seconds a model has to answer the doctor's one-word question. */
    private const MODEL_SECONDS = 30.0;

    private int $problems = 0;

    /**
     * @param \Closure(Config): Model $model Makes a model's adapter, as a conversation would.
     * @param \Closure(Config): array{manager: ?Manager, untrusted: ?string, errors: list<string>} $mcp Reads the MCP servers, as a conversation would.
     */
    public function __construct(
        private readonly \Closure $model,
        private readonly \Closure $mcp,
        /** @var resource */
        private readonly mixed $out = STDOUT,
    ) {}

    public function run(): int
    {
        $this->requirements();
        try {
            $config = Config::load();
        } catch (\Throwable $error) {
            $this->row('✗', 'config', $error->getMessage());
            return $this->end();
        }
        $this->row('✓', 'config', $config->sources() === [] ? 'defaults' : implode(', ', array_map(self::home(...), $config->sources())));
        $permissions = array_filter((array) $config->get('permissions'), static fn (mixed $value): bool => $value !== 'auto');
        if ($permissions !== []) {
            $this->row('·', 'permissions', implode(' · ', array_map(static fn (string $key, mixed $value): string => "{$key} {$value}", array_keys($permissions), $permissions)));
        }
        $this->database($config);
        $this->connections($config);
        $instructions = SystemPrompt::files(realpath((string) getcwd()) ?: (string) getcwd(), $config->configDirectory());
        $this->row('·', 'context', number_format((int) $config->get('model.context_window')) . ' tokens · compaction ' . ($config->get('compaction.enabled') === false ? 'off' : 'on'));
        $this->row('·', 'instructions', $instructions === [] ? 'none' : implode(', ', array_map(self::home(...), $instructions)));
        return $this->end();
    }

    /**
     * What Bia cannot run without, and what it does less well without.
     */
    private function requirements(): void
    {
        $missing = array_keys(array_filter([
            'PHP 8.4' => version_compare(PHP_VERSION, '8.4.0', '<'),
            'pdo_sqlite' => !extension_loaded('pdo_sqlite'),
            'mbstring' => !extension_loaded('mbstring'),
        ]));
        $this->row(
            $missing === [] ? '✓' : '✗',
            'php',
            $missing === [] ? PHP_VERSION . ' · pdo_sqlite · mbstring' : PHP_VERSION . ' · needs ' . implode(', ', $missing),
        );
        $optional = [
            'git' => [self::which('git'), 'apply_patch and git_diff need it · install git'],
            'ripgrep' => [self::which('rg'), 'search needs it · install ripgrep'],
            'process groups' => [\Crab\Engine\Process\ProcessGroup::wrap(['true']) !== null, "a command's children may outlive it · install php's posix extension, and setsid or perl"],
            'pcntl' => [function_exists('pcntl_exec'), "Ctrl-C needs it · install php's pcntl extension"],
            'readline' => [extension_loaded('readline'), "line editing is plainer · install php's readline extension"],
        ];
        $have = array_keys(array_filter($optional, static fn (array $check): bool => $check[0]));
        if ($have !== []) {
            $this->row('✓', 'tools', implode(' · ', $have));
        }
        foreach ($optional as $name => [$ok, $why]) {
            if (!$ok) {
                $this->row('○', 'tools', "{$name} missing", $why);
            }
        }
    }

    private function database(Config $config): void
    {
        try {
            (new SqliteStore($config->databasePath()))->list(1);
            $this->row('✓', 'database', self::home($config->databasePath()));
        } catch (\Throwable $error) {
            $this->row('✗', 'database', self::home($config->databasePath()) . ': ' . self::short($error->getMessage()), 'sessions are not kept');
        }
    }

    /**
     * The model, its fallbacks and the MCP servers, each tried at once.
     */
    private function connections(Config $config): void
    {
        $models = [['model', $config, null]];
        foreach ((array) $config->get('fallback.models', []) as $name) {
            try {
                $models[] = ['fallback', $config->withModel((string) $name), null];
            } catch (\InvalidArgumentException $error) {
                $models[] = ['fallback', null, (string) $name . ' · ' . self::short($error->getMessage())];
            }
        }
        $mcp = ($this->mcp)($config);
        $tty = function_exists('stream_isatty') && @stream_isatty($this->out);
        if ($tty) {
            fwrite($this->out, Style::dim('  checking…'));
        }
        $rows = Loop::run(function (Cancellation $cancellation) use ($models, $mcp, $config): array {
            $tasks = [];
            foreach ($models as $index => [$kind, $other, $error]) {
                $tasks[$index] = $other === null ? null : Task::spawn(EventLoop::current(), fn (): array => $this->ask($kind, $other, $cancellation));
            }
            $manager = $mcp['manager'];
            if ($manager !== null) {
                $manager->start();
                $manager->ready((float) $config->get('mcp.startup_timeout'));
            }
            $rows = [];
            foreach ($models as $index => [$kind, $other, $error]) {
                $rows[] = $tasks[$index] === null ? ['✗', $kind, (string) $error, ''] : $tasks[$index]->await();
            }
            foreach ($manager?->status() ?? [] as $server) {
                $rows[] = match ($server['status']) {
                    'connected' => ['✓', 'mcp', "{$server['name']} · {$server['tools']} " . ($server['tools'] === 1 ? 'tool' : 'tools'), ''],
                    'login' => ['○', 'mcp', "{$server['name']} · needs login", "/mcp login {$server['name']}"],
                    'connecting' => ['✗', 'mcp', "{$server['name']} · did not start in " . (int) $config->get('mcp.startup_timeout') . ' s', ''],
                    default => ['✗', 'mcp', "{$server['name']} · " . self::short((string) preg_replace('/^the server is gone:\s*/', '', $server['error'])), ""],
                };
            }
            $manager?->close();
            return $rows;
        });
        if ($tty) {
            fwrite($this->out, "\r\e[K");
        }
        foreach ($rows as [$mark, $label, $text, $fix]) {
            $this->row($mark, $label, $text, $fix);
        }
        foreach ($mcp['errors'] as $error) {
            $this->row('✗', 'mcp', self::short($error));
        }
        if ($mcp['untrusted'] !== null) {
            $this->row('○', 'mcp', self::home($mcp['untrusted']) . ' not trusted', 'open bia here to allow it');
        }
    }

    /**
     * Asks a model for one word: whether its key, address and name work,
     * and how long it takes.
     *
     * @return array{string, string, string, string}
     */
    private function ask(string $kind, Config $config, Cancellation $cancellation): array
    {
        $label = $config->modelLabel() . (($effort = $config->effort()) !== null ? " · {$effort}" : '');
        if ((string) ($config->get('model.api_key') ?? '') === '') {
            // "No API key for …: set … in …, or export …. To use …": the part that says what to set.
            $help = $config->keyHelp();
            $what = (string) preg_replace('/^[^:]*:\s*|\.\s+To use.*$/s', '', $help);
            $home = (string) getenv('HOME');
            return ['✗', $kind, "{$label} · no API key", $home !== '' ? str_replace($home . '/', '~/', $what) : $what];
        }
        $notices = [];
        $model = new Lenient(($this->model)($config), static function (string $notice) use (&$notices): void {
            $notices[] = $notice;
        });
        $own = new Cancellation();
        $timer = EventLoop::current()->delay(self::MODEL_SECONDS, static fn () => $own->cancel());
        $unsubscribe = $cancellation->subscribe(static fn () => $own->cancel());
        $started = microtime(true);
        try {
            $model->stream(
                new ModelRequest((string) $config->get('model.name'), [Message::user('Reply with the one word OK.')], [], $effort !== null ? ['reasoning_effort' => $effort] : []),
                static function (): void {},
                $own,
            );
            $took = (int) round((microtime(true) - $started) * 1000);
            return ['✓', $kind, "{$label} · answered in " . self::time($took), $notices === [] ? '' : self::short(implode(' · ', $notices))];
        } catch (\Throwable $error) {
            if ($own->isCancelled() && !$cancellation->isCancelled()) {
                return ['✗', $kind, "{$label} · no answer in " . (int) self::MODEL_SECONDS . ' s', ''];
            }
            // The request's own address is in the row's fix, where it is the thing to check.
            $why = self::short((string) preg_replace('#^(POST|GET) \S+ failed:\s*#', '', $error->getMessage()));
            $fix = match (true) {
                preg_match('/\b(401|403)\b|unauthori[sz]ed|invalid.{0,20}key/i', $why) === 1 => 'check the API key',
                preg_match('/\b404\b|not found|does not exist|unknown model/i', $why) === 1 => 'check the model name and ' . (string) $config->get('model.base_url'),
                preg_match('/could not be reached|resolve|refused|timed out|SSL|certificate/i', $why) === 1 => 'check ' . (string) $config->get('model.base_url'),
                default => '',
            };
            return ['✗', $kind, "{$label} · {$why}", $fix];
        } finally {
            EventLoop::current()->cancel($timer);
            $unsubscribe();
        }
    }

    /**
     * One row: its mark, what was checked, how it is, and dim, what to do.
     */
    private function row(string $mark, string $label, string $text, string $fix = ''): void
    {
        if ($mark === '✗') {
            $this->problems++;
        }
        $shown = match ($mark) {
            '✓' => Style::ok($mark),
            '✗' => Style::error($mark),
            '○' => Style::warn($mark),
            default => Style::dim($mark),
        };
        fwrite($this->out, "{$shown} " . str_pad($label, 13) . $text . ($fix !== '' ? Style::dim(" · {$fix}") : '') . PHP_EOL);
    }

    private function end(): int
    {
        fwrite($this->out, PHP_EOL . ($this->problems === 0 ? Style::ok('Ready') : Style::error($this->problems === 1 ? '1 problem' : "{$this->problems} problems")) . PHP_EOL);
        return $this->problems === 0 ? 0 : 1;
    }

    private static function which(string $command): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable($directory . '/' . $command)) {
                return true;
            }
        }
        return false;
    }

    private static function home(string $path): string
    {
        $home = (string) getenv('HOME');
        return $home !== '' && str_starts_with($path, $home . '/') ? '~' . substr($path, strlen($home)) : $path;
    }

    private static function short(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) <= 140 ? $text : mb_substr($text, 0, 139) . '…';
    }

    private static function time(int $ms): string
    {
        return $ms < 1000 ? "{$ms} ms" : number_format($ms / 1000, 1) . ' s';
    }
}
