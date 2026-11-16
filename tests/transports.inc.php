<?php

declare(strict_types=1);

/*
 * Transports in the runtime (contract 3, phase 2): two homes exchange messages over the local network with aamio.at out
 * of reach.
 *
 * The lan transport from addons/lan runs on 127.0.0.1, each home on a UDP port of its own with the other named as its
 * peer and broadcast off, so nothing leaves the machine. The service is at http://127.0.0.1:9, where nobody answers:
 * what arrives came over the transport. enable reads the transport from its manifest and probes it; the commands bind
 * and unbind and keep the profiles; and two processes, as two machines run them, exchange a message: one reads and
 * waits, or serves, and the other sends. Then what the follow-up check of 15 November 2026 found: a scope shared over a
 * transport, the byte budget of a read, what waits after the last unbind, a transport nobody is bound to any more, and
 * bytes written under another mode than the profile has now.
 */

use Aamio\Addons;
use Aamio\CarryState;
use Aamio\Codec;
use Aamio\Http;
use Aamio\McpServer;
use Aamio\Runtime;
use Aamio\TransportProcess;
use Aamio\Transports;

echo "transports\n";

$lanDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . 'lan';
$nowhere = 'http://127.0.0.1:9';
$tRoot = $root . DIRECTORY_SEPARATOR . 'transports';
mkdir($tRoot, 0700, true);
$savedOverride = Http::$override;
$savedAddons = getenv('AAMIO_ADDONS');
$savedLocal = getenv('LOCALAPPDATA');
$savedState = getenv('XDG_STATE_HOME');
Http::$override = null;
// The transports' working folders under the test's own root, not in the user's.
putenv('LOCALAPPDATA=' . $tRoot . DIRECTORY_SEPARATOR . 'state');
putenv('XDG_STATE_HOME=' . $tRoot . DIRECTORY_SEPARATOR . 'state');
$tFreePorts = static function (int $count): array {
    $holders = [];
    $ports = [];
    for ($i = 0; $i < $count; $i++) {
        $holder = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $holders[] = $holder;
        $ports[] = (int) substr((string) strrchr((string) stream_socket_get_name($holder, false), ':'), 1);
    }
    foreach ($holders as $holder) {
        fclose($holder);
    }

    return $ports;
};
$lanVariables = static fn (int $port, int $peer): array => ['AAMIO_LAN_PORT' => (string) $port, 'AAMIO_LAN_BIND' => '127.0.0.1', 'AAMIO_LAN_BROADCAST' => 'none', 'AAMIO_LAN_PEERS' => '127.0.0.1:' . $peer];
$quietly = static function (string $line): void {
};

try {
    // enable: from the manifest, probed, and said.
    putenv('AAMIO_ADDONS');
    $enableHome = $tRoot . DIRECTORY_SEPARATOR . 'enable';
    Addons::useHome($enableHome);
    Addons::useInstalled([['package' => 'aisenseapi/aamio-lan', 'version' => '0.1.0', 'path' => $lanDir]]);
    $row = Addons::describe()['addons'][0] ?? [];
    $check(($row['name'] ?? null) === 'lan' && ($row['contract'] ?? null) === 3 && ($row['enabled'] ?? null) === false && ($row['medium'] ?? null) === 'lan'
        && ($row['permissions'] ?? null) === ['network_local'] && ($row['environment'] ?? null) === ['AAMIO_LAN_PORT', 'AAMIO_LAN_BIND', 'AAMIO_LAN_BROADCAST', 'AAMIO_LAN_PEERS'],
        'a transport is listed from its manifest: contract 3, the medium, what it says it uses and the variables it asks for', json_encode($row));
    $said = Addons::enable('lan');
    $note = (string) ($said['note'] ?? '');
    $check(($said['addons'][0]['enabled'] ?? null) === true && ($said['addons'][0]['mcp_tools'] ?? null) === [] && str_contains($note, 'is a transport here from now on')
        && str_contains($note, "never the home's key") && str_contains($note, 'network_local') && str_contains($note, 'aamio transport bind PARTNER lan'),
        'enable probes it, records it, and says who starts it, what it gets and what it says it uses', $note);
    $folder = Transports::workingFolder($enableHome, 'lan', false);
    $check(is_dir($folder) && !str_starts_with($folder, $enableHome), 'its working folder is made outside the home');
    Addons::useInstalled(null);
    Addons::useHome(null);

    // The commands, in this process, with lan named in AAMIO_ADDONS.
    putenv('AAMIO_ADDONS=' . $lanDir);
    Addons::reset();
    $made = [];
    foreach (['c', 'd'] as $name) {
        $made[$name] = new Runtime($tRoot . DIRECTORY_SEPARATOR . $name, $nowhere, null, true, $quietly);
    }
    $made['c']->partnerAdd('d', $made['d']->keys->public);
    $made['d']->partnerAdd('c', $made['c']->keys->public);
    $bound = Transports::bind($made['c'], 'd', 'lan');
    $check($bound['routes'] === [['transport' => 'lan', 'profile' => 'sealed']] && str_contains($bound['note'], 'never over HTTPS instead'), 'bind: a partner over lan, sealed unless another profile is named');
    $readable = Transports::profileAdd($made['c'], 'open-lan', true, 300, 30);
    $check($readable['profile']['mode'] === 'readable' && str_contains($readable['note'], 'everyone who hears the medium can read'), 'a readable profile is made only by choice, and says what it means');
    $code = static function (callable $try): ?string {
        try {
            $try();
        } catch (\Throwable $error) {
            return Runtime::errorCode($error);
        }

        return null;
    };
    $check($code(static fn () => Transports::profileAdd($made['c'], 'short', false, 20, 30)) === 'transport_profile_invalid', 'a window as long as the lifetime is refused');
    $check($code(static fn () => Transports::profileAdd($made['c'], 'Big Name')) === 'transport_profile_invalid', 'a name of another kind is refused');
    $check($code(static fn () => Transports::profileRemove($made['c'], 'sealed')) === 'transport_profile_in_use', 'a profile a partner is bound to is not removed');
    $check($code(static fn () => Transports::profileAdd($made['c'], 'sealed', true)) === 'transport_profile_in_use', 'nor changed under it');
    $check($code(static fn () => Transports::bind($made['c'], 'd', 'radio')) === 'transport_unknown', 'a transport that is not enabled is not bound');
    $listed = Transports::listing($made['c']);
    $check(array_column($listed['transports'], 'name') === ['lan'] && $listed['transports'][0]['partners'] === ['d'] && count($listed['profiles']) === 2 && $listed['bindings'][0]['routes'] === $bound['routes'],
        'list: the transport, the partners bound to it, the profiles and the bindings');
    // bindings.json is the file aamio-python writes: an object of objects, the empty one too.
    $check(Transports::unbind($made['c'], 'd')['routes'] === [] && str_contains((string) file_get_contents($tRoot . '/c/transports/bindings.json'), '"partners":{}'),
        'unbind, and an empty map is written as an object, as the other runtimes read it');
    Transports::bind($made['c'], 'd', 'lan');
    file_put_contents($tRoot . '/c/transports/bindings.json', '{not json');
    $check($code(static fn () => $made['c']->send('d', 'hvor?')) === 'transport_config_unreadable' && $made['c']->outboxPending() === [],
        'bindings that cannot be read stop the send, and nothing goes over HTTPS instead');
    foreach ($made as $runtime) {
        $runtime->close();
    }

    // Two processes, as two machines run them.
    [, $loaded] = $cliRun([PHP_BINARY, '-r', 'echo strtolower(implode(",", get_loaded_extensions()));']);
    $tChild = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir')];
    foreach (['sodium', 'curl'] as $extension) {
        if (!in_array($extension, explode(',', trim($loaded)), true)) {
            $tChild = array_merge($tChild, ['-d', 'extension=' . $extension]);
        }
    }
    [$portE, $portF] = $tFreePorts(2);
    foreach (['e', 'f'] as $name) {
        $made[$name] = new Runtime($tRoot . DIRECTORY_SEPARATOR . $name, $nowhere, null, true, $quietly);
    }
    $made['e']->partnerAdd('f', $made['f']->keys->public);
    $made['f']->partnerAdd('e', $made['e']->keys->public);
    Transports::bind($made['e'], 'f', 'lan');
    Transports::bind($made['f'], 'e', 'lan');
    $made['e']->close();
    $made['f']->close();
    $tEnv = static function (int $port, int $peer) use ($lanDir, $lanVariables, $tRoot): array {
        $env = [];
        // PHPRC and PHP_INI_SCAN_DIR with the rest: what the child's PHP lacks was asked above of a child with this
        // process's environment, so the children here read the same configuration, or they start without sodium.
        foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'PHPRC', 'PHP_INI_SCAN_DIR'] as $name) {
            if (getenv($name) !== false) {
                $env[$name] = (string) getenv($name);
            }
        }

        return $env + ['AAMIO_ADDONS' => $lanDir, 'LOCALAPPDATA' => $tRoot . DIRECTORY_SEPARATOR . 'state', 'XDG_STATE_HOME' => $tRoot . DIRECTORY_SEPARATOR . 'state'] + $lanVariables($port, $peer);
    };
    $tStart = static function (array $words, array $env, bool $input = false) use ($tChild): array {
        $errFile = (string) tempnam(sys_get_temp_dir(), 'aamio-transport-');
        $spec = [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']];
        if ($input) {
            $spec[0] = ['pipe', 'r'];
        }
        $process = proc_open(array_merge($tChild, [dirname(__DIR__) . '/bin/aamio'], $words), $spec, $pipes, null, $env);

        return [$process, $pipes, $errFile];
    };
    $tUp = static function (string $errFile, float $seconds = 20.0): bool {
        $until = hrtime(true) / 1e9 + $seconds;
        while (hrtime(true) / 1e9 < $until) {
            if (str_contains((string) @file_get_contents($errFile), 'transport lan: up')) {
                return true;
            }
            usleep(100000);
        }

        return false;
    };
    $tSend = static function (string $home, string $to, string $text, array $env) use ($tStart, $nowhere): array {
        [$process, $pipes, $errFile] = $tStart(['--home', $home, '--host', $nowhere, 'send', $to, $text], $env);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);

        return [$status, json_decode($out, true), $err];
    };

    [$reader, $readerPipes, $readerErr] = $tStart(['--home', $tRoot . '/f', '--host', $nowhere, 'read', '--wait', '25'], $tEnv($portF, $portE));
    $readerUp = $tUp($readerErr);
    [$sentStatus, $sent, $sentErr] = $readerUp ? $tSend($tRoot . '/e', 'f', 'hei fra kommandolinjen', $tEnv($portE, $portF)) : [-1, null, ''];
    $check($readerUp && $sentStatus === 0 && ($sent['status'] ?? null) === 'confirmed' && ($sent['via'] ?? null) === 'lan', 'send on the command line goes over lan and is confirmed by the receipt', json_encode([$sent, substr($sentErr, -400)]));
    $readOut = (string) stream_get_contents($readerPipes[1]);
    fclose($readerPipes[1]);
    $readStatus = proc_close($reader);
    $read = json_decode($readOut, true);
    $texts = array_map(static fn (array $m): mixed => $m['body']['text'] ?? null, $read['messages'] ?? []);
    $unreachable = array_filter($read['attention'] ?? [], static fn (array $n): bool => ($n['channel'] ?? null) === 'inbox' && ($n['state'] ?? null) === 'unreachable') !== [];
    $check($readStatus === 0 && $texts === ['hei fra kommandolinjen'] && ($read['messages'][0]['channel'] ?? null) === 'lan' && ($read['messages'][0]['encrypted'] ?? null) === true && $unreachable,
        'read --wait hosts the transport while it waits, returns what came over it, and says the inbox it could not reach', json_encode([$readStatus, $read, substr((string) @file_get_contents($readerErr), -400)]));
    @unlink($readerErr);

    // serve runs the transport while it waits for the next line: an ask that comes meanwhile is answered without a call.
    [$server, $serverPipes, $serverErr] = $tStart(['--home', $tRoot . '/f', '--host', $nowhere, 'serve'], $tEnv($portF, $portE), true);
    $serverUp = $tUp($serverErr);
    [$sentStatus, $sent, $sentErr] = $serverUp ? $tSend($tRoot . '/e', 'f', 'hei til serveren', $tEnv($portE, $portF)) : [-1, null, ''];
    $check($serverUp && $sentStatus === 0 && ($sent['status'] ?? null) === 'confirmed', 'serve answers the ask and takes the message while no call is served', json_encode([$sent, substr($sentErr, -400), substr((string) @file_get_contents($serverErr), -600)]));
    fwrite($serverPipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '0']]]) . "\n");
    fwrite($serverPipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'aamio_read', 'arguments' => ['wait' => 0]]]) . "\n");
    fclose($serverPipes[0]);
    $served = (string) stream_get_contents($serverPipes[1]);
    fclose($serverPipes[1]);
    proc_close($server);
    $readAnswer = null;
    foreach (explode("\n", $served) as $line) {
        $message = json_decode($line, true);
        if (is_array($message) && ($message['id'] ?? null) === 2) {
            $readAnswer = $message['result']['structuredContent'] ?? null;
        }
    }
    $check(array_map(static fn (array $m): mixed => $m['body']['text'] ?? null, $readAnswer['messages'] ?? []) === ['hei til serveren'], 'and aamio_read hands it over', json_encode([$readAnswer, substr($served, 0, 600)]));
    @unlink($serverErr);

    // The follow-up check of 2026-11-15, findings 1, 2, 6, 7 and 8. Where a transport itself is under test it runs, with
    // the partner in a process of its own. Where a read is, what a transport took in is put in the reader's carrier state
    // as its owner puts it there, so the read finds it without a second process. In this process the service is a
    // stand-in that gives no answer at once, as the connection that is refused gives none after two seconds.
    echo "transports: the follow-up check of 2026-11-15\n";
    Http::$override = static fn (): array => [0, ['error' => 'no answer: nobody is there', 'fix' => 'Nothing to do: this is the test.'], []];
    /** Every runtime opened below, so that each is closed whatever happens. */
    $tOpen = [];
    /** Homes a and b in a folder of their own, each the other's partner and bound to lan, open in this process: [a, b]. */
    $tHomes = static function (string $folder, bool $archive = true) use ($tRoot, $nowhere, $quietly, &$tOpen): array {
        @mkdir($tRoot . DIRECTORY_SEPARATOR . $folder, 0700, true);
        $pair = [];
        foreach (['a', 'b'] as $name) {
            $pair[$name] = $tOpen[] = new Runtime($tRoot . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $name, $nowhere, null, $archive, $quietly);
        }
        $pair['a']->partnerAdd('b', $pair['b']->keys->public);
        $pair['b']->partnerAdd('a', $pair['a']->keys->public);
        Transports::bind($pair['a'], 'b', 'lan');
        Transports::bind($pair['b'], 'a', 'lan');

        return [$pair['a'], $pair['b']];
    };
    /**
     * What $from sent $to over a transport and nobody has read: taken into the carrier state of $to as its owner takes it
     * in, under a profile of $mode, each body as send writes one.
     */
    $tTakeIn = static function (Runtime $from, Runtime $to, array $bodies, string $mode = 'sealed'): void {
        $profile = Transports::checked(Transports::profileRecord('as-bound', $mode));
        $theirs = $from->keys->publicRaw();
        $own = $to->keys->publicRaw();
        $atFrom = new CarryState($from->keys);
        $atTo = $to->transportService()->carry();
        $now = time();
        [$session, $ask] = $atFrom->ask($own, $profile, 0.0);
        [, $challenge] = $atTo->deliver($ask, [$theirs], $profile, 0.0, $now);
        [, $opening] = $atFrom->deliver($challenge, [$own], $profile, 0.0, $now);
        $atTo->deliver($opening, [$theirs], $profile, 0.0, $now);
        foreach ($bodies as $body) {
            $atTo->deliver($atFrom->send($own, $session, Codec::json(['from' => $from->keys->hashPrefix] + $body), $profile), [$theirs], $profile, 0.0, $now);
        }
    };
    /** Every row of a home's archive, as one text. */
    $tArchive = static function (Runtime $runtime): string {
        $folder = $runtime->home . DIRECTORY_SEPARATOR . 'archive';
        $rows = '';
        foreach ((array) @scandir($folder) as $file) {
            if (is_string($file) && str_ends_with($file, '.jsonl')) {
                $rows .= (string) file_get_contents($folder . DIRECTORY_SEPARATOR . $file);
            }
        }

        return $rows;
    };
    $tTexts = static fn (array $messages): array => array_map(static fn (array $m): mixed => $m['body']['text'] ?? null, $messages);
    $tStarts = static fn (array $messages): array => array_map(static fn (array $m): string => substr((string) ($m['body']['text'] ?? ''), 0, 2), $messages);
    /** What $try returns, or null when it throws: what it threw is in $tThrown, for the detail of a check. */
    $tThrown = null;
    $tTry = static function (callable $try) use (&$tThrown): mixed {
        $tThrown = null;
        try {
            return $try();
        } catch (\Throwable $error) {
            $tThrown = $error;

            return null;
        }
    };

    // Finding 1: a scope that came over a transport went past the scope handling: the key was shown to the reader and
    // written to the archive, and no scope was kept. And the one who shared it kept the key in its archive of what it sent.
    [$portFrom, $portTo] = $tFreePorts(2);
    [$from, $to] = $tHomes('scope');
    $to->close();
    $from->scopeNew('review');
    $scopeKey = $from->scopeKey('review')['key'];
    $from->transportService()->environ = array_merge(getenv(), $lanVariables($portFrom, $portTo));
    [$reader, $readerPipes, $readerErr] = $tStart(['--home', $to->home, '--host', $nowhere, 'read', '--wait', '25'], $tEnv($portTo, $portFrom));
    $readerUp = $tUp($readerErr);
    $shared = $readerUp ? $tTry(static fn () => $from->scopeShare('review', 'b', 'read')) : null;
    $check(($shared['status'] ?? null) === 'confirmed' && ($shared['via'] ?? null) === 'lan' && ($shared['scope'] ?? null) === 'review' && ($shared['access'] ?? null) === 'read',
        'a scope shared with a partner bound to a transport goes over the transport, as every message to that partner does', json_encode([$shared, $tThrown?->getMessage()]));
    $readOut = (string) stream_get_contents($readerPipes[1]);
    fclose($readerPipes[1]);
    $readStatus = proc_close($reader);
    $got = json_decode($readOut, true)['messages'] ?? [];
    $view = $got[0]['body']['data']['aamio_scope'] ?? [];
    $check($readStatus === 0 && count($got) === 1 && ($view['kept'] ?? null) === true && ($view['shared_as'] ?? null) === 'review' && ($view['can_read'] ?? null) === true
        && !array_key_exists('key', $view) && !str_contains($readOut, $scopeKey), 'read on the command line says the scope was kept, and its key never reaches whoever reads the message',
        json_encode([$readStatus, substr($readOut, 0, 900), substr((string) @file_get_contents($readerErr), -400)]));
    @unlink($readerErr);
    $to = $tOpen[] = new Runtime($to->home, $nowhere, null, true, $quietly);
    $check(array_column($to->scopeList(), 'name') === ['a.review'] && ($tTry(static fn () => $to->scopeKey('a.review'))['key'] ?? null) === $scopeKey, 'the scope is kept under the partner\'s name, with its key', json_encode($to->scopeList()));
    $check(($got[0]['archived'] ?? null) === true && $tArchive($to) !== '' && !str_contains($tArchive($to), $scopeKey), 'the archive of the one who read has the message, and not the key');
    $sharedRows = [];
    foreach (explode("\n", $tArchive($from)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['kind'] ?? null) === 'sent') {
            $sharedRows[] = $row['body']['data'] ?? null;
        }
    }
    $check($sharedRows === [['aamio_scope' => ['name' => 'review', 'access' => 'read']]] && !str_contains($tArchive($from), $scopeKey), 'the archive of the one who shared says what was shared and how, never the key', json_encode($sharedRows));
    $from->close();
    $to->close();

    // The same as the local server reads it. Every aamio_read has a byte budget, so the message is weighed first, on a
    // copy, and the scope in it is taken once.
    [$from, $to] = $tHomes('scope-server');
    $from->scopeNew('review');
    $scopeKey = $from->scopeKey('review')['key'];
    $tTakeIn($from, $to, [['text' => 'Scope review, shared to read and post.', 'data' => ['aamio_scope' => ['name' => 'review', 'key' => $scopeKey]]]]);
    $answer = (new McpServer($to))->dispatch('aamio_read', ['wait' => 0]);
    $got = $answer['structuredContent']['messages'] ?? [];
    $view = $got[0]['body']['data']['aamio_scope'] ?? [];
    $check(($answer['isError'] ?? null) === false && count($got) === 1 && ($view['kept'] ?? null) === true && ($view['shared_as'] ?? null) === 'review' && !array_key_exists('key', $view)
        && !str_contains((string) json_encode($answer), $scopeKey) && array_column($to->scopeList(), 'name') === ['a.review'] && ($tTry(static fn () => $to->scopeKey('a.review'))['key'] ?? null) === $scopeKey
        && ($got[0]['archived'] ?? null) === true && !str_contains($tArchive($to), $scopeKey),
        'aamio_read weighs it for its byte budget and then keeps the scope, once, and its key is never shown or archived', json_encode([$answer['structuredContent'] ?? null, $to->scopeList()]));
    $from->close();
    $to->close();

    // Finding 1: under a readable profile everyone who hears the medium would read the key, so it does not go; and a
    // message that carries one all the same has it taken out, and nothing is kept from it.
    [$from, $to] = $tHomes('scope-readable');
    foreach ([[$from, 'b'], [$to, 'a']] as [$runtime, $partner]) {
        Transports::unbind($runtime, $partner);
        Transports::profileAdd($runtime, 'open', true);
        Transports::bind($runtime, $partner, 'lan', 'open');
    }
    $from->scopeNew('review');
    $scopeKey = $from->scopeKey('review')['key'];
    $tTry(static fn () => $from->scopeShare('review', 'b', 'read'));
    $check($tThrown !== null && Runtime::errorCode($tThrown) === 'scope_needs_sealed' && str_contains($tThrown->getMessage(), 'readable') && $from->transportService()->outbox() === []
        && $from->outboxPending() === [], 'a scope is not shared under a readable profile: nothing goes, over the transport or over HTTPS', (string) $tThrown?->getMessage());
    $tTakeIn($from, $to, [['text' => 'by hand', 'data' => ['aamio_scope' => ['name' => 'review', 'key' => $scopeKey]]]], 'readable');
    $got = $tTry(static fn () => $to->read(0)) ?? [];
    $view = $got[0]['body']['data']['aamio_scope'] ?? [];
    $check(count($got) === 1 && ($got[0]['encrypted'] ?? null) === false && ($view['kept'] ?? null) === false && str_contains((string) ($view['note'] ?? ''), 'sealed')
        && !str_contains((string) json_encode($got), $scopeKey) && $to->scopeList() === [] && !str_contains($tArchive($to), $scopeKey),
        'a scope that comes readable is not kept, and its key is taken out before the message is shown or archived', json_encode($view));
    $from->close();
    $to->close();

    // Finding 6: three messages of 600 characters came whole on a budget of 512, all three were taken, and nothing said
    // so. The local server and the command line read the same way: the first read here goes through aamio_read, the
    // others through read as the command line calls it.
    $bodies = [];
    for ($n = 0; $n < 3; $n++) {
        $bodies[] = ['text' => $n . ' ' . str_repeat('x', 600)];
    }
    [$from, $to] = $tHomes('budget', false);
    $tTakeIn($from, $to, $bodies);
    $answer = (new McpServer($to))->dispatch('aamio_read', ['wait' => 0, 'max_bytes' => 512])['structuredContent'] ?? [];
    $notes = [];
    foreach ($answer['attention'] ?? [] as $noted) {
        $notes[$noted['state']] = $noted;
    }
    $check($tStarts($answer['messages'] ?? []) === ['0 '] && ($notes['over_budget']['channel'] ?? null) === 'lan' && str_contains((string) ($notes['over_budget']['what'] ?? ''), '512')
        && str_contains((string) ($notes['more_carried_bytes']['what'] ?? ''), 'lan') && str_contains((string) ($notes['more_carried_bytes']['what'] ?? ''), 'nothing was dropped'),
        'a message larger than the whole byte budget comes on its own, as one already here does, and that and the stop at the budget are both said', json_encode([$tStarts($answer['messages'] ?? []), array_keys($notes)]));
    $check(count($to->transportService()->carry()->pending()) === 2, 'only what was handed over is taken: the rest waits in the carrier state');
    $rest = $tTry(static fn () => $to->read(0, 50, 4096)) ?? [];
    $states = array_column($to->attentionTaken(), 'state');
    $check($tStarts($rest) === ['1 ', '2 '] && !in_array('more_carried_bytes', $states, true) && $to->transportService()->carry()->pending() === [] && $tTry(static fn () => $to->read(0, 50, 512)) === [],
        'the next read hands over whole messages within its budget, in the order they came, and then nothing is left', json_encode([$tStarts($rest), $states]));
    $from->close();
    $to->close();
    [$from, $to] = $tHomes('budget-for-two', false);
    $tTakeIn($from, $to, $bodies);
    $weight = strlen(Codec::json($to->transportService()->entries()[0]));
    $got = $tTry(static fn () => $to->read(0, 50, 2 * $weight + 100)) ?? [];
    $states = array_column($to->attentionTaken(), 'state');
    $check($tStarts($got) === ['0 ', '1 '] && in_array('more_carried_bytes', $states, true) && !in_array('over_budget', $states, true) && $tStarts($tTry(static fn () => $to->read(0)) ?? []) === ['2 '],
        'a budget two messages fit under hands over the two and leaves the third for the next read', json_encode([$tStarts($got), $states]));
    $from->close();
    $to->close();

    // Finding 7: read asked whether a partner was bound before it looked at what had come, so after the last unbind a
    // message that waited was not read, while unbind said it still would be.
    foreach (['on the command line' => false, 'by the local server' => true] as $how => $overMcp) {
        [$from, $to] = $tHomes($overMcp ? 'unbound-server' : 'unbound-command', false);
        $tTakeIn($from, $to, [['text' => 'before the unbind']]);
        $unbound = Transports::unbind($to, 'a');
        $got = $overMcp ? ((new McpServer($to))->dispatch('aamio_read', ['wait' => 0])['structuredContent']['messages'] ?? []) : ($tTry(static fn () => $to->read(0)) ?? []);
        $check($unbound['routes'] === [] && str_contains($unbound['note'], 'is still read') && $tTexts($got) === ['before the unbind'] && ($got[0]['channel'] ?? null) === Transports::UNBOUND_CHANNEL
            && ($got[0]['sender'] ?? null) === 'a' && array_key_exists('profile', $got[0]) && $got[0]['profile'] === null && $to->transportService()->carry()->pending() === []
            && $to->transportService()->status() === [], 'what came before the last unbind is still read ' . $how . ', and no transport is started to read it', json_encode([$tTexts($got), $tThrown?->getMessage()]));
        $from->close();
        $to->close();
    }

    // Finding 7 keeps the check against the address book: what waits from a key no longer in it is not handed over. And
    // finding 1 holds for it too: the key of a scope in it comes out before it goes to the archive, and nothing is kept.
    [$from, $to] = $tHomes('left-the-book');
    $from->scopeNew('review');
    $scopeKey = $from->scopeKey('review')['key'];
    $tTakeIn($from, $to, [['text' => 'from one who left', 'data' => ['aamio_scope' => ['name' => 'review', 'key' => $scopeKey]]]]);
    $to->partnerRemove('a');
    $got = $tTry(static fn () => $to->read(0));
    $states = array_column($to->attentionTaken(), 'state');
    $check($to->transportService()->config->bindings() === [] && $got === [] && in_array('removed_key', $states, true) && $to->transportService()->carry()->pending() === [],
        'a message from a key that left the address book is still withheld after the last unbind: said, and taken out of the carrier state', json_encode([$got, $states, $tThrown?->getMessage()]));
    $check(str_contains($tArchive($to), 'from one who left') && !str_contains($tArchive($to), $scopeKey) && $to->scopeList() === [], 'the archive has it without the key of the scope in it, and nothing is kept from it');
    $from->close();
    $to->close();

    // Finding 4, in a read: a carrier state closed after a write that failed answers nothing from memory. The read says
    // so and goes on with what it can still reach, rather than hand over from memory or fail as a whole.
    [$from, $to] = $tHomes('closed-state', false);
    $tTakeIn($from, $to, [['text' => 'taken in before the disk failed']]);
    $inTheWay = $to->transportService()->config->statePath() . '.tmp';
    mkdir($inTheWay);
    $first = $tTry(static fn () => $to->read(0)) ?? [];
    $firstStates = array_column($to->attentionTaken(), 'state');
    $second = $tTry(static fn () => $to->read(0));
    $notes = [];
    foreach ($to->attentionTaken() as $noted) {
        $notes[$noted['channel'] . ' ' . $noted['state']] = $noted['what'];
    }
    rmdir($inTheWay);
    $closedSaid = str_contains((string) ($notes['transports carry_unreadable'] ?? ''), 'carry_storage_failed') && isset($notes['inbox unreachable']);
    $detail = json_encode([$tTexts($first), $firstStates, $second, $notes, $tThrown?->getMessage()]);
    // What could not be marked as taken comes again after a restart, with the same mark: the file never said it was taken.
    $to->close();
    $to = $tOpen[] = new Runtime($to->home, $nowhere, null, false, $quietly);
    $again = $tTry(static fn () => $to->read(0)) ?? [];
    $check($tTexts($first) === ['taken in before the disk failed'] && in_array('not_taken', $firstStates, true) && $second === [] && $closedSaid
        && $tTexts($again) === ['taken in before the disk failed'] && ($again[0]['mark'] ?? null) === ($first[0]['mark'] ?? ''),
        'after a write of the carrier state failed, a read hands nothing over from memory: it says the state is closed and goes on with the rest, and a restart reads the file again', (string) $detail);
    $from->close();
    $to->close();

    // The same while a read waits with a transport running. Nothing pumps behind a read here, so what a carrier cannot do
    // with the state closed comes to the read that pumps it, which says it and waits its wait out rather than fail; and
    // a round as serve pumps them between its lines says it in the log and goes on.
    [$portFrom, $portTo] = $tFreePorts(2);
    [$from, $to] = $tHomes('closed-while-waiting', false);
    $toKeys = $to->keys;
    $to->close();
    $sealed = Transports::checked(Transports::profileRecord(Transports::DEFAULT_PROFILE));
    $state = $from->transportService()->carry();
    $other = new CarryState($toKeys);
    $now = time();
    [$session, $ask] = $state->ask($toKeys->publicRaw(), $sealed, 0.0);
    [, $challenge] = $other->deliver($ask, [$from->keys->publicRaw()], $sealed, 0.0, $now);
    $state->deliver($challenge, [$toKeys->publicRaw()], $sealed, 0.0, $now);
    $state->send($toKeys->publicRaw(), $session, 'waits for its receipt', $sealed);
    $from->transportService()->environ = array_merge(getenv(), $lanVariables($portFrom, $portTo));
    $inTheWay = $from->transportService()->config->statePath() . '.tmp';
    mkdir($inTheWay);
    $began = hrtime(true) / 1e9;
    $got = $tTry(static fn () => $from->read(2));
    $waited = hrtime(true) / 1e9 - $began;
    $readThrew = $tThrown;
    $states = array_map(static fn (array $noted): string => $noted['channel'] . ' ' . $noted['state'], $from->attentionTaken());
    $tTry(static function () use ($from): void {
        $from->pumpTransports(0.05);
    });
    rmdir($inTheWay);
    $check($got === [] && $readThrew === null && $waited >= 1.5 && in_array('transports carry_unreadable', $states, true) && $tThrown === null,
        'a read that waits with a transport running says the state is closed and waits its wait out, and a round between two calls goes on',
        json_encode([$got, $readThrew?->getMessage(), $waited, $states, $tThrown?->getMessage()]));
    // The owner closes while a frame still waits for the transport, with the state closed: the drain pumps then, and
    // what the carrier cannot do comes to close. It says it in the log, stops the transports and lets go of the home
    // all the same; thrown out of close, the home stayed taken in this process.
    $carrier = $from->transportService()->running['lan']['carrier'] ?? null;
    $closeThrew = $carrier === null ? 'no carrier ran' : null;
    if ($carrier !== null) {
        (new ReflectionProperty($carrier, 'queue'))->setValue($carrier, [[['x'], null, null, [], true]]);
    }
    try {
        $from->close();
    } catch (\Throwable $error) {
        $closeThrew = get_class($error) . ': ' . $error->getMessage();
    }
    $again = null;
    try {
        $again = $tOpen[] = new Runtime($from->home, $nowhere, null, false, $quietly);
    } catch (\Throwable $error) {
        $closeThrew = ($closeThrew ?? 'close did not throw') . ', and the home could not be taken again: ' . $error->getMessage();
    }
    $check($closeThrew === null && $again !== null,
        'the owner closes with a frame still waiting and the state closed: the transports stop and the home is let go of all the same', (string) $closeThrew);
    $again?->close();

    // Finding 8: after the only partner was removed the transport ran on, held its port and would have been started again
    // after a crash. PHP cannot tell on its own whether a UDP port is held, so the process is asked for instead.
    [$portFrom, $portTo] = $tFreePorts(2);
    [$from, $to] = $tHomes('nobody-bound');
    $fromKey = $from->keys->public;
    $from->close();
    $service = $to->transportService();
    $service->environ = array_merge(getenv(), $lanVariables($portTo, $portFrom));
    $serving = $to->startTransports();
    /** Pumped as serve pumps between its lines, until $condition holds: whether it did within $seconds. */
    $tPumped = static function (callable $condition, float $seconds = 15.0) use ($to): bool {
        $until = hrtime(true) / 1e9 + $seconds;
        while (!$condition()) {
            if (hrtime(true) / 1e9 >= $until) {
                return false;
            }
            $to->pumpTransports(0.05);
        }

        return true;
    };
    $up = $tPumped(static fn (): bool => ($service->status()['lan'] ?? null) === 'up');
    $ran = $service->running['lan']['process'] ?? null;
    $handle = new ReflectionProperty(TransportProcess::class, 'process');
    $alive = $ran !== null && is_resource($handle->getValue($ran)) && (proc_get_status($handle->getValue($ran))['running'] ?? false);
    $to->partnerRemove('a');
    $out = $tPumped(static fn (): bool => !isset($service->status()['lan']));
    $check($serving && $up && $alive && $out && $ran !== null && $handle->getValue($ran) === null, 'a transport no partner is bound to any more is taken out of what runs, and its process is stopped',
        json_encode([$serving, $up, $alive, $out, $service->status()]));
    $to->partnerAdd('a', $fromKey);
    Transports::bind($to, 'a', 'lan');
    $again = $tPumped(static fn (): bool => ($service->status()['lan'] ?? null) === 'up');
    // The other home sends from a process of its own, while this one pumps as serve does.
    [$sender, $senderPipes, $senderErr] = $tStart(['--home', $from->home, '--host', $nowhere, 'send', 'b', 'after the new binding'], $tEnv($portFrom, $portTo));
    $tPumped(static fn (): bool => !(proc_get_status($sender)['running'] ?? false), 45.0);
    $sent = json_decode((string) stream_get_contents($senderPipes[1]), true);
    fclose($senderPipes[1]);
    proc_close($sender);
    $got = $tTry(static fn () => $to->read(0)) ?? [];
    $check($again && ($sent['status'] ?? null) === 'confirmed' && $tTexts($got) === ['after the new binding'], 'and a transport bound again is started, and carries a message',
        json_encode([$again, $sent, $tTexts($got), substr((string) @file_get_contents($senderErr), -400)]));
    @unlink($senderErr);
    $to->close();

    // Finding 2, through the runtime: the owner takes the outbox up, gives the readable message up, and says so in attention.
    [$portFrom, $portTo] = $tFreePorts(2);
    [$from, $to] = $tHomes('another-mode');
    $toKeys = $to->keys;
    $to->close();
    $open = Transports::checked(Transports::profileRecord('open', 'readable'));
    $state = $from->transportService()->carry();
    $other = new CarryState($toKeys);
    $now = time();
    [$session, $ask] = $state->ask($toKeys->publicRaw(), $open, 0.0);
    [, $challenge] = $other->deliver($ask, [$from->keys->publicRaw()], $open, 0.0, $now);
    $state->deliver($challenge, [$toKeys->publicRaw()], $open, 0.0, $now);
    $state->send($toKeys->publicRaw(), $session, 'readable', $open);
    $mark = CarryState::mark($toKeys->publicRaw(), $session, 1);
    $service = $from->transportService();
    $service->environ = array_merge(getenv(), $lanVariables($portFrom, $portTo));
    $service->start();
    $until = hrtime(true) / 1e9 + 15;
    while (($service->status()['lan'] ?? null) !== 'up' && hrtime(true) / 1e9 < $until) {
        $service->pump();
    }
    for ($i = 0; $i < 5; $i++) {
        $service->pump();
    }
    $notes = array_values(array_filter($from->attentionTaken(), static fn (array $noted): bool => $noted['state'] === 'not_sent_again'));
    $check(($service->status()['lan'] ?? null) === 'up' && $service->outbox() === [['mark' => $mark, 'outcome' => 'unknown']] && count($notes) === 1 && $notes[0]['channel'] === 'lan'
        && str_contains($notes[0]['what'], $mark) && str_contains($notes[0]['what'], 'sealed'), 'a message written readable is given up and said in attention when the partner is bound sealed',
        json_encode([$service->status(), $service->outbox(), $notes]));
    $from->close();

    // seen never named a message that came over a transport: what was read over one went past the trace, where seen is
    // taken from, and a send over one was not recorded there either. In all three runtimes (the exchange over one local
    // network of 16 November 2026). b reads in a process of its own while a sends, and then the other way round.
    echo "transports: seen and the trace over a transport\n";
    [$portFrom, $portTo] = $tFreePorts(2);
    [$from, $to] = $tHomes('seen');
    $to->close();
    $from->transportService()->environ = array_merge(getenv(), $lanVariables($portFrom, $portTo));
    [$reader, $readerPipes, $readerErr] = $tStart(['--home', $to->home, '--host', $nowhere, 'read', '--wait', '25'], $tEnv($portTo, $portFrom));
    $readerUp = $tUp($readerErr);
    $snFirst = $readerUp ? $tTry(static fn () => $from->send('b', 'første over nettet')) : null;
    $readOut = (string) stream_get_contents($readerPipes[1]);
    fclose($readerPipes[1]);
    proc_close($reader);
    $snGot = json_decode($readOut, true)['messages'] ?? [];
    $snSha = (string) ($snFirst['sha256'] ?? '');
    $check(($snFirst['status'] ?? null) === 'confirmed' && Runtime::isMessageHash($snSha) && count($snGot) === 1 && ($snGot[0]['sha256'] ?? null) === $snSha
        && !array_key_exists('seen', $snGot[0]['body'] ?? []), 'both sides name a message over a transport by the sha256 of its envelope',
        json_encode([$snFirst, $tThrown?->getMessage(), substr($readOut, 0, 600), substr((string) @file_get_contents($readerErr), -400)]));
    @unlink($readerErr);
    $from->close();

    $to = $tOpen[] = new Runtime($to->home, $nowhere, null, true, $quietly);
    $to->transportService()->environ = array_merge(getenv(), $lanVariables($portTo, $portFrom));
    [$reader, $readerPipes, $readerErr] = $tStart(['--home', $from->home, '--host', $nowhere, 'read', '--wait', '25'], $tEnv($portFrom, $portTo));
    $readerUp = $tUp($readerErr);
    $snBack = $readerUp ? $tTry(static fn () => $to->send('a', 'svar over nettet', null, null, $snSha)) : null;
    $readOut = (string) stream_get_contents($readerPipes[1]);
    fclose($readerPipes[1]);
    proc_close($reader);
    $snAnswers = json_decode($readOut, true)['messages'] ?? [];
    $check(($snBack['status'] ?? null) === 'confirmed' && Runtime::isMessageHash($snBack['sha256'] ?? null) && count($snAnswers) === 1
        && ($snAnswers[0]['body']['seen'] ?? null) === $snSha && ($snAnswers[0]['body']['re'] ?? null) === $snSha && ($snAnswers[0]['sha256'] ?? null) === ($snBack['sha256'] ?? ''),
        'b read it, and its next message says so in seen', json_encode([$snBack, $tThrown?->getMessage(), substr($readOut, 0, 600), substr((string) @file_get_contents($readerErr), -400)]));
    @unlink($readerErr);
    $snTheirs = $to->trace('a');
    $to->close();

    $from = $tOpen[] = new Runtime($from->home, $nowhere, null, true, $quietly);
    $snMine = $from->trace('b');
    $snRow = $snMine['sent'][0] ?? [];
    $check(count($snMine['sent'] ?? []) === 1 && ($snRow['message_id'] ?? null) === ($snFirst['mark'] ?? '') && ($snRow['via'] ?? null) === 'lan' && ($snRow['sha256'] ?? null) === $snSha
        && ($snRow['outcome'] ?? null) === 'confirmed' && ($snRow['seen_by_them'] ?? null) === true && ($snRow['answered_by_them'] ?? null) === true && ($snRow['sealed'] ?? null) === true
        && ($snRow['fields'] ?? null) === ['from', 'text'] && !array_key_exists('status', $snRow) && !array_key_exists('w', $snRow)
        && ($snMine['received'][0]['channel'] ?? null) === 'lan' && ($snMine['received'][0]['seen'] ?? null) === $snSha && ($snMine['received'][0]['acknowledges']['sha256'] ?? null) === $snSha
        && ($snMine['no_read_claim'] ?? null) === [] && str_starts_with((string) ($snMine['note'] ?? ''), '1 delivered, and their runtime says it read and opened 1 of them.')
        && ($snMine['last_read']['sha256'] ?? null) === ($snBack['sha256'] ?? ''),
        'the sender sees in its trace that the message it sent over the transport was read, read back from the file', json_encode($snMine));
    $snNote = (string) ($snTheirs['note'] ?? '');
    $check(array_map(static fn (array $row): array => [$row['channel'] ?? null, $row['sha256'] ?? null], $snTheirs['received'] ?? []) === [['lan', $snSha]]
        && array_map(static fn (array $row): array => [$row['via'] ?? null, $row['seen_by_them'] ?? null], $snTheirs['sent'] ?? []) === [['lan', null]]
        && str_contains($snNote, 'unknown, not unread') && str_contains($snNote, 'the envelope a transport carried') && !str_contains($snNote, 'is what the service stored'),
        'and the one who read it has it in its trace, with its own send not yet named', json_encode($snTheirs));
    $from->close();
} finally {
    foreach ($tOpen ?? [] as $runtime) {
        $runtime->close();
    }
    Http::$override = $savedOverride;
    $savedAddons === false ? putenv('AAMIO_ADDONS') : putenv('AAMIO_ADDONS=' . $savedAddons);
    $savedLocal === false ? putenv('LOCALAPPDATA') : putenv('LOCALAPPDATA=' . $savedLocal);
    $savedState === false ? putenv('XDG_STATE_HOME') : putenv('XDG_STATE_HOME=' . $savedState);
    Addons::useInstalled(null);
    Addons::useHome(null);
    Addons::reset();
}
