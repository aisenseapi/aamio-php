<?php

declare(strict_types=1);

namespace Aamio;

/** The transport cannot be used as it is: it said something contract 3 does not allow, or stopped answering. */
final class TransportFailed extends \RuntimeException
{
    public function __construct(public readonly string $transportCode, string $detail = '')
    {
        parent::__construct($transportCode . ($detail === '' ? '' : ': ' . $detail));
    }
}
