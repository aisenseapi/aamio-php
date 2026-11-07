<?php

declare(strict_types=1);

/*
 * The domain key list and the read of it, with nothing around them. The same
 * vectors as aamio-python's, and the same checks as its test_domain_keys.py and
 * test_domain_fetch.py as far as they carry over: threads and sockets differ, so
 * the lookup and the connection have checks of their own at the end.
 *
 * Included by the core's tests/run.php, with $check in scope.
 */

require_once dirname(__DIR__) . '/aamio-addon.php';

require_once __DIR__ . '/fake.inc.php';

use Aamio\DomainKeys\Connection;
use Aamio\DomainKeys\Fetch;
use Aamio\DomainKeys\Lists;
use Aamio\DomainKeys\Refused;
use Aamio\DomainKeys\InvalidList;

echo "domain keys: the format\n";

$vectors = json_decode((string) file_get_contents(__DIR__ . '/domain_keys_vectors.json'), true);
$addressVectors = json_decode((string) file_get_contents(__DIR__ . '/domain_fetch_vectors.json'), true);

// The same two files, where aamio-python's checkout is beside this one.
foreach (['domain_keys_vectors.json', 'domain_fetch_vectors.json'] as $file) {
    $there = dirname(__DIR__, 4) . '/aamio-python/tests/' . $file;
    if (is_file($there)) {
        $check(str_replace("\r\n", "\n", (string) file_get_contents($there)) === str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/' . $file)), $file . ' is the one aamio-python keeps, whichever way git wrote the line breaks');
    } else {
        echo "  skip  $file against aamio-python's: no checkout beside this one\n";
    }
}

$wrong = [];
foreach ($vectors['documents'] as $case) {
    $raw = isset($case['b64']) ? (string) base64_decode($case['b64'], true) : $case['text'];
    try {
        $entries = Lists::parse($raw);
        if (!$case['valid'] || count($entries) !== $case['keys']) {
            $wrong[] = $case['case'];
        }
    } catch (InvalidList) {
        if ($case['valid']) {
            $wrong[] = $case['case'];
        }
    }
}
$check($wrong === [], count($vectors['documents']) . ' documents answer as the vectors say', implode('; ', $wrong));

$wrong = [];
foreach ($vectors['matches'] as $case) {
    $found = Lists::match($case['text'], $case['key'], $case['name'] ?? null);
    $right = $found['status'] === $case['status'] && (!isset($case['reason']) || $found['reason'] === $case['reason'])
        && (!array_key_exists('matched_name', $case) || $found['matched_name'] === $case['matched_name'])
        && (!isset($case['document_sha256']) || $found['document_sha256'] === $case['document_sha256']);
    if (!$right) {
        $wrong[] = $case['case'];
    }
}
$check($wrong === [], count($vectors['matches']) . ' matches answer as the vectors say', implode('; ', $wrong));

$wrong = array_filter($vectors['domains']['valid'], static fn (array $pair): bool => Lists::normaliseDomain($pair[0]) !== $pair[1]);
$check($wrong === [], count($vectors['domains']['valid']) . ' domains come out in their exact form');
$wrong = array_filter($vectors['domains']['invalid'], static fn (string $domain): bool => Lists::normaliseDomain($domain) !== null);
$check($wrong === [], count($vectors['domains']['invalid']) . ' domains are refused');
$check(Lists::normaliseDomain(null) === null && Lists::normaliseDomain(7) === null, 'and what is no string is no domain');

$wrong = array_filter($vectors['generated'], static fn (array $case): bool => Lists::make($case['entries']) !== $case['text']);
$check($wrong === [], 'the generator writes the bytes every generator writes');
$wrong = [];
foreach ($vectors['refused'] as $case) {
    try {
        Lists::make($case['entries']);
        $wrong[] = $case['case'];
    } catch (InvalidList) {
    }
}
$check($wrong === [], 'and refuses what the parser would refuse', implode('; ', $wrong));
$check(Lists::urlFor('Example.ORG') === 'https://example.org/.well-known/aamio-keys.json' && Lists::urlFor('https://example.org') === null, 'the address is built here and from a host alone');

$doc = (string) tempnam(sys_get_temp_dir(), 'aamio-domain-');
file_put_contents($doc, $vectors['generated'][0]['text']);
[$said, $code] = Lists::checkFile($doc);
$check($code === 0 && $said === ['format_valid' => true, 'keys' => 2, 'source' => 'local_file', 'domain_checked' => false], 'a file is checked against the format, and says no domain was');
file_put_contents($doc, '{"aamio_keys":2,"keys":[]}');
[$said, $code] = Lists::checkFile($doc);
$check($code === 2 && $said['format_valid'] === false && $said['detail'] === 'unsupported_version' && $said['domain_checked'] === false, 'a file that is not version 1 says why');
unlink($doc);
[$said, $code] = Lists::checkFile($doc);
$check($code === 2 && $said === ['error' => 'local_file_unreadable'], 'and one that cannot be read says that');

echo "domain keys: the read\n";

$dKey = 'c3lrmIg_bOnE-fQyMRpxvqoeXAhkBGqDHCD9LXKWSnc';
$dOther = 'wK1BJa9cuHn7Y7QPC_4EQ6X_jtCbgbMbR6pqT-VGmmA';
$dPublic = '93.184.216.34';
$dList = Lists::make([['key' => $dKey, 'name' => 'support']]);
$dPage = fakeDomainHttp(200, null, $dList);
$noon = gmmktime(12, 0, 0, 11, 7, 2026);
/** One read against stand-ins: the answer, what was asked for, and the connection. */
$dRun = static function (mixed $chunks, array $options = []) use ($dKey, $dPublic, $noon): array {
    $asked = ['resolve' => [], 'connect' => []];
    $connection = new FakeDomainConnection(is_array($chunks) ? $chunks : [$chunks]);
    $addresses = $options['addresses'] ?? [$dPublic];
    $errors = $options['connect_errors'] ?? [];
    $resolve = static function (string $host, float $remaining) use (&$asked, $addresses): array {
        $asked['resolve'][] = $host;
        if ($addresses instanceof \Throwable) {
            throw $addresses;
        }

        return $addresses;
    };
    $connect = static function (string $address, string $host, float $remaining) use (&$asked, &$errors, $connection, &$shares): FakeDomainConnection {
        $asked['connect'][] = [$address, $host];
        $shares[] = $remaining;
        if ($errors !== []) {
            throw array_shift($errors);
        }

        return $connection;
    };
    $shares = [];
    $result = Fetch::check($options['domain'] ?? 'example.org', $options['key'] ?? $dKey, $options['name'] ?? null, $options['allowed'] ?? null, $options['deny'] ?? [], $resolve, $connect, $options['clock'] ?? null, static fn (): int => $noon, $options['deadline'] ?? Fetch::DEADLINE_SECONDS);

    return [$result, $asked, $connection, $shares];
};

[$result, $asked, $connection] = $dRun($dPage, ['name' => 'support']);
ksort($result);
$expected = ['domain' => 'example.org', 'key' => $dKey, 'checked_at' => '2026-11-07T12:00:00Z', 'requested_name' => 'support', 'status' => 'listed', 'reason' => 'key_listed', 'matched_name' => 'support', 'document_sha256' => hash('sha256', $dList)];
ksort($expected);
$check($result === $expected, 'a listed key, with what was read and when');
$check($asked === ['resolve' => ['example.org'], 'connect' => [[$dPublic, 'example.org']]] && $connection->closed, 'the name looked up once, the checked address connected, the connection closed');
$request = $connection->sent;
$check(str_starts_with($request, "GET /.well-known/aamio-keys.json HTTP/1.0\r\nHost: example.org\r\n") && str_contains($request, "Accept-Encoding: identity\r\n") && str_ends_with($request, "\r\n\r\n"), 'the request is the fixed path on the host');
$check(!str_contains($request, $dKey) && !str_contains($request, 'support') && !str_contains($request, 'Cookie') && !str_contains($request, 'Authorization') && !str_contains($request, 'Proxy'), 'and says nothing about the reader');

$check($dRun($dPage, ['key' => $dOther])[0]['status'] === 'not_listed' && $dRun($dPage, ['name' => 'billing'])[0]['status'] === 'not_listed', 'a key that is not there and a name it is not under are not_listed');
$result = $dRun(fakeDomainHttp(200, null, '{"aamio_keys": 2, "keys": []}'))[0];
$check($result['status'] === 'invalid' && $result['reason'] === 'invalid_document' && $result['detail'] === 'unsupported_version', 'a list that is not version 1 is invalid and lists nothing');
foreach ([404, 410] as $status) {
    $result = $dRun(fakeDomainHttp($status))[0];
    $check($result['status'] === 'no_list' && $result['http_status'] === $status, $status . ' is no list at the exact path');
}
foreach ([301, 302, 303, 307, 308] as $status) {
    [$result, $asked, $connection] = $dRun(fakeDomainHttp($status, ['Location' => 'https://elsewhere.example/']));
    $check($result['status'] === 'invalid' && $result['reason'] === 'redirect_refused' && count($asked['connect']) === 1 && $connection->sent !== '', $status . ' is refused and never followed');
}
foreach ([304, 401, 403, 429, 500, 503] as $status) {
    $result = $dRun(fakeDomainHttp($status))[0];
    $check($result['status'] === 'unreachable' && $result['reason'] === 'http_status', $status . ' is no answer and never not_listed');
}
foreach ([['application/json', 'listed'], ['application/json;charset=utf-8', 'listed'], ['Application/JSON; Charset=UTF-8', 'listed'], ['text/html', 'invalid'], ['application/json; charset=latin-1', 'invalid'], ['', 'invalid']] as [$type, $status]) {
    $result = $dRun(fakeDomainHttp(200, ['Content-Type' => $type], $dList))[0];
    $check($result['status'] === $status, 'content type "' . $type . '" is ' . $status);
}
$check($dRun(fakeDomainHttp(200, [], $dList))[0]['reason'] === 'content_type', 'no content type is no list');
$result = $dRun(fakeDomainHttp(200, ['Content-Type' => 'text/html'], '<html>Sign in</html>'))[0];
$check($result['status'] === 'invalid' && $result['reason'] === 'content_type', 'a login page with 200 is not a list');
foreach (['Content-Encoding' => 'gzip', 'Transfer-Encoding' => 'chunked'] as $name => $value) {
    $result = $dRun(fakeDomainHttp(200, ['Content-Type' => 'application/json', $name => $value], $dList))[0];
    $check($result['status'] === 'invalid' && $result['reason'] === 'content_encoding', $name . ' is refused');
}
[$result, , $connection] = $dRun(array_merge([fakeDomainHttp(200, null, '')], array_fill(0, 100, str_repeat('x', 4096))));
$check($result['status'] === 'invalid' && $result['reason'] === 'body_limit' && $connection->chunks !== [], 'a body past the limit is cut off and never read whole');
$result = $dRun('HTTP/1.1 200 OK' . "\r\n" . str_repeat('X-Pad: ' . str_repeat('x', 100) . "\r\n", 200))[0];
$check($result['status'] === 'invalid' && $result['reason'] === 'header_limit', 'headers past the limit');
$result = $dRun(fakeDomainHttp(200, ['Content-Type' => 'application/json', 'Content-Length' => (string) (strlen($dList) + 5)], $dList))[0];
$check($result['status'] === 'invalid' && $result['reason'] === 'length_mismatch', 'a body shorter than announced');
$check($dRun("SSH-2.0-OpenSSH\r\n\r\n")[0]['reason'] === 'not_http', 'an answer that is not HTTP');
foreach (["\xB2\xB2\xB2", 'abc', '20', '2000', '+20'] as $status) {
    $check($dRun('HTTP/1.1 ' . $status . " OK\r\n\r\n")[0]['reason'] === 'not_http', 'a status that is not three digits: ' . bin2hex($status));
}
$check($dRun("HTTP/1.1 200 OK\r\nno colon here\r\n\r\n")[0]['reason'] === 'bad_header', 'a header with no colon');
$result = $dRun("HTTP/1.1 200 OK\r\n")[0];
$check($result['status'] === 'unreachable' && $result['reason'] === 'connection_closed', 'a connection closed before the headers are done');
$result = $dRun([new Refused('timeout', null, 'unreachable')])[0];
$check($result['status'] === 'unreachable' && $result['reason'] === 'timeout', 'a read that times out');

$tick = 0.0;
$slow = new FakeDomainConnection(array_fill(0, 50, 'x'), static function () use (&$tick): void {
    $tick += 2.0;
});
$result = Fetch::check('example.org', $dKey, null, null, [], static fn (): array => [$dPublic], static fn (): FakeDomainConnection => $slow, static function () use (&$tick): float {
    return $tick;
});
$check($result['status'] === 'unreachable' && $result['reason'] === 'timeout' && $slow->reads <= 3, 'five seconds are for everything, and a server that drips is no answer');

[$result, $asked] = $dRun($dPage, ['addresses' => [$dPublic, '10.0.0.5']]);
$check($result['status'] === 'invalid' && $result['reason'] === 'target_not_public' && $asked['connect'] === [], 'one address that is not public refuses the whole read before connecting');
$wrong = array_filter($addressVectors['refused'], static fn (string $address): bool => Fetch::publicAddress($address));
$check($wrong === [], count($addressVectors['refused']) . ' addresses are never reached', implode(', ', $wrong));
$wrong = array_filter($addressVectors['public'], static fn (string $address): bool => !Fetch::publicAddress($address));
$check($wrong === [], count($addressVectors['public']) . ' addresses are', implode(', ', $wrong));
$check(Fetch::publicAddress(7) === false && Fetch::publicAddress(null) === false, 'and what is no string is no address');
$check(Fetch::ADDRESS_POLICY === $addressVectors['address_policy'] && Fetch::IPV6_GLOBAL === $addressVectors['ipv6_global'], 'the address policy is the shared one');
$same = array_map(Fetch::network(...), Fetch::DENIED_NETWORKS) == array_map(Fetch::network(...), $addressVectors['denied_networks']);
$check($same, 'and so are the denied ranges, whichever way they are written');
$keptOut = [Fetch::network('93.184.216.0/24')];
$check(!Fetch::publicAddress($dPublic, $keptOut) && Fetch::publicAddress('1.1.1.1', $keptOut), 'an operator can keep more networks out');
[$result, $asked] = $dRun($dPage, ['deny' => $keptOut]);
$check($result['reason'] === 'target_not_public' && $asked['connect'] === [], 'and the read holds to them');
$check(Fetch::network('203.0.113.7') !== null && Fetch::network('2001:db8::/32') !== null && Fetch::network('1.2.3.0/33') === null && Fetch::network('1.2.3.0/x') === null && Fetch::network('nonsense') === null && Fetch::network('1.2.3.0/24/1') === null, 'a network parses or it does not');

[$result, $asked] = $dRun($dPage, ['addresses' => ['2606:4700:4700::1111', $dPublic]]);
$check($result['status'] === 'listed' && $asked['connect'] === [['2606:4700:4700::1111', 'example.org']], 'the first checked address is the one connected');
[$result, $asked] = $dRun($dPage, ['addresses' => [$dPublic, '1.1.1.1'], 'connect_errors' => [new Refused('connect', null, 'unreachable')]]);
$check($result['status'] === 'listed' && array_column($asked['connect'], 0) === [$dPublic, '1.1.1.1'], 'the next checked address when one will not connect');
foreach (['connect', 'timeout'] as $why) {
    $result = $dRun($dPage, ['connect_errors' => [new Refused($why, null, 'unreachable')]])[0];
    $check($result['status'] === 'unreachable' && $result['reason'] === $why, 'no address that connects: ' . $why);
}
$result = $dRun($dPage, ['addresses' => [$dPublic, '1.1.1.1'], 'connect_errors' => [new Refused('tls', null, 'unreachable')]]);
$check($result[0]['status'] === 'unreachable' && $result[0]['reason'] === 'tls' && count($result[1]['connect']) === 1, 'a certificate that does not fit stops the read, on the first address that shows it');
$shares = $dRun($dPage, ['addresses' => [$dPublic, '1.1.1.1', '8.8.8.8'], 'deadline' => 3.0, 'connect_errors' => [new Refused('timeout', null, 'unreachable'), new Refused('timeout', null, 'unreachable')]])[3];
$check(count($shares) === 3 && abs($shares[0] - 1.0) < 0.1, 'each address gets its share of the time, and a silent one does not take it all');

$result = $dRun($dPage, ['addresses' => new Refused('dns', null, 'unreachable')])[0];
$check($result['status'] === 'unreachable' && $result['reason'] === 'dns', 'a name that does not resolve');
$result = $dRun($dPage, ['addresses' => new Refused('timeout', null, 'unreachable')])[0];
$check($result['status'] === 'unreachable' && $result['reason'] === 'timeout', 'a lookup that takes too long');
$result = $dRun($dPage, ['addresses' => []])[0];
$check($result['status'] === 'unreachable' && $result['reason'] === 'dns', 'a name with no address');

foreach ([['https://example.org', $dKey, null, 'invalid_domain'], ['example.org', 'not a key', null, 'invalid_subject_key'], ['example.org', $dKey, 'Bad Name', 'invalid_requested_name']] as [$domain, $key, $name, $reason]) {
    [$result, $asked] = $dRun($dPage, ['domain' => $domain, 'key' => $key, 'name' => $name]);
    $check($result['status'] === 'invalid' && $result['reason'] === $reason && $asked === ['resolve' => [], 'connect' => []], 'what is refused never reaches the network: ' . $reason);
}
[$result, $asked] = $dRun($dPage, ['allowed' => ['other.example']]);
$check($result['reason'] === 'domain_not_allowed' && $asked === ['resolve' => [], 'connect' => []], 'a domain the reader did not agree to read');

echo "domain keys: the lookup and the connection\n";

$began = hrtime(true);
$threw = null;
try {
    Fetch::resolve('nothing.invalid', 0.0);
} catch (Refused $refused) {
    $threw = $refused;
}
$check($threw !== null && $threw->status === 'unreachable' && $threw->reason === 'timeout' && (hrtime(true) - $began) / 1e9 < 2.0, 'a lookup is given up on time, and its process is ended');

$threw = null;
try {
    Connection::open('127.0.0.1', 'example.org', 0.5, null, 1);
} catch (Refused $refused) {
    $threw = $refused;
}
$check($threw !== null && $threw->status === 'unreachable' && in_array($threw->reason, ['connect', 'timeout', 'tls_unavailable'], true), 'a port nobody answers on is no connection');

if (extension_loaded('openssl')) {
    // A socket that takes the connection and never says a word: the handshake
    // has to end by the clock, since a blocking one waits as long as the peer.
    $listening = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($listening, false), ':'), 1);
    $began = hrtime(true);
    $threw = null;
    try {
        Connection::open('127.0.0.1', 'example.org', 0.3, null, $port);
    } catch (Refused $refused) {
        $threw = $refused;
    }
    $took = (hrtime(true) - $began) / 1e9;
    fclose($listening);
    $check($threw !== null && $threw->reason === 'timeout' && $took < 1.5, 'a peer that never answers the handshake is given up on time');
} else {
    echo "  skip  the handshake's clock: no openssl here\n";
}
