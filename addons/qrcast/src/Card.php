<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * The card a QR code carries, and the rule that keeps every key off the screen.
 *
 *     aamio:scope/k7rq2m4xw5tbz3yd6ena
 *     aamio:scope/k7rq2m4xw5tbz3yd6ena?n=stue-tv&t=1794200000
 *     aamio:scope/k7rq2m4xw5tbz3yd6ena?t=1794200000&v=bt:00:00:5E:00:53:01&k=KEY
 *
 * The same card as the earshot add-on's, read by the same rules, so a card seen
 * and a card heard are one thing: the shared vectors in the tests hold both to
 * it. The address is the write capability of a scope: whoever holds it can post
 * into the scope, and only whoever holds the key can read what was posted. It is
 * no secret in aamio, which is why it may be shown. A key may not: a scope's key
 * and a thread's read key are 26 to 64 characters of a-z and 0-9, and a card is
 * made from exactly 20 characters of a-z and 2-7 and nothing else. n is a name to
 * keep the scope under, a hint the reader may take or leave, and t the Unix time
 * the card stops being good, so a photo of an old code is turned down. t is not
 * signed: whoever saw the code can make a new one with a later time, so a new
 * scope for each occasion is still what stops the rest.
 *
 * From 0.2.0 a card may say more, as earshot's does from 0.5.0. v is the media
 * the one who shows it prefers answers over, in order, at most three, each once:
 * internet, the scope over aamio.at as a card without v means, or the medium of
 * a transport as its manifest names it, as lan or bt, alone or with where they
 * are reached on it as the transport says it (contract 3, B3: here), as
 * bt:00:00:5E:00:53:01. k is their public key, 43 characters of base64url, which
 * a reader binds a transport to; a card that names a medium other than internet
 * carries it. Nothing here knows what a medium is: a transport made tomorrow is
 * carried the same way, and a medium a reader has no transport for is said
 * rather than refused. A public key is public, and a scope's key or a thread's
 * read key never takes its place: k refuses their shape as the address does.
 *
 * A card this version does not read, with a parameter it does not know, is not
 * understood rather than read in part: a later card may name another service,
 * and must never be taken for one on this. A card is at most 128 bytes, as one
 * sound carries, so the same card goes both ways. The same as aamio-python's
 * aamio_qrcast.card.
 */
final class Card
{
    public const PREFIX = 'aamio:scope/';
    public const ADDRESS = '/^[a-z2-7]{20}$/D';
    public const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D';
    /** A scope key or a thread's read key, as aamio makes them. */
    public const KEY_SHAPED = '/^[a-z0-9]{26,64}$/D';
    /** Unix seconds, as a card carries them: nine to eleven digits. */
    public const EXPIRY = '/^[0-9]{9,11}$/D';
    public const MAX_BYTES = 128;
    /**
     * The media a card may ask answers over: internet, or a transport's medium as its manifest names it, each alone or
     * with where it is reached, as the transport says it: medium:address.
     */
    public const MEDIUM = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';
    public const ENTRY = '/^([a-z0-9][a-z0-9_-]{0,31})(?::([A-Za-z0-9._:-]{1,64}))?$/D';
    public const INTERNET = 'internet';
    public const VIA_MOST = 3;
    /** A public key as aamio prints it: 32 bytes of Ed25519 in base64url without padding. */
    public const PUBLIC_KEY = '/^[A-Za-z0-9_-]{43}$/D';
    public const VIA_FIX = 'via is the media you prefer answers over, in order, at most three and each once: internet, the scope over aamio.at, '
        . 'or the medium of a transport, as lan or bt, alone or with where you are reached on it as aamio transport here shows it, '
        . 'as bt:00:00:5E:00:53:01. As text they are separated by commas, as bt:00:00:5E:00:53:01,internet.';
    public const KEY_FIX = 'key is your public key, the 43 characters aamio whoami shows as key. A card that names a medium other than internet carries it, and everyone who sees the code can read it.';

    /** The medium of a via entry: bt for bt:00:00:5E:00:53:01, internet for internet. */
    public static function mediumOf(string $entry): string
    {
        return explode(':', $entry, 2)[0];
    }

    public static function checkAddress(mixed $address): string
    {
        if (is_string($address) && preg_match(self::ADDRESS, $address) === 1) {
            return $address;
        }
        if (is_string($address) && preg_match(self::KEY_SHAPED, $address) === 1) {
            throw new Refused('a_key_not_an_address', 'That has the shape of a key, and a key is never shown: anyone who saw or photographed the code could read with it. Give the scope\'s write address, the 20 characters aamio scope list shows (aamio_scopes over MCP).');
        }
        throw new Refused('not_a_write_address', 'Give the write address of a scope: exactly 20 characters of a-z and 2-7, as aamio scope list shows it (aamio_scopes over MCP). With no scope for the room yet, make one first with aamio scope new NAME (aamio_scope_new).');
    }

    public static function checkName(mixed $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        if (is_string($name) && preg_match(self::NAME, $name) === 1) {
            return $name;
        }
        throw new Refused('invalid_name', 'The name is a hint the readers may keep the scope under: 1 to 32 letters, digits, dots, underscores and dashes, starting with a letter or a digit. It can be left out.');
    }

    /**
     * The entries in a v, each a medium or medium:address, or null when the text is not one: at most VIA_MOST, each
     * medium once, and internet never with an address.
     *
     * @return list<string>|null
     */
    private static function media(string $via): ?array
    {
        $entries = explode(',', $via);
        if (count($entries) > self::VIA_MOST) {
            return null;
        }
        foreach ($entries as $entry) {
            if (preg_match(self::ENTRY, $entry) !== 1) {
                return null;
            }
        }
        $media = array_map([self::class, 'mediumOf'], $entries);
        if (count(array_unique($media)) !== count($media)) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry !== self::INTERNET && self::mediumOf($entry) === self::INTERNET) {
                return null;
            }
        }

        return $entries;
    }

    /**
     * The entries a card asks answers over, or null. internet alone is what a card without v says, so it adds nothing.
     *
     * @return list<string>|null
     */
    public static function checkVia(mixed $via): ?array
    {
        if ($via === null || $via === '' || $via === []) {
            return null;
        }
        if (is_array($via) && array_is_list($via) && array_filter($via, 'is_string') === $via) {
            $via = implode(',', $via);
        }
        $entries = is_string($via) ? self::media($via) : null;
        if ($entries === null) {
            throw new Refused('invalid_via', self::VIA_FIX);
        }

        return $entries === [self::INTERNET] ? null : $entries;
    }

    /** Whether $key is a public key as aamio prints it, and not a secret of the shape a card refuses. */
    private static function isPublic(mixed $key): bool
    {
        if (!is_string($key) || preg_match(self::PUBLIC_KEY, $key) !== 1 || preg_match(self::KEY_SHAPED, $key) === 1) {
            return false;
        }
        $raw = base64_decode(strtr($key, '-_', '+/') . '=', true);

        return is_string($raw) && strlen($raw) === 32 && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $key;
    }

    public static function checkKey(mixed $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (is_string($key) && preg_match(self::KEY_SHAPED, $key) === 1) {
            throw new Refused('a_key_not_a_public_key', 'That has the shape of a scope\'s key or a thread\'s read key, and those are never shown: anyone who saw or photographed the code could read with it. Give your public key, the 43 characters aamio whoami shows as key.');
        }
        if (!self::isPublic($key)) {
            throw new Refused('invalid_key', self::KEY_FIX);
        }

        return $key;
    }

    /**
     * The card for a scope's write address, good until $expires (Unix seconds) when given, or Refused. $via and $key
     * make the card of 0.2.0; without them it is the card earlier versions made.
     */
    public static function make(mixed $address, mixed $name = null, mixed $expires = null, mixed $via = null, mixed $key = null): string
    {
        $address = self::checkAddress($address);
        $name = self::checkName($name);
        $media = self::checkVia($via);
        $key = self::checkKey($key);
        $parts = $name !== null ? ['n=' . $name] : [];
        if ($expires !== null) {
            if (!is_int($expires) || preg_match(self::EXPIRY, (string) $expires) !== 1) {
                throw new Refused('invalid_expiry', 'The time a card stops being good is Unix seconds, nine to eleven digits.');
            }
            $parts[] = 't=' . $expires;
        }
        $beside = $media === null ? [] : array_values(array_filter(array_map([self::class, 'mediumOf'], $media), static fn (string $medium): bool => $medium !== self::INTERNET));
        if ($beside !== [] && $key === null) {
            throw new Refused('key_needed', 'A card that asks for answers over ' . implode(', ', $beside) . ' carries the public key a reader binds that medium to. ' . self::KEY_FIX);
        }
        if ($media !== null) {
            $parts[] = 'v=' . implode(',', $media);
        }
        if ($key !== null) {
            $parts[] = 'k=' . $key;
        }
        $text = self::PREFIX . $address . ($parts !== [] ? '?' . implode('&', $parts) : '');
        if (strlen($text) > self::MAX_BYTES) {
            throw new Refused('card_too_long', 'The card would be ' . strlen($text) . ' bytes, and a card is at most ' . self::MAX_BYTES . ', as one sound carries: shorten the name or leave it out, or name fewer media. '
                . 'Where a medium is reached takes room too: bt with its Bluetooth address is 18 bytes longer than bt alone.');
        }

        return $text;
    }

    /**
     * The card in what was seen, with via and key when it carries them, or NotUnderstood. via is the entries as the card
     * has them: a medium, or medium:address.
     *
     * @return array<string, mixed>
     */
    public static function read(mixed $text): array
    {
        if (!is_string($text) || !str_starts_with($text, self::PREFIX)) {
            throw new NotUnderstood('not_an_aamio_card');
        }
        $rest = substr($text, strlen(self::PREFIX));
        $mark = strpos($rest, '?');
        $address = $mark === false ? $rest : substr($rest, 0, $mark);
        if (preg_match(self::ADDRESS, $address) !== 1) {
            throw new NotUnderstood('not_a_write_address');
        }
        $found = ['n' => null, 't' => null, 'v' => null, 'k' => null];
        $seen = [];
        if ($mark !== false) {
            foreach (explode('&', substr($rest, $mark + 1)) as $part) {
                $equals = strpos($part, '=');
                $key = $equals === false ? $part : substr($part, 0, $equals);
                if (!array_key_exists($key, $found) || $equals === false || isset($seen[$key])) {
                    throw new NotUnderstood('not_this_version');
                }
                $seen[$key] = true;
                $value = substr($part, $equals + 1);
                if ($key === 'n' && preg_match(self::NAME, $value) !== 1) {
                    throw new NotUnderstood('invalid_name');
                }
                if ($key === 't' && preg_match(self::EXPIRY, $value) !== 1) {
                    throw new NotUnderstood('invalid_expiry');
                }
                if ($key === 'v' && self::media($value) === null) {
                    throw new NotUnderstood('invalid_via');
                }
                if ($key === 'k' && !self::isPublic($value)) {
                    throw new NotUnderstood('invalid_key');
                }
                $found[$key] = $value;
            }
        }
        $seenCard = ['kind' => 'scope', 'address' => $address, 'name' => $found['n'], 'expires_at' => $found['t'] !== null ? (int) $found['t'] : null];
        // A card without v and k reads as it always did, and one with them says them.
        if ($found['v'] !== null) {
            $seenCard['via'] = explode(',', $found['v']);
        }
        if ($found['k'] !== null) {
            $seenCard['key'] = $found['k'];
        }

        return $seenCard;
    }
}
