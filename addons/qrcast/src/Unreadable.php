<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/** A picture the reader here cannot open, or no reader here at all. */
final class Unreadable extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix)
    {
        parent::__construct($fix);
    }
}
