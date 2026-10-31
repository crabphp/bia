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
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $model,
        public array $messages,
        public array $tools = [],
        public array $options = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonMessages(): array
    {
        return array_map(static fn (Message $message): array => $message->json(), $this->messages);
    }
}
