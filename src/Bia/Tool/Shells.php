<?php

declare(strict_types=1);

namespace Bia\Tool;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Process;

/**
 * Commands running in the background, as a dev server or a watcher does:
 * each started through ProcessRunner, numbered, its output kept (its end,
 * past a megabyte) for shell_output to read a part at a time. Each is
 * stopped, with what it started, when Bia ends.
 */
final class Shells
{
    /** Background commands running at once, at most. */
    public const MOST = 8;

    /** Seconds a command in the foreground has to end once the user writes, before it goes on in the background. */
    public const GRACE = 2.0;

    /** Bytes of a command's output kept unread; past this, the oldest go. */
    private const KEEP = 1_048_576;

    /** @var array<int, array{process: Process, command: string, started: float, unread: string, dropped: int, exit: ?int, done: bool}> */
    private array $shells = [];
    private int $last = 0;
    /** Commands running in the foreground now, for Ctrl-B to move. */
    private int $foreground = 0;
    /** @var list<\Closure(): void> */
    private array $listeners = [];

    public function __construct(private ProcessRunner $runner) {}

    /**
     * Starts the commands from now on through this runner, as `/reload` makes
     * one for the keys it read: those running go on.
     */
    public function use(ProcessRunner $runner): void
    {
        $this->runner = $runner;
    }

    /**
     * @param list<string> $argv The shell and its arguments, the command among them.
     * @param array<string, string> $environment
     */
    public function start(array $argv, string $command, string $cwd, array $environment): int
    {
        $running = count(array_filter($this->shells, static fn (array $shell): bool => !$shell['done']));
        if ($running >= self::MOST) {
            throw new \RuntimeException('There are ' . self::MOST . ' background commands running already: stop one with shell_output and kill: true.');
        }
        return $this->adopt($this->runner->start($argv, $cwd, $environment), $command);
    }

    /**
     * Told when a command starts or ends in the background, and when one
     * in the foreground starts or ends, to show what runs.
     *
     * @param \Closure(): void $listener
     */
    public function onChange(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Runs a command in the foreground, to its end, its timeout or its
     * output past $maxBytes; but once $handOver gives it a grace (the
     * user has written: GRACE, so one nearly done still gives its result
     * whole; or asked for it with Ctrl-B: none), it goes on in the
     * background past that, numbered as one started there, and its output
     * so far is what is returned.
     *
     * @param list<string> $argv The shell and its arguments, the command among them.
     * @param array<string, string> $environment
     * @param \Closure(): ?float $handOver Asked as it runs: seconds it has left, null while it may run on.
     * @param (\Closure(string): void)|null $output Told its output as it comes.
     * @return array{output: string, exit: ?int, timedOut: bool, truncated: bool, background: ?int}
     */
    public function run(array $argv, string $command, string $cwd, array $environment, float $timeout, int $maxBytes, \Closure $handOver, ?Cancellation $cancellation = null, ?\Closure $output = null): array
    {
        $cancellation?->throwIfCancelled();
        $process = $this->runner->start($argv, $cwd, $environment);
        $this->foreground++;
        $this->changed();
        try {
            return $this->wait($process, $command, $timeout, $maxBytes, $handOver, $cancellation, $output);
        } finally {
            $this->foreground--;
            $this->changed();
        }
    }

    /**
     * How many commands run in the foreground now.
     */
    public function foreground(): int
    {
        return $this->foreground;
    }

    /**
     * The commands running in the background, by number.
     *
     * @return array<int, array{command: string, started: float}>
     */
    public function all(): array
    {
        $running = array_filter($this->shells, static fn (array $shell): bool => !$shell['done']);
        return array_map(static fn (array $shell): array => ['command' => $shell['command'], 'started' => $shell['started']], $running);
    }

    /**
     * @param \Closure(): ?float $handOver
     * @param (\Closure(string): void)|null $output
     * @return array{output: string, exit: ?int, timedOut: bool, truncated: bool, background: ?int}
     */
    private function wait(Process $process, string $command, float $timeout, int $maxBytes, \Closure $handOver, ?Cancellation $cancellation, ?\Closure $output): array
    {
        $loop = EventLoop::current();
        $suspension = $loop->getSuspension();
        $taken = '';
        $heard = 0; // pieces of output so far, to tell when they have stopped after its exit
        $ended = null; // why the wait is over: exit, truncated, timeout, handover
        $end = static function (string $why) use (&$ended, $suspension): void {
            if ($ended === null) {
                $ended = $why;
                $suspension->resume();
            }
        };
        $keep = static function (string $bytes) use (&$taken, &$ended, &$heard, $end, $maxBytes, $output, $process): void {
            if ($ended !== null) {
                return;
            }
            $heard++;
            if (strlen($bytes) > $maxBytes - strlen($taken)) {
                $bytes = substr($bytes, 0, $maxBytes - strlen($taken));
                $process->kill();
                $end('truncated');
            }
            $taken .= $bytes;
            if ($output !== null && $bytes !== '') {
                $output($bytes);
            }
        };
        $process->onOutput($keep);
        $process->onError($keep);
        $exit = null;
        $process->onExit(static function (?int $code) use (&$exit, $end): void {
            $exit = $code;
            $end('exit'); // after Process has read what was left in its pipes: all of it is here
        });
        // Process notices an exit only every 50 ms, too slow for the many commands that take a few:
        // it is looked for every millisecond for its first 100, then less often, and what it
        // left in its pipes is read, until a millisecond passes with nothing more, or 50 do.
        $timers = [];
        $begun = microtime(true);
        $exited = null;
        $reap = static function () use (&$reap, &$exited, &$heard, &$timers, $begun, $loop, $process, $end): void {
            if ($exited === null) {
                if ($process->running()) {
                    $timers['reap'] = $loop->delay(microtime(true) - $begun < 0.1 ? 0.001 : 0.02, $reap);
                    return;
                }
                if (self::code($process) === false) {
                    return; // its code cannot be read here: onExit tells it
                }
                $exited = [microtime(true), $heard];
            } elseif ($exited[1] === $heard || microtime(true) - $exited[0] > 0.05) {
                $end('exit');
                return;
            }
            $exited[1] = $heard;
            $timers['reap'] = $loop->delay(0.001, $reap);
        };
        $timers['reap'] = $loop->delay(0.001, $reap);
        $timers['timeout'] = $loop->delay($timeout, static fn () => $end('timeout'));
        $until = null; // when it goes on in the background, once the user has written
        $handover = static function () use ($handOver, $end, &$until): void {
            $left = $handOver();
            if ($left !== null) {
                $until = min($until ?? INF, microtime(true) + $left);
            }
            if ($until !== null && microtime(true) >= $until) {
                $end('handover');
            }
        };
        $timers['handover'] = $loop->repeat(0.1, $handover);
        $unsubscribe = $cancellation?->subscribe(static function (\Throwable $error) use (&$ended, $suspension): void {
            if ($ended === null) {
                $ended = 'cancelled';
                $suspension->throw($error);
            }
        });
        try {
            if ($ended === null) {
                $suspension->suspend();
            }
        } catch (\Throwable $error) {
            $process->kill();
            throw $error;
        } finally {
            foreach ($timers as $timer) {
                $loop->cancel($timer);
            }
            $unsubscribe?->__invoke();
        }
        if ($ended !== 'handover') {
            $process->kill(); // what it started, still running, goes with it
        }
        return [
            'output' => $taken,
            'exit' => $ended === 'exit' ? ($exit ?? (is_int($code = self::code($process)) ? $code : null)) : null,
            'timedOut' => $ended === 'timeout',
            'truncated' => $ended === 'truncated',
            'background' => $ended === 'handover' ? $this->adopt($process, $command) : null,
        ];
    }

    /**
     * Numbers a running command and keeps its output from here on for shell_output.
     */
    private function adopt(Process $process, string $command): int
    {
        $id = ++$this->last;
        $this->shells[$id] = ['process' => $process, 'command' => $command, 'started' => microtime(true), 'unread' => '', 'dropped' => 0, 'exit' => null, 'done' => false];
        $keep = function (string $bytes) use ($id): void {
            $unread = $this->shells[$id]['unread'] . $bytes;
            if (strlen($unread) > self::KEEP) {
                $this->shells[$id]['dropped'] += strlen($unread) - self::KEEP;
                $unread = substr($unread, -self::KEEP);
            }
            $this->shells[$id]['unread'] = $unread;
        };
        $process->onOutput($keep);
        $process->onError($keep);
        $process->onExit(function (?int $code) use ($id): void {
            $this->shells[$id]['exit'] = $code;
            $this->shells[$id]['done'] = true;
            $this->changed();
        });
        $this->changed();
        return $id;
    }

    /**
     * What a background command printed since it was last read, waiting up
     * to $wait seconds for something to read or for it to end; null when no
     * command has that number.
     *
     * @return array{command: string, output: string, dropped: int, running: bool, exit: ?int}|null
     */
    public function read(int $id, float $wait = 0.0, ?Cancellation $cancellation = null): ?array
    {
        if (!isset($this->shells[$id])) {
            return null;
        }
        $deadline = microtime(true) + max(0.0, $wait);
        while ($this->shells[$id]['unread'] === '' && !$this->shells[$id]['done'] && microtime(true) < $deadline) {
            self::sleep(min(0.1, $deadline - microtime(true)), $cancellation);
        }
        $shell = $this->shells[$id];
        $this->shells[$id]['unread'] = '';
        $this->shells[$id]['dropped'] = 0;
        return [
            'command' => $shell['command'],
            'output' => $shell['unread'],
            'dropped' => $shell['dropped'],
            'running' => !$shell['done'],
            'exit' => $shell['exit'],
        ];
    }

    /**
     * Stops a background command and what it started: a moment to end, then killed.
     */
    public function stop(int $id): bool
    {
        if (!isset($this->shells[$id])) {
            return false;
        }
        $this->shells[$id]['process']->stop(0.5);
        return true;
    }

    /**
     * @return list<int> The numbers of the commands still running.
     */
    public function running(): array
    {
        return array_keys(array_filter($this->shells, static fn (array $shell): bool => !$shell['done']));
    }

    private function changed(): void
    {
        foreach ($this->listeners as $listener) {
            $listener();
        }
    }

    /**
     * The code a command that has ended exited with, null for a signal, as
     * Process keeps it before it tells onExit; false where it cannot be read.
     */
    private static function code(Process $process): int|false|null
    {
        static $property = null;
        try {
            $property ??= new \ReflectionProperty(Process::class, 'exitCode');
            $code = $property->getValue($process);
            return is_int($code) || $code === null ? $code : false;
        } catch (\ReflectionException) {
            return false;
        }
    }

    private static function sleep(float $seconds, ?Cancellation $cancellation): void
    {
        $cancellation?->throwIfCancelled();
        $loop = EventLoop::current();
        $suspension = $loop->getSuspension();
        $timer = $loop->delay(max(0.001, $seconds), static fn () => $suspension->resume());
        $unsubscribe = $cancellation?->subscribe(static fn (\Throwable $error) => $suspension->throw($error));
        try {
            $suspension->suspend();
        } finally {
            $loop->cancel($timer);
            if ($unsubscribe !== null) {
                $unsubscribe();
            }
        }
    }
}
