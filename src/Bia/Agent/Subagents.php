<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;

/**
 * The subagents the main agent hands tasks to: each a loop of its own with
 * its own context and read-only tools, working in parallel with the others,
 * whose final answer alone goes back to the main agent.
 *
 * The main agent manages them through the `agent` tool: a new name starts
 * one, the same name again continues it with a follow-up. One ends when it
 * answers, fails or is stopped; nothing of it runs after. The user may stop
 * one, or steer it while it works. Those finished are forgotten at the
 * user's next message.
 */
final class Subagents
{
    /** At most this many work at once; the others wait their turn. */
    public const LIMIT = 4;

    private const INSTRUCTIONS = <<<'TEXT'

        # You are a subagent

        The main agent gave you one task and sees nothing of your work but your final answer. You can read, list, search and see the diff, but not edit files or run commands. Do the task thoroughly, then answer with what was asked: findings, file paths and line numbers, and what you could not find. Be complete but brief; no preamble.
        TEXT;

    /** @var array<string, array{name: string, task: string, state: string, doing: string, calls: int, started: float, ended: ?float, agent: Agent, session: string, view: mixed, cancel: ?Cancellation, steer: list<string>}> */
    private array $agents = [];
    private int $working = 0;
    /** @var list<\Crab\Engine\Suspension> Those waiting for a turn to work, in order. */
    private array $queue = [];

    /**
     * @param \Closure(string, \Closure(string): void, \Closure(): void): array{0: Agent, 1: string, 2: mixed} $make
     *        Makes a subagent named so: its agent, its session, and what shows its run; told what it is
     *        doing and of each call it made.
     * @param \Closure(): void $changed Told whenever one starts, does something, or ends.
     * @param (\Closure(mixed, string): void)|null $asked Told each task given, with what shows that subagent's run.
     */
    public function __construct(
        private readonly \Closure $make,
        private readonly \Closure $changed,
        private readonly string $system = '',
        private readonly ?\Closure $asked = null,
    ) {}

    /**
     * Gives a subagent a task, or a follow-up when one has this name, and
     * waits for its answer.
     */
    public function ask(string $name, string $task, ?Cancellation $parent = null): ToolResult
    {
        $name = self::name($name);
        $task = trim($task);
        if ($task === '') {
            return new ToolResult('Give the subagent a task.', false);
        }
        if (isset($this->agents[$name]) && in_array($this->agents[$name]['state'], ['working', 'waiting'], true)) {
            return new ToolResult("The subagent {$name} is still working; give another one another name.", false);
        }
        if (!isset($this->agents[$name])) {
            [$agent, $session, $view] = ($this->make)(
                $name,
                function (string $doing) use ($name): void {
                    if (isset($this->agents[$name])) {
                        $this->agents[$name]['doing'] = $doing;
                        ($this->changed)();
                    }
                },
                function () use ($name): void {
                    if (isset($this->agents[$name])) {
                        $this->agents[$name]['calls']++;
                    }
                },
            );
            $this->agents[$name] = ['name' => $name, 'task' => $task, 'state' => 'waiting', 'doing' => '', 'calls' => 0, 'started' => microtime(true), 'ended' => null, 'agent' => $agent, 'session' => $session, 'view' => $view, 'cancel' => null, 'steer' => []];
        } else {
            $this->agents[$name] = ['task' => $task, 'state' => 'waiting', 'doing' => '', 'started' => microtime(true), 'ended' => null, 'cancel' => null, 'steer' => []] + $this->agents[$name];
        }
        if ($this->asked !== null) {
            ($this->asked)($this->agents[$name]['view'], $task);
        }
        $cancel = new Cancellation();
        $this->agents[$name]['cancel'] = $cancel;
        $unsubscribe = $parent?->subscribe(static fn (\Throwable $reason) => $cancel->cancel($reason));
        ($this->changed)();
        $took = false;
        try {
            $this->take($cancel);
            $took = true;
            $this->agents[$name]['state'] = 'working';
            ($this->changed)();
            $record = $this->agents[$name];
            $result = $record['agent']->run(
                $record['session'],
                $task,
                $this->system . self::INSTRUCTIONS,
                $cancel,
                function () use ($name): array {
                    $steer = $this->agents[$name]['steer'] ?? [];
                    if (isset($this->agents[$name])) {
                        $this->agents[$name]['steer'] = [];
                    }
                    return $steer;
                },
            );
            $this->agents[$name]['state'] = 'done';
            $answer = trim($result->text);
            return new ToolResult($answer !== '' ? $answer : '(the subagent gave no answer)', true, $this->data($name));
        } catch (CancelledException $stopped) {
            if ($parent?->isCancelled() === true) {
                $this->agents[$name]['state'] = 'stopped';
                throw $stopped; // the turn was stopped: it ends with it
            }
            $this->agents[$name]['state'] = 'stopped';
            return new ToolResult('The user stopped this subagent before it finished.', false, $this->data($name));
        } catch (\Throwable $error) {
            $this->agents[$name]['state'] = 'failed';
            $this->agents[$name]['doing'] = $error->getMessage();
            return new ToolResult("The subagent failed: {$error->getMessage()}", false, $this->data($name));
        } finally {
            if ($unsubscribe !== null) {
                $unsubscribe();
            }
            $this->agents[$name]['ended'] = microtime(true);
            $this->agents[$name]['cancel'] = null;
            if ($took) {
                $this->release();
            } else {
                $this->queue = array_values(array_filter($this->queue, static fn ($waiting): bool => $waiting !== null));
            }
            ($this->changed)();
        }
    }

    /**
     * Each subagent, in the order they were started.
     *
     * @return list<array{name: string, task: string, state: string, doing: string, calls: int, started: float, ended: ?float, view: mixed}>
     */
    public function all(): array
    {
        return array_values(array_map(static fn (array $agent): array => array_diff_key($agent, ['agent' => 0, 'session' => 0, 'cancel' => 0, 'steer' => 0]), $this->agents));
    }

    public function working(): int
    {
        return count(array_filter($this->agents, static fn (array $agent): bool => in_array($agent['state'], ['working', 'waiting'], true)));
    }

    /**
     * Stops one; the main agent is told it was stopped.
     */
    public function stop(string $name): void
    {
        $this->agents[$name]['cancel']?->cancel();
    }

    /**
     * Closes one that has finished: gone from the list, its run forgotten.
     * One still at work is only stopped; it closes once it has.
     */
    public function close(string $name): void
    {
        if (in_array($this->agents[$name]['state'] ?? 'working', ['working', 'waiting'], true)) {
            $this->stop($name);
            return;
        }
        unset($this->agents[$name]);
        ($this->changed)();
    }

    /**
     * A message from the user for one at work, joining its next step.
     */
    public function steer(string $name, string $text): bool
    {
        if (($this->agents[$name]['state'] ?? '') !== 'working') {
            return false;
        }
        $this->agents[$name]['steer'][] = $text;
        return true;
    }

    /**
     * Forgets those that have finished, as the user's next message to the
     * main agent starts something new.
     */
    public function forget(): void
    {
        $this->agents = array_filter($this->agents, static fn (array $agent): bool => in_array($agent['state'], ['working', 'waiting'], true));
        ($this->changed)();
    }

    /**
     * Waits for a turn to work, among at most LIMIT.
     */
    private function take(Cancellation $cancel): void
    {
        $cancel->throwIfCancelled();
        if ($this->working < self::LIMIT) {
            $this->working++;
            return;
        }
        $suspension = EventLoop::current()->getSuspension();
        $this->queue[] = $suspension;
        $unsubscribe = $cancel->subscribe(function (\Throwable $reason) use ($suspension): void {
            $this->queue = array_values(array_filter($this->queue, static fn ($waiting): bool => $waiting !== $suspension));
            $suspension->throw($reason);
        });
        try {
            $suspension->suspend(); // the slot is handed over by release(), already counted
        } finally {
            $unsubscribe();
        }
    }

    private function release(): void
    {
        $next = array_shift($this->queue);
        if ($next !== null) {
            $next->resume(); // the slot passes on, so the count stays
            return;
        }
        $this->working--;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $name): array
    {
        $agent = $this->agents[$name];
        return ['agent' => $name, 'calls' => $agent['calls'], 'seconds' => round(microtime(true) - $agent['started'], 1)];
    }

    /**
     * A short name of lowercase letters, digits and dashes.
     */
    public static function name(string $name): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return $name === '' ? 'agent' : substr($name, 0, 16);
    }
}
