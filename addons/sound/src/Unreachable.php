<?php

declare(strict_types=1);

namespace Aamio\Sound;

/** aisenseapi.com, the speaker or the microphone could not do it now. */
final class Unreachable extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix, public readonly ?string $retryAfter = null)
    {
        parent::__construct($fix);
    }
}
