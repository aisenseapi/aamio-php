<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/** More bytes than a version 10 code at level M holds. */
final class TooLong extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
