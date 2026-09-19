<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The encodings every other class shares: base64url without padding for keys,
 * signatures and envelopes; lowercase base32 for write addresses; sha256 in
 * hex over exact bytes. Nothing here re-encodes what it was given.
 */
final class Codec
{
    public static function b64url(string $bytes): string
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** Accepts both base64url and standard base64, with or without padding, as the service does. */
    public static function unb64url(string $text): string
    {
        $text = rtrim($text, '=');
        // Legacy records may contain unused trailing bits. Identity strings
        // are still compared exactly; only their byte decoding is permissive.
        if (preg_match('/^[A-Za-z0-9+\/_-]*$/D', $text) !== 1 || strlen($text) % 4 === 1) {
            throw new \SodiumException('invalid base64');
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false) {
            throw new \SodiumException('invalid base64');
        }
        return $bytes;
    }

    public static function sha256hex(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /** RFC 4648 base32, lowercased, no padding. The standard library has none. */
    public static function base32(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out .= $alphabet[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    public static function isKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $key) === 1;
    }

    /** JSON the way the wire wants it: no escaped slashes, raw UTF-8, floats kept. */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
