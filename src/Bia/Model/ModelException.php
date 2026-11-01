<?php

declare(strict_types=1);

namespace Bia\Model;

/**
 * A provider refused a request. The message is the provider's own, so the
 * user sees "rate limit exceeded" rather than a bare status code.
 */
final class ModelException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly bool $contextOverflow = false,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Reads a provider's error answer and says whether it is about the
     * context window being too small for the request.
     */
    public static function fromAnswer(int $status, string $body): self
    {
        $data = json_decode($body, true);
        $detail = is_array($data)
            ? ($data['error']['message'] ?? $data['message'] ?? $data['error'] ?? null)
            : null;
        $detail = is_string($detail) && $detail !== '' ? $detail : trim(mb_strcut($body, 0, 500, 'UTF-8'));
        $overflow = in_array($status, [400, 413], true)
            && preg_match('/context[_ ]length|context window|maximum context|too many tokens|prompt is too long|reduce the length/i', $body) === 1;
        return new self("The model provider answered {$status}: {$detail}", $status, $overflow);
    }
}
