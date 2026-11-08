<?php

declare(strict_types=1);

namespace Aamio\Sound;

/** Input refused, here before anything was sent anywhere, or by aisenseapi.com in its own words. */
final class Refused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix)
    {
        parent::__construct($fix);
    }
}
