<?php

declare(strict_types=1);

namespace Bia\Plugin;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;

/**
 * The main agent's renderer, telling plugins of each tool call as it
 * starts and ends. Text passes straight through.
 */
final readonly class Observed implements Renderer
{
    public function __construct(
        private Renderer $renderer,
        private Plugins $plugins,
    ) {}

    public function event(ModelEvent $event): void
    {
        $this->renderer->event($event);
    }

    public function running(ToolCall $call, string $output): void
    {
        $this->renderer->running($call, $output);
        if ($output === '') {
            $this->plugins->emit('tool.call', ['tool' => $call->name, 'arguments' => $call->arguments]);
        }
    }

    public function tool(ToolCall $call, ToolResult $result): void
    {
        $this->renderer->tool($call, $result);
        $this->plugins->emit('tool.result', ['tool' => $call->name, 'arguments' => $call->arguments, 'output' => $result->text, 'ok' => $result->ok]);
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
