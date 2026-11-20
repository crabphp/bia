<?php

declare(strict_types=1);

namespace Bia\Share;

/** Fresh, memory-only Nostr identities using PHP's OpenSSL + pure-PHP secp256k1. No model credentials enter this layer. */
final class Identity
{
    // NIP-01 ephemeral range: relay storage is neither requested nor relied upon.
    public const KIND = 21117;
    private const EVENT_JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    private string $privateKey;
    private readonly string $publicKey;
    /** @var array<string, string> Cached NIP-44 conversation keys by peer hex pubkey. */
    private array $conversations = [];

    public function __construct()
    {
        if (!extension_loaded('openssl') || !in_array('chacha20', openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException('Sharing needs the PHP openssl extension with ChaCha20 support');
        }
        $this->privateKey = Secp256k1::generateKey();
        $this->publicKey = bin2hex(Secp256k1::publicKey($this->privateKey));
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function seal(string $recipient, array $payload): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $recipient)) {
            throw new \InvalidArgumentException('Invalid peer');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 24000) {
            throw new \LengthException('Share packet too large');
        }
        return $this->sign(self::KIND, Nip44::encrypt($json, $this->conversationKey($recipient)), [['p', $recipient]]);
    }

    /** Sign either an encrypted session packet or its encrypted discovery announcement. */
    public function sign(int $kind, string $content, array $tags): array
    {
        $createdAt = time();
        $idBytes = self::eventId($this->publicKey, $createdAt, $kind, $tags, $content);
        return [
            'id' => bin2hex($idBytes),
            'pubkey' => $this->publicKey,
            'created_at' => $createdAt,
            'kind' => $kind,
            'tags' => $tags,
            'content' => $content,
            'sig' => bin2hex(Secp256k1::sign($this->privateKey, $idBytes)),
        ];
    }

    /** @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array, content: string, sig: string}|null */
    public function verified(array $data, int $kind, array $tags): ?array
    {
        try {
            if (($data['kind'] ?? null) !== $kind || ($data['tags'] ?? null) !== $tags
                || !is_int($data['created_at'] ?? null) || abs(time() - $data['created_at']) > 120
                || !is_string($data['pubkey'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['pubkey'])
                || !is_string($data['id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['id'])
                || !is_string($data['sig'] ?? null) || !preg_match('/^[a-f0-9]{128}$/D', $data['sig'])
                || !is_string($data['content'] ?? null) || strlen($data['content']) > 50000
                || !mb_check_encoding($data['content'], 'UTF-8')) {
                return null;
            }
            $idBytes = self::eventId($data['pubkey'], $data['created_at'], $kind, $tags, $data['content']);
            if (!hash_equals(bin2hex($idBytes), $data['id'])) {
                return null;
            }
            if (!Secp256k1::verify((string) hex2bin($data['pubkey']), $idBytes, (string) hex2bin($data['sig']))) {
                return null;
            }
            return [
                'id' => $data['id'],
                'pubkey' => $data['pubkey'],
                'created_at' => $data['created_at'],
                'kind' => $kind,
                'tags' => $tags,
                'content' => $data['content'],
                'sig' => $data['sig'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{sender: string, payload: array}|null */
    public function open(array $data): ?array
    {
        try {
            $event = $this->verified($data, self::KIND, [['p', $this->publicKey]]);
            if ($event === null) {
                return null;
            }
            $plain = Nip44::decrypt($event['content'], $this->conversationKey($event['pubkey']));
            if (strlen($plain) > 24000) {
                return null;
            }
            $payload = json_decode($plain, true, 24, JSON_THROW_ON_ERROR);
            return is_array($payload) ? ['sender' => $event['pubkey'], 'payload' => $payload] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function conversationKey(string $peerHex): string
    {
        if (isset($this->conversations[$peerHex])) {
            return $this->conversations[$peerHex];
        }
        $key = Nip44::conversationKey($this->privateKey, (string) hex2bin($peerHex));
        if (count($this->conversations) >= 16) {
            array_shift($this->conversations);
        }
        return $this->conversations[$peerHex] = $key;
    }

    private static function eventId(string $pubkey, int $createdAt, int $kind, array $tags, string $content): string
    {
        return hash('sha256', json_encode([0, $pubkey, $createdAt, $kind, $tags, $content], self::EVENT_JSON), true);
    }

    public function __destruct()
    {
        $this->privateKey = str_repeat("\0", 32);
        $this->conversations = [];
    }
}
