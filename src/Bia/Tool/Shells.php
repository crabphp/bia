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

    /** Bytes of a command's output kept unread; past this, the oldest go. */
    private const KEEP = 1_048_576;

    /** @var array<int, array{process: Process, command: string, unread: string, dropped: int, exit: ?int, done: bool}> */
    private array $shells = [];
    private int $last = 0;

    public function __construct(private readonly ProcessRunner $runner) {}

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
        $process = $this->runner->start($argv, $cwd, $environment);
        $id = ++$this->last;
        $this->shells[$id] = ['process' => $process, 'command' => $command, 'unread' => '', 'dropped' => 0, 'exit' => null, 'done' => false];
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
        });
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
