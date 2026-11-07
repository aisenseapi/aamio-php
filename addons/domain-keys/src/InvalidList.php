<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;


/** A domain key list that is not version 1. $reason says why, without echoing the content. */
final class InvalidList extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
