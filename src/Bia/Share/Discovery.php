<?php

declare(strict_types=1);

namespace Bia\Share;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/** Live rendezvous: only the short code can unlock an invite, and admission still needs host approval. */
final class Discovery
{
    public const KIND = 21118;
    private const DOMAIN = 'bia-share-discovery-v1';
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public static function code(): string
    {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::ALPHABET[random_int(0, 61)];
        }
        return $code;
    }

    public static function tag(string $code): string
    {
        if (!preg_match('/^[A-Za-z0-9]{12}$/D', $code)) {
            throw new \InvalidArgumentException('Expected a 12-character share code (case-sensitive)');
        }
        return hash('sha256', self::DOMAIN . "\0" . $code);
    }

    private static function key(string $code): string
    {
        // Separate from the public lookup hash: knowing the relay tag must not reveal the encryption key.
        return hash_hkdf('sha256', $code, 32, self::DOMAIN . ' encryption');
    }

    public static function announce(Identity $identity, Invite $invite, string $code): array
    {
        if ($invite->host !== $identity->publicKey()) {
            throw new \InvalidArgumentException('Invite does not belong to this host');
        }
        $tag = self::tag($code);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $invite->code(), self::DOMAIN . $tag . $invite->host, $nonce, self::key($code),
        );
        return $identity->sign(self::KIND, base64_encode($nonce . $ciphertext), [['p', $tag]]);
    }

    public static function open(array $data, string $code, Identity $verifier): ?Invite
    {
        try {
            $tag = self::tag($code);
            if (($data['kind'] ?? null) !== self::KIND || ($data['tags'] ?? null) !== [['p', $tag]]
                || !is_string($data['pubkey'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['pubkey'])
                || !is_string($data['content'] ?? null) || strlen($data['content']) > 4096) {
                return null;
            }
            $bytes = base64_decode($data['content'], true);
            if ($bytes === false || strlen($bytes) < 40) { return null; }
            // Reject unknown-code traffic cheaply, before doing signature arithmetic.
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($bytes, 24), self::DOMAIN . $tag . $data['pubkey'], substr($bytes, 0, 24), self::key($code),
            );
            if ($plain === false || $verifier->verified($data, self::KIND, [['p', $tag]]) === null) { return null; }
            $invite = Invite::parse($plain);
            return $invite->host === $data['pubkey'] ? $invite : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Suspends inside a task, never in the input loop. No relay storage or extra service is needed. */
    public static function resolve(string $code, Cancellation $cancel, array $relays = Invite::RELAYS, float $timeout = 30): Invite
    {
        $tag = self::tag($code);
        if (count($relays) < 1 || count($relays) > 3 || $timeout <= 0 || $timeout > 30 || !is_finite($timeout)) {
            throw new \InvalidArgumentException('Invalid share lookup limits');
        }
        foreach ($relays as $url) { Invite::relay($url); }
        $cancel->throwIfCancelled();
        $verifier = new Identity();
        $loop = EventLoop::current();
        $network = new Cancellation();
        $unsubscribe = $cancel->subscribe(fn () => $network->cancel());
        $connections = []; $incoming = []; $seen = [];
        try {
            foreach (array_unique($relays) as $url) {
                Task::spawn($loop, static function () use ($loop, $url, $tag, $network, &$connections, &$incoming): void {
                    while (!$network->isCancelled()) {
                        $relay = new Relay(static function (array $message) use (&$incoming): void {
                            if (($message[0] ?? null) === 'EVENT' && ($message[1] ?? null) === 'invite' && is_array($message[2] ?? null)) {
                                if (count($incoming) < 32) { $incoming[] = $message[2]; }
                            } elseif (in_array($message[0] ?? null, ['CLOSED', 'AUTH'], true)) {
                                throw new \RuntimeException('Relay refused invite lookup');
                            }
                        });
                        $connections[$url] = $relay;
                        try {
                            $relay->connect($url, $network);
                            $relay->send(['REQ', 'invite', ['kinds' => [self::KIND], '#p' => [$tag], 'since' => time() - 120, 'limit' => 1]]);
                            while ($relay->alive()) { Async::sleep($loop, 0.25, $network); }
                        } catch (\Throwable) {
                            // Try the other relays too; one unavailable relay must not fail the lookup.
                        } finally {
                            $relay->close();
                            unset($connections[$url]);
                        }
                        try { Async::sleep($loop, 5, $network); } catch (\Throwable) { return; }
                    }
                });
            }
            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline) {
                $cancel->throwIfCancelled();
                for ($i = 0; $i < 2 && $incoming !== []; $i++) {
                    $data = array_shift($incoming);
                    // Cache the whole candidate, not its unverified id: a forgery must not suppress a valid event.
                    $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
                    if (isset($seen[$digest])) { continue; }
                    $seen[$digest] = true;
                    if (count($seen) > 128) { array_shift($seen); }
                    $invite = self::open($data, $code, $verifier);
                    if ($invite !== null) {
                        // Only contact the relays chosen locally, never redirect from an opaque short code.
                        return new Invite($invite->host, $invite->token, $relays, $invite->expires);
                    }
                }
                Async::sleep($loop, min(0.1, max(0.001, $deadline - microtime(true))), $cancel);
            }
            throw new \RuntimeException('Host not found · check the code and relays, and keep /share running on the host');
        } finally {
            $network->cancel();
            $unsubscribe();
            foreach ($connections as $relay) { $relay->close(); }
        }
    }
}
