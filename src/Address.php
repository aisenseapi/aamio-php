<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The read key and the write address. The read key is made here, from a
 * CSPRNG, and travels only in the X-Read header. The write address is the
 * first 20 characters of the lowercase base32 of sha256(id), the same on
 * every client and on the service.
 */
final class Address
{
    public const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public static function newId(int $length = 26): string
    {
        if ($length < 20 || $length > 64) {
            throw new \InvalidArgumentException('an id is 20 to 64 characters');
        }
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ID_ALPHABET[random_int(0, 35)];
        }

        return $out;
    }

    public static function isId(string $id): bool
    {
        return preg_match('/^[a-z0-9]{20,64}$/', $id) === 1;
    }

    public static function isW(string $w): bool
    {
        return preg_match('/^[a-z2-7]{20}$/', $w) === 1;
    }

    public static function w(string $id): string
    {
        if (!self::isId($id)) {
            throw new \InvalidArgumentException('an id is 20 to 64 characters of a-z and 0-9');
        }

        return substr(Codec::base32(hash('sha256', $id, true)), 0, 20);
    }
}
