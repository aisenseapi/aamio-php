<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;


/**
 * A domain check stopped: on this machine before anything was read, when $fix
 * says what to do instead, or in the read itself, when there is no fix and
 * $status and $reason are the answer.
 */
final class Refused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $fix = null, public readonly string $status = 'invalid')
    {
        parent::__construct($reason);
    }
}
