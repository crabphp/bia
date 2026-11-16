<?php

declare(strict_types=1);

namespace Bia\Share;

/** An invitation is a capability to request admission, never permission to act. */
final readonly class Invite
{
    public const RELAYS = ['wss://relay.damus.io', 'wss://nos.lol'];

    public function __construct(public string $host, public string $token, public array $relays, public int $expires)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $host) || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || $expires <= time() || $expires > time() + 86400 || count($relays) < 1 || count($relays) > 3) {
            throw new \InvalidArgumentException('Invalid or expired share invite');
        }
        foreach ($relays as $relay) {
            self::relay($relay);
        }
    }

    public static function relay(mixed $url): void
    {
        $parts = is_string($url) ? parse_url($url) : false;
        // Plain WS is only for local integration tests or a local relay.
        if (!is_string($url) || strlen($url) > 256 || preg_match('/[\x00-\x20\x7f]/', $url)
            || !is_array($parts) || !isset($parts['host']) || isset($parts['pass'])
            || isset($parts['user']) || isset($parts['fragment'])
            || !(($parts['scheme'] ?? '') === 'wss' || (($parts['scheme'] ?? '') === 'ws' && in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]'], true)))) {
            throw new \InvalidArgumentException('Share relays must be wss:// URLs (ws:// only on loopback)');
        }
    }

    public function code(): string
    {
        return 'bia1.' . rtrim(strtr(base64_encode(json_encode([$this->host, $this->token, $this->relays, $this->expires], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    public static function parse(string $code): self
    {
        if (strlen($code) > 2048 || !str_starts_with($code, 'bia1.')) {
            throw new \InvalidArgumentException('Expected a bia1 share invite');
        }
        // Terminal wrapping may introduce whitespace when copying a long invite.
        $code = preg_replace('/\s+/', '', $code);
        $json = base64_decode(strtr(substr($code, 5), '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true, 8);
        if (!is_array($data) || count($data) !== 4 || !is_string($data[0] ?? null) || !is_string($data[1] ?? null)
            || !is_array($data[2] ?? null) || !is_int($data[3] ?? null)) {
            throw new \InvalidArgumentException('Invalid share invite');
        }
        return new self(...$data);
    }
}
