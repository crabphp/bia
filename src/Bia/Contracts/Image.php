<?php

declare(strict_types=1);

namespace Bia\Contracts;

/**
 * An image a user message carries: a file Bia keeps, and its type. The
 * session stores where it is, never its bytes; an adapter reads it when the
 * message is sent.
 */
final readonly class Image
{
    public const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        public string $path,
        public string $mime,
    ) {
        if (!in_array($mime, self::TYPES, true)) {
            throw new \InvalidArgumentException("An image is one of: " . implode(', ', self::TYPES) . ".");
        }
    }

    /**
     * The type of image bytes, by their first bytes; null when they are none of TYPES.
     */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * The image as a data URL, as a model is sent it; null when its file is gone.
     */
    public function dataUrl(): ?string
    {
        $bytes = is_file($this->path) ? file_get_contents($this->path) : false;
        return $bytes === false ? null : 'data:' . $this->mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * @return array{path: string, mime: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'mime' => $this->mime];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['path'] ?? ''), (string) ($data['mime'] ?? 'image/png'));
    }
}
