<?php

declare(strict_types=1);

/*
 * Checking a key against a website from inside a runtime: which key, which
 * website, and what a result does. The same checks as aamio-python's
 * tests/test_domain_runtime.py: the key comes from the address book or from a
 * signature this process checked and from nobody else, only websites the user
 * put on the list are read, a refusal reaches neither the resolver nor the
 * network, the limit holds, and a result changes nothing. Real runtimes over the
 * fake service, with the resolver and the connection replaced, so the read
 * itself is the real one down to the socket.
 *
 * Included by the core's tests/runtime.php after partner-add.inc.php, with $fake,
 * $root, $check, $childPhp and $cliRun in scope. The add-on is loaded the way a
 * checkout that is not installed loads it: named in AAMIO_ADDONS.
 */

require_once dirname(__DIR__) . '/aamio-addon.php';
putenv('AAMIO_ADDONS=' . dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aamio-addon.php');
\Aamio\Addons::reset();

require_once __DIR__ . '/fake.inc.php';

use Aamio\Codec;
use Aamio\DomainKeys\Fetch;
use Aamio\DomainKeys\Lists;
use Aamio\DomainKeys\Refused;
use Aamio\DomainKeys\Addon;
use Aamio\DomainKeys\Mcp;
use Aamio\DomainKeys\Policy;
use Aamio\Http;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

echo "domain keys: from inside a runtime\n";

const DOMAIN_SITE = 'leverandor.example';

// A scope of its own: the runner goes on with its $a and $b after this file.
(static function () use ($root, $check, $childPhp, $cliRun): void {
    $dQuiet = static function (string $line): void {
    };
    $dHomes = 0;
    /** Two runtimes that know each other, with inboxes open. */
    $dPair = static function () use ($root, $dQuiet, &$dHomes): array {
        $made = [];
        foreach (['a', 'b'] as $side) {
            $home = $root . DIRECTORY_SEPARATOR . 'domain-' . (++$dHomes);
            mkdir($home, 0700, true);
            $made[] = new Runtime($home, 'https://fake.test', [$side], false, $dQuiet);
        }
        $made[0]->partnerAdd('b', $made[1]->keys->public);
        $made[1]->partnerAdd('a', $made[0]->keys->public);
        $made[0]->ensureInbox();
        $made[1]->ensureInbox();

        return $made;
    };
    /** The internet as a read sees it: a page per domain, and what was asked for. */
    $dWeb = static function (): object {
        return new class () {
            public array $sites = [];
            public array $lookups = [];
            public array $connections = [];

            public function publish(string $domain, array ...$entries): void
            {
                $this->sites[$domain] = fakeDomainHttp(200, null, Lists::make($entries));
            }

            public function network(): array
            {
                return [
                    'resolve' => function (string $host, float $remaining): array {
                        $this->lookups[] = $host;
                        $site = $this->sites[$host] ?? null;
                        if ($site instanceof \Throwable) {
                            throw $site;
                        }

                        return ['93.184.216.34'];
                    },
                    'connect' => function (string $address, string $host, float $remaining): FakeDomainConnection {
                        $connection = new FakeDomainConnection([$this->sites[$host]]);
                        $this->connections[] = $connection;

                        return $connection;
                    },
                ];
            }

            public function untouched(): bool
            {
                return $this->lookups === [] && $this->connections === [];
            }
        };
    };
    /** One real message, sealed and signed, read by the reader: its sha256. */
    $dSent = static function (Runtime $from, Runtime $to, string $to_name, string $text = 'hello'): string {
        $from->send($to_name, $text);
        foreach ($to->read() as $entry) {
            if (($entry['body']['text'] ?? null) === $text) {
                return $entry['sha256'];
            }
        }
        throw new \RuntimeException('the message did not arrive');
    };
    Policy::$started = [];

    // ------------------------------------------------------- the list of sites

    [$a, $b] = $dPair();
    $check(Policy::allowedDomains($a) === [] && !file_exists($a->homeFile(Policy::POLICY_FILE)), 'nothing is on the list until the user puts it there, and asking writes nothing');
    $check(Policy::allow($a, 'Leverandor.Example') === ['domain' => DOMAIN_SITE, 'added' => true, 'domains' => [DOMAIN_SITE]], 'a domain is added in its exact form');
    $check(Policy::allow($a, DOMAIN_SITE)['added'] === false, 'and once');
    $check(Policy::allow($a, 'another.example')['domains'] === ['another.example', DOMAIN_SITE], 'the list is kept in order');
    $check(json_decode((string) file_get_contents($a->homeFile(Policy::POLICY_FILE)), true) === ['another.example', DOMAIN_SITE], 'on disk as a plain list');
    $check(Policy::forget($a, DOMAIN_SITE) === ['domain' => DOMAIN_SITE, 'removed' => true, 'domains' => ['another.example']], 'and it can be taken off');
    $check(Policy::forget($a, DOMAIN_SITE)['removed'] === false, 'once');
    foreach (['https://leverandor.example', 'leverandor.example/path', '127.0.0.1', 'localhost', "leverandor.example\n", null, 7] as $value) {
        $threw = null;
        try {
            Policy::allow($a, $value);
        } catch (Refused $refused) {
            $threw = $refused->reason;
        }
        $check($threw === 'invalid_domain', 'only a plain host goes on the list: ' . json_encode($value));
    }
    foreach (['', 'not json', '{}', '{"domains": []}', '["ok.example", 7]', '["https://ok.example"]', '["OK.example"]'] as $content) {
        $web = $dWeb();
        file_put_contents($a->homeFile(Policy::POLICY_FILE), $content);
        $answer = Policy::check($a, 'ok.example', 'b', null, null, $web->network());
        $threw = null;
        try {
            Policy::allow($a, 'new.example');
        } catch (Refused $refused) {
            $threw = $refused->reason;
        }
        $check($answer['reason'] === 'domain_policy_unreadable' && $threw === 'domain_policy_unreadable' && file_get_contents($a->homeFile(Policy::POLICY_FILE)) === $content && $web->untouched(), 'a list that cannot be read stops the check and is never saved over: ' . json_encode($content));
    }
    $check($a->whoami()['key'] === $a->keys->public && $a->partnerList() !== [], 'a broken list stops domain checks and nothing else');
    unlink($a->homeFile(Policy::POLICY_FILE));

    [$a] = $dPair();
    $released = new \ReflectionProperty(Runtime::class, 'homeReleased');
    $released->setValue($a, true);
    $threw = null;
    try {
        Policy::allow($a, DOMAIN_SITE);
    } catch (Refused $refused) {
        $threw = $refused->reason;
    }
    $check($threw === 'domain_policy_unwritable', 'a save that a closed runtime dropped is not called added');
    $released->setValue($a, false);
    Policy::allow($a, DOMAIN_SITE);
    $released->setValue($a, true);
    $threw = null;
    try {
        Policy::forget($a, DOMAIN_SITE);
    } catch (Refused $refused) {
        $threw = $refused->reason;
    }
    $check($threw === 'domain_policy_unwritable' && Policy::allowedDomains($a) === [DOMAIN_SITE], 'nor a removal called removed: the next runtime reads this file');
    $released->setValue($a, false);

    // ------------------------------------------------------ which key is checked

    [$a, $b] = $dPair();
    $web = $dWeb();
    Policy::allow($a, DOMAIN_SITE);
    Policy::allow($b, DOMAIN_SITE);
    $web->publish(DOMAIN_SITE, ['key' => $a->keys->public], ['key' => $b->keys->public, 'name' => 'support']);
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $check($answer['status'] === 'listed' && $answer['key'] === $b->keys->public && $answer['subject_source'] === 'partner' && $answer['partner'] === 'b' && $answer['signature_checked_locally'] === null, 'a partner is checked with the key in the address book');
    $digest = $dSent($a, $b, 'b');
    $answer = Policy::check($b, DOMAIN_SITE, null, $digest, null, $web->network());
    $check($answer['status'] === 'listed' && $answer['key'] === $a->keys->public && $answer['subject_source'] === 'message' && $answer['message_sha256'] === $digest && $answer['signature_checked_locally'] === true, 'a message is checked with the key this process verified');

    // A signed stranger, and what a message says about itself counts for nothing.
    $stranger = Keys::generate();
    $channel = $b->channels['inbox'];
    $channel->observed[901] = ['seq' => 901, 'sha256' => str_repeat('b', 64), 'verified' => true, 'from_key' => $stranger->public];
    $channel->observed[902] = ['seq' => 902, 'sha256' => str_repeat('c', 64), 'verified' => false, 'from_key' => $a->keys->public];
    $channel->observed[903] = ['seq' => 903, 'sha256' => str_repeat('d', 64), 'verified' => true, 'from_key' => 'not a key'];
    $channel->observed[904] = ['seq' => 904, 'sha256' => str_repeat('e', 64), 'verified' => true, 'from_key' => $a->keys->public];
    $channel->observed[905] = ['seq' => 905, 'sha256' => str_repeat('e', 64), 'verified' => true, 'from_key' => $b->keys->public];
    $answer = Policy::check($b, DOMAIN_SITE, null, str_repeat('b', 64), null, $web->network());
    $check($answer['status'] === 'not_listed' && $answer['key'] === $stranger->public, 'a signed stranger can be checked too, a kept-out message included');
    foreach ([['c', 'source_unavailable', 'a signature that did not verify gives no key'], ['d', 'invalid_local_observation', 'nor does a key that is not one'], ['e', 'ambiguous_signer', 'and the same bytes signed by two keys name no one'], ['f', 'source_unavailable', 'and a hash this process never read is not known']] as [$letter, $reason, $label]) {
        $before = count($web->lookups);
        $answer = Policy::check($b, DOMAIN_SITE, null, str_repeat($letter, 64), null, $web->network());
        $check($answer['reason'] === $reason && count($web->lookups) === $before && isset($answer['fix']), $label);
    }
    $home = $b->home;
    $b->saveState();
    $b->close();
    $b = new Runtime($home, 'https://fake.test', null, false, $dQuiet);
    $before = count($web->lookups);
    $answer = Policy::check($b, DOMAIN_SITE, null, $digest, null, $web->network());
    $check($answer['reason'] === 'source_unavailable' && count($web->lookups) === $before, 'a message read by an earlier process is not known: state.json holds no observation to invent one from');
    $check(str_contains($answer['fix'], 'later message') && !in_array($digest, array_column($b->read(), 'sha256'), true), 'and the way on is the next message, since no read goes back to this one');

    foreach ([[[], 'choose_partner_or_message'], [['b', str_repeat('a', 64)], 'choose_partner_or_message'], [['', null], 'invalid_partner'], [[7, null], 'invalid_partner'], [['nobody', null], 'partner_unavailable'], [[null, 'not a hash'], 'invalid_message_hash'], [[null, str_repeat('A', 64)], 'invalid_message_hash'], [[null, str_repeat('a', 64) . "\n"], 'invalid_message_hash']] as [$subject, $reason]) {
        $web = $dWeb();
        $answer = Policy::check($a, DOMAIN_SITE, $subject[0] ?? null, $subject[1] ?? null, null, $web->network());
        $check($answer['reason'] === $reason && $web->untouched(), 'a subject that cannot be named stops before the network: ' . $reason . ' ' . substr((string) json_encode($subject), 0, 24));
    }
    $a->partners[] = ['name' => 'crooked', 'key' => str_repeat('A', 42) . 'B'];
    $web = $dWeb();
    $answer = Policy::check($a, DOMAIN_SITE, 'crooked', null, null, $web->network());
    $check($answer['reason'] === 'partner_unavailable' && $web->untouched(), 'a partner key that is not canonical is not compared');
    array_pop($a->partners);

    // ----------------------------------------------------- which website is read

    [$a] = $dPair();
    $web = $dWeb();
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $check($answer['status'] === 'invalid' && $answer['reason'] === 'domain_not_allowed' && str_contains($answer['fix'], 'aamio addon domain allow ' . DOMAIN_SITE) && $web->untouched(), 'a domain that is not on the list is refused before any lookup');
    Policy::allow($a, 'example.org');
    foreach (['www.example.org', 'org.example', 'sub.www.example.org'] as $other) {
        $check(Policy::check($a, $other, 'b', null, null, $web->network())['reason'] === 'domain_not_allowed', 'a parent or a child of a listed domain is another place: ' . $other);
    }
    Policy::allow($a, DOMAIN_SITE);
    $web->publish(DOMAIN_SITE);
    Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $lookups = count($web->lookups);
    Policy::forget($a, DOMAIN_SITE);
    $check(Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network())['reason'] === 'domain_not_allowed' && count($web->lookups) === $lookups, 'a domain taken off the list is not read again');
    Policy::allow($a, DOMAIN_SITE);
    foreach (['https://leverandor.example', '127.0.0.1', '0x7f.0.0.1', '', null] as $domain) {
        $check(Policy::check($a, $domain, 'b', null, null, $web->network())['reason'] === 'invalid_domain', 'a bad domain stops here: ' . json_encode($domain));
    }
    $check(Policy::check($a, DOMAIN_SITE, 'b', null, 'Not A Label', $web->network())['reason'] === 'invalid_requested_name', 'and a bad name');

    $network = $web->network();
    $network['resolve'] = static fn (string $host, float $remaining): array => ['10.0.0.5'];
    $connections = count($web->connections);
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $network);
    $check($answer['status'] === 'invalid' && $answer['reason'] === 'target_not_public' && count($web->connections) === $connections, 'the address rules still hold for a domain on the list');
    $check($answer['statement'] === Policy::NOT_CONTACTED && !str_contains($answer['statement'], 'answered'), 'and the answer says no website said anything');

    putenv('AAMIO_DOMAIN_DENY=93.184.216.0/24');
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $check($answer['reason'] === 'target_not_public', 'the operator can keep more networks out');
    putenv('AAMIO_DOMAIN_DENY=203.0.113.0/24, nonsense');
    $before = count($web->lookups);
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $check($answer['reason'] === 'domain_deny_invalid' && count($web->lookups) === $before, 'a keep-out that does not parse stops the check rather than leave a range open');
    putenv('AAMIO_DOMAIN_DENY');

    // ------------------------------------------------------- what comes back

    [$a, $b] = $dPair();
    Policy::allow($a, DOMAIN_SITE);
    $cases = [
        [fakeDomainHttp(404), 'no_list', 'no_list'],
        [fakeDomainHttp(410), 'no_list', 'no_list'],
        [fakeDomainHttp(503), 'unreachable', 'http_status'],
        [fakeDomainHttp(301, ['Location' => 'https://elsewhere.example/']), 'invalid', 'redirect_refused'],
        [fakeDomainHttp(200, ['Content-Type' => 'text/html'], '<html>'), 'invalid', 'content_type'],
        [fakeDomainHttp(200, null, '{"aamio_keys": 2, "keys": []}'), 'invalid', 'invalid_document'],
    ];
    foreach ($cases as [$page, $status, $reason]) {
        $web = $dWeb();
        $web->sites[DOMAIN_SITE] = $page;
        $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
        $check($answer['status'] === $status && $answer['reason'] === $reason && $answer['statement'] === Policy::STATEMENTS[$status] && !isset($answer['fix']), 'every answer comes through with its statement: ' . $reason);
    }
    $web = $dWeb();
    $web->publish(DOMAIN_SITE, ['key' => Keys::generate()->public]);
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $check($answer['status'] === 'not_listed' && str_contains($answer['statement'], 'no evidence of fraud') && !str_contains($answer['statement'], 'label'), 'a list without the key says it is no evidence of fraud');
    $web = $dWeb();
    $web->publish(DOMAIN_SITE, ['key' => $b->keys->public, 'name' => 'support']);
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, 'billing', $web->network());
    $check($answer['status'] === 'not_listed' && $answer['requested_name'] === 'billing' && $answer['statement'] === Policy::NOT_UNDER_THAT_LABEL && !str_contains($answer['statement'], 'did not have this key at'), 'the wrong label does not say the key is missing');
    $check(Policy::check($a, DOMAIN_SITE, 'b', null, 'support', $web->network())['status'] === 'listed' && Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network())['matched_name'] === 'support', 'and a check with no name shows it, as the words say');
    $web = $dWeb();
    $web->sites[DOMAIN_SITE] = new Refused('dns', null, 'unreachable');
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    $check($answer['status'] === 'unreachable' && $answer['statement'] === Policy::STATEMENTS['unreachable'], 'a lookup that fails is unreachable and never not_listed');
    $network = $web->network();
    $network['resolve'] = static fn (string $host, float $remaining): array => ['93.184.216.34'];
    $network['connect'] = static function (string $address, string $host, float $remaining): never {
        throw new Refused('tls', null, 'unreachable');
    };
    $answer = Policy::check($a, DOMAIN_SITE, 'b', null, null, $network);
    $check($answer['reason'] === 'tls' && str_contains($answer['note'], 'openssl.cafile') && str_contains($answer['note'], 'never switched off'), 'a certificate this machine cannot verify says what to look at');

    $web = $dWeb();
    $web->publish(DOMAIN_SITE, ['key' => $b->keys->public, 'name' => 'support']);
    $before = [json_encode($a->partners), json_encode($a->channels['inbox']->allow), json_encode($a->outbox), file_get_contents($a->homeFile(Policy::POLICY_FILE))];
    Policy::check($a, DOMAIN_SITE, 'b', null, 'support', $web->network());
    $sent = $web->connections[0]->sent;
    $check(!str_contains($sent, $b->keys->public) && !str_contains($sent, $a->keys->public) && !str_contains($sent, 'support'), 'nothing about the reader goes to the site');
    $check($before === [json_encode($a->partners), json_encode($a->channels['inbox']->allow), json_encode($a->outbox), file_get_contents($a->homeFile(Policy::POLICY_FILE))], 'a listed key changes nothing here');

    // -------------------------------------------------------------- the limit

    Policy::$started = [];
    $moment = 1000.0;
    Policy::$clock = static function () use (&$moment): float {
        return $moment;
    };
    $web = $dWeb();
    $web->publish(DOMAIN_SITE);
    $answers = [];
    for ($i = 0; $i < 21; $i++) {
        $answers[] = Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network());
    }
    $check(count($web->lookups) === 20 && $answers[20]['status'] === 'unreachable' && $answers[20]['reason'] === 'local_rate_limit' && isset($answers[20]['fix']), 'twenty checks start per minute');
    $moment += 61.0;
    $check(Policy::check($a, DOMAIN_SITE, 'b', null, null, $web->network())['status'] === 'not_listed', 'and the window moves');
    Policy::$started = [];
    for ($i = 0; $i < 30; $i++) {
        Policy::check($a, 'not-on-the-list.example', 'b', null, null, $web->network());
    }
    $check(Policy::$started === [], 'refused checks use up no budget');
    Policy::$clock = null;
    Policy::$started = [];

    // ---------------------------------------------------------- two parties

    [$a, $b] = $dPair();
    $web = $dWeb();
    Policy::allow($b, DOMAIN_SITE);
    $web->publish(DOMAIN_SITE, ['key' => $a->keys->public, 'name' => 'support']);
    $check(Policy::check($b, DOMAIN_SITE, 'a', null, 'support', $web->network())['status'] === 'listed', 'two parties: the partner is listed');
    $digest = $dSent($a, $b, 'b', 'a live message');
    $check(Policy::check($b, DOMAIN_SITE, null, $digest, 'support', $web->network())['status'] === 'listed', 'and so is the key behind a live message');
    $next = Keys::generate();
    $web->publish(DOMAIN_SITE, ['key' => $a->keys->public, 'name' => 'support'], ['key' => $next->public, 'name' => 'support']);
    $check(Policy::check($b, DOMAIN_SITE, 'a', null, 'support', $web->network())['status'] === 'listed', 'during a rotation the old key is still listed');
    $web->publish(DOMAIN_SITE, ['key' => $next->public, 'name' => 'support']);
    $answer = Policy::check($b, DOMAIN_SITE, null, $digest, 'support', $web->network());
    $check($answer['status'] === 'not_listed' && $answer['key'] === $a->keys->public, 'and when it has rotated out, the same message answers not_listed');
    $check($b->partnerByName('a')['key'] === $a->keys->public, 'and the address book is as it was: rotating a partner is the user\'s word');

    // ---------------------------------------------------------- the command line

    $cliRun2 = static function (array $words, ?string $home = null) use ($childPhp, $cliRun): array {
        return $cliRun(array_merge($childPhp, [dirname(__DIR__, 3) . '/bin/aamio', '--home', (string) $home, '--no-archive'], $words));
    };
    $cliHome = $root . DIRECTORY_SEPARATOR . 'domain-cli';
    [$code, $out, $err] = $cliRun2(['addon', 'domain', 'file', '--name', 'support'], $cliHome);
    $created = new Runtime($cliHome, 'https://fake.test', null, false, $dQuiet);
    $check($code === 0 && $out === Lists::make([['key' => $created->keys->public, 'name' => 'support']]) && str_contains($err, 'nothing secret'), 'domain file writes the bytes every generator writes, and no secret');
    $check(!str_contains($out, "\r"), 'with a plain line break, from a process of its own');
    $created->close();
    [$code, $out, $err] = $cliRun2(['addon', 'domain', 'file', '--name', 'Not A Label'], $cliHome);
    $check($code === 2 && $out === '' && json_decode($err, true)['error'] === 'invalid_name', 'a refusal does not go where the file goes');

    $listed = $root . DIRECTORY_SEPARATOR . 'domain-list.json';
    file_put_contents($listed, Lists::make([]));
    $noHome = $root . DIRECTORY_SEPARATOR . 'domain-no-home';
    [$code, $out] = $cliRun2(['addon', 'domain', 'validate', $listed], $noHome);
    $said = json_decode($out, true);
    $check($code === 0 && $said['format_valid'] === true && $said['keys'] === 0 && $said['domain_checked'] === false && !file_exists($noHome), 'domain validate reads the file and never the home');
    [$code, $out] = $cliRun2(['addon', 'domain', 'validate', $listed . '.gone'], $noHome);
    $check($code === 2 && json_decode($out, true)['error'] === 'local_file_unreadable', 'and says when it cannot');
    $holder = new Runtime($cliHome, 'https://fake.test', null, false, $dQuiet);
    [$code, $out] = $cliRun2(['addon', 'domain', 'validate', $listed], $cliHome);
    $check($code === 0, 'it runs beside a runtime that holds the home');
    [$code, , $err] = $cliRun2(['addon', 'domain', 'list'], $cliHome);
    $check($code === 1 && str_contains($err, 'is using'), 'and the commands that need the home are told who has it');
    $holder->close();

    [$code, $out] = $cliRun2(['addon', 'domain', 'allow', 'Example.ORG'], $cliHome);
    $said = json_decode($out, true);
    $check($code === 0 && $said['added'] === true && $said['domain'] === 'example.org' && str_contains($said['note'], 'Nothing was read now'), 'domain allow');
    [$code, $out] = $cliRun2(['addon', 'domain', 'list'], $cliHome);
    $check($code === 0 && json_decode($out, true) === ['domains' => ['example.org']], 'domain list');
    [$code, $out] = $cliRun2(['addon', 'domain', 'allow', 'https://example.org'], $cliHome);
    $check($code === 2 && json_decode($out, true)['reason'] === 'invalid_domain', 'a domain that is no host is refused with exit 2');
    [$code, $out] = $cliRun2(['addon', 'domain', 'forget', 'example.org'], $cliHome);
    $check($code === 0 && json_decode($out, true)['removed'] === true, 'domain forget');
    [$code, $out] = $cliRun2(['addon', 'domain', 'check', 'example.org', '--partner', 'nobody'], $cliHome);
    $check($code === 2 && json_decode($out, true)['reason'] === 'partner_unavailable', 'a check of a partner nobody has');
    [$code, , $err] = $cliRun2(['addon', 'domain', 'check', 'example.org'], $cliHome);
    $check($code === 2 && str_contains($err, '--partner'), 'and the command line takes no key and no message: a partner or nothing');
    $partnerHome = new Runtime($cliHome, 'https://fake.test', null, false, $dQuiet);
    $partnerHome->partnerAdd('b', Keys::generate()->public);
    $partnerHome->close();
    [$code, $out] = $cliRun2(['addon', 'domain', 'check', 'example.org', '--partner', 'b'], $cliHome);
    $said = json_decode($out, true);
    $check($code === 2 && $said['reason'] === 'domain_not_allowed' && str_contains($said['fix'], 'aamio addon domain allow example.org'), 'what is not on the list is refused, with the way on');
    $check(Addon::EXIT_CODES === ['listed' => 0, 'not_listed' => 3, 'no_list' => 4, 'unreachable' => 5, 'invalid' => 2] && array_keys(Policy::STATEMENTS) === array_keys(Addon::EXIT_CODES), 'every answer has its exit code and its statement');
    [$code, , $err] = $cliRun2(['addon', 'domain', 'nonsense'], $cliHome);
    $check($code === 2 && str_contains($err, 'unknown command nonsense'), 'an unknown domain command says which there are');

    // ----------------------------------------------------------- over the tool

    /** One tool call, through dispatch as the server makes it. */
    $dCall = static function (Runtime $runtime, array $arguments, ?callable $ask = null): array {
        return (new McpServer($runtime))->dispatch('aamio_addon_domain_check', $arguments, $ask);
    };
    $dSaid = static fn (array $result): array => $result['structuredContent'] instanceof \stdClass ? [] : $result['structuredContent'];

    $spec = json_decode((string) file_get_contents(dirname(__DIR__) . '/src/mcp-tools.json'), true);
    $tool = array_values(array_filter($spec['tools'], static fn (array $t): bool => $t['name'] === 'aamio_addon_domain_check'))[0] ?? [];
    $check(($tool['inputSchema']['required'] ?? null) === ['domain'] && ($tool['annotations']['readOnlyHint'] ?? null) === false && !isset($tool['inputSchema']['oneOf']) && !isset($tool['inputSchema']['properties']['key']), 'the tool takes no key, says it is not read-only, and has no top-level oneOf a host would refuse');
    $there = dirname(__DIR__, 4) . '/aamio-python/addons/domain-keys/src/aamio_domain_keys/mcp.py';
    if (is_file($there)) {
        $python = (string) file_get_contents($there);
        $check(str_contains($python, substr((string) json_encode($tool['description'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1)), 'and the same description as aamio-python serves');
    } else {
        echo "  skip  the tool against aamio-python's: no checkout beside this one\n";
    }

    [$a, $b] = $dPair();
    $web = $dWeb();
    Policy::$standIn = $web->network();
    $result = $dCall($a, ['domain' => DOMAIN_SITE, 'partner' => 'b']);
    $said = $dSaid($result);
    $check($web->untouched() && $result['isError'] === true && $said['reason'] === 'domain_not_allowed' && str_contains($said['fix'], 'aamio addon domain allow ' . DOMAIN_SITE) && str_contains($said['fix'], 'stop this server'), 'without a form nothing is read, and the answer says how the user adds it');
    foreach ([['key', 'x'], ['verified', true], ['from_key', 'x'], ['allow', true], ['subject_source', 'x']] as [$field, $value]) {
        $result = $dCall($a, ['domain' => DOMAIN_SITE, 'partner' => 'b', $field => $value]);
        $said = $dSaid($result);
        $check($result['isError'] === true && $said['given'] === [$field] && str_contains($said['fix'], 'never from the conversation'), 'a key or a verdict from the model is refused by name: ' . $field);
    }
    $check($dSaid($dCall($a, ['domain' => DOMAIN_SITE, 'partner' => 'b', 'message' => str_repeat('a', 64)]))['reason'] === 'choose_partner_or_message' && $dSaid($dCall($a, ['domain' => DOMAIN_SITE]))['reason'] === 'choose_partner_or_message', 'partner and message together, or neither');

    $digest = $dSent($a, $b, 'b');
    $broken = static fn (array $question, string $about, string $tool, string $key): array => ['no_dialog' => 'the app answered the question with an error: no'];
    foreach ([null, $broken] as $ask) {
        $result = $dCall($b, ['domain' => DOMAIN_SITE, 'message' => $digest], $ask);
        $said = $dSaid($result);
        $check($result['isError'] === true && $said['reason'] === 'domain_not_allowed' && str_contains($said['fix'], 'aamio addon domain allow ' . DOMAIN_SITE . ' the next time') && str_contains($said['fix'], 'a later message from the same sender') && !str_contains($said['fix'], 'stop this server'), 'a message is not told to restart, since that would lose it');
    }
    $result = $dCall($a, ['domain' => DOMAIN_SITE, 'partner' => 'b'], $broken);
    $check($result['isError'] === true && str_contains($dSaid($result)['fix'], 'the app answered') && str_contains($dSaid($result)['fix'], 'aamio addon domain allow'), 'a form that could not be shown is said');

    Policy::allow($b, DOMAIN_SITE);
    $web->publish(DOMAIN_SITE, ['key' => $a->keys->public]);
    $never = static function (): never {
        throw new \RuntimeException('the user was asked');
    };
    $result = $dCall($b, ['domain' => DOMAIN_SITE, 'partner' => 'a'], $never);
    $check($result['isError'] === false && $dSaid($result)['status'] === 'listed' && $dSaid($result)['key'] === $a->keys->public, 'a domain on the list is read and the user is not asked');
    $result = $dCall($b, ['domain' => DOMAIN_SITE, 'message' => $digest], $never);
    $check($result['isError'] === false && $dSaid($result)['status'] === 'listed' && $dSaid($result)['signature_checked_locally'] === true, 'a message over the tool');
    $web->sites[DOMAIN_SITE] = fakeDomainHttp(503);
    $result = $dCall($b, ['domain' => DOMAIN_SITE, 'partner' => 'a'], $never);
    $check($result['isError'] === false && $dSaid($result)['status'] === 'unreachable', 'unreachable is an answer and not an error');
    $web->publish(DOMAIN_SITE);
    $result = $dCall($b, ['domain' => DOMAIN_SITE, 'partner' => 'a'], $never);
    $check($result['isError'] === false && $dSaid($result)['status'] === 'not_listed', 'and so is not_listed');

    $seen = [];
    $typing = static function (mixed $typed) use (&$seen): callable {
        return static function (array $question, string $about, string $tool, string $key) use ($typed, &$seen): array {
            $seen = [$question, $about, $tool, $key];

            return ['answer' => ['action' => 'accept', 'content' => ['domain' => $typed]]];
        };
    };
    $result = $dCall($a, ['domain' => 'supp1ier.example', 'partner' => 'b'], $typing('supplier.example'));
    $said = $dSaid($result);
    $question = (string) json_encode($seen[0] ?? null);
    $check($result['isError'] === true && $said['reason'] === 'domain_not_confirmed' && Policy::allowedDomains($a) === [], 'a lookalike stops at what the user types');
    $check(!str_contains(json_encode($said), 'supplier.example'), 'what the user typed is not handed to the model');
    $check(!str_contains($question, 'supp1ier') && str_contains($question, 'your partner b') && str_contains($question, 'example.org') && ($seen[2] ?? null) === 'aamio_addon_domain_check' && ($seen[3] ?? null) === Mcp::QUESTION, 'the form asks for the domain and does not offer the one asked for');
    $kelvin = "\u{212A}it.example";
    $check($dSaid($dCall($a, ['domain' => 'kit.example', 'partner' => 'b'], $typing($kelvin)))['reason'] === 'domain_not_confirmed', 'what the command line refuses stops at the form too');
    foreach ([[], ['domain' => 7], ['domain' => '']] as $content) {
        $result = $dCall($a, ['domain' => DOMAIN_SITE, 'partner' => 'b'], static fn (): array => ['answer' => ['action' => 'accept', 'content' => $content]]);
        $check($dSaid($result)['reason'] === 'domain_not_confirmed' && Policy::allowedDomains($a) === [], 'an accept with no domain in it is no agreement');
    }
    foreach ([['decline', 'domain_declined'], ['cancel', 'domain_not_answered']] as [$action, $reason]) {
        $result = $dCall($a, ['domain' => DOMAIN_SITE, 'partner' => 'b'], static fn (): array => ['answer' => ['action' => $action]]);
        $check($result['isError'] === false && $dSaid($result)['reason'] === $reason && Policy::allowedDomains($a) === [] && str_contains($dSaid($result)['note'], 'Do not ask again'), 'a user who does not agree adds nothing: ' . $action);
    }
    $check(in_array(Policy::isSameHost(strtoupper(DOMAIN_SITE), DOMAIN_SITE), [true], true) && Policy::isSameHost('  ' . DOMAIN_SITE . "\n", DOMAIN_SITE) && !Policy::isSameHost("\u{00A0}" . DOMAIN_SITE, DOMAIN_SITE) && !Policy::isSameHost(7, DOMAIN_SITE), 'capitals and a space around it are how people type, and nothing else is forgiven');

    // Over the wire of 2026-07-28: the question is the result, and the second call has the answer.
    $modern = static fn (array $arguments, ?string $state = null, ?array $responses = null, array $capabilities = ['elicitation' => ['form' => []]], int $id = 1): array => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_domain_check', 'arguments' => $arguments] + ($state === null ? [] : ['requestState' => $state]) + ($responses === null ? [] : ['inputResponses' => $responses]) + ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'check', 'version' => '1'], 'io.modelcontextprotocol/clientCapabilities' => $capabilities]]];
    [$a, $b] = $dPair();
    $server = new McpServer($a);
    $first = $server->handle($modern(['domain' => DOMAIN_SITE, 'partner' => 'b']));
    $asked = $first['result'] ?? [];
    $check(($asked['resultType'] ?? null) === 'input_required' && isset($asked['inputRequests'][Mcp::QUESTION]) && is_string($asked['requestState'] ?? null) && !str_contains((string) json_encode($asked['inputRequests']), DOMAIN_SITE), 'over 2026-07-28 the question is the result, and does not show the domain');
    $accept = [Mcp::QUESTION => ['action' => 'accept', 'content' => ['domain' => DOMAIN_SITE]]];
    $otherCall = $server->handle($modern(['domain' => 'another.example', 'partner' => 'b'], $asked['requestState'], [Mcp::QUESTION => ['action' => 'accept', 'content' => ['domain' => 'another.example']]]));
    $check(($otherCall['result']['structuredContent']['reason'] ?? null) === 'domain_not_allowed' && Policy::allowedDomains($a) === [], 'an answer cannot be carried over to another domain or another subject');
    // The same call, so all that tells the two apart is the tool the state was sealed for.
    $partnerState = $server->sealState(Codec::json([DOMAIN_SITE, 'b', null, null]));
    $otherTool = $server->handle($modern(['domain' => DOMAIN_SITE, 'partner' => 'b'], $partnerState, $accept));
    $check(($otherTool['result']['structuredContent']['reason'] ?? null) === 'domain_not_allowed' && Policy::allowedDomains($a) === [], 'a state from the partner question opens no website');
    $noForm = $server->handle($modern(['domain' => DOMAIN_SITE, 'partner' => 'b'], null, null, []));
    $check(($noForm['result']['isError'] ?? null) === true && str_contains($noForm['result']['structuredContent']['fix'] ?? '', 'aamio addon domain allow'), 'an app that cannot show a form gets the command line');

    $web = $dWeb();
    Policy::$standIn = $web->network();
    $web->publish(DOMAIN_SITE, ['key' => $b->keys->public]);
    $stateFor = static function (McpServer $server, array $arguments) use ($modern): string {
        return $server->handle($modern($arguments))['result']['requestState'];
    };
    $arguments = ['domain' => DOMAIN_SITE, 'partner' => 'b'];
    $state = $stateFor($server, $arguments);
    $second = $server->handle($modern($arguments, $state, $accept));
    $check(($second['result']['structuredContent']['status'] ?? null) === 'listed' && $web->lookups === [DOMAIN_SITE] && Policy::allowedDomains($a) === [DOMAIN_SITE], 'the second call reads, and a yes in the form is kept');
    $third = $server->handle($modern(['domain' => DOMAIN_SITE, 'partner' => 'b']));
    $check(!isset($third['result']['inputRequests']), 'the user is asked once');
    Policy::forget($a, DOMAIN_SITE);
    $reused = $server->handle($modern($arguments, $state, $accept));
    $check(($reused['result']['structuredContent']['reason'] ?? null) === 'domain_not_allowed' && Policy::allowedDomains($a) === [], 'an answer is used once');

    // Before 2026-07-28 the question is a request of the server's own, sent while the call waits.
    [$a, $b] = $dPair();
    $web = $dWeb();
    Policy::$standIn = $web->network();
    $web->publish(DOMAIN_SITE, ['key' => $b->keys->public]);
    $lines = [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['elicitation' => new \stdClass()], 'clientInfo' => ['name' => 'check', 'version' => '1']]],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_domain_check', 'arguments' => ['domain' => DOMAIN_SITE, 'partner' => 'b']]],
        ['jsonrpc' => '2.0', 'id' => 'aamio-1', 'result' => ['action' => 'accept', 'content' => ['domain' => DOMAIN_SITE]]],
    ];
    $in = fopen('php://memory', 'r+');
    $outStream = fopen('php://memory', 'r+');
    fwrite($in, implode("\n", array_map('json_encode', $lines)) . "\n");
    rewind($in);
    $home = $a->home;
    (new McpServer($a))->serve($in, $outStream);
    rewind($outStream);
    $replies = array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", (string) stream_get_contents($outStream)))));
    $question = $replies[1] ?? [];
    $check(($question['method'] ?? null) === 'elicitation/create' && ($question['params']['mode'] ?? null) === 'form' && !str_contains((string) json_encode($question), DOMAIN_SITE), 'before 2026-07-28 the question goes out on the wire while the call waits');
    $check(($replies[2]['result']['structuredContent']['status'] ?? null) === 'listed' && ($replies[2]['id'] ?? null) === 2, 'and the answer reads');
    $a = new Runtime($home, 'https://fake.test', null, false, $dQuiet);
    $check(Policy::allowedDomains($a) === [DOMAIN_SITE], 'and the list survives the server');
    Policy::$standIn = [];
    Policy::$started = [];
})();
putenv('AAMIO_ADDONS');
\Aamio\Addons::reset();
