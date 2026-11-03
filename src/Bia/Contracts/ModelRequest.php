<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * A provider-neutral model request.
 */
final readonly class ModelRequest
{
    /**
     * @param list<Message> $messages
     * @param list<ToolDefinition> $tools
     * @param array<string, mixed> $options
     * @param string $reasoning Which replies' reasoning goes back with them: `stored`, those
     *        whose provider sent it in a field of its own; `all`, every reply, an empty one
     *        where none was kept, for a provider that requires it; `none`.
     */
    public function __construct(
        public string $model,
        public array $messages,
        public array $tools = [],
        public array $options = [],
        public string $reasoning = 'stored',
    ) {}

    public function with(?array $options = null, ?string $model = null, ?string $reasoning = null): self
    {
        return new self($model ?? $this->model, $this->messages, $this->tools, $options ?? $this->options, $reasoning ?? $this->reasoning);
    }
}
