<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * Durable state for tool calls that may outlive the PHP process.
 */
interface ExecutionStore
{
    public function start(string $sessionId, ToolCall $call): string;

    public function finish(string $executionId, ToolResult $result): void;

    /**
     * Returns executions whose conversation result was not recorded yet.
     * Running executions are changed to `unknown` before they are returned.
     *
     * @return list<ToolExecution>
     */
    public function recover(string $sessionId): array;

    public function recordExecution(string $executionId): void;
}
