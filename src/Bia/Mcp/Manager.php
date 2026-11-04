<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Bia\Contracts\Tool;
use Bia\Tool\ProcessRunner;
use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/**
 * Bia's MCP servers: each connected in the background, so that Bia never
 * waits on one to start; their tools offered as they come, and taken away
 * when a server goes. One lost after it was up is connected again, a few
 * times, each after a longer wait; one that keeps falling over fails.
 * Closing stops every server Bia started.
 */
final class Manager
{
    /** The waits before each attempt to connect a server lost again, in seconds. */
    public const RETRIES = [1.0, 2.0, 4.0];
    /** A server up this long, in seconds, has its attempts counted afresh when it is lost. */
    private const STEADY = 60.0;

    /**
     * @var array<string, array{server: Server, status: string, error: string, tools: list<McpTool>, connection: ?Connection, retries: int, since: float, timer: ?string}>
     */
    private array $servers = [];
    /** @var \Closure(string, string): void|null */
    private ?\Closure $changed = null;
    private bool $closed = false;
    /** @var array<string, OAuth> Each remote server's logging in, made when first needed. */
    private array $auths = [];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Client $http,
        private readonly string $cwd,
        private readonly string $version,
        private readonly float $timeout = 120.0,
        private readonly float $startup = 30.0,
        private readonly ?AuthStore $authStore = null,
        /** @var list<float> */
        private readonly array $retries = self::RETRIES,
    ) {}

    public function add(Server $server): void
    {
        $this->servers[$server->name] ??= ['server' => $server, 'status' => 'off', 'error' => '', 'tools' => [], 'connection' => null, 'retries' => 0, 'since' => 0.0, 'timer' => null];
    }

    /**
     * Connects every server not connected yet, each in a task of its own.
     *
     * @param \Closure(string, string): void|null $changed Told a server's name and its new status as it changes.
     */
    public function start(?\Closure $changed = null): void
    {
        $this->changed = $changed ?? $this->changed;
        foreach ($this->servers as $name => $entry) {
            if ($entry['status'] === 'off' || $entry['status'] === 'failed') {
                $this->servers[$name]['retries'] = 0;
                $this->set($name, 'connecting');
                Task::spawn(EventLoop::current(), fn () => $this->connect($name));
            }
        }
    }

    /**
     * The tools of every connected server.
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        $tools = [];
        foreach ($this->servers as $entry) {
            array_push($tools, ...$entry['tools']);
        }
        return $tools;
    }

    /**
     * @return list<array{name: string, status: string, error: string, tools: int, project: bool}>
     */
    public function status(): array
    {
        return array_values(array_map(static fn (array $entry): array => [
            'name' => $entry['server']->name,
            'status' => $entry['status'],
            'error' => $entry['error'],
            'tools' => count($entry['tools']),
            'project' => $entry['server']->project,
        ], $this->servers));
    }

    /**
     * Waits until no server is still connecting, or the time is up: for a
     * one-shot prompt, which has no next step to pick tools up at.
     */
    public function ready(float $seconds): void
    {
        $until = microtime(true) + $seconds;
        $loop = EventLoop::current();
        while (microtime(true) < $until && array_filter($this->servers, static fn (array $e): bool => $e['status'] === 'connecting') !== []) {
            $suspension = $loop->getSuspension();
            $loop->delay(0.05, static fn () => $suspension->resume());
            $suspension->suspend();
        }
    }

    /**
     * Stops every server, all at once: the slowest to stop sets how long it takes.
     */
    public function close(): void
    {
        $this->closed = true;
        $closing = [];
        foreach (array_keys($this->servers) as $name) {
            $connection = $this->drop($name);
            if ($connection !== null) {
                $closing[] = Task::spawn(EventLoop::current(), static fn () => $connection->close());
            }
        }
        foreach ($closing as $task) {
            try {
                $task->await();
            } catch (\Throwable) {
                // One that will not stop cleanly does not keep the others from stopping.
            }
        }
    }

    /**
     * Logs in to a remote server, then connects it again.
     *
     * @param \Closure(string): void $open Given the address to log in at.
     */
    public function login(string $name, \Closure $open, ?Cancellation $cancellation = null): void
    {
        $auth = $this->auth($name) ?? throw new \RuntimeException("{$name} is not a remote server; only those log in");
        $auth->login($open, $cancellation);
        $this->restart($name);
    }

    /**
     * Stops a server and connects it afresh, its attempts counted anew.
     */
    public function restart(string $name): void
    {
        if (!isset($this->servers[$name])) {
            throw new \RuntimeException("no MCP server {$name}");
        }
        $this->drop($name)?->close();
        $this->set($name, 'off');
        $this->start();
    }

    /**
     * Forgets a remote server's tokens and registration: it needs a login again.
     */
    public function logout(string $name): void
    {
        $auth = $this->auth($name) ?? throw new \RuntimeException("{$name} is not a remote server; only those log in");
        $auth->forget();
        $this->drop($name)?->close();
        $this->set($name, 'login', 'logged out');
    }

    /**
     * Hands a pasted redirect address to the login waiting for it; false when none is.
     */
    public function deliver(string $url): bool
    {
        foreach ($this->auths as $auth) {
            if ($auth->deliver($url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The address a login waits to be sent back to, while one does.
     */
    public function waitingLogin(): ?string
    {
        foreach ($this->auths as $auth) {
            if (($prefix = $auth->waiting()) !== null) {
                return $prefix;
            }
        }
        return null;
    }

    private function auth(string $name): ?OAuth
    {
        $server = $this->servers[$name]['server'] ?? null;
        if ($server === null || $server->type !== 'http' || $this->authStore === null) {
            return null;
        }
        return $this->auths[$name] ??= new OAuth($server, $this->http, $this->authStore);
    }

    private function connect(string $name): void
    {
        $server = $this->servers[$name]['server'];
        $transport = $server->type === 'http'
            ? new HttpTransport($server, $this->http, $this->timeout, $this->auth($name))
            : new StdioTransport($server, $this->runner, $this->cwd);
        $connection = new Connection($transport);
        $this->servers[$name]['connection'] = $connection;
        // A server that refuses for want of a login needs one, which is not a failure of its own.
        $refused = static fn (): bool => $transport instanceof HttpTransport && $transport->needsLogin();
        try {
            $connection->start(
                $this->version,
                fn () => Task::spawn(EventLoop::current(), fn () => $this->list($name, $connection)),
                function (string $why) use ($name, $connection, $refused): void {
                    // Lost while starting, the failure to start says so; lost once up, it is connected again.
                    if (!$this->closed && $this->servers[$name]['connection'] === $connection && $this->servers[$name]['status'] === 'connected') {
                        $this->servers[$name]['tools'] = [];
                        if ($refused()) {
                            $this->set($name, 'login', 'needs login');
                            return;
                        }
                        if (microtime(true) - $this->servers[$name]['since'] >= self::STEADY) {
                            $this->servers[$name]['retries'] = 0;
                        }
                        $this->retry($name, $why);
                    }
                },
                $this->startup,
            );
            $this->list($name, $connection);
            $this->servers[$name]['since'] = microtime(true);
            $this->set($name, 'connected');
        } catch (\Throwable $error) {
            if (!$this->closed && $this->servers[$name]['connection'] === $connection) {
                $connection->close();
                $this->servers[$name]['tools'] = [];
                if ($refused()) {
                    $this->set($name, 'login', 'needs login');
                } elseif ($this->servers[$name]['retries'] > 0) {
                    $this->retry($name, $error->getMessage()); // an attempt to connect one lost again
                } else {
                    $this->set($name, 'failed', $error->getMessage()); // one that never started is not tried again unasked
                }
            }
        }
    }

    /**
     * Connects a lost server again after a wait, or, its attempts used up, fails it.
     */
    private function retry(string $name, string $why): void
    {
        $attempt = $this->servers[$name]['retries'];
        if ($attempt >= count($this->retries)) {
            $this->set($name, 'failed', $why);
            return;
        }
        $this->servers[$name]['retries']++;
        if ($this->servers[$name]['status'] !== 'connecting') {
            $this->set($name, 'connecting', $why); // why it was lost, told once however many attempts it takes
        }
        $this->servers[$name]['timer'] = EventLoop::current()->delay($this->retries[$attempt], function () use ($name): void {
            $this->servers[$name]['timer'] = null;
            if (!$this->closed && $this->servers[$name]['status'] === 'connecting') {
                Task::spawn(EventLoop::current(), fn () => $this->connect($name));
            }
        });
    }

    /**
     * Lets a server go: no attempt to connect it waits, its tools are taken
     * away, and its connection, no longer its, is handed back to be closed.
     */
    private function drop(string $name): ?Connection
    {
        if (($timer = $this->servers[$name]['timer']) !== null) {
            EventLoop::current()->cancel($timer);
            $this->servers[$name]['timer'] = null;
        }
        $connection = $this->servers[$name]['connection'];
        $this->servers[$name]['connection'] = null;
        $this->servers[$name]['tools'] = [];
        return $connection;
    }

    private function list(string $name, Connection $connection): void
    {
        $tools = [];
        foreach ($connection->tools($this->startup) as $tool) {
            $tools[] = new McpTool($name, $tool, $connection, $this->timeout);
        }
        $this->servers[$name]['tools'] = $tools;
    }

    private function set(string $name, string $status, string $error = ''): void
    {
        if ($this->servers[$name]['status'] === $status && $this->servers[$name]['error'] === $error) {
            return; // told once: a server refused is seen both as gone and as failing to start
        }
        $this->servers[$name]['status'] = $status;
        $this->servers[$name]['error'] = $error;
        if ($this->changed !== null) {
            ($this->changed)($name, $status);
        }
    }
}
