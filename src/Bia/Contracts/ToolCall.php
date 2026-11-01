<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * One tool call a model made, in no provider's format.
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     * @param string|null $error Why the arguments could not be read; such a call is answered with it and never run.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public ?string $error = null,
    ) {}

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>, error?: string}
     */
    public function toArray(): array
    {
        $call = ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
        if ($this->error !== null) {
            $call['error'] = $this->error;
        }
        return $call;
    }

    /**
     * @param array<string, mixed> $call
     */
    public static function fromArray(array $call): self
    {
        return new self(
            (string) ($call['id'] ?? ''),
            (string) ($call['name'] ?? ''),
            is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
            isset($call['error']) ? (string) $call['error'] : null,
        );
    }
}
