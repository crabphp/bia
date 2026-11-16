<?php

declare(strict_types=1);

namespace Bia\Share;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\ConversationCipher;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Infrastructure\Crypto\Nip44Cipher;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Ecdh;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

/** Fresh, memory-only Nostr identities. No model credentials enter this layer. */
final class Identity
{
    // NIP-01 ephemeral range: relay storage is neither requested nor relied upon.
    public const KIND = 21117;
    private readonly KeyPair $keys;
    private readonly Secp256k1Signer $signer;
    private readonly ConversationCipher $cipher;

    public function __construct()
    {
        if (!extension_loaded('gmp') || !extension_loaded('sodium')) {
            throw new \RuntimeException('Sharing needs the PHP gmp and sodium extensions');
        }
        $this->signer = Secp256k1Signer::createPurePhp();
        $this->cipher = new ConversationCipher(new Nip44Cipher(), Secp256k1Ecdh::createPurePhp());
        $this->keys = KeyPair::generate($this->signer);
    }

    public function publicKey(): string
    {
        return $this->keys->getPublicKey()->toHex();
    }

    public function seal(string $recipient, array $payload): array
    {
        $peer = PublicKey::tryFromHex($recipient) ?? throw new \InvalidArgumentException('Invalid peer');
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 24000) {
            throw new \LengthException('Share packet too large');
        }
        return $this->sign(self::KIND, $this->cipher->encrypt($json, $this->keys->getPrivateKey(), $peer), [['p', $recipient]]);
    }

    /** Sign either an encrypted session packet or its encrypted discovery announcement. */
    public function sign(int $kind, string $content, array $tags): array
    {
        return Rumour::draft(
            pubkey: $this->keys->getPublicKey(),
            kind: EventKind::fromInt($kind),
            content: EventContent::fromString($content),
            tags: new TagCollection(array_map(Tag::fromArray(...), $tags)),
        )->sign($this->keys, $this->signer)->toArray();
    }

    public function verified(array $data, int $kind, array $tags): ?Event
    {
        try {
            if (($data['kind'] ?? null) !== $kind || ($data['tags'] ?? null) !== $tags
                || !is_int($data['created_at'] ?? null) || abs(time() - $data['created_at']) > 120
                || !is_string($data['content'] ?? null) || strlen($data['content']) > 50000) {
                return null;
            }
            $event = Event::tryFromJson(json_encode($data, JSON_THROW_ON_ERROR));
            return $event !== null && $event->verify($this->signer) ? $event : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{sender: string, payload: array}|null */
    public function open(array $data): ?array
    {
        try {
            $event = $this->verified($data, self::KIND, [['p', $this->publicKey()]]);
            if ($event === null) {
                return null;
            }
            $plain = $this->cipher->decrypt((string) $event->getContent(), $this->keys->getPrivateKey(), $event->getPubkey());
            if (strlen($plain) > 24000) {
                return null;
            }
            $payload = json_decode($plain, true, 24, JSON_THROW_ON_ERROR);
            return is_array($payload) ? ['sender' => $event->getPubkey()->toHex(), 'payload' => $payload] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function __destruct()
    {
        if (isset($this->keys)) {
            $this->keys->getPrivateKey()->zero();
        }
    }
}
