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
 * waits, or serves, and the other sends.
 */

use Aamio\Addons;
use Aamio\Http;
use Aamio\Runtime;
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
        foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $name) {
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
} finally {
    Http::$override = $savedOverride;
    $savedAddons === false ? putenv('AAMIO_ADDONS') : putenv('AAMIO_ADDONS=' . $savedAddons);
    $savedLocal === false ? putenv('LOCALAPPDATA') : putenv('LOCALAPPDATA=' . $savedLocal);
    $savedState === false ? putenv('XDG_STATE_HOME') : putenv('XDG_STATE_HOME=' . $savedState);
    Addons::useInstalled(null);
    Addons::useHome(null);
    Addons::reset();
}
