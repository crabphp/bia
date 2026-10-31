<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * One normalized message exchanged with a model.
 */
final readonly class Message
{
    /**
     * @param 'system'|'user'|'assistant'|'tool' $role
     * @param string|list<array<string, mixed>> $content
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $role,
        public string|array $content,
        public array $metadata = [],
    ) {
        if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) {
            throw new \InvalidArgumentException("Unknown message role \"{$role}\".");
        }
    }

    /**
     * @return array{role: string, content: string|list<array<string, mixed>>}
     */
    public function json(): array
    {
        $message = ['role' => $this->role, 'content' => $this->content];
        if ($this->role === 'tool') {
            if (isset($this->metadata['tool_call_id'])) {
                $message['tool_call_id'] = $this->metadata['tool_call_id'];
            }
            if (isset($this->metadata['name'])) {
                $message['name'] = $this->metadata['name'];
            }
        }
        if ($this->role === 'assistant' && isset($this->metadata['tool_calls'])) {
            $message['tool_calls'] = $this->metadata['tool_calls'];
        }
        return $message;
    }
}
