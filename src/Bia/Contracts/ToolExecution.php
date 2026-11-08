<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * A durable tool invocation. The execution is written before a tool starts,
 * and marked recorded only after its result is in the conversation log.
 */
final readonly class ToolExecution
{
    /**
     * @param 'running'|'completed'|'failed'|'unknown' $status
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public string $sessionId,
        public string $callId,
        public string $name,
        public array $arguments,
        public string $status,
        public string $startedAt,
        public ?string $finishedAt = null,
        public ?bool $ok = null,
        public string $result = '',
        public array $data = [],
    ) {
        if (!in_array($status, ['running', 'completed', 'failed', 'unknown'], true)) {
            throw new \InvalidArgumentException("Unknown tool execution status {$status}.");
        }
    }

    public function call(): ToolCall
    {
        return new ToolCall($this->callId, $this->name, $this->arguments);
    }
}
