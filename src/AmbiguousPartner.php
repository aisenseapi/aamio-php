<?php

declare(strict_types=1);

namespace Aamio;

/**
 * A name that matches two partners apart from upper and lower case, and
 * neither exactly.
 *
 * A name used to be matched without regard to case, and the first partner in
 * the book won. With Ada and ADA in it, a send to ADA's key, or to ada, went to
 * Ada, sealed to Ada's key, and Ada opened a message meant for someone else
 * (finding 1 of the review of 9 November 2026, found in the Node runtime
 * first). Two such partners is an error now, never a guess.
 */
final class AmbiguousPartner extends \InvalidArgumentException implements Coded
{
    public function errorCode(): string
    {
        return 'ambiguous_partner';
    }
}
