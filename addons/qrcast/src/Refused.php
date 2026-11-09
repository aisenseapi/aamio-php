<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/** Input refused, here before anything was made or sent, or by aisenseapi.com in its own words. */
final class Refused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix)
    {
        parent::__construct($fix);
    }
}
