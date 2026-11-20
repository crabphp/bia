<?php

declare(strict_types=1);

namespace Bia\Share;

/**
 * NIP-44 v2 conversation encryption and XChaCha20-Poly1305 AEAD using PHP's
 * built-in OpenSSL and hash extensions (no sodium or sodium_compat required).
 */
final class Nip44
{
    private const VERSION = 2;
    private const MIN_PLAINTEXT = 1;
    private const MAX_PLAINTEXT = 65535;
    private const MIN_DECODED = 99; // 1 version + 32 nonce + 2 length + 32 min padded + 32 MAC

    /** Derives the 32-byte NIP-44 v2 conversation key via HKDF-extract(sha256, shared_x, "nip44-v2"). */
    public static function conversationKey(string $privateKey, string $peerPublicKey): string
    {
        return hash_hmac('sha256', Secp256k1::ecdh($privateKey, $peerPublicKey), 'nip44-v2', true);
    }

    /** Encrypts UTF-8 plaintext into a base64 NIP-44 v2 payload. */
    public static function encrypt(string $plaintext, string $conversationKey, ?string $nonce = null): string
    {
        $length = strlen($plaintext);
        if ($length < self::MIN_PLAINTEXT || $length > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('NIP-44 plaintext length out of bounds');
        }
        if (!mb_check_encoding($plaintext, 'UTF-8')) {
            throw new \InvalidArgumentException('NIP-44 plaintext must be valid UTF-8');
        }
        $nonce ??= random_bytes(32);
        if (strlen($conversationKey) !== 32 || strlen($nonce) !== 32) {
            throw new \InvalidArgumentException('NIP-44 requires 32-byte conversation key and nonce');
        }
        [$chachaKey, $chachaNonce, $hmacKey] = self::messageKeys($conversationKey, $nonce);
        $padded = self::pad($plaintext);
        $ciphertext = openssl_encrypt($padded, 'chacha20', $chachaKey, OPENSSL_RAW_DATA, "\0\0\0\0" . $chachaNonce);
        if ($ciphertext === false) {
            throw new \RuntimeException('OpenSSL ChaCha20 encryption failed');
        }
        $mac = hash_hmac('sha256', $nonce . $ciphertext, $hmacKey, true);
        return base64_encode(chr(self::VERSION) . $nonce . $ciphertext . $mac);
    }

    /** Decrypts and authenticates a base64 NIP-44 v2 payload. */
    public static function decrypt(string $payload, string $conversationKey): string
    {
        $length = strlen($payload);
        if ($length < 132 || $length > 87472 || str_starts_with($payload, '#')) {
            throw new \RuntimeException('Invalid NIP-44 payload');
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false || base64_encode($decoded) !== $payload || strlen($decoded) < self::MIN_DECODED) {
            throw new \RuntimeException('Invalid NIP-44 base64');
        }
        if (ord($decoded[0]) !== self::VERSION || strlen($conversationKey) !== 32) {
            throw new \RuntimeException('Unsupported NIP-44 version');
        }
        $nonce = substr($decoded, 1, 32);
        $mac = substr($decoded, -32);
        $ciphertext = substr($decoded, 33, -32);
        [$chachaKey, $chachaNonce, $hmacKey] = self::messageKeys($conversationKey, $nonce);
        $expected = hash_hmac('sha256', $nonce . $ciphertext, $hmacKey, true);
        if (!hash_equals($expected, $mac)) {
            throw new \RuntimeException('Invalid NIP-44 MAC');
        }
        $padded = openssl_decrypt($ciphertext, 'chacha20', $chachaKey, OPENSSL_RAW_DATA, "\0\0\0\0" . $chachaNonce);
        if ($padded === false) {
            throw new \RuntimeException('OpenSSL ChaCha20 decryption failed');
        }
        $plaintext = self::unpad($padded);
        if (!mb_check_encoding($plaintext, 'UTF-8')) {
            throw new \RuntimeException('NIP-44 plaintext is not valid UTF-8');
        }
        return $plaintext;
    }

    /**
     * XChaCha20-Poly1305 AEAD (HChaCha20 subkey + OpenSSL IETF ChaCha20-Poly1305).
     * Bit-for-bit compatible with libsodium's sodium_crypto_aead_xchacha20poly1305_ietf_encrypt.
     */
    public static function xchachaEncrypt(string $plaintext, string $aad, string $nonce24, string $key32): string
    {
        if (strlen($nonce24) !== 24 || strlen($key32) !== 32) {
            throw new \InvalidArgumentException('XChaCha20-Poly1305 requires a 24-byte nonce and 32-byte key');
        }
        $subkey = self::hchacha20($key32, substr($nonce24, 0, 16));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'chacha20-poly1305', $subkey, OPENSSL_RAW_DATA, "\0\0\0\0" . substr($nonce24, 16, 8), $tag, $aad, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('OpenSSL ChaCha20-Poly1305 encryption failed');
        }
        return $ciphertext . $tag;
    }

    /** Returns decrypted bytes, or null when authentication fails. */
    public static function xchachaDecrypt(string $ciphertextWithTag, string $aad, string $nonce24, string $key32): ?string
    {
        if (strlen($nonce24) !== 24 || strlen($key32) !== 32 || strlen($ciphertextWithTag) < 16) {
            return null;
        }
        $subkey = self::hchacha20($key32, substr($nonce24, 0, 16));
        $ciphertext = substr($ciphertextWithTag, 0, -16);
        $tag = substr($ciphertextWithTag, -16);
        $plain = openssl_decrypt($ciphertext, 'chacha20-poly1305', $subkey, OPENSSL_RAW_DATA, "\0\0\0\0" . substr($nonce24, 16, 8), $tag, $aad);
        return $plain === false ? null : $plain;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function messageKeys(string $conversationKey, string $nonce): array
    {
        $t1 = hash_hmac('sha256', $nonce . "\x01", $conversationKey, true);
        $t2 = hash_hmac('sha256', $t1 . $nonce . "\x02", $conversationKey, true);
        $t3 = hash_hmac('sha256', $t2 . $nonce . "\x03", $conversationKey, true);
        $keys = substr($t1 . $t2 . $t3, 0, 76);
        return [substr($keys, 0, 32), substr($keys, 32, 12), substr($keys, 44, 32)];
    }

    private static function pad(string $plaintext): string
    {
        $length = strlen($plaintext);
        $padded = self::paddedLength($length);
        return pack('n', $length) . $plaintext . str_repeat("\0", $padded - $length);
    }

    private static function unpad(string $padded): string
    {
        $total = strlen($padded);
        if ($total < 34) {
            throw new \RuntimeException('Invalid NIP-44 padding');
        }
        $length = unpack('n', $padded)[1];
        if ($length < self::MIN_PLAINTEXT || $length > self::MAX_PLAINTEXT || self::paddedLength($length) + 2 !== $total) {
            throw new \RuntimeException('Invalid NIP-44 padding length');
        }
        $tail = substr($padded, 2 + $length);
        if ($tail !== '' && !hash_equals(str_repeat("\0", strlen($tail)), $tail)) {
            throw new \RuntimeException('Non-zero NIP-44 padding');
        }
        return substr($padded, 2, $length);
    }

    private static function paddedLength(int $unpadded): int
    {
        if ($unpadded <= 32) {
            return 32;
        }
        $nextPower = 1 << strlen(decbin($unpadded - 1));
        $chunk = $nextPower <= 256 ? 32 : intdiv($nextPower, 8);
        return $chunk * (intdiv($unpadded - 1, $chunk) + 1);
    }

    private static function hchacha20(string $key32, string $nonce16): string
    {
        $s = [
            0x61707865, 0x3320646e, 0x79622d32, 0x6b206574,
            ...array_values(unpack('V8', $key32)),
            ...array_values(unpack('V4', $nonce16)),
        ];
        for ($i = 0; $i < 10; $i++) {
            self::quarterRound($s, 0, 4, 8, 12);
            self::quarterRound($s, 1, 5, 9, 13);
            self::quarterRound($s, 2, 6, 10, 14);
            self::quarterRound($s, 3, 7, 11, 15);
            self::quarterRound($s, 0, 5, 10, 15);
            self::quarterRound($s, 1, 6, 11, 12);
            self::quarterRound($s, 2, 7, 8, 13);
            self::quarterRound($s, 3, 4, 9, 14);
        }
        return pack('V8', $s[0], $s[1], $s[2], $s[3], $s[12], $s[13], $s[14], $s[15]);
    }

    /** @param array<int, int> $s */
    private static function quarterRound(array &$s, int $a, int $b, int $c, int $d): void
    {
        $s[$a] = ($s[$a] + $s[$b]) & 0xffffffff;
        $s[$d] ^= $s[$a];
        $s[$d] = (($s[$d] << 16) | ($s[$d] >> 16)) & 0xffffffff;
        $s[$c] = ($s[$c] + $s[$d]) & 0xffffffff;
        $s[$b] ^= $s[$c];
        $s[$b] = (($s[$b] << 12) | ($s[$b] >> 20)) & 0xffffffff;
        $s[$a] = ($s[$a] + $s[$b]) & 0xffffffff;
        $s[$d] ^= $s[$a];
        $s[$d] = (($s[$d] << 8) | ($s[$d] >> 24)) & 0xffffffff;
        $s[$c] = ($s[$c] + $s[$d]) & 0xffffffff;
        $s[$b] ^= $s[$c];
        $s[$b] = (($s[$b] << 7) | ($s[$b] >> 25)) & 0xffffffff;
    }
}
