<?php

declare(strict_types=1);

namespace Bia\Session;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/** One quiet, bounded attempt after a completed turn; ordinary typing never cancels it. */
final class Naming
{
    public const TIMEOUT = 20.0;

    private ?Cancellation $pending = null;
    /** @var array{string, int}|null Do not ask twice about the same completed turn. */
    private ?array $attempted = null;

    public function __construct(
        private readonly SqliteStore $sessions,
        private readonly float $timeout = self::TIMEOUT,
    ) {}

    /** @param \Closure(string, Cancellation): ?string $write */
    public function start(string $sessionId, \Closure $write): void
    {
        $head = $this->sessions->head($sessionId);
        if ($head === null || $this->sessions->title($sessionId) !== null || $this->attempted === [$sessionId, $head]) {
            return;
        }
        $this->cancel();
        $this->attempted = [$sessionId, $head];
        $cancellation = $this->pending = new Cancellation();
        $loop = EventLoop::current();
        Task::spawn($loop, function () use ($sessionId, $head, $write, $cancellation, $loop): void {
            $deadline = $loop->delay($this->timeout, static fn () => $cancellation->cancel());
            try {
                $cancellation->throwIfCancelled();
                $title = $write($sessionId, $cancellation);
                $cancellation->throwIfCancelled();
                if ($title !== null) {
                    $this->sessions->saveTitle($sessionId, $title, $head);
                }
            } catch (\Throwable) {
                // Optional housekeeping must never interrupt the conversation. Try after its next turn.
            } finally {
                $loop->cancel($deadline);
                if ($this->pending === $cancellation) {
                    $this->pending = null;
                }
            }
        });
    }

    public function cancel(): void
    {
        $this->pending?->cancel();
        $this->pending = null;
    }
}
