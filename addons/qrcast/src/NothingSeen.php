<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/** A picture in which aisenseapi.com found no code. */
final class NothingSeen extends \RuntimeException
{
    public function __construct(public readonly string $fix)
    {
        parent::__construct($fix);
    }
}
