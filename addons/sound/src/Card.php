<?php

declare(strict_types=1);

namespace Aamio\Sound;

/**
 * The card a sound carries, and the rule that keeps every key out of the air.
 *
 *     aamio:scope/k7rq2m4xw5tbz3yd6ena
 *     aamio:scope/k7rq2m4xw5tbz3yd6ena?n=rom-42
 *
 * The address is the write capability of a scope: whoever holds it can post
 * into the scope, and only whoever holds the key can read what was posted. It
 * is no secret in aamio, which is why it may go into the air. A key may not: a
 * scope's key and a thread's read key are 26 to 64 characters of a-z and 0-9,
 * and a card is made from exactly 20 characters of a-z and 2-7 and nothing
 * else. n is a name to keep the scope under, a hint the listener may take or
 * leave. A card this version does not read is not understood rather than read
 * in part. The same as aamio-python's aamio_sound.card.
 */
final class Card
{
    public const PREFIX = 'aamio:scope/';
    public const ADDRESS = '/^[a-z2-7]{20}$/D';
    public const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D';
    /** A scope key or a thread's read key, as aamio makes them. */
    public const KEY_SHAPED = '/^[a-z0-9]{26,64}$/D';
    public const MAX_BYTES = 128;

    public static function checkAddress(mixed $address): string
    {
        if (is_string($address) && preg_match(self::ADDRESS, $address) === 1) {
            return $address;
        }
        if (is_string($address) && preg_match(self::KEY_SHAPED, $address) === 1) {
            throw new Refused('a_key_not_an_address', 'That has the shape of a key, and a key never goes into the air: anyone who recorded the sound could read with it. Give the scope\'s write address, the 20 characters aamio scope list shows (aamio_scopes over MCP).');
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
        throw new Refused('invalid_name', 'The name is a hint the listeners may keep the scope under: 1 to 32 letters, digits, dots, underscores and dashes, starting with a letter or a digit. It can be left out.');
    }

    /** The card for a scope's write address, or Refused. */
    public static function make(mixed $address, mixed $name = null): string
    {
        $address = self::checkAddress($address);
        $name = self::checkName($name);

        return self::PREFIX . $address . ($name !== null ? '?n=' . $name : '');
    }

    /**
     * The card in what was heard, or NotUnderstood.
     *
     * @return array{kind: string, address: string, name: ?string}
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
        $name = null;
        if ($mark !== false) {
            foreach (explode('&', substr($rest, $mark + 1)) as $part) {
                $equals = strpos($part, '=');
                $key = $equals === false ? $part : substr($part, 0, $equals);
                if ($key !== 'n' || $equals === false || $name !== null) {
                    throw new NotUnderstood('not_this_version');
                }
                $value = substr($part, $equals + 1);
                if (preg_match(self::NAME, $value) !== 1) {
                    throw new NotUnderstood('invalid_name');
                }
                $name = $value;
            }
        }

        return ['kind' => 'scope', 'address' => $address, 'name' => $name];
    }
}
