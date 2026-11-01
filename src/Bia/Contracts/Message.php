<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * One message of a conversation, in no provider's format. Adapters turn it
 * into their wire format; the session stores it as it is.
 *
 * - user and system: text.
 * - assistant: text, the reasoning it showed, and the tool calls it made.
 * - tool: the result of one tool call (toolCallId, toolName, ok, text).
 */
final readonly class Message
{
    public const ROLES = ['system', 'user', 'assistant', 'tool'];

    /**
     * @param 'system'|'user'|'assistant'|'tool' $role
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed> $meta Usage, finish reason, a tool's data: kept, never sent.
     */
    public function __construct(
        public string $role,
        public string $text = '',
        public array $toolCalls = [],
        public string $reasoning = '',
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public bool $ok = true,
        public array $meta = [],
    ) {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown message role \"{$role}\".");
        }
    }

    public static function user(string $text): self
    {
        return new self('user', $text);
    }

    public static function system(string $text): self
    {
        return new self('system', $text);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function toolResult(ToolCall $call, string $text, bool $ok, array $meta = []): self
    {
        return new self('tool', $text, toolCallId: $call->id, toolName: $call->name, ok: $ok, meta: $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['role' => $this->role, 'text' => $this->text];
        if ($this->toolCalls !== []) {
            $data['tool_calls'] = array_map(static fn (ToolCall $call): array => $call->toArray(), $this->toolCalls);
        }
        if ($this->reasoning !== '') {
            $data['reasoning'] = $this->reasoning;
        }
        if ($this->role === 'tool') {
            $data['tool_call_id'] = $this->toolCallId;
            $data['tool_name'] = $this->toolName;
            $data['ok'] = $this->ok;
        }
        if ($this->meta !== []) {
            $data['meta'] = $this->meta;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['role'] ?? 'user'),
            (string) ($data['text'] ?? ''),
            array_map(ToolCall::fromArray(...), is_array($data['tool_calls'] ?? null) ? array_values($data['tool_calls']) : []),
            (string) ($data['reasoning'] ?? ''),
            isset($data['tool_call_id']) ? (string) $data['tool_call_id'] : null,
            isset($data['tool_name']) ? (string) $data['tool_name'] : null,
            (bool) ($data['ok'] ?? true),
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }
}
