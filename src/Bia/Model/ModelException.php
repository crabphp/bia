<?php

declare(strict_types=1);

namespace Bia\Model;

/**
 * A provider refused a request. The message is the provider's own, so the
 * user sees "rate limit exceeded" rather than a bare status code.
 */
final class ModelException extends \RuntimeException
{
    /**
     * @param float|null $retryAfter Seconds the provider asked to wait before trying again.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly bool $contextOverflow = false,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Whether trying the same request again later may work: a timeout, a
     * rate limit, or the provider failing on its side.
     */
    public function transient(): bool
    {
        // A server failing passes; one that does not do what was asked (501, 505) does not.
        return in_array($this->status, [408, 425, 429], true)
            || ($this->status >= 500 && $this->status <= 599 && !in_array($this->status, [501, 505], true));
    }

    /**
     * Reads a provider's error answer and says whether it is about the
     * context window being too small for the request.
     *
     * @param string $retryAfter The answer's Retry-After header: seconds, or an HTTP date.
     */
    public static function fromAnswer(int $status, string $body, string $retryAfter = ''): self
    {
        $data = json_decode($body, true);
        $detail = is_array($data)
            ? ($data['error']['message'] ?? $data['message'] ?? $data['error'] ?? null)
            : null;
        $detail = is_string($detail) && $detail !== '' ? $detail : trim(mb_strcut($body, 0, 500, 'UTF-8'));
        $overflow = in_array($status, [400, 413], true)
            && preg_match('/context[_ ]length|context window|maximum context|too many tokens|prompt is too long|reduce the length/i', $body) === 1;
        $retryAfter = trim($retryAfter);
        $wait = match (true) {
            $retryAfter === '' => null,
            is_numeric($retryAfter) => max(0.0, (float) $retryAfter),
            ($time = strtotime($retryAfter)) !== false => max(0.0, (float) ($time - time())),
            default => null,
        };
        return new self("The model provider answered {$status}: {$detail}", $status, $overflow, $wait);
    }
}
