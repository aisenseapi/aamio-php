<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;


/**
 * Reading a domain key list from the domain itself, so that a stranger cannot
 * turn the read on anyone else.
 *
 * Lists is the format. This is the one way this client reads a list from a
 * website, and it is built for the case where the domain came from a message:
 * whatever it names, the read reaches only the public internet, only that host,
 * only once and only for a few seconds.
 *
 * - The domain is a plain host name and nothing else. The address is built here,
 *   https://<domain>/.well-known/aamio-keys.json on port 443; no URL is taken
 *   from anyone.
 * - A local policy can refuse the domain before anything is looked up.
 * - Every address the name resolves to must be public. One private, loopback,
 *   link-local, shared, reserved, documentation, multicast or translation
 *   address among public ones refuses the whole read, so a name cannot hide an
 *   internal target behind a public one. An IPv6 address must also be global
 *   unicast, inside 2000::/3. The connection goes to an address that was
 *   checked, and the name is not looked up again.
 * - TLS is checked against the domain, as the browser would: the server name is
 *   sent and the certificate must be for it.
 * - No proxy, no cookie, no credential, no redirect and no retry. The request
 *   carries the host and the fixed path and nothing about the reader: not the
 *   key, not the name, not a message.
 * - Five seconds for everything, 16 KiB of headers and 16 KiB of body, counted as
 *   they arrive rather than as the server announces them. Each address tried
 *   gets an even share of the time left, so one that never answers cannot use up
 *   the time of the next.
 * - PHP's lookups take no timeout and it has no threads to run one beside the
 *   clock, so a lookup runs in a process of its own, which is ended when the
 *   time is up. Where a process cannot be started, nothing is looked up.
 *
 * What comes back is a dated observation, never a decision:
 *
 *     listed       a valid list from this exact domain lists the key, under the name if one was asked
 *     not_listed   a valid list that does not
 *     no_list      the domain answered 404 or 410 at the exact path
 *     unreachable  no answer that could be read: DNS, TLS, the network, the time, or any other status
 *     invalid      the domain, the key or the name was refused, an address was not public,
 *                  a redirect, the wrong type or encoding, too much, or a list that is not version 1
 *
 * unreachable is never not_listed, and not_listed is no evidence of fraud.
 * listed says what this site listed when it was read, and grants nothing.
 */
final class Fetch
{
    public const PORT = 443;
    public const DEADLINE_SECONDS = 5.0;
    public const MAX_HEADER_BYTES = 16384;
    public const USER_AGENT = 'aamio-domain-keys/1';
    /** The most addresses one name may give. More cannot all be looked at, so none is used. */
    public const MAX_ADDRESSES = 64;

    /**
     * Never reached, whatever a name resolves to, and the same on every client
     * through tests/domain_fetch_vectors.json. A change to it is a new
     * ADDRESS_POLICY there and here.
     */
    public const ADDRESS_POLICY = 1;
    /**
     * Outside it is every IPv6 range that is not handed out as public: IPv4 in
     * any old embedding, segment routing, the discard prefixes and whatever the
     * IETF has not allocated yet. The denied list still holds inside it.
     */
    public const IPV6_GLOBAL = '2000::/3';
    public const DENIED_NETWORKS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0.0.0.0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** An address as plain text in its packed bytes, or null. IPv4 is held to one spelling here, and not left to the platform's inet_pton. */
    public static function packed(mixed $text): ?string
    {
        if (!is_string($text) || $text === '') {
            return null;
        }
        if (str_contains($text, ':')) {
            if (strspn($text, '0123456789abcdefABCDEF:.') !== strlen($text)) {
                return null;
            }
        } elseif (preg_match('/^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$/D', $text) !== 1) {
            return null;
        }
        $packed = @inet_pton($text);

        return is_string($packed) ? $packed : null;
    }

    /** A network as written, 192.0.2.0/24, or one address, as [packed, prefix], or null. */
    public static function network(mixed $text): ?array
    {
        if (!is_string($text)) {
            return null;
        }
        $parts = explode('/', $text);
        $packed = count($parts) <= 2 ? self::packed($parts[0]) : null;
        if ($packed === null) {
            return null;
        }
        $bits = strlen($packed) * 8;
        if (!isset($parts[1])) {
            return [$packed, $bits];
        }

        return preg_match('/^[0-9]{1,3}$/D', $parts[1]) === 1 && (int) $parts[1] <= $bits ? [$packed, (int) $parts[1]] : null;
    }

    /** Whether a packed address lies in a network of the same family. */
    private static function inNetwork(string $packed, array $network): bool
    {
        [$start, $prefix] = $network;
        if (strlen($packed) !== strlen($start)) {
            return false;
        }
        $whole = $prefix >> 3;
        if (substr($packed, 0, $whole) !== substr($start, 0, $whole)) {
            return false;
        }
        $rest = $prefix & 7;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($start[$whole]) & $mask);
    }

    /**
     * Whether this is an address a domain key read may connect to.
     *
     * @param list<array{0:string,1:int}> $deny more networks, as network() gives them
     */
    public static function publicAddress(mixed $text, array $deny = []): bool
    {
        $packed = self::packed($text);
        if ($packed === null) {
            return false;
        }
        if (strlen($packed) === 16 && !self::inNetwork($packed, (array) self::network(self::IPV6_GLOBAL))) {
            return false;
        }
        foreach (array_merge(array_map(self::network(...), self::DENIED_NETWORKS), $deny) as $network) {
            if (self::inNetwork($packed, $network)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every address of a name, looked up in a process of its own beside the
     * clock, which is ended when the time is up. stream_select does not work on
     * a process's pipes on Windows, so the process is asked after, and what it
     * prints fits the pipe by the limit on addresses.
     *
     * @return list<string>
     * @throws Refused
     */
    public static function resolve(string $domain, float $remaining): array
    {
        if (!function_exists('proc_open') || PHP_BINARY === '') {
            throw new Refused('dns_unavailable', null, 'unreachable');
        }
        $limit = hrtime(true) / 1e9 + $remaining;
        // -n: no php.ini, so nothing else is loaded. dns_get_record is in PHP's core.
        $code = 'foreach ([DNS_A, DNS_AAAA] as $type) { foreach (@dns_get_record($argv[1], $type) ?: [] as $row) { $found[] = $row["ip"] ?? $row["ipv6"] ?? ""; } }'
            . ' $found = array_values(array_unique($found ?? [])); echo count($found) > ' . self::MAX_ADDRESSES . ' ? "many" : json_encode($found);';
        $nowhere = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-n', '-r', $code, '--', $domain], [0 => ['file', $nowhere, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nowhere, 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Refused('dns_unavailable', null, 'unreachable');
        }
        try {
            while (proc_get_status($process)['running']) {
                if (hrtime(true) / 1e9 >= $limit) {
                    proc_terminate($process, 9);
                    throw new Refused('timeout', null, 'unreachable');
                }
                usleep(5000);
            }
            $said = stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]);
            proc_close($process);
        }
        $addresses = is_string($said) ? json_decode($said, true) : null;
        if ($said === 'many' || !is_array($addresses)) {
            throw new Refused('dns', null, 'unreachable');
        }

        return array_values(array_filter($addresses, static fn (mixed $address): bool => is_string($address) && $address !== ''));
    }

    /**
     * Status, headers and body, within the limits.
     *
     * @return array{0:int,1:array<string,string>,2:string}
     * @throws Refused
     */
    private static function readResponse(mixed $connection, float $deadline, callable $clock): array
    {
        $receive = static function () use ($connection, $deadline, $clock): string {
            $left = $deadline - $clock();
            if ($left <= 0) {
                throw new Refused('timeout', null, 'unreachable');
            }

            return $connection->receive($left);
        };
        $buffer = '';
        while (!str_contains($buffer, "\r\n\r\n")) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                throw new Refused('header_limit');
            }
            $chunk = $receive();
            if ($chunk === '') {
                throw new Refused('connection_closed', null, 'unreachable');
            }
            $buffer .= $chunk;
        }
        [$head, $body] = explode("\r\n\r\n", $buffer, 2);
        if (strlen($head) > self::MAX_HEADER_BYTES) {
            throw new Refused('header_limit');
        }
        $lines = explode("\r\n", $head);
        $parts = explode(' ', $lines[0], 3);
        if (count($parts) < 2 || !str_starts_with($parts[0], 'HTTP/1.') || preg_match('/^[0-9]{3}$/D', $parts[1]) !== 1) {
            throw new Refused('not_http');
        }
        $status = (int) $parts[1];
        $headers = [];
        $blank = " \t\n\r\x0B\x0C\x1C\x1D\x1E\x1F\x85\xA0";
        foreach (array_slice($lines, 1) as $line) {
            $colon = strpos($line, ':');
            $name = $colon === false ? '' : substr($line, 0, $colon);
            if ($name === '' || $name !== trim($name, $blank)) {
                throw new Refused('bad_header');
            }
            $headers[strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')] = trim(substr($line, $colon + 1), $blank);
        }
        if ($status !== 200) {
            return [$status, $headers, ''];
        }
        while (true) {
            if (strlen($body) > Lists::MAX_BYTES) {
                throw new Refused('body_limit');
            }
            $chunk = $receive();
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return [$status, $headers, $body];
    }

    private static function contentTypeOk(string $value): bool
    {
        $parts = explode(';', $value, 2);
        if (strtolower(trim($parts[0])) !== 'application/json') {
            return false;
        }
        $rest = str_replace(' ', '', strtolower(trim($parts[1] ?? '')));

        return $rest === '' || $rest === 'charset=utf-8';
    }

    /**
     * Read the list at domain once and say whether it lists key, under name if one is given.
     *
     * domain comes from the reader's own records, never from the message being
     * checked. allowed, when given, is the set of domains this reader agreed to
     * read, and anything else is refused before a lookup. deny adds networks the
     * operator keeps out, such as their own global ranges, as network() gives
     * them. resolve, connect, clock and now replace the network and the time in
     * tests; nothing else should pass them.
     *
     * @param list<string>|null $allowed
     */
    public static function check(mixed $domain, mixed $key, mixed $name = null, ?array $allowed = null, array $deny = [], ?callable $resolve = null, ?callable $connect = null, ?callable $clock = null, ?callable $now = null, float $deadlineSeconds = self::DEADLINE_SECONDS): array
    {
        $clock ??= static fn (): float => hrtime(true) / 1e9;
        $when = $now === null ? time() : $now();
        $result = ['domain' => is_string($domain) ? $domain : null, 'key' => is_string($key) ? $key : null, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $when)];
        if ($name !== null) {
            $result['requested_name'] = $name;
        }
        $answer = static function (string $status, string $reason, array $extra = []) use (&$result): array {
            return ['status' => $status, 'reason' => $reason] + $extra + $result;
        };
        $host = Lists::normaliseDomain($domain);
        if ($host === null) {
            return $answer('invalid', 'invalid_domain');
        }
        $result['domain'] = $host;
        if (!Lists::canonicalKey($key)) {
            return $answer('invalid', 'invalid_subject_key');
        }
        if ($name !== null && !Lists::validName($name)) {
            return $answer('invalid', 'invalid_requested_name');
        }
        if ($allowed !== null && !in_array($host, $allowed, true)) {
            return $answer('invalid', 'domain_not_allowed');
        }

        $deadline = $clock() + $deadlineSeconds;
        $resolve ??= self::resolve(...);
        $connect ??= static fn (string $address, string $server, float $remaining): Connection => Connection::open($address, $server, $remaining, $clock);
        try {
            $addresses = $resolve($host, $deadline - $clock());
        } catch (Refused $refused) {
            return $answer($refused->status, $refused->reason);
        }
        if ($addresses === []) {
            return $answer('unreachable', 'dns');
        }
        foreach ($addresses as $address) {
            if (!self::publicAddress($address, $deny)) {
                return $answer('invalid', 'target_not_public');
            }
        }

        $connection = null;
        $timedOut = false;
        foreach (array_values($addresses) as $index => $address) {
            $left = $deadline - $clock();
            if ($left <= 0) {
                return $answer('unreachable', 'timeout');
            }
            // An even share of what is left, so an address that never answers,
            // such as an IPv6 route that goes nowhere, does not take the next
            // one's time.
            try {
                $connection = $connect($address, $host, $left / (count($addresses) - $index));
                break;
            } catch (Refused $refused) {
                if ($refused->reason === 'tls' || $refused->reason === 'tls_unavailable') {
                    return $answer('unreachable', $refused->reason);
                }
                $timedOut = $timedOut || $refused->reason === 'timeout';
            }
        }
        if ($connection === null) {
            return $answer('unreachable', $timedOut ? 'timeout' : 'connect');
        }

        try {
            $request = 'GET ' . Lists::PATH . " HTTP/1.0\r\nHost: " . $host . "\r\nAccept: application/json\r\nAccept-Encoding: identity\r\nUser-Agent: " . self::USER_AGENT . "\r\nConnection: close\r\n\r\n";
            $connection->send($request, max($deadline - $clock(), 0.001));
            [$status, $headers, $body] = self::readResponse($connection, $deadline, $clock);
        } catch (Refused $refused) {
            return $answer($refused->status, $refused->reason);
        } finally {
            $connection->close();
        }

        if ($status === 404 || $status === 410) {
            return $answer('no_list', 'no_list', ['http_status' => $status]);
        }
        // The statuses that point elsewhere. 304 is not one of them: a read that
        // asked for no cache has no business being told nothing changed, and
        // that is no answer rather than a refusal.
        if (in_array($status, [300, 301, 302, 303, 307, 308], true)) {
            return $answer('invalid', 'redirect_refused', ['http_status' => $status]);
        }
        if ($status !== 200) {
            return $answer('unreachable', 'http_status', ['http_status' => $status]);
        }
        if (!self::contentTypeOk($headers['content-type'] ?? '')) {
            return $answer('invalid', 'content_type');
        }
        if (strtolower(trim($headers['content-encoding'] ?? 'identity')) !== 'identity' || isset($headers['transfer-encoding'])) {
            return $answer('invalid', 'content_encoding');
        }
        if (isset($headers['content-length']) && trim($headers['content-length']) !== (string) strlen($body)) {
            return $answer('invalid', 'length_mismatch');
        }

        $found = Lists::match($body, $key, $name);
        if ($found['status'] === 'invalid') {
            return $answer('invalid', 'invalid_document', ['detail' => $found['detail'] ?? null]);
        }
        $extra = ['document_sha256' => $found['document_sha256']];
        if ($found['status'] === 'listed') {
            $extra['matched_name'] = $found['matched_name'];
        }

        return $answer($found['status'], $found['reason'], $extra);
    }
}
