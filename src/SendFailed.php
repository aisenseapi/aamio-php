<?php

declare(strict_types=1);

namespace Aamio;

/**
 * A send that did not land, and everything it knows: which message, whether
 * aamio refused it or never answered, and the status. Refused and unknown
 * want opposite reactions, and unknown is not failure.
 */
final class SendFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $outcome,
        public readonly string $messageId,
        public readonly int $status,
        public readonly mixed $detail,
    ) {
        $reason = is_array($detail) ? (string) ($detail['error'] ?? json_encode($detail)) : (string) $detail;
        parent::__construct(sprintf('send %s (%s): %s', $outcome, $status === 0 ? 'no answer' : (string) $status, $reason));
    }
}
