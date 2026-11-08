<?php

declare(strict_types=1);

/*
 * Domain Keys on contract 2: the add-on's process, and the core around it.
 *
 * Lists and Fetch have tests of their own, and the core's handling of a
 * contract-2 add-on (the manifest, enable, hosts, the subject, the deadline) is
 * tried in tests/addons.inc.php with a fake. What is tried here is Domain Keys
 * itself: the process answers every request the core sends, with the words each
 * answer carries, over the fake web; and the real package through the core,
 * named in AAMIO_ADDONS, in a process of its own, where no website is needed or
 * the website cannot be reached. The same checks as aamio-python's
 * tests/test_domain_process.py.
 *
 * Included by the core's tests/runtime.php, with $root, $check, $childPhp and
 * $cliRun in scope.
 */

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/fake.inc.php';

use Aamio\AddonProcess;
use Aamio\Addons;
use Aamio\DomainKeys\Lists;
use Aamio\DomainKeys\Policy;
use Aamio\DomainKeys\Process;
use Aamio\DomainKeys\Refused;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

echo "domain keys: the process\n";

const DOMAIN_SITE = 'leverandor.example';

(static function () use ($root, $check, $childPhp, $cliRun): void {
    $quiet = static function (string $line): void {
    };
    /** The internet as a read sees it: a page per domain, and what was asked for. */
    $web = new class () {
        public array $sites = [];
        public array $lookups = [];
        public array $connections = [];
        public ?\Throwable $failOpen = null;

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
                    if ($this->failOpen !== null) {
                        throw $this->failOpen;
                    }
                    $connection = new FakeDomainConnection([$this->sites[$host]]);
                    $this->connections[] = $connection;

                    return $connection;
                },
            ];
        }

        public function reset(): void
        {
            $this->sites = [];
            $this->lookups = [];
            $this->connections = [];
            $this->failOpen = null;
        }

        public function untouched(): bool
        {
            return $this->lookups === [] && $this->connections === [];
        }
    };
    $partner = static fn (string $key, string $name = 'b'): array => ['key' => $key, 'subject_source' => 'partner', 'partner' => $name, 'signature_checked_locally' => null];
    $message = static fn (string $key, string $digest): array => ['key' => $key, 'subject_source' => 'message', 'message_sha256' => $digest, 'signature_checked_locally' => true];
    $request = static fn (string $kind, string $name, array $arguments = [], ?array $subject = null, ?string $ownKey = null, ?string $stateDir = null): array => ['aamio_addon' => 2, 'kind' => $kind, 'name' => $name, 'arguments' => $arguments, 'subject' => $subject, 'own_key' => $ownKey, 'state_dir' => $stateDir, 'deadline_seconds' => 30, 'aamio' => ['version' => 'test', 'runtime' => 'php']];
    $call = static fn (?array $subject, ?string $name = null, string $domain = DOMAIN_SITE): array => Process::handle($request('command', 'check', ['domain' => $domain, 'partner' => 'b', 'name' => $name], $subject), $web->network());
    putenv('AAMIO_DOMAIN_DENY');

    // ------------------------------------------------------- the process answers

    $said = Process::handle($request('probe', 'probe'));
    $check($said === ['exit_code' => 0, 'is_error' => false, 'result' => ['ok' => true, 'name' => 'domain', 'version' => Process::VERSION, 'aamio_addon' => 2]], 'the probe names the add-on');
    $check(Process::handle(['aamio_addon' => 1, 'kind' => 'probe'])['exit_code'] === 2 && Process::handle(null)['is_error'] === true, 'a request on another contract is refused, not guessed at');

    $key = Keys::generate()->public;
    $web->publish(DOMAIN_SITE, ['key' => $key, 'name' => 'support']);
    $said = $call($partner($key));
    $result = $said['result'];
    $check($said['exit_code'] === 0 && $said['is_error'] === false && $result['status'] === 'listed' && $result['key'] === $key && $result['domain'] === DOMAIN_SITE && $result['matched_name'] === 'support', 'a partner is checked with the key the core resolved');
    $check($result['subject_source'] === 'partner' && $result['partner'] === 'b' && $result['signature_checked_locally'] === null && $result['statement'] === Policy::STATEMENTS['listed'] && !isset($result['fix']) && $web->lookups === [DOMAIN_SITE], 'what the core said of the subject travels with the answer');

    $web->reset();
    $digest = str_repeat('ab', 32);
    $web->publish(DOMAIN_SITE, ['key' => $key]);
    $result = Process::handle($request('tool', 'aamio_addon_domain_check', ['domain' => DOMAIN_SITE, 'message' => $digest], $message($key, $digest)), $web->network())['result'];
    $check($result['status'] === 'listed' && $result['subject_source'] === 'message' && $result['message_sha256'] === $digest && $result['signature_checked_locally'] === true && !isset($result['partner']), 'a message subject comes back with its hash');

    foreach ([
        [fakeDomainHttp(404, null, ''), 'no_list', 'no_list', 4],
        [fakeDomainHttp(410, null, ''), 'no_list', 'no_list', 4],
        [fakeDomainHttp(503, null, ''), 'unreachable', 'http_status', 5],
        [fakeDomainHttp(301, ['Location' => 'https://elsewhere.example/'], ''), 'invalid', 'redirect_refused', 2],
        [fakeDomainHttp(200, ['Content-Type' => 'text/html'], '<html>'), 'invalid', 'content_type', 2],
        [fakeDomainHttp(200, null, '{"aamio_keys": 2, "keys": []}'), 'invalid', 'invalid_document', 2],
    ] as [$page, $status, $reason, $code]) {
        $web->reset();
        $web->sites[DOMAIN_SITE] = $page;
        $said = $call($partner(Keys::generate()->public));
        $check([$said['result']['status'], $said['result']['reason'], $said['exit_code']] === [$status, $reason, $code] && $said['result']['statement'] === Policy::STATEMENTS[$status] && !isset($said['result']['fix']) && $said['is_error'] === false, 'an answer a site can give comes through with its statement and code: ' . $status . '/' . $reason);
    }

    $web->reset();
    $web->publish(DOMAIN_SITE, ['key' => Keys::generate()->public]);
    $said = $call($partner(Keys::generate()->public));
    $check($said['exit_code'] === 3 && $said['result']['status'] === 'not_listed' && str_contains($said['result']['statement'], 'no evidence of fraud') && !str_contains($said['result']['statement'], 'label'), 'a list without the key is not listed, and no evidence of fraud');

    $web->reset();
    $web->publish(DOMAIN_SITE, ['key' => $key, 'name' => 'support']);
    $answer = $call($partner($key), 'billing')['result'];
    $check($answer['status'] === 'not_listed' && $answer['requested_name'] === 'billing' && $answer['statement'] === Policy::NOT_UNDER_THAT_LABEL, 'the wrong label is not listed, and the words say under which label');
    $check($call($partner($key), 'support')['result']['status'] === 'listed' && $call($partner($key))['result']['matched_name'] === 'support', 'and the right label, or none, finds it');

    $web->reset();
    $web->sites[DOMAIN_SITE] = new Refused('dns', null, 'unreachable');
    $said = $call($partner($key));
    $check($said['exit_code'] === 5 && $said['result']['status'] === 'unreachable' && str_contains($said['result']['statement'], 'not the same as not listed'), 'a lookup that fails is unreachable and never not listed');

    $web->reset();
    $web->sites[DOMAIN_SITE] = fakeDomainHttp();
    $web->failOpen = new Refused('tls', null, 'unreachable');
    $result = $call($partner($key))['result'];
    $check($result['status'] === 'unreachable' && $result['reason'] === 'tls' && $result['note'] === Policy::TLS_NOTE, 'a certificate this machine cannot verify says what to look at');

    $web->reset();
    foreach ([['localhost', null, 'invalid_domain'], ['https://leverandor.example', null, 'invalid_domain'], [DOMAIN_SITE, 'Bad Name', 'invalid_requested_name']] as [$domain, $name, $reason]) {
        $said = $call($partner($key), $name, $domain);
        $check($said['exit_code'] === 2 && $said['is_error'] === true && $said['result']['status'] === 'invalid' && $said['result']['reason'] === $reason && isset($said['result']['fix']) && $web->untouched(), 'a bad domain or name stops here: ' . $reason);
    }
    $web->publish(DOMAIN_SITE, ['key' => $key]);
    $said = Process::handle($request('command', 'check', ['domain' => DOMAIN_SITE, 'partner' => 'b']), $web->network());
    $check($said['exit_code'] === 2 && $said['result']['reason'] === 'subject_missing' && $web->untouched(), 'no subject is refused before the network');

    putenv('AAMIO_DOMAIN_DENY=93.184.216.0/24');
    $result = $call($partner($key))['result'];
    $check($result['status'] === 'invalid' && $result['reason'] === 'target_not_public' && $result['statement'] === Policy::NOT_CONTACTED && $web->connections === [], 'the operator can keep more networks out');
    putenv('AAMIO_DOMAIN_DENY=not a network');
    $said = $call($partner($key));
    $check($said['result']['reason'] === 'domain_deny_invalid' && $said['exit_code'] === 2, 'a keep-out that does not parse stops the check rather than leave a range open');
    putenv('AAMIO_DOMAIN_DENY');

    $web->reset();
    $web->publish(DOMAIN_SITE, ['key' => $key]);
    $call($partner($key));
    $sent = $web->connections[0]->sent;
    $check(str_contains($sent, '/.well-known/aamio-keys.json') && !str_contains($sent, $key) && !str_contains($sent, 'partner'), 'the site learns that a list was read, and nothing of whom it was read about');

    $said = Process::handle($request('command', 'file', ['name' => 'support'], null, $key));
    $check(base64_decode($said['raw'], true) === Lists::make([['key' => $key, 'name' => 'support']]) && str_contains($said['stderr'], 'Publish it at') && $said['exit_code'] === 0 && !array_key_exists('result', $said), 'file is the bytes every generator writes, with the note to the user');
    $bad = Process::handle($request('command', 'file', ['name' => 'Bad Name'], null, $key));
    $check($bad['exit_code'] === 2 && $bad['result'] === null && str_contains($bad['stderr'], '--name is a label'), 'the complaint goes to the user, never into the file standard output may be');
    $listFile = $root . DIRECTORY_SEPARATOR . 'domain-list.json';
    file_put_contents($listFile, Lists::make([['key' => $key]]));
    $said = Process::handle($request('command', 'validate', ['file' => $listFile]));
    $check($said['exit_code'] === 0 && $said['result']['format_valid'] === true && $said['result']['keys'] === 1 && $said['result']['domain_checked'] === false, 'validate reads the file');
    $said = Process::handle($request('command', 'validate', ['file' => $root . DIRECTORY_SEPARATOR . 'nope.json']));
    $check($said['exit_code'] === 2 && $said['result']['error'] === 'local_file_unreadable', 'and says when it cannot');
    $said = Process::handle($request('command', 'nope'));
    $check($said['exit_code'] === 2 && str_contains($said['result']['error'], 'has no "command" called "nope"'), 'an unknown name is refused');

    // ---------------------------------------- the real package, through the core

    putenv('AAMIO_ADDONS=' . dirname(__DIR__));
    Addons::reset();
    AddonProcess::resetLimits();
    $cli = static fn (array $words, string $home): array => $cliRun(array_merge($childPhp, [dirname(__DIR__, 3) . '/bin/aamio', '--home', $home, '--host', 'https://fake.test', '--no-archive'], $words));
    $homes = 0;
    $homeWithPartner = static function (?string $key = null) use ($root, $quiet, &$homes): array {
        $home = $root . DIRECTORY_SEPARATOR . 'domain-process-' . (++$homes);
        mkdir($home, 0700, true);
        $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
        $key ??= Keys::generate()->public;
        $runtime->partnerAdd('bob', $key);
        $own = $runtime->keys->public;
        $runtime->close();

        return [$home, $key, $own];
    };

    $home = $root . DIRECTORY_SEPARATOR . 'domain-process-list';
    [$code, $out] = $cli(['addon', 'list'], $home);
    $row = json_decode($out, true)['addons'][0] ?? [];
    $commands = $row['commands'] ?? [];
    sort($commands);
    $check($code === 0 && ($row['name'] ?? null) === 'domain' && ($row['contract'] ?? null) === 2 && ($row['version'] ?? null) === Process::VERSION && ($row['named_in'] ?? null) === 'AAMIO_ADDONS', 'the core reads the manifest and lists the add-on without running it');
    $check($commands === ['allow', 'check', 'file', 'forget', 'list', 'validate'] && ($row['mcp_tools'] ?? null) === ['aamio_addon_domain_check'] && !is_dir($home . DIRECTORY_SEPARATOR . 'addons'), 'its commands and the three the core gives it, and no directory was made');
    $tools = Addons::tools(McpServer::coreTools());
    $check(count($tools) === 1 && $tools[0]['name'] === 'aamio_addon_domain_check' && $tools[0]['annotations']['readOnlyHint'] === false && $tools[0]['inputSchema']['required'] === ['domain'] && $tools[0]['inputSchema']['additionalProperties'] === false && !isset($tools[0]['inputSchema']['oneOf']), 'the tool comes from the manifest, with its schema and hints');

    [$home, , $own] = $homeWithPartner();
    [$code, $out, $err] = $cli(['addon', 'domain', 'file', '--name', 'support'], $home);
    $check($code === 0 && $out === Lists::make([['key' => $own, 'name' => 'support']]) && !str_contains($out, "\r") && str_contains($err, 'Publish it at'), 'file writes the bytes every generator writes, from a process of its own');
    [$code, $out, $err] = $cli(['addon', 'domain', 'file', '--name', 'Bad Name'], $home);
    $check($code === 2 && $out === '' && str_contains($err, '--name is a label'), 'nothing on standard output for a bad name, so a redirect publishes no complaint');

    $home = $root . DIRECTORY_SEPARATOR . 'domain-process-validate';
    [$code, $out] = $cli(['addon', 'domain', 'validate', $listFile], $home);
    $check($code === 0 && json_decode($out, true)['format_valid'] === true && !is_dir($home), 'validate reads the file and makes no home');
    [$code, $out] = $cli(['addon', 'domain', 'validate', $root . DIRECTORY_SEPARATOR . 'nope.json'], $home);
    $check($code === 2 && json_decode($out, true)['error'] === 'local_file_unreadable', 'and says when it cannot');
    [$home] = $homeWithPartner();
    $held = new Runtime($home, 'https://fake.test', null, false, $quiet);
    [$code, $out] = $cli(['addon', 'domain', 'validate', $listFile], $home);
    $held->close();
    $check($code === 0 && json_decode($out, true)['keys'] === 1, 'validate runs beside a runtime that holds the home');

    [$home, $bobKey] = $homeWithPartner();
    [$code, $out] = $cli(['addon', 'domain', 'check', DOMAIN_SITE, '--partner', 'bob'], $home);
    $said = json_decode($out, true);
    $check($code === 2 && ($said['reason'] ?? null) === 'host_not_allowed' && str_contains($said['fix'] ?? '', 'aamio addon domain allow ' . DOMAIN_SITE) && str_contains($said['fix'] ?? '', 'aamio_addon_domain_check asks them') && !is_dir($home . '/addons/domain'), 'a website not on the list is refused by the core, before the process starts');
    [$code, $out] = $cli(['addon', 'domain', 'allow', 'Leverandor.Example'], $home);
    $check($code === 0 && json_decode($out, true)['host'] === DOMAIN_SITE && json_decode($out, true)['hosts'] === [DOMAIN_SITE], 'allow puts a website on the list, in its exact form');
    [, $out] = $cli(['addon', 'domain', 'list'], $home);
    $check(json_decode($out, true) === ['hosts' => [DOMAIN_SITE]], 'list shows it');
    [$code, $out] = $cli(['addon', 'domain', 'check', DOMAIN_SITE, '--partner', 'nobody'], $home);
    $check($code === 2 && json_decode($out, true)['reason'] === 'partner_unavailable', 'a partner nobody has is refused by the core');
    [$code, $out] = $cli(['addon', 'domain', 'forget', DOMAIN_SITE], $home);
    $check($code === 0 && json_decode($out, true)['removed'] === true && json_decode($cli(['addon', 'domain', 'list'], $home)[1], true) === ['hosts' => []], 'forget takes it off');

    [$home] = $homeWithPartner();
    file_put_contents($home . DIRECTORY_SEPARATOR . 'domains.json', json_encode(['old.example']));
    [, $out] = $cli(['addon', 'domain', 'list'], $home);
    $check(json_decode($out, true) === ['hosts' => ['old.example']] && is_file($home . '/addons/domain.hosts.json'), 'domains.json from 0.1.0 is the list, once');

    [$home, $bobKey] = $homeWithPartner();
    $host = 'no-such-host-aamio-test.invalid';
    $cli(['addon', 'domain', 'allow', $host], $home);
    [$code, $out] = $cli(['addon', 'domain', 'check', $host, '--partner', 'bob'], $home);
    $said = json_decode($out, true) ?? [];
    $check($code === 5 && ($said['status'] ?? null) === 'unreachable' && ($said['reason'] ?? null) === 'dns' && ($said['key'] ?? null) === $bobKey && ($said['partner'] ?? null) === 'bob' && ($said['statement'] ?? null) === Policy::STATEMENTS['unreachable'] && is_dir($home . '/addons/domain'), 'a check through the core reaches the process and comes back, the name unresolved');
    [$code, , $err] = $cli(['addon', 'domain', 'check', DOMAIN_SITE, '--partner', 'bob', '--message', str_repeat('a', 64)], $home);
    $check($code === 2 && str_contains($err, 'unrecognized arguments'), 'the command line offers no message');
    [$code, , $err] = $cli(['addon', 'domain', 'check', DOMAIN_SITE, '--partner', 'bob', '--key', 'x'], $home);
    $check($code === 2 && str_contains($err, 'unrecognized arguments'), 'and no key');

    // --------------------------------------------------------------- over MCP

    $modern = static fn (array $arguments, ?string $state = null, ?array $responses = null, array $capabilities = ['elicitation' => ['form' => []]], int $id = 1): array => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_domain_check', 'arguments' => $arguments] + ($state === null ? [] : ['requestState' => $state]) + ($responses === null ? [] : ['inputResponses' => $responses]) + ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'check', 'version' => '1'], 'io.modelcontextprotocol/clientCapabilities' => $capabilities]]];
    [$home] = $homeWithPartner();
    $a = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $manifest = Addons::byName('domain', $home);
    $server = new McpServer($a);
    $first = $server->handle($modern(['domain' => $host, 'partner' => 'bob']));
    $asked = $first['result'] ?? [];
    $form = $asked['inputRequests'][AddonProcess::HOST_QUESTION] ?? [];
    $check(($asked['resultType'] ?? null) === 'input_required' && ($form['method'] ?? null) === 'elicitation/create' && ($form['params']['mode'] ?? null) === 'form' && !str_contains((string) json_encode($form), $host) && str_contains($form['params']['message'] ?? '', 'your partner bob') && $manifest !== null && AddonProcess::allowedHosts($a, $manifest) === [], 'over 2026-07-28 the question is the result: who, and not the website asked for');
    $second = $server->handle($modern(['domain' => $host, 'partner' => 'bob'], $asked['requestState'], [AddonProcess::HOST_QUESTION => ['action' => 'accept', 'content' => ['domain' => $host]]], id: 2));
    $said = $second['result']['structuredContent'] ?? [];
    $check(($said['status'] ?? null) === 'unreachable' && ($said['reason'] ?? null) === 'dns' && ($said['partner'] ?? null) === 'bob' && AddonProcess::allowedHosts($a, $manifest) === [$host], 'the user\'s yes lets the process run, and is kept');
    $moved = $server->handle($modern(['domain' => 'other.example', 'partner' => 'bob'], $asked['requestState'], [AddonProcess::HOST_QUESTION => ['action' => 'accept', 'content' => ['domain' => 'other.example']]], id: 3));
    $check(($moved['result']['isError'] ?? null) === true && ($moved['result']['structuredContent']['reason'] ?? null) === 'host_not_allowed' && AddonProcess::allowedHosts($a, $manifest) === [$host], 'an answer is not carried over to another website');
    $noForm = $server->handle($modern(['domain' => DOMAIN_SITE, 'partner' => 'bob'], null, null, []));
    $check(($noForm['result']['isError'] ?? null) === true && str_contains($noForm['result']['structuredContent']['fix'] ?? '', 'aamio addon domain allow'), 'an app that cannot show a form gets the command line');
    $a->close();

    putenv('AAMIO_ADDONS');
    Addons::reset();
    AddonProcess::resetLimits();
})();
