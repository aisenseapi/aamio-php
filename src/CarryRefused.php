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
    /**
     * What was read of the envelope when it was refused after its sender was verified, so the carrier can say who it
     * came from (A7); null for a refusal before that.
     *
     * @var array<string, mixed>|null
     */
    public ?array $reading = null;

    public function __construct(public readonly string $carryCode, string $detail = '')
    {
        parent::__construct($carryCode . ($detail === '' ? '' : ': ' . $detail));
    }
}
