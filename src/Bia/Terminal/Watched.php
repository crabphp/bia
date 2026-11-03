<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;

/**
 * A subagent's renderer, telling as it goes what the subagent does, for
 * its row in the list of agents: "Reading src/App.php", "Writing".
 */
final readonly class Watched implements Renderer
{
    /**
     * @param \Closure(string): void $doing
     * @param \Closure(): void $called Told of each call done.
     */
    public function __construct(
        private Renderer $renderer,
        private \Closure $doing,
        private \Closure $called,
    ) {}

    public function event(ModelEvent $event): void
    {
        match ($event->type) {
            'text_delta' => ($this->doing)('Writing'),
            'reasoning_delta' => ($this->doing)('Thinking'),
            default => null,
        };
        $this->renderer->event($event);
    }

    public function running(ToolCall $call, string $output): void
    {
        if ($output === '') {
            ($this->doing)(TuiRenderer::doing($call));
        }
        $this->renderer->running($call, $output);
    }

    public function tool(ToolCall $call, ToolResult $result): void
    {
        ($this->called)();
        ($this->doing)('Thinking');
        $this->renderer->tool($call, $result);
    }

    public function notice(string $message): void
    {
        $this->renderer->notice($message);
    }

    public function finish(ModelResult $result): void
    {
        $this->renderer->finish($result);
    }
}
