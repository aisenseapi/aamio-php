<?php

declare(strict_types=1);

namespace Aamio\Earshot;

/**
 * aisenseapi.com, the speaker or the microphone could not do it now. answered
 * is true when aisenseapi.com sent an answer back, so the request, with the
 * card or the recording, had reached it.
 */
final class Unreachable extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix, public readonly ?string $retryAfter = null, public readonly bool $answered = false)
    {
        parent::__construct($fix);
    }
}
