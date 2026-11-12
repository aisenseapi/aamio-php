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
        /** Null when the failure came before the message had an outbox entry. */
        public readonly ?string $messageId,
        public readonly int $status,
        public readonly mixed $detail,
        /**
         * When the message that did not land was the one carrying the address
         * of a channel just opened: the channel is there, and the caller has
         * to be told which, or it is a thread nobody knows about.
         */
        public readonly ?array $opened = null,
        /**
         * The trace of this attempt, when it could not be written. A delivery
         * said so on its answer and a failure did not: a command that sends
         * once and exits never fetches attention, and was told of the refusal
         * and not of the trace (a check of 12 November 2026).
         */
        public readonly ?string $traceError = null,
        /** And the archive's record of the attempt, when that could not be written. */
        public readonly ?string $archiveError = null,
    ) {
        $reason = is_array($detail) ? (string) ($detail['error'] ?? json_encode($detail)) : (string) $detail;
        parent::__construct(sprintf('send %s (%s): %s', $outcome, $status === 0 ? 'no answer' : (string) $status, $reason));
    }

    /** What the failure says of the trace, for the command line and the MCP server alike: trace_error, or nothing. */
    public function traceSaid(): array
    {
        return $this->traceError === null ? [] : ['trace_error' => $this->traceError];
    }

    /** What the failure says of the archive, the same way: archive_error, or nothing. */
    public function archiveSaid(): array
    {
        return $this->archiveError === null ? [] : ['archive_error' => $this->archiveError];
    }
}
