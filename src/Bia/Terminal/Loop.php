<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;

/**
 * Runs one cancellable operation on Crab's event loop and returns its result.
 * Ctrl-C cancels the operation rather than the process.
 */
final class Loop
{
    private readonly EventLoop $loop;

    public function __construct()
    {
        $this->loop = EventLoop::install(new StreamSelectEventLoop());
    }

    /**
     * @template T
     * @param callable(Cancellation): T $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        return (new self())->execute($operation);
    }

    /**
     * Runs an operation on this loop. Reusing a Loop preserves Crab HTTP
     * connections across interactive turns.
     *
     * @template T
     * @param callable(Cancellation): T $operation
     * @return T
     */
    public function execute(callable $operation): mixed
    {
        $cancellation = new Cancellation();
        $signal = null;
        if (defined('SIGINT') && function_exists('pcntl_signal')) {
            // The first Ctrl-C cancels this operation; a second, while it winds down, quits.
            $signal = $this->loop->onSignal(SIGINT, static function () use ($cancellation): void {
                if ($cancellation->isCancelled()) {
                    exit(130);
                }
                $cancellation->cancel();
            });
        }
        $task = Task::spawn($this->loop, static fn () => $operation($cancellation));
        $task->onComplete(fn () => $this->loop->stop());
        try {
            $this->loop->run();
            return $task->result();
        } finally {
            if ($signal !== null) {
                $this->loop->cancel($signal);
            }
        }
    }
}
