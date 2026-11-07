<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;


/**
 * Domain keys: which public aamio keys a website says are its own.
 *
 * A site can publish the public keys of the agents it stands behind at
 *
 *     https://<domain>/.well-known/aamio-keys.json
 *
 * as {"aamio_keys": 1, "keys": [{"key": "<43 characters>", "name": "support"}]},
 * and a reader who already knows the domain from its own records can ask
 * whether the key behind a message it checked is on that list. This class is
 * the format and nothing else: a strict parser, the match, a generator for the
 * file, and the syntax of a domain. It opens no connection. Fetching the list
 * is the separate, hardened step in Fetch, and nothing here may be taken
 * for it.
 *
 * A listed key says that this exact site listed this key when it was read. It
 * is not an identity, an authority to act or proof that a message is true, and
 * it changes nothing by itself: no partner is added and no allowlist is touched.
 *
 * The format, version 1:
 *
 * - a JSON object whose aamio_keys is the integer 1, and keys, a list of at most
 *   64 objects, each with key, the public key as 43 characters of base64url that
 *   encode back to themselves, and an optional name of 1 to 64 characters of
 *   [a-z0-9._-], which labels and authorises nothing;
 * - at most 16384 bytes of UTF-8 with no byte order mark, nested at most 16
 *   deep, finite numbers only and integers within 2**53 - 1, so every client can
 *   agree;
 * - a duplicate key, or a duplicate member name in any object, makes the whole
 *   document invalid, and so does one bad entry: a list is never shown as valid
 *   with the bad entries filtered out;
 * - members this version does not know mean nothing and are allowed;
 * - an empty list is valid, and is how a site says it lists no key.
 *
 * json_decode keeps the last of two members of the same name without a word, so
 * it cannot hold the rule about duplicates, and the reader here is its own.
 * tests/domain_keys_vectors.json holds what every client has to answer, and is
 * the same file aamio-python keeps.
 */
final class Lists
{
    public const VERSION = 1;
    public const PATH = '/.well-known/aamio-keys.json';
    public const MAX_BYTES = 16384;
    public const MAX_KEYS = 64;
    public const MAX_DEPTH = 16;
    /** 2**53 - 1, as text, so a 32-bit PHP compares it the same way. */
    private const MAX_INTEGER = '9007199254740991';

    /** A public key as aamio writes it: 32 bytes, 43 characters, one spelling only. */
    public static function canonicalKey(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            return false;
        }
        $raw = base64_decode(strtr($value, '-_', '+/') . '=', true);

        // A last character carrying bits past the 32 bytes decodes to the same
        // bytes as another string; only the one an encoder writes is the key.
        return $raw !== false && strlen($raw) === 32 && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $value;
    }

    /** A name a list can carry: 1 to 64 characters of a-z, 0-9, dot, underscore and dash. */
    public static function validName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9._-]{1,64}$/D', $value) === 1;
    }

    /**
     * The exact host a list is read from, in lowercase, or null.
     *
     * Plain ASCII names of two labels or more. No port, path, IP literal,
     * trailing dot, underscore or space is trimmed into shape: anything but a
     * host name is refused. Internationalised names, xn-- labels, wait until
     * every client converts them the same way. www.example.org and example.org
     * are different places, and no parent domain lends its list to a child.
     */
    public static function normaliseDomain(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        // Not strtolower, which went by the locale before PHP 8.2.
        $domain = strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        $labels = explode('.', $domain);
        if (strlen($domain) < 1 || strlen($domain) > 253 || count($labels) < 2) {
            return null;
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1 || str_starts_with($label, 'xn--')) {
                return null;
            }
        }

        // A last label of digits or hex is an old spelling of an IPv4 address.
        return preg_match('/^(?:[0-9]+|0x[0-9a-f]+)$/D', $labels[count($labels) - 1]) === 1 ? null : $domain;
    }

    /** Where the list of this exact domain is, over HTTPS on the default port, or null. */
    public static function urlFor(mixed $domain): ?string
    {
        $host = self::normaliseDomain($domain);

        return $host === null ? null : 'https://' . $host . self::PATH;
    }

    /**
     * The entries of a version 1 list, as ['key' => ...] or ['key' => ..., 'name' => ...].
     *
     * @return list<array{key:string,name?:string}>
     * @throws InvalidList
     */
    public static function parse(mixed $raw): array
    {
        if (!is_string($raw)) {
            throw new InvalidList('not_bytes');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidList('body_limit');
        }
        // PCRE refuses overlong forms and surrogates, as a strict decoder does.
        if (preg_match('//u', $raw) !== 1) {
            throw new InvalidList('not_utf8');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            throw new InvalidList('byte_order_mark');
        }
        $at = 0;
        $value = self::value($raw, $at, 1);
        self::skip($raw, $at);
        if ($at !== strlen($raw)) {
            throw new InvalidList('invalid_json');
        }
        if (!$value instanceof \ArrayObject) {
            throw new InvalidList('object_required');
        }
        // === 1 and nothing looser: 1.0, 1e0 and true are not the integer 1.
        if (($value['aamio_keys'] ?? null) !== self::VERSION) {
            throw new InvalidList('unsupported_version');
        }
        $rows = $value['keys'] ?? null;
        if (!is_array($rows) || count($rows) > self::MAX_KEYS) {
            throw new InvalidList('invalid_keys');
        }
        $seen = [];
        $entries = [];
        foreach ($rows as $row) {
            if (!$row instanceof \ArrayObject || !self::canonicalKey($row['key'] ?? null)) {
                throw new InvalidList('invalid_key');
            }
            $key = $row['key'];
            if (isset($seen[$key])) {
                throw new InvalidList('duplicate_key');
            }
            $seen[$key] = true;
            $entry = ['key' => $key];
            // offsetExists, which says yes to a member that is null, and a name
            // of null is a bad name and not a missing one.
            if ($row->offsetExists('name')) {
                if (!self::validName($row['name'])) {
                    throw new InvalidList('invalid_name');
                }
                $entry['name'] = $row['name'];
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Whether this exact document lists key, and under name when one is asked for.
     *
     * status is listed, not_listed or invalid. not_listed is no evidence of
     * fraud: a key that was rotated out, the wrong name or the wrong domain look
     * the same. The document hash is of the exact bytes compared, for comparison
     * and fault finding; it is not a statement the domain signed.
     */
    public static function match(mixed $raw, mixed $key, mixed $name = null): array
    {
        if (!self::canonicalKey($key)) {
            return ['status' => 'invalid', 'reason' => 'invalid_subject_key'];
        }
        if ($name !== null && !self::validName($name)) {
            return ['status' => 'invalid', 'reason' => 'invalid_requested_name'];
        }
        try {
            $entries = self::parse($raw);
        } catch (InvalidList $error) {
            return ['status' => 'invalid', 'reason' => 'invalid_document', 'detail' => $error->reason];
        }
        $digest = hash('sha256', $raw);
        foreach ($entries as $entry) {
            if ($entry['key'] === $key && ($name === null || ($entry['name'] ?? null) === $name)) {
                return ['status' => 'listed', 'reason' => 'key_listed', 'matched_name' => $entry['name'] ?? null, 'document_sha256' => $digest];
            }
        }

        return ['status' => 'not_listed', 'reason' => 'key_or_name_not_listed', 'document_sha256' => $digest];
    }

    /**
     * The file for a site to publish, from public keys and optional names.
     *
     * The bytes are fixed so every generator gives the same file for the same
     * list: two spaces of indentation, aamio_keys before keys, key before name,
     * ASCII only, and a line break at the end. An empty list is allowed, since
     * that is how a site says it lists no key, so callers ask for it on purpose.
     * A list this would refuse to read is refused here as well.
     *
     * @param list<array{key:mixed,name?:mixed}> $entries
     * @throws InvalidList
     */
    public static function make(array $entries): string
    {
        $rows = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !self::canonicalKey($entry['key'] ?? null)) {
                throw new InvalidList('invalid_key');
            }
            if (isset($seen[$entry['key']])) {
                throw new InvalidList('duplicate_key');
            }
            $seen[$entry['key']] = true;
            $row = '    {' . "\n" . '      "key": "' . $entry['key'] . '"';
            if (($entry['name'] ?? null) !== null) {
                if (!self::validName($entry['name'])) {
                    throw new InvalidList('invalid_name');
                }
                $row .= ',' . "\n" . '      "name": "' . $entry['name'] . '"';
            }
            $rows[] = $row . "\n" . '    }';
        }
        if (count($rows) > self::MAX_KEYS) {
            throw new InvalidList('invalid_keys');
        }
        $list = $rows === [] ? '[]' : "[\n" . implode(",\n", $rows) . "\n  ]";
        $raw = '{' . "\n" . '  "aamio_keys": ' . self::VERSION . ',' . "\n" . '  "keys": ' . $list . "\n" . '}' . "\n";
        self::parse($raw);

        return $raw;
    }

    /**
     * A local file against the format, with its exit code. It reads the one
     * file and nothing else: no domain and no runtime's home.
     *
     * @return array{0:array,1:int}
     */
    public static function checkFile(string $path): array
    {
        $stream = is_file($path) ? @fopen($path, 'rb') : false;
        $raw = $stream === false ? false : stream_get_contents($stream, self::MAX_BYTES + 1);
        if ($stream !== false) {
            fclose($stream);
        }
        if ($raw === false) {
            return [['error' => 'local_file_unreadable'], 2];
        }
        try {
            $result = ['format_valid' => true, 'keys' => count(self::parse($raw))];
            $code = 0;
        } catch (InvalidList $error) {
            $result = ['format_valid' => false, 'reason' => 'invalid_document', 'detail' => $error->reason];
            $code = 2;
        }
        // Said every time, so a file found valid here is never taken for a domain read.
        $result['source'] = 'local_file';
        $result['domain_checked'] = false;

        return [$result, $code];
    }

    private static function skip(string $text, int &$at): void
    {
        $at += strspn($text, " \t\r\n", $at);
    }

    /** One JSON value at $at. An object comes back as an ArrayObject and a list as an array, so the two stay apart. */
    private static function value(string $text, int &$at, int $level): mixed
    {
        self::skip($text, $at);
        $first = $text[$at] ?? '';
        if ($first === '{') {
            return self::members($text, $at, $level);
        }
        if ($first === '[') {
            return self::rows($text, $at, $level);
        }
        if ($first === '"') {
            return self::string($text, $at);
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $meaning) {
            if (substr($text, $at, strlen($word)) === $word) {
                $at += strlen($word);

                return $meaning;
            }
        }

        return self::number($text, $at);
    }

    private static function members(string $text, int &$at, int $level): \ArrayObject
    {
        if ($level > self::MAX_DEPTH) {
            throw new InvalidList('depth_limit');
        }
        $at++;
        $found = [];
        self::skip($text, $at);
        if (($text[$at] ?? '') === '}') {
            $at++;

            return new \ArrayObject($found);
        }
        while (true) {
            self::skip($text, $at);
            if (($text[$at] ?? '') !== '"') {
                throw new InvalidList('invalid_json');
            }
            $name = self::string($text, $at);
            if (array_key_exists($name, $found)) {
                throw new InvalidList('duplicate_member');
            }
            self::skip($text, $at);
            if (($text[$at] ?? '') !== ':') {
                throw new InvalidList('invalid_json');
            }
            $at++;
            $found[$name] = self::value($text, $at, $level + 1);
            self::skip($text, $at);
            $next = $text[$at] ?? '';
            $at++;
            if ($next === '}') {
                return new \ArrayObject($found);
            }
            if ($next !== ',') {
                throw new InvalidList('invalid_json');
            }
        }
    }

    /** @return list<mixed> */
    private static function rows(string $text, int &$at, int $level): array
    {
        if ($level > self::MAX_DEPTH) {
            throw new InvalidList('depth_limit');
        }
        $at++;
        $found = [];
        self::skip($text, $at);
        if (($text[$at] ?? '') === ']') {
            $at++;

            return $found;
        }
        while (true) {
            $found[] = self::value($text, $at, $level + 1);
            self::skip($text, $at);
            $next = $text[$at] ?? '';
            $at++;
            if ($next === ']') {
                return $found;
            }
            if ($next !== ',') {
                throw new InvalidList('invalid_json');
            }
        }
    }

    /**
     * A string at $at, as bytes. Two surrogate escapes in a row are one
     * character, the same bytes as the character written out, and one on its
     * own stays what it is, so two different ones are never taken for one name.
     */
    private static function string(string $text, int &$at): string
    {
        static $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];
        // Everything that ends a run: the quote, the backslash and the control
        // characters a string may not hold.
        $stops = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";
        $at++;
        $out = '';
        while (true) {
            $run = strcspn($text, $stops, $at);
            $out .= substr($text, $at, $run);
            $at += $run;
            $stop = $text[$at] ?? '';
            if ($stop === '"') {
                $at++;

                return $out;
            }
            if ($stop !== '\\') {
                throw new InvalidList('invalid_json');
            }
            $escape = $text[$at + 1] ?? '';
            $at += 2;
            if (isset($simple[$escape])) {
                $out .= $simple[$escape];
                continue;
            }
            if ($escape !== 'u') {
                throw new InvalidList('invalid_json');
            }
            $unit = self::unit($text, $at);
            if ($unit >= 0xD800 && $unit <= 0xDBFF && substr($text, $at, 2) === '\\u') {
                $next = $at + 2;
                $low = self::unit($text, $next);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $at = $next;
                    $unit = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                }
            }
            $out .= self::bytes($unit);
        }
    }

    /** The four hexadecimal digits of a \u escape at $at. */
    private static function unit(string $text, int &$at): int
    {
        $digits = substr($text, $at, 4);
        if (strlen($digits) !== 4 || strspn($digits, '0123456789abcdefABCDEF') !== 4) {
            throw new InvalidList('invalid_json');
        }
        $at += 4;

        return (int) hexdec($digits);
    }

    /** A code point as UTF-8, a lone surrogate in the same three-byte form. */
    private static function bytes(int $point): string
    {
        if ($point < 0x80) {
            return chr($point);
        }
        if ($point < 0x800) {
            return chr(0xC0 | ($point >> 6)) . chr(0x80 | ($point & 0x3F));
        }
        if ($point < 0x10000) {
            return chr(0xE0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
        }

        return chr(0xF0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3F)) . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
    }

    private static function number(string $text, int &$at): int|float
    {
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $text, $found, 0, $at) !== 1) {
            throw new InvalidList('invalid_json');
        }
        $token = $found[0];
        $at += strlen($token);
        if (strpbrk($token, '.eE') !== false) {
            $value = (float) $token;
            if (!is_finite($value)) {
                throw new InvalidList('non_finite_number');
            }

            return $value;
        }
        $digits = ltrim($token, '-');
        if (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, self::MAX_INTEGER) > 0)) {
            throw new InvalidList('integer_range');
        }

        return (int) $token;
    }
}
