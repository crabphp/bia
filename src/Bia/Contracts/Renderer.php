<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * Consumes model events without coupling the agent loop to a terminal.
 */
interface Renderer
{
    public function event(ModelEvent $event): void;

    /**
     * A tool call is running: told once as it starts, with no output, then
     * with each piece of output it makes, for a tool that streams it.
     */
    public function running(ToolCall $call, string $output): void;

    /**
     * A tool call has finished.
     */
    public function tool(ToolCall $call, ToolResult $result): void;

    /**
     * Something the agent did on its own, such as compacting the conversation.
     */
    public function notice(string $message): void;

    public function finish(ModelResult $result): void;
}
