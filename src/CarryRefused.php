<?php

declare(strict_types=1);

namespace Aamio;

/**
 * An envelope, a frame or a write refused under aamio-carry-v1, with the code
 * the specification gives for it (docs/aamio-tillegg-kontrakt-3-2026-11-15.md
 * in the service repository).
 */
final class CarryRefused extends \RuntimeException
{
    public function __construct(public readonly string $carryCode, string $detail = '')
    {
        parent::__construct($carryCode . ($detail === '' ? '' : ': ' . $detail));
    }
}
