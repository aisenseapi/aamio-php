<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * aisenseapi.com, the screen or the camera could not do it now. answered is
 * true when aisenseapi.com sent an answer back, so the request, with the card
 * or the picture, had reached it.
 */
final class Unreachable extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix, public readonly ?string $retryAfter = null, public readonly bool $answered = false)
    {
        parent::__construct($fix);
    }
}
