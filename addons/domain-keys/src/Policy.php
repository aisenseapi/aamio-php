<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;

/**
 * The words an answer comes with, and the one check this add-on does.
 *
 * Lists is the format and Fetch is the read. Which key is compared and which
 * websites may be read at all is the core's from 0.2.0: it resolves a partner
 * or a verified message to a key, keeps the user's list of websites, asks the
 * user in a form over MCP, and holds the limits per add-on. This class gets a
 * host the user agreed to and a subject with a key, reads once, and says what
 * it found in words that come from why it came and not from the status alone.
 *
 * AAMIO_DOMAIN_DENY adds networks that are never read, comma separated, for an
 * operator's own global ranges; an entry that does not parse stops the check
 * rather than leave a range open.
 *
 * A result is a dated observation and it changes nothing: no partner, no
 * allowlist, no permission and nothing in the outbox. The core treats it as text.
 */
final class Policy
{
    /** One exit code per answer, so a script need not read the JSON to tell a key that is not listed from a list it could not read. */
    public const EXIT_CODES = ['listed' => 0, 'not_listed' => 3, 'no_list' => 4, 'unreachable' => 5, 'invalid' => 2];

    public const STATEMENTS = [
        'listed' => 'This exact website listed this key at the time of this check. That is all it says: not who holds the key and not what they may do, and nothing changed here because of it.',
        'not_listed' => "This website's list did not have this key at the time of this check. That is no evidence of fraud: a key rotated out or the wrong domain look the same.",
        'no_list' => 'This website publishes no key list at the exact address, so it says nothing about this key.',
        'unreachable' => 'The list could not be read, so nothing is known about this key. This is not the same as not listed.',
        'invalid' => 'What this website answered could not be used as a list, so nothing is known about this key.',
    ];

    // The two answers a status alone would describe wrongly. match() says
    // not_listed for a key under another label too, on purpose, since a check for
    // a label is a check for that label, and target_not_public stops before any
    // connection, so no website answered anything.
    public const NOT_UNDER_THAT_LABEL = "This website's list did not have this key under the label asked for at the time of this check. It may hold the key under another label, and a check with no name shows that. That is no evidence of fraud: a key rotated out, the wrong label or the wrong domain look the same.";
    public const NOT_CONTACTED = 'This website was not contacted: an address its name points to is not a public one, or is on a network kept out here. So nothing is known about this key.';

    // A site whose certificate fails here may be fine everywhere else: the machine's store may lack its root.
    public const TLS_NOTE = "The certificate could not be verified on this machine. If the site opens in a browser, this machine's store may lack its root: openssl.cafile in php.ini can point PHP at a CA bundle, and verification is never switched off.";

    /** What a Refused looks like as an answer. Nothing was read, so it carries no time and no statement. */
    public static function refusal(Refused $refused, ?string $host = null, ?array $subject = null, mixed $name = null): array
    {
        $answer = ['status' => $refused->status, 'reason' => $refused->reason, 'domain' => $host, 'fix' => $refused->fix] + ($subject ?? []);
        if ($name !== null) {
            $answer['requested_name'] = $name;
        }

        return $answer;
    }

    /**
     * The networks an operator keeps out, from AAMIO_DOMAIN_DENY.
     *
     * @return list<array{0:string,1:int}>
     * @throws Refused
     */
    public static function denyNetworks(): array
    {
        $networks = [];
        foreach (explode(',', (string) getenv('AAMIO_DOMAIN_DENY')) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $network = Fetch::network($part);
            if ($network === null) {
                throw new Refused('domain_deny_invalid', 'AAMIO_DOMAIN_DENY holds something that is not a network, so no website is read until it is put right. It takes a comma separated list such as 203.0.113.0/24,2001:db8::/32.');
            }
            $networks[] = $network;
        }

        return $networks;
    }

    /** An answer in words, from why it came and what was asked and not from its status alone. */
    public static function statement(array $found, mixed $name = null): string
    {
        if (($found['reason'] ?? null) === 'target_not_public') {
            return self::NOT_CONTACTED;
        }
        if ($found['status'] === 'not_listed' && $name !== null) {
            return self::NOT_UNDER_THAT_LABEL;
        }

        return self::STATEMENTS[$found['status']];
    }

    /**
     * Read the list at a website the user agreed to, once, and say whether it lists the subject's key.
     *
     * $subject is what the core resolved: key, subject_source and what goes with
     * it. Refused before anything is looked up: a bad domain or name, no subject,
     * or a keep-out list that does not parse. $network is passed on to
     * Fetch::check, for tests.
     */
    public static function check(mixed $domain, mixed $subject, mixed $name = null, array $network = []): array
    {
        $host = null;
        try {
            $host = Lists::normaliseDomain($domain);
            if ($host === null) {
                throw new Refused('invalid_domain', 'A domain is a plain host name of two labels or more, as example.org, with no https://, port or path. www.example.org and example.org are different places. Internationalised names are not read yet.');
            }
            if ($name !== null && !Lists::validName($name)) {
                throw new Refused('invalid_requested_name', 'name is the label on the list: 1 to 64 characters of a-z, 0-9, dot, underscore and dash. Leave it out to look for the key under any label.');
            }
            if (!is_array($subject) || !Lists::canonicalKey($subject['key'] ?? null)) {
                throw new Refused('subject_missing', 'A check needs the key aamio resolves from the address book or from a signature it checked itself; none came with this call.');
            }
            $deny = self::denyNetworks();
        } catch (Refused $refused) {
            return self::refusal($refused, $host, is_array($subject) ? $subject : null, $name);
        }
        $found = Fetch::check($host, $subject['key'], $name, [$host], $deny, ...$network);
        $rest = $subject;
        unset($rest['key']);
        $answer = $rest + $found;
        $answer['statement'] = self::statement($found, $name);
        if (($found['reason'] ?? null) === 'tls') {
            $answer['note'] = self::TLS_NOTE;
        }

        return $answer;
    }
}
