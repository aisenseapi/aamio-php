<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The inbox asked for something this client will not or cannot do, and
 * nothing was sent. Sending again changes nothing; the fix says what can.
 */
final class GateStop extends \RuntimeException
{
    public function __construct(string $reason, public readonly string $fix = 'Open an address whose conditions this client can meet, or update the client.')
    {
        parent::__construct($reason);
    }
}
