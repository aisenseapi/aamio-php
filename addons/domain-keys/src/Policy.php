<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;

use Aamio\Runtime;

/**
 * Checking a key against a website, from inside a runtime.
 *
 * Lists is the format and Fetch is the read. This is the part that
 * decides what is asked: which key, and which websites may be read at all.
 *
 * The key never comes from whoever asks. For a partner it comes from the address
 * book, and for a message from the signature this process checked itself. There
 * is no way to hand in a key, a signer or a verified flag, so nothing a message
 * says can choose what is compared. A message read by an earlier process is not
 * known here: what this process checked lives in its memory and is never written
 * down, and a check of one answers source_unavailable rather than guess.
 *
 * The website comes from the user. domains.json in the home is the list of
 * websites this runtime may read, and only the user adds to it: `aamio domain
 * allow` on the command line, or a form in their app over MCP, where they type
 * the domain themselves. A check of a domain that is not on the list stops before
 * anything is looked up, whoever asks. The rule is one rule on every surface, and
 * there is no flag around it. A stranger can list their own key on their own
 * site, so a domain taken from the message being checked proves nothing at all.
 *
 * domains.json is read at each check and not at startup, so a file that cannot be
 * read stops domain checks and nothing else: this add-on is optional, and an
 * agent that uses none of it works as before. Nothing is read, ever, until the
 * user has put a domain on the list, and there is no background work.
 *
 * Twenty checks start per minute in one process, on top of the limits of the read
 * itself. They run one at a time, since this client runs one thing at a time:
 * the server answers nothing else for the few seconds a read can take.
 * AAMIO_DOMAIN_DENY adds networks that are never read, comma separated, for an
 * operator's own global ranges; an entry that does not parse stops the check
 * rather than leave a range open.
 *
 * A result is a dated observation and it changes nothing here: no partner, no
 * allowlist, no permission and nothing in the outbox.
 */
final class Policy
{
    public const POLICY_FILE = 'domains.json';
    public const MAX_PER_WINDOW = 20;
    public const WINDOW_SECONDS = 60.0;

    public const STATEMENTS = [
        'listed' => 'This exact website listed this key at the time of this check. That is all it says: not who holds the key and not what they may do, and nothing changed here because of it.',
        'not_listed' => "This website's list did not have this key at the time of this check. That is no evidence of fraud: a key rotated out or the wrong domain look the same.",
        'no_list' => 'This website publishes no key list at the exact address, so it says nothing about this key.',
        'unreachable' => 'The list could not be read, so nothing is known about this key. This is not the same as not listed.',
        'invalid' => 'What this website answered could not be used as a list, so nothing is known about this key.',
    ];

    /**
     * The two answers a status alone would describe wrongly. match() says
     * not_listed for a key under another label too, on purpose, since a check
     * for a label is a check for that label, and target_not_public stops before
     * any connection, so no website answered anything.
     */
    public const NOT_UNDER_THAT_LABEL = "This website's list did not have this key under the label asked for at the time of this check. It may hold the key under another label, and a check with no name shows that. That is no evidence of fraud: a key rotated out, the wrong label or the wrong domain look the same.";
    public const NOT_CONTACTED = 'This website was not contacted: an address its name points to is not a public one, or is on a network kept out here. So nothing is known about this key.';

    /** A site whose certificate fails here may be fine everywhere else. */
    public const TLS_NOTE = "The certificate could not be verified on this machine. If the site opens in a browser, this machine's store may lack its root: openssl.cafile in php.ini can point PHP at a CA bundle, and verification is never switched off.";

    /**
     * When the checks of the last window started, and the clock they are
     * counted on, which a test can replace.
     *
     * @var list<float>
     */
    public static array $started = [];
    /** @var callable|null */
    public static $clock = null;
    /**
     * A stand-in for the network, for tests: what check() passes on to
     * Fetch::check when it is given nothing itself. Empty in production.
     */
    public static array $standIn = [];

    private static function unreadable(Runtime $runtime): Refused
    {
        return new Refused('domain_policy_unreadable', $runtime->homeFile(self::POLICY_FILE) . ' could not be read as a list of domains, so no website is read. Repair the file, or move it away and add the domains again with aamio addon domain allow.');
    }

    /**
     * The websites the user has agreed to, from the file as it is now. A file that cannot be read is refused, never an empty list.
     *
     * @return list<string>
     * @throws Refused
     */
    public static function allowedDomains(Runtime $runtime): array
    {
        $path = $runtime->homeFile(self::POLICY_FILE);
        if (!file_exists($path)) {
            return [];
        }
        $text = @file_get_contents($path);
        // As objects, so {} is not taken for an empty list.
        $rows = is_string($text) ? json_decode($text) : null;
        if (!is_array($rows)) {
            throw self::unreadable($runtime);
        }
        foreach ($rows as $row) {
            if (Lists::normaliseDomain($row) !== $row) {
                throw self::unreadable($runtime);
            }
        }

        return $rows;
    }

    private static function host(mixed $domain): string
    {
        $host = Lists::normaliseDomain($domain);
        if ($host === null) {
            throw new Refused('invalid_domain', 'A domain is a plain host name of two labels or more, as example.org, with no https://, port or path. www.example.org and example.org are different places. Internationalised names are not read yet.');
        }

        return $host;
    }

    /**
     * Whether what a person typed is this host, by the rule the command line
     * holds. A space around it is forgiven, since people paste, and nothing else is.
     */
    public static function isSameHost(mixed $typed, string $host): bool
    {
        return is_string($typed) && Lists::normaliseDomain(trim($typed, " \t\r\n\x0B\x0C")) === $host;
    }

    private static function write(Runtime $runtime, array $rows): void
    {
        try {
            $runtime->saveJson(self::POLICY_FILE, $rows);
        } catch (\RuntimeException) {
            throw new Refused('domain_policy_unwritable', $runtime->homeFile(self::POLICY_FILE) . " could not be written, so the list is as it was. Check the disk and the home's permissions.");
        }
    }

    /** Put a domain on the list. Only for the user's own word: the command line or the form. */
    public static function allow(Runtime $runtime, mixed $domain): array
    {
        $host = self::host($domain);
        $held = self::allowedDomains($runtime);
        if (in_array($host, $held, true)) {
            return ['domain' => $host, 'added' => false, 'domains' => $held];
        }
        $rows = array_merge($held, [$host]);
        sort($rows);
        self::write($runtime, $rows);
        // Read back, since a save after close writes nothing and says so only to the log.
        if (!in_array($host, self::allowedDomains($runtime), true)) {
            throw new Refused('domain_policy_unwritable', $runtime->homeFile(self::POLICY_FILE) . ' was not written, so the list is as it was.');
        }

        return ['domain' => $host, 'added' => true, 'domains' => $rows];
    }

    /** Take a domain off the list. What was read from it before stays what it was. */
    public static function forget(Runtime $runtime, mixed $domain): array
    {
        $host = self::host($domain);
        $held = self::allowedDomains($runtime);
        if (!in_array($host, $held, true)) {
            return ['domain' => $host, 'removed' => false, 'domains' => $held];
        }
        $left = array_values(array_filter($held, static fn (string $row): bool => $row !== $host));
        self::write($runtime, $left);
        // Read back, as allow does: a website called gone and still on the list
        // would go on being read by the next runtime.
        if (in_array($host, self::allowedDomains($runtime), true)) {
            throw new Refused('domain_policy_unwritable', $runtime->homeFile(self::POLICY_FILE) . ' was not written, so the list is as it was.');
        }

        return ['domain' => $host, 'removed' => true, 'domains' => $left];
    }

    /**
     * The key to check and where it came from.
     *
     * An observation can be a message the channel's allowlist kept out: a
     * signature that checked is not a permission, and this reads only the signature.
     *
     * @throws Refused
     */
    public static function subjectSnapshot(Runtime $runtime, mixed $partner = null, mixed $message = null): array
    {
        if (($partner === null) === ($message === null)) {
            throw new Refused('choose_partner_or_message', 'Name exactly one: partner, a name in the address book, or message, the sha256 of a message this process read and verified. A key is never given here.');
        }
        if ($partner !== null) {
            if (!is_string($partner) || $partner === '') {
                throw new Refused('invalid_partner', 'partner is a name in the address book.');
            }
            $row = $runtime->partnerByName($partner);
            if ($row === null || !Lists::canonicalKey($row['key'] ?? null)) {
                throw new Refused('partner_unavailable', 'No partner by that name with a usable key is in the address book. aamio_partners or aamio partner list shows who is.');
            }

            return ['key' => $row['key'], 'subject_source' => 'partner', 'partner' => $row['name'], 'signature_checked_locally' => null];
        }
        if (!is_string($message) || preg_match('/^[0-9a-f]{64}$/D', $message) !== 1) {
            throw new Refused('invalid_message_hash', 'message is the sha256 of a message as read shows it: 64 lowercase hexadecimal characters.');
        }
        $keys = $runtime->verifiedSigners($message);
        foreach ($keys as $key) {
            if (!Lists::canonicalKey($key)) {
                throw new Refused('invalid_local_observation', 'The message is recorded here without a usable signing key, so it cannot be checked.');
            }
        }
        if ($keys === []) {
            throw new Refused('source_unavailable', 'This process holds no verified message with that hash. Only a message this process read itself and whose signature checked out can be used: an unsigned one has no key to check, and what an earlier process read is not kept and cannot be read again. Check a later message from the same sender, or the partner by name.');
        }
        if (count($keys) !== 1) {
            throw new Refused('ambiguous_signer', 'Different keys signed the same bytes, so the hash does not say which one to check. Check the partner by name.');
        }

        return ['key' => (string) $keys[0], 'subject_source' => 'message', 'message_sha256' => $message, 'signature_checked_locally' => true];
    }

    /**
     * The exact host and the subject. Everything that can be settled here is settled before anyone is asked anything.
     *
     * @return array{0:string,1:array}
     * @throws Refused
     */
    public static function prepare(Runtime $runtime, mixed $domain, mixed $partner = null, mixed $message = null, mixed $name = null): array
    {
        $host = self::host($domain);
        if ($name !== null && !Lists::validName($name)) {
            throw new Refused('invalid_requested_name', 'name is the label on the list: 1 to 64 characters of a-z, 0-9, dot, underscore and dash. Leave it out to look for the key under any label.');
        }

        return [$host, self::subjectSnapshot($runtime, $partner, $message)];
    }

    /** What a refusal looks like as an answer. Nothing was read, so it carries no time and no statement. */
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

    /** A place in the window, or false when the window is full. */
    private static function take(): bool
    {
        $now = self::$clock === null ? hrtime(true) / 1e9 : (self::$clock)();
        self::$started = array_values(array_filter(self::$started, static fn (float $at): bool => $now - $at < self::WINDOW_SECONDS));
        if (count(self::$started) >= self::MAX_PER_WINDOW) {
            return false;
        }
        self::$started[] = $now;

        return true;
    }

    /**
     * Read the list at a domain on the user's list and say whether it lists this subject's key.
     *
     * Refused before anything is looked up: a bad domain or name, no such
     * subject, a domain that is not on the list, a list that cannot be read, and
     * a process at its limit. $network is passed to Fetch::check, for tests.
     */
    public static function check(Runtime $runtime, mixed $domain, mixed $partner = null, mixed $message = null, mixed $name = null, array $network = []): array
    {
        $host = null;
        $subject = null;
        try {
            [$host, $subject] = self::prepare($runtime, $domain, $partner, $message, $name);
            if (!in_array($host, self::allowedDomains($runtime), true)) {
                throw new Refused('domain_not_allowed', 'This runtime reads only websites the user has put on its list, and this one is not there. The user adds it with aamio addon domain allow ' . $host . ', from their own records and never from the message being checked. Over MCP aamio_addon_domain_check asks them.');
            }
            $deny = self::denyNetworks();
        } catch (Refused $refused) {
            return self::refusal($refused, $host, $subject, $name);
        }
        if (!self::take()) {
            return self::refusal(new Refused('local_rate_limit', 'At most ' . self::MAX_PER_WINDOW . ' checks start per minute here. Nothing was read. Try again shortly.', 'unreachable'), $host, $subject, $name);
        }
        $found = Fetch::check($host, $subject['key'], $name, [$host], $deny, ...($network === [] ? self::$standIn : $network));
        unset($subject['key']);
        $answer = $subject + $found;
        $answer['statement'] = self::statement($found, $name);
        if (($found['reason'] ?? null) === 'tls') {
            $answer['note'] = self::TLS_NOTE;
        }

        return $answer;
    }
}
