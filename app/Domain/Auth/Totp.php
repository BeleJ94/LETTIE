<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 6 digits, 30 seconds): the codes of
 * authenticator applications. Pure: the secret and the time are given by the caller.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Codes of the previous and next period are accepted (clock drift of the phone). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Base32 text of a random secret ($bytes comes from random_bytes(20)). */
    public static function encodeSecret(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function decodeSecret(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '')) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }
        return $bytes;
    }

    public static function counter(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    public static function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), self::decodeSecret($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Counter of the period the code belongs to, or null when the code is wrong.
     * A counter not greater than $lastCounter is refused: a code is used once.
     */
    public static function verify(string $secret, string $code, int $timestamp, ?int $lastCounter = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $current = self::counter($timestamp);
        for ($counter = $current - self::WINDOW; $counter <= $current + self::WINDOW; $counter++) {
            if (hash_equals(self::code($secret, $counter), $code)) {
                return $lastCounter !== null && $counter <= $lastCounter ? null : $counter;
            }
        }
        return null;
    }

    /** Address understood by authenticator applications (the secret can also be typed by hand). */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** "ABCD EFGH IJKL…": easier to copy by hand. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
