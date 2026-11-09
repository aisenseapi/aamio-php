<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/** Something seen that is not a card this version reads. */
final class NotUnderstood extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
