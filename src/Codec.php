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
        if ($bytes === '') {
            // PHP 8.1 gives str_split('') one empty element, which the loop
            // below would take for a byte and encode as "aa"; 8.2 gives none.
            return '';
        }
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
    /**
     * What a body may be and still be read as JSON, in all three runtimes:
     * every number finite and nested at most this deep, the outermost array or
     * object counted as one. Anything else is handed over as text (a security
     * review of 13 November 2026).
     */
    public const BODY_MAX_DEPTH = 64;

    /**
     * [path, what] for the first part of $value no reader can hold, in
     * document order, or null: "not finite" for a number such as 1e999, which
     * JSON can write and nothing that reads it can hold, and "too deep" for
     * arrays and objects nested deeper than $limit. Arrays and objects as
     * json_decode gives them, either way. The path names keys and indices with
     * dots, and is "" for $value itself.
     */
    public static function unfitJson(mixed $value, int $limit = self::BODY_MAX_DEPTH): ?array
    {
        $stack = [[$value, '', 0]];
        while ($stack !== []) {
            [$node, $path, $level] = array_pop($stack);
            if (is_float($node)) {
                if (!is_finite($node)) {
                    return [$path, 'not finite'];
                }
                continue;
            }
            if (!is_array($node) && !$node instanceof \stdClass) {
                continue;
            }
            if ($level + 1 > $limit) {
                return [$path, 'too deep'];
            }
            $items = [];
            foreach ((array) $node as $key => $child) {
                $items[] = [$child, $path === '' ? (string) $key : $path . '.' . $key, $level + 1];
            }
            foreach (array_reverse($items) as $item) {
                $stack[] = $item;
            }
        }

        return null;
    }

    /** What is wrong with an argument unfitJson found, as all three runtimes say it. */
    public static function unfitText(array $unfit): string
    {
        [$path, $what] = $unfit;
        $where = $path === '' ? 'arguments' : $path;
        if ($what === 'not finite') {
            return $where . ' is a number that is not finite, as 1e999 is: JSON can write it, and nothing that reads it can hold it';
        }

        return $where . ' is nested deeper than ' . self::BODY_MAX_DEPTH . ': a reader hands a message nested that deep over as text';
    }

    /**
     * A JSON value decoded with its objects kept, made into what this client
     * reads elsewhere: an object is an array where an array says the same, and
     * stays a stdClass where an array would make it a list, as {} and an
     * object whose keys are 0, 1, 2 in order. Read as arrays, {"0": "sample"}
     * went out and came in as ["sample"], and {} as [] (a check of 13 November
     * 2026); a reader of $data['n'] reads it as before.
     */
    public static function jsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            if ($fields === [] || array_is_list($fields)) {
                $kept = new \stdClass();
                foreach ($fields as $key => $inner) {
                    $kept->{(string) $key} = self::jsonValue($inner);
                }

                return $kept;
            }

            return array_map([self::class, 'jsonValue'], $fields);
        }
        if (is_array($value)) {
            return array_map([self::class, 'jsonValue'], $value);
        }

        return $value;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
