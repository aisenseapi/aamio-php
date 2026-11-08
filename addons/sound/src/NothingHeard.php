<?php

declare(strict_types=1);

namespace Aamio\Sound;

/** A recording that holds no complete message, as aisenseapi.com read it. */
final class NothingHeard extends \RuntimeException
{
    public function __construct(public readonly string $fix)
    {
        parent::__construct($fix);
    }
}
