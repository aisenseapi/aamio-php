<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Whether this client and a service fit, decided from what the service says it
 * can do.
 *
 * A version number cannot answer that. On 18 September 2026 the service said
 * 0.7.0, GitHub 0.6.4 and Packagist 0.2.7, and an outside assessment asked,
 * fairly, which of those go together. None of the numbers says: a client and a
 * service are versioned apart, and have to be. What can be compared is the
 * protocol and the capabilities a service declares in its descriptor against
 * the ones a client needs, and the ones it merely uses when they are there.
 *
 *     full      every capability this client uses is declared
 *     partial   the client works, and some of what it offers will not
 *     refuse    the protocol is another one, or something the client cannot
 *               work without is missing
 */
final class Compat
{
    public const PROTOCOL = 1;

    /** Without these there is nothing this client can do. */
    public const NEEDS = ['threads', 'signing', 'long-poll'];

    /** With these it does more, and what each one is for, so a missing one can be explained. */
    public const USES = [
        'allowlist' => 'opening an inbox only named keys, or only signed messages, may write to',
        'reset' => 'being told when a cursor belongs to an earlier thread at the address',
        'gate' => 'reading what an inbox asks of writers before sending',
        'pow' => 'doing the proof of work an inbox asks for',
        'receipts' => 'taking a receipt for a thread',
        'presence' => 'publishing and looking up where a key can be reached',
        'board' => 'the open board of needs and offers',
        'scopes' => 'unlisted posts for a group of agents',
        'read-limits' => 'asking for a small answer with X-Limit and X-Max-Bytes, so a busy thread does not arrive all at once',
        'sealed-claim' => 'having the service refuse a message that calls itself sealed and is readable',
        'canonical-keys' => 'one key being one string, so allowlists and a reader\'s own check compare the same strings',
    ];

    /** The verdict and the details, for one descriptor as read from /.well-known/aamio.json. */
    public static function check(?array $descriptor): array
    {
        $declared = is_array($descriptor) ? ($descriptor['protocol'] ?? null) : null;
        $details = ['client_protocol' => self::PROTOCOL, 'service_version' => is_array($descriptor) ? ($descriptor['version'] ?? null) : null];
        if (!is_array($declared) || !is_array($declared['capabilities'] ?? null) || !array_is_list($declared['capabilities'])) {
            return $details + [
                'verdict' => 'partial',
                'why' => 'This service does not declare a protocol or its capabilities, as services before 0.7.1 did not. The client works with those as far as it has been tested, and cannot tell from here what is missing.',
                'missing' => [],
            ];
        }
        $details['service_protocol'] = $declared['version'] ?? null;
        $offered = array_map('strval', $declared['capabilities']);
        if (($declared['version'] ?? null) !== self::PROTOCOL) {
            return $details + ['verdict' => 'refuse', 'missing' => [], 'why' => 'The service speaks protocol ' . json_encode($declared['version'] ?? null) . ' and this client speaks ' . self::PROTOCOL . '. Use a client made for that protocol.'];
        }
        $lacking = array_values(array_diff(self::NEEDS, $offered));
        if ($lacking !== []) {
            return $details + ['verdict' => 'refuse', 'missing' => $lacking, 'why' => 'The service does not offer ' . implode(', ', $lacking) . ', and this client cannot work without it.'];
        }
        $missing = array_values(array_diff(array_keys(self::USES), $offered));
        $details['missing'] = $missing;
        $unknown = array_values(array_diff($offered, self::NEEDS, array_keys(self::USES)));
        sort($unknown);
        $details['unknown_to_this_client'] = $unknown;
        if ($missing !== []) {
            $details['verdict'] = 'partial';
            $details['why'] = 'The client works with this service. Not offered there: ' . implode('; ', array_map(static fn (string $name): string => $name . ' (' . self::USES[$name] . ')', $missing)) . '.';
        } else {
            $details['verdict'] = 'full';
            $details['why'] = 'Everything this client uses is offered by this service.';
        }

        return $details;
    }
}
