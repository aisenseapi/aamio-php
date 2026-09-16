<?php

declare(strict_types=1);

namespace Aamio;

/**
 * A receipt says that these messages passed through this thread, with their
 * hashes, times and signer keys, and one root over all of it. This recomputes
 * the root from the lines, so a client trusts a number it can check rather
 * than one it was handed.
 */
final class Receipt
{
    /** root = sha256 of the lines "seq<TAB>at<TAB>sha256<TAB>from-or-dash<LF>" in seq order. */
    public static function root(array $messages): string
    {
        usort($messages, static fn (array $a, array $b): int => ((int) $a['seq']) <=> ((int) $b['seq']));
        $lines = '';
        foreach ($messages as $message) {
            $lines .= (int) $message['seq'] . "\t" . (int) $message['at'] . "\t" . $message['sha256'] . "\t" . (($message['from'] ?? null) ?: '-') . "\n";
        }

        return hash('sha256', $lines);
    }

    /**
     * ['root_adds_up' => bool, 'commitment_matches' => bool, 'local_root_matches' => bool|null]
     * local_root_matches compares against hashes this process saw, and is
     * null when the receipt counts more messages than the client holds,
     * which is a receipt taken later, not a failure.
     */
    public static function verify(array $receipt, ?array $localHashes = null): array
    {
        $root = self::root($receipt['messages'] ?? []);
        $out = [
            'root_adds_up' => hash_equals($root, (string) ($receipt['root'] ?? '')),
            'commitment_matches' => (($receipt['commitment'] ?? null) === 'sha256:' . ($receipt['root'] ?? '')),
            'local_root_matches' => null,
        ];
        if ($localHashes !== null) {
            $seen = array_map(static fn (array $m): string => (string) $m['sha256'], $receipt['messages'] ?? []);
            if (count($seen) <= count($localHashes)) {
                $out['local_root_matches'] = array_slice($localHashes, 0, count($seen)) === $seen;
            }
        }

        return $out;
    }
}
