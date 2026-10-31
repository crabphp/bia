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
     * A tool call has finished.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>, error?: string} $call
     */
    public function tool(array $call, ToolResult $result): void;

    public function finish(ModelResult $result): void;
}
