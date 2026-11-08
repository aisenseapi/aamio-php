<?php

declare(strict_types=1);

namespace Aamio;

/**
 * A call to a contract-2 add-on settled before its process starts: a bad
 * argument, no such subject, a website the user has not agreed to. fix says
 * what to do instead, and status is what the answer says it is.
 */
final class AddonRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $fix, public readonly string $status = 'invalid')
    {
        parent::__construct($fix);
    }
}
