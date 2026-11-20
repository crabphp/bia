<?php

declare(strict_types=1);

namespace Bia\Share;

/**
 * Pure-PHP 64-bit-limb secp256k1 for BIP-340 Schnorr signing/verification and
 * x-only ECDH, requiring zero extra PHP extensions (no gmp, bcmath, or ffi).
 *
 * Uses 16 unsigned 16-bit limbs (little-endian), keeping every intermediate
 * product well under 2^47 (far below PHP's signed 64-bit limit of 2^63 - 1).
 * Not constant-time; used only for ephemeral per-session Nostr keys.
 */
final class Secp256k1
{
    /** Field prime p = 2^256 - 2^32 - 977 (16-bit limbs, least significant first). */
    private const P = [0xFC2F, 0xFFFF, 0xFFFE, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF];

    /** Curve order n (16-bit limbs, least significant first). */
    private const N = [0x4141, 0xD036, 0x5E8C, 0xBFD2, 0xA03B, 0xAF48, 0xDCE6, 0xBAAE, 0xFFFE, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF];

    /** Generator G = (Gx, Gy). */
    private const GX = [0x1798, 0x16F8, 0x815B, 0x59F2, 0x28D9, 0x2DCE, 0xFCDB, 0x029B, 0x0B07, 0xCE87, 0x6295, 0x55A0, 0xBBAC, 0xF9DC, 0x667E, 0x79BE];
    private const GY = [0xD4B8, 0xFB10, 0xD08F, 0x9C47, 0x5419, 0xA685, 0xB448, 0xFD17, 0x08A8, 0x0E11, 0xFBFC, 0x5DA4, 0xC465, 0x26A3, 0xDA77, 0x483A];

    private const ZERO = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    private const ONE = [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    private const SEVEN = [7, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

    /** p - 2 for Fermat inversion a^(p-2) mod p. */
    private const P_MINUS_2 = [0xFC2D, 0xFFFF, 0xFFFE, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF];

    /** (p + 1) / 4 for square root a^((p+1)/4) mod p (since p ≡ 3 mod 4). */
    private const SQRT_EXP = [0xFF0C, 0xBFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0x3FFF];

    /** @var array<string, string> */
    private static array $tagHashes = [];
    /** @var list<array{0: list<int>, 1: list<int>, 2: list<int>}>|null */
    private static ?array $gTable = null;

    public static function generateKey(): string
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('Sharing requires 64-bit PHP');
        }
        for ($i = 0; $i < 64; $i++) {
            $bytes = random_bytes(32);
            $d = self::fromBytes($bytes);
            if (!self::isZero($d) && self::cmp($d, self::N) < 0) {
                return $bytes;
            }
        }
        throw new \RuntimeException('Failed to generate secp256k1 private key');
    }

    /** Returns the 32-byte BIP-340 x-only public key for a 32-byte secret scalar. */
    public static function publicKey(string $privateKey): string
    {
        $d = self::validScalar($privateKey);
        $point = self::toAffine(self::mulG($d));
        return self::toBytes($point[0]);
    }

    /** Produces a 64-byte BIP-340 Schnorr signature for a 32-byte message. */
    public static function sign(string $privateKey, string $message, ?string $auxRand = null): string
    {
        if (strlen($message) !== 32) {
            throw new \InvalidArgumentException('BIP-340 signs a 32-byte message');
        }
        $aux = $auxRand ?? random_bytes(32);
        if (strlen($aux) !== 32) {
            throw new \InvalidArgumentException('BIP-340 auxiliary randomness must be 32 bytes');
        }
        $d0 = self::validScalar($privateKey);
        [$px, $py] = self::toAffine(self::mulG($d0));
        $d = ($py[0] & 1) === 0 ? $d0 : self::scSub(self::N, $d0);
        $pxBytes = self::toBytes($px);
        $t = self::toBytes($d) ^ self::taggedHash('BIP0340/aux', $aux);
        $rand = self::taggedHash('BIP0340/nonce', $t . $pxBytes . $message);
        $k0 = self::scMod(self::fromBytes($rand));
        if (self::isZero($k0)) {
            throw new \RuntimeException('BIP-340 nonce reduced to zero');
        }
        [$rx, $ry] = self::toAffine(self::mulG($k0));
        $k = ($ry[0] & 1) === 0 ? $k0 : self::scSub(self::N, $k0);
        $rxBytes = self::toBytes($rx);
        $e = self::scMod(self::fromBytes(self::taggedHash('BIP0340/challenge', $rxBytes . $pxBytes . $message)));
        $s = self::scAdd($k, self::scMul($e, $d));
        return $rxBytes . self::toBytes($s);
    }

    /** Verifies a 64-byte BIP-340 Schnorr signature against a 32-byte x-only pubkey and 32-byte message. */
    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        if (PHP_INT_SIZE < 8 || strlen($publicKey) !== 32 || strlen($message) !== 32 || strlen($signature) !== 64) {
            return false;
        }
        $P = self::liftX($publicKey);
        if ($P === null) {
            return false;
        }
        $rxBytes = substr($signature, 0, 32);
        $r = self::fromBytes($rxBytes);
        $s = self::fromBytes(substr($signature, 32, 32));
        if (self::cmp($r, self::P) >= 0 || self::cmp($s, self::N) >= 0) {
            return false;
        }
        $e = self::scMod(self::fromBytes(self::taggedHash('BIP0340/challenge', $rxBytes . $publicKey . $message)));
        $sG = self::isZero($s) ? null : self::mulG($s);
        $negEP = self::isZero($e) ? null : self::ptMul([$P[0], self::feNeg($P[1]), self::ONE], $e);
        $R = self::ptAdd($sG, $negEP);
        if ($R === null) {
            return false;
        }
        [$rx, $ry] = self::toAffine($R);
        return ($ry[0] & 1) === 0 && self::toBytes($rx) === $rxBytes;
    }

    /** Computes the 32-byte x-only ECDH shared secret (used by NIP-44 v2). */
    public static function ecdh(string $privateKey, string $peerPublicKey): string
    {
        $d = self::validScalar($privateKey);
        $P = self::liftX($peerPublicKey);
        if ($P === null) {
            throw new \InvalidArgumentException('Invalid peer secp256k1 public key');
        }
        $shared = self::ptMul($P, $d);
        if ($shared === null) {
            throw new \RuntimeException('ECDH shared point is at infinity');
        }
        return self::toBytes(self::toAffine($shared)[0]);
    }

    public static function taggedHash(string $tag, string $message): string
    {
        $prefix = self::$tagHashes[$tag] ??= hash('sha256', $tag, true);
        return hash('sha256', $prefix . $prefix . $message, true);
    }

    /** @return list<int> */
    private static function validScalar(string $bytes): array
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('Sharing requires 64-bit PHP');
        }
        if (strlen($bytes) !== 32) {
            throw new \InvalidArgumentException('secp256k1 private key must be 32 bytes');
        }
        $d = self::fromBytes($bytes);
        if (self::isZero($d) || self::cmp($d, self::N) >= 0) {
            throw new \InvalidArgumentException('secp256k1 private key out of range');
        }
        return $d;
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<int>}|null */
    private static function liftX(string $bytes): ?array
    {
        if (strlen($bytes) !== 32) {
            return null;
        }
        $x = self::fromBytes($bytes);
        if (self::cmp($x, self::P) >= 0) {
            return null;
        }
        $c = self::feNorm(self::feAdd(self::feMul(self::feMul($x, $x), $x), self::SEVEN));
        $y = self::feNorm(self::fePow($c, self::SQRT_EXP));
        if (self::feNorm(self::feMul($y, $y)) !== $c) {
            return null;
        }
        if (($y[0] & 1) === 1) {
            $y = self::feNeg($y);
        }
        return [$x, $y, self::ONE];
    }

    /** @return list<int> */
    private static function fromBytes(string $bytes): array
    {
        $words = unpack('n16', $bytes);
        return [
            $words[16], $words[15], $words[14], $words[13],
            $words[12], $words[11], $words[10], $words[9],
            $words[8], $words[7], $words[6], $words[5],
            $words[4], $words[3], $words[2], $words[1],
        ];
    }

    /** @param list<int> $limbs */
    private static function toBytes(array $limbs): string
    {
        return pack(
            'n16',
            $limbs[15], $limbs[14], $limbs[13], $limbs[12],
            $limbs[11], $limbs[10], $limbs[9], $limbs[8],
            $limbs[7], $limbs[6], $limbs[5], $limbs[4],
            $limbs[3], $limbs[2], $limbs[1], $limbs[0],
        );
    }

    /** @param list<int> $a */
    private static function isZero(array $a): bool
    {
        return $a === self::ZERO;
    }

    /** @param list<int> $a @param list<int> $b */
    private static function cmp(array $a, array $b): int
    {
        for ($i = 15; $i >= 0; $i--) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        return 0;
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function subRaw(array $a, array $b): array
    {
        $r = [];
        $borrow = 0;
        for ($i = 0; $i < 16; $i++) {
            $v = $a[$i] - $b[$i] - $borrow;
            if ($v < 0) {
                $r[$i] = $v + 0x10000;
                $borrow = 1;
            } else {
                $r[$i] = $v;
                $borrow = 0;
            }
        }
        return $r;
    }

    /** @param list<int> $a @return list<int> */
    private static function feNorm(array $a): array
    {
        return self::cmp($a, self::P) >= 0 ? self::subRaw($a, self::P) : $a;
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function feAdd(array $a, array $b): array
    {
        $r = [];
        $c = 0;
        for ($i = 0; $i < 16; $i++) {
            $v = $a[$i] + $b[$i] + $c;
            $r[$i] = $v & 0xFFFF;
            $c = $v >> 16;
        }
        if ($c > 0 || self::cmp($r, self::P) >= 0) {
            return self::subRaw($r, self::P);
        }
        return $r;
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function feSub(array $a, array $b): array
    {
        $r = [];
        $borrow = 0;
        for ($i = 0; $i < 16; $i++) {
            $v = $a[$i] - $b[$i] - $borrow;
            if ($v < 0) {
                $r[$i] = $v + 0x10000;
                $borrow = 1;
            } else {
                $r[$i] = $v;
                $borrow = 0;
            }
        }
        if ($borrow === 1) {
            $c = 0;
            for ($i = 0; $i < 16; $i++) {
                $v = $r[$i] + self::P[$i] + $c;
                $r[$i] = $v & 0xFFFF;
                $c = $v >> 16;
            }
        }
        return $r;
    }

    /** @param list<int> $a @return list<int> */
    private static function feNeg(array $a): array
    {
        $a = self::feNorm($a);
        return self::isZero($a) ? self::ZERO : self::subRaw(self::P, $a);
    }

    /**
     * Multiplies two field elements modulo p = 2^256 - 2^32 - 977.
     *
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function feMul(array $a, array $b): array
    {
        $t = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        for ($i = 0; $i < 16; $i++) {
            $ai = $a[$i];
            if ($ai === 0) {
                continue;
            }
            for ($j = 0; $j < 16; $j++) {
                $t[$i + $j] += $ai * $b[$j];
            }
        }
        // 2^256 ≡ 2^32 + 977 (mod p): fold limbs 31..16 downward into 0..15.
        for ($i = 31; $i >= 16; $i--) {
            $v = $t[$i];
            $t[$i - 16] += $v * 977;
            $t[$i - 14] += $v;
        }
        $r = [];
        $c = 0;
        for ($i = 0; $i < 16; $i++) {
            $v = $t[$i] + $c;
            $r[$i] = $v & 0xFFFF;
            $c = $v >> 16;
        }
        while ($c > 0) {
            $v = $r[0] + $c * 977;
            $r[0] = $v & 0xFFFF;
            $c1 = $v >> 16;
            $v = $r[1] + $c1;
            $r[1] = $v & 0xFFFF;
            $c1 = $v >> 16;
            $v = $r[2] + $c + $c1;
            $r[2] = $v & 0xFFFF;
            $c = $v >> 16;
            for ($i = 3; $c > 0 && $i < 16; $i++) {
                $v = $r[$i] + $c;
                $r[$i] = $v & 0xFFFF;
                $c = $v >> 16;
            }
        }
        return self::cmp($r, self::P) >= 0 ? self::subRaw($r, self::P) : $r;
    }

    /** @param list<int> $base @param list<int> $exp @return list<int> */
    private static function fePow(array $base, array $exp): array
    {
        $res = self::ONE;
        for ($i = 15; $i >= 0; $i--) {
            $word = $exp[$i];
            for ($bit = 15; $bit >= 0; $bit--) {
                $res = self::feMul($res, $res);
                if ((($word >> $bit) & 1) === 1) {
                    $res = self::feMul($res, $base);
                }
            }
        }
        return $res;
    }

    /** @param list<int> $a @return list<int> */
    private static function scMod(array $a): array
    {
        // Any 256-bit unsigned integer is strictly less than 2*n.
        return self::cmp($a, self::N) >= 0 ? self::subRaw($a, self::N) : $a;
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function scAdd(array $a, array $b): array
    {
        $r = [];
        $c = 0;
        for ($i = 0; $i < 16; $i++) {
            $v = $a[$i] + $b[$i] + $c;
            $r[$i] = $v & 0xFFFF;
            $c = $v >> 16;
        }
        if ($c > 0 || self::cmp($r, self::N) >= 0) {
            return self::subRaw($r, self::N);
        }
        return $r;
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function scSub(array $a, array $b): array
    {
        return self::cmp($a, $b) >= 0 ? self::subRaw($a, $b) : self::scAdd($a, self::subRaw(self::N, $b));
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function scMul(array $a, array $b): array
    {
        $res = self::ZERO;
        $cur = self::scMod($a);
        for ($i = 0; $i < 16; $i++) {
            $word = $b[$i];
            for ($bit = 0; $bit < 16; $bit++) {
                if ((($word >> $bit) & 1) === 1) {
                    $res = self::scAdd($res, $cur);
                }
                $cur = self::scAdd($cur, $cur);
            }
        }
        return $res;
    }

    /**
     * Jacobian point doubling for a = 0 (secp256k1).
     *
     * @param array{0: list<int>, 1: list<int>, 2: list<int>}|null $P
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}|null
     */
    private static function ptDouble(?array $P): ?array
    {
        if ($P === null) {
            return null;
        }
        [$x1, $y1, $z1] = $P;
        if (self::isZero(self::feNorm($y1))) {
            return null;
        }
        $a = self::feMul($x1, $x1);
        $b = self::feMul($y1, $y1);
        $c = self::feMul($b, $b);
        $xb = self::feAdd($x1, $b);
        $d = self::feSub(self::feSub(self::feMul($xb, $xb), $a), $c);
        $d = self::feAdd($d, $d); // 2 * ((x1 + b)^2 - a - c) = 4 * x1 * y1^2
        $e = self::feAdd(self::feAdd($a, $a), $a); // 3 * a
        $f = self::feMul($e, $e);
        $x3 = self::feSub($f, self::feAdd($d, $d));
        $c2 = self::feAdd($c, $c);
        $c4 = self::feAdd($c2, $c2);
        $c8 = self::feAdd($c4, $c4);
        $y3 = self::feSub(self::feMul($e, self::feSub($d, $x3)), $c8);
        $yz = self::feMul($y1, $z1);
        $z3 = self::feAdd($yz, $yz);
        return [$x3, $y3, $z3];
    }

    /**
     * Jacobian point addition.
     *
     * @param array{0: list<int>, 1: list<int>, 2: list<int>}|null $P
     * @param array{0: list<int>, 1: list<int>, 2: list<int>}|null $Q
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}|null
     */
    private static function ptAdd(?array $P, ?array $Q): ?array
    {
        if ($P === null) {
            return $Q;
        }
        if ($Q === null) {
            return $P;
        }
        [$x1, $y1, $z1] = $P;
        [$x2, $y2, $z2] = $Q;
        $z1z1 = self::feMul($z1, $z1);
        $z2z2 = self::feMul($z2, $z2);
        $u1 = self::feMul($x1, $z2z2);
        $u2 = self::feMul($x2, $z1z1);
        $s1 = self::feMul($y1, self::feMul($z2, $z2z2));
        $s2 = self::feMul($y2, self::feMul($z1, $z1z1));
        $h = self::feNorm(self::feSub($u2, $u1));
        $r = self::feNorm(self::feSub($s2, $s1));
        if (self::isZero($h)) {
            return self::isZero($r) ? self::ptDouble($P) : null;
        }
        $hh = self::feMul($h, $h);
        $hhh = self::feMul($h, $hh);
        $v = self::feMul($u1, $hh);
        $x3 = self::feSub(self::feSub(self::feMul($r, $r), $hhh), self::feAdd($v, $v));
        $y3 = self::feSub(self::feMul($r, self::feSub($v, $x3)), self::feMul($s1, $hhh));
        $z3 = self::feMul(self::feMul($z1, $z2), $h);
        return [$x3, $y3, $z3];
    }

    /**
     * 4-bit windowed scalar multiplication.
     *
     * @param array{0: list<int>, 1: list<int>, 2: list<int>} $P
     * @param list<int> $k
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}|null
     */
    private static function ptMul(array $P, array $k): ?array
    {
        $table = [null, $P];
        for ($i = 2; $i < 16; $i++) {
            $table[$i] = ($i & 1) === 0 ? self::ptDouble($table[$i >> 1]) : self::ptAdd($table[$i - 1], $P);
        }
        return self::mulTable($table, $k);
    }

    /**
     * @param list<int> $k
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}|null
     */
    private static function mulG(array $k): ?array
    {
        if (self::$gTable === null) {
            $G = [self::GX, self::GY, self::ONE];
            $table = [null, $G];
            for ($i = 2; $i < 16; $i++) {
                $table[$i] = ($i & 1) === 0 ? self::ptDouble($table[$i >> 1]) : self::ptAdd($table[$i - 1], $G);
            }
            self::$gTable = $table;
        }
        return self::mulTable(self::$gTable, $k);
    }

    /**
     * @param list<array{0: list<int>, 1: list<int>, 2: list<int>}|null> $table
     * @param list<int> $k
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}|null
     */
    private static function mulTable(array $table, array $k): ?array
    {
        $acc = null;
        for ($i = 15; $i >= 0; $i--) {
            $word = $k[$i];
            for ($shift = 12; $shift >= 0; $shift -= 4) {
                if ($acc !== null) {
                    $acc = self::ptDouble(self::ptDouble(self::ptDouble(self::ptDouble($acc))));
                }
                $w = ($word >> $shift) & 0xF;
                if ($w !== 0) {
                    $acc = $acc === null ? $table[$w] : self::ptAdd($acc, $table[$w]);
                }
            }
        }
        return $acc;
    }

    /**
     * @param array{0: list<int>, 1: list<int>, 2: list<int>}|null $P
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function toAffine(?array $P): array
    {
        if ($P === null) {
            throw new \RuntimeException('Cannot convert point at infinity to affine coordinates');
        }
        [$x, $y, $z] = $P;
        $zInv = self::fePow($z, self::P_MINUS_2);
        $zInv2 = self::feMul($zInv, $zInv);
        $zInv3 = self::feMul($zInv2, $zInv);
        return [self::feNorm(self::feMul($x, $zInv2)), self::feNorm(self::feMul($y, $zInv3))];
    }
}
