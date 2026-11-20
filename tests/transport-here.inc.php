<?php

declare(strict_types=1);

/*
 * Where things are reached: where a transport is reached (contract 3, B3: here), where a partner is reached (bind
 * --address, and what a fresh envelope shows), and what a send that found nobody says. The same checks as aamio-python's
 * tests/test_transport_here.py, over the local network on 127.0.0.1 where Python also has Bluetooth simulated there:
 * this runtime has no Bluetooth transport. Each lan transport has a UDP port of its own and broadcast off, so nothing
 * leaves the machine, and the service is at http://127.0.0.1:9, where nobody answers.
 *
 * Found in the field test over real Bluetooth of 19 November 2026: the address had to be typed by hand, a machine had
 * another address than the day before, nothing kept where the partner had been heard from, and the send said "the same
 * network" and nothing of what the transport knew.
 *
 * Included by tests/runtime.php, with $root, $check and $cliRun in scope.
 */

use Aamio\Addons;
use Aamio\Carrier;
use Aamio\Carry;
use Aamio\CarryState;
use Aamio\Http;
use Aamio\Keys;
use Aamio\Runtime;
use Aamio\TransportConfig;
use Aamio\TransportProcess;
use Aamio\Transports;

echo "transports: where things are reached\n";

(static function () use ($root, $check, $cliRun): void {
    $lanDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . 'lan';
    $lanScript = $lanDir . DIRECTORY_SEPARATOR . 'transport.php';
    $lanVersion = (string) (json_decode((string) file_get_contents($lanDir . DIRECTORY_SEPARATOR . 'aamio-addon.json'), true)['version'] ?? '');
    $nowhere = 'http://127.0.0.1:9';
    $hRoot = $root . DIRECTORY_SEPARATOR . 'transport-here';
    mkdir($hRoot, 0700, true);
    $quietly = static function (string $line): void {
    };
    $freePorts = static function (int $count): array {
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
    $base = [];
    foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP'] as $name) {
        if (getenv($name) !== false) {
            $base[$name] = (string) getenv($name);
        }
    }
    /** The lan transport's variables: a port of its own on 127.0.0.1, broadcast off, and a peer when one is named. */
    $lanEnv = static fn (int $port, ?int $peer = null): array => ['AAMIO_LAN_PORT' => (string) $port, 'AAMIO_LAN_BIND' => '127.0.0.1', 'AAMIO_LAN_BROADCAST' => 'none']
        + ($peer === null ? [] : ['AAMIO_LAN_PEERS' => '127.0.0.1:' . $peer]);
    /** This process's environment with the lan transport's variables as given and no others, for a service's environ. */
    $withLan = static fn (array $variables): array => array_diff_key(getenv(), array_flip(['AAMIO_LAN_PORT', 'AAMIO_LAN_BIND', 'AAMIO_LAN_BROADCAST', 'AAMIO_LAN_PEERS'])) + $variables;
    $lan = static function (int $port, ?int $peer = null) use ($base, $lanEnv, $lanScript, $lanVersion): TransportProcess {
        $env = TransportProcess::environment(['AAMIO_LAN_PORT', 'AAMIO_LAN_BIND', 'AAMIO_LAN_BROADCAST', 'AAMIO_LAN_PEERS'], $base + $lanEnv($port, $peer));

        return new TransportProcess('lan', $lanVersion, [PHP_BINARY, $lanScript], sys_get_temp_dir(), $env);
    };
    /** Started with its medium open: the events until a ready gave room, and what start kept for the first poll. */
    $started = static function (TransportProcess $process, float $seconds = 15.0): array {
        $process->start('0.0.0-test', 'php', sys_get_temp_dir());
        $events = [];
        $until = hrtime(true) / 1e9 + $seconds;
        while ($process->room() <= 0 && hrtime(true) / 1e9 < $until) {
            foreach ($process->poll(0.05) as $event) {
                $events[] = $event;
            }
        }
        foreach ($process->poll() as $event) {
            $events[] = $event;
        }

        return $events;
    };
    $pumpUntil = static function (array $carriers, callable $condition, float $seconds = 30.0): bool {
        $until = hrtime(true) / 1e9 + $seconds;
        while (!$condition()) {
            if (hrtime(true) / 1e9 >= $until) {
                return false;
            }
            foreach ($carriers as $carrier) {
                $carrier->pump(0.01);
            }
        }

        return true;
    };
    $thrown = null;
    $try = static function (callable $call) use (&$thrown): mixed {
        $thrown = null;
        try {
            return $call();
        } catch (\Throwable $error) {
            $thrown = $error;

            return null;
        }
    };
    $code = static function (callable $call): ?string {
        try {
            $call();
        } catch (\Throwable $error) {
            return Runtime::errorCode($error);
        }

        return null;
    };
    $texts = static fn (array $messages): array => array_map(static fn (array $m): mixed => $m['body']['text'] ?? null, $messages);
    $transportItem = static function (array $listed, string $name): array {
        foreach ($listed['transports'] ?? [] as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }

        return [];
    };

    // ------------------------------------------------------------- here, in the host

    $check(TransportProcess::hereOf(['bt:8C:1D:55:03:7B:53'], 'bt') === ['bt:8C:1D:55:03:7B:53']
        && TransportProcess::hereOf(['lan:10.0.0.7:47474', 'lan:10.0.0.8:47474'], 'lan') === ['lan:10.0.0.7:47474', 'lan:10.0.0.8:47474']
        && TransportProcess::hereOf([], 'bt') === [], 'here is a short list of the transport\'s own addresses');
    $bad = [['lan:10.0.0.7:47474'], ['bt:'], ['bt:a b'], ['bt:x', 'bt:x'], array_map(static fn (int $n): string => 'bt:' . $n, range(0, 4)), 'bt:x', [7], ['bt:' . str_repeat('x', 126)], null, ['one' => 'bt:x']];
    $taken = array_values(array_filter($bad, static fn (mixed $value): bool => TransportProcess::hereOf($value, 'bt') !== null));
    $check($taken === [], 'and nothing else is taken for one: another medium, no rest, a space, the same twice, five, no list, too long', json_encode($taken));

    $logged = [];
    $byHand = new TransportProcess('bt', '0.1.0', [], null, [], null, static function (string $line) use (&$logged): void {
        $logged[] = $line;
    });
    $byHand->medium = 'bt';
    $ready = static function (array $more) use ($byHand): array {
        $byHand->injected[] = (string) json_encode(['t' => 'ready', 'credit' => 1, 'sends_read' => 0] + $more);

        return $byHand->poll();
    };
    $first = $ready(['here' => ['bt:8C:1D:55:03:7B:53']]);
    $again = $ready(['here' => ['bt:8C:1D:55:03:7B:53']]);
    $other = $ready(['here' => ['lan:10.0.0.7:47474']]);
    $check($first === [['here', ['bt:8C:1D:55:03:7B:53']]] && $again === [] && $other === [] && str_contains((string) end($logged), 'here is not taken')
        && $byHand->here === ['bt:8C:1D:55:03:7B:53'] && $byHand->room() === 1 && $byHand->failed === null && $byHand->readied,
        'a ready with here is said once, the same again is nothing new, and a here that cannot be taken is said and stops nothing', json_encode([$first, $again, $other, $logged]));
    $check($ready([]) === [] && $byHand->here === ['bt:8C:1D:55:03:7B:53'] && $ready(['here' => ['bt:DC:FB:48:D5:EA:C0']]) === [['here', ['bt:DC:FB:48:D5:EA:C0']]],
        'a ready without here leaves it as it was, and another day brings another address');

    $norwegian = 'et tilkoblingsforsøk';
    $cp1252 = "et tilkoblingsfors\xf8k";
    $read = TransportProcess::textOf($cp1252);
    $ansi = PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_get') ? sapi_windows_cp_get('ansi') : null;
    $check(TransportProcess::textOf($norwegian) === $norwegian && preg_match('//u', $read) === 1 && str_starts_with($read, 'et tilkoblingsfors') && ($ansi !== 1252 || $read === $norwegian),
        'standard error that is not UTF-8 is read in the system\'s code page, as a Norwegian Windows wrote it, and is UTF-8 after', $read);

    [$port] = $freePorts(1);
    $process = $lan($port);
    try {
        $events = $started($process);
        $check($process->here === ['lan:127.0.0.1:' . $port] && in_array(['here', ['lan:127.0.0.1:' . $port]], $events, true),
            'the local network says the address it listens on, on its ready', json_encode([$process->here, $events]));
    } finally {
        $process->stop();
    }

    // ------------------------------------------------------------- what moves a partner's address

    $keysA = Keys::fromSeedHex(str_repeat('21', 32));
    $keysB = Keys::fromSeedHex(str_repeat('22', 32));
    $profile = CarryState::profile('sealed');

    // An ask can be played again from anywhere: had it moved the address, every message would go to the machine that
    // played it. A's ask is played to B from a third machine, a socket of this process on a port of its own.
    [$portB, $portC] = $freePorts(2);
    [, $ask] = (new CarryState($keysA))->ask($keysB->publicRaw(), $profile, 0.0);
    $radioB = $lan($portB);
    $third = null;
    try {
        $started($radioB);
        $heardB = [];
        $atB = new Carrier(new CarryState($keysB, $hRoot . DIRECTORY_SEPARATOR . 'replay-b.json'), $radioB, $profile, [$keysA->publicRaw()],
            heard: static function (string $pk, string $address) use (&$heardB): void {
                $heardB[] = [$pk, $address];
            });
        $third = stream_socket_server('udp://127.0.0.1:' . $portC, $errno, $errstr, STREAM_SERVER_BIND);
        stream_set_blocking($third, false);
        stream_socket_sendto($third, 'AL1' . random_bytes(8) . Carry::split($ask, 1200)[0], 0, '127.0.0.1:' . $portB);
        $answer = '';
        $until = hrtime(true) / 1e9 + 10;
        while ($answer === '' && hrtime(true) / 1e9 < $until) {
            $atB->pump(0.01);
            $answer = (string) @stream_socket_recvfrom($third, 65535);
        }
        $check(str_starts_with($answer, 'AL1') && substr($answer, 11, 2) === Carry::FRAME_MAGIC, 'an ask played again from another machine is taken and answered with a challenge, to where it came from');
        $check(!isset($atB->addresses[bin2hex($keysA->publicRaw())]) && $heardB === [], 'and it moves no address', json_encode([$atB->addresses, count($heardB)]));
    } finally {
        if (is_resource($third)) {
            fclose($third);
        }
        $radioB->stop();
    }

    [$portA, $portB] = $freePorts(2);
    $radios = [$lan($portA, $portB), $lan($portB, $portA)];
    try {
        $started($radios[0]);
        $started($radios[1]);
        $heardA = [];
        $heardB = [];
        $logged = [];
        $atA = new Carrier(new CarryState($keysA, $hRoot . DIRECTORY_SEPARATOR . 'fresh-a.json'), $radios[0], $profile, [$keysB->publicRaw()], retrySeconds: 1.0, askRetrySeconds: 3.0,
            heard: static function (string $pk, string $address) use (&$heardA): void {
                $heardA[] = [$pk, $address];
            });
        $atB = new Carrier(new CarryState($keysB, $hRoot . DIRECTORY_SEPARATOR . 'fresh-b.json'), $radios[1], $profile, [$keysA->publicRaw()], retrySeconds: 1.0, askRetrySeconds: 3.0,
            heard: static function (string $pk, string $address) use (&$heardB): void {
                $heardB[] = [$pk, $address];
            }, nameOf: static fn (string $pk): string => 'a', log: static function (string $line) use (&$logged): void {
                $logged[] = $line;
            });
        $atA->open($keysB->publicRaw());
        $sessionOpen = $pumpUntil([$atA, $atB], static fn (): bool => $atA->openSession($keysB->publicRaw()) !== null);
        $check($sessionOpen && $heardA === [[$keysB->publicRaw(), 'lan:127.0.0.1:' . $portB]], 'the challenge answers A\'s own ask: fresh, and where B was heard from is said');
        $sent = $sessionOpen ? $atA->send($keysB->publicRaw(), (string) $atA->openSession($keysB->publicRaw()), 'hei') : '';
        $there = $sessionOpen && $pumpUntil([$atA, $atB], static fn (): bool => $atA->confirmed($sent) && $atB->inbox() !== []);
        $check($there && $heardB === [[$keysA->publicRaw(), 'lan:127.0.0.1:' . $portA]], 'the open answers B\'s own challenge: fresh, and said once');
        $check(in_array('transport lan: a message came in from a', $logged, true), 'and a message that came in is said where the owner keeps its log', json_encode($logged));
    } finally {
        foreach ($radios as $radio) {
            $radio->stop();
        }
    }

    // ------------------------------------------------------------- through the core

    $savedOverride = Http::$override;
    $savedAddons = getenv('AAMIO_ADDONS');
    $savedLocal = getenv('LOCALAPPDATA');
    $savedState = getenv('XDG_STATE_HOME');
    Http::$override = static fn (): array => [0, ['error' => 'no answer: nobody is there', 'fix' => 'Nothing to do: this is the test.'], []];
    putenv('AAMIO_ADDONS=' . $lanDir);
    putenv('LOCALAPPDATA=' . $hRoot . DIRECTORY_SEPARATOR . 'state');
    putenv('XDG_STATE_HOME=' . $hRoot . DIRECTORY_SEPARATOR . 'state');
    Addons::reset();
    /** Every runtime opened below, so that each is closed whatever happens. */
    $opened = [];
    try {
        [, $loaded] = $cliRun([PHP_BINARY, '-r', 'echo strtolower(implode(",", get_loaded_extensions()));']);
        $child = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir')];
        foreach (['sodium', 'curl'] as $extension) {
            if (!in_array($extension, explode(',', trim($loaded)), true)) {
                $child = array_merge($child, ['-d', 'extension=' . $extension]);
            }
        }
        $childEnv = static function (array $variables) use ($lanDir, $hRoot): array {
            $env = [];
            foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'PHPRC', 'PHP_INI_SCAN_DIR'] as $name) {
                if (getenv($name) !== false) {
                    $env[$name] = (string) getenv($name);
                }
            }

            return $env + ['AAMIO_ADDONS' => $lanDir, 'LOCALAPPDATA' => $hRoot . DIRECTORY_SEPARATOR . 'state', 'XDG_STATE_HOME' => $hRoot . DIRECTORY_SEPARATOR . 'state'] + $variables;
        };
        $startChild = static function (array $words, array $env) use ($child): array {
            $errFile = (string) tempnam(sys_get_temp_dir(), 'aamio-here-');
            $process = proc_open(array_merge($child, [dirname(__DIR__) . '/bin/aamio'], $words), [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);

            return [$process, $pipes, $errFile];
        };
        /** What a child said: its exit code, its answer, its standard error. */
        $finish = static function (array $running): array {
            [$process, $pipes, $errFile] = $running;
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $err = (string) @file_get_contents($errFile);
            @unlink($errFile);

            return [$status, json_decode($out, true), $err];
        };
        $upIn = static function (array $running, float $seconds = 20.0): bool {
            $until = hrtime(true) / 1e9 + $seconds;
            while (hrtime(true) / 1e9 < $until) {
                if (str_contains((string) @file_get_contents($running[2]), 'transport lan: up')) {
                    return true;
                }
                usleep(100000);
            }

            return false;
        };
        /** Homes a and b in a folder of their own, each the other's partner, open in this process: [a, b]. */
        $homes = static function (string $folder) use ($hRoot, $nowhere, $quietly, &$opened): array {
            @mkdir($hRoot . DIRECTORY_SEPARATOR . $folder, 0700, true);
            $pair = [];
            foreach (['a', 'b'] as $name) {
                $pair[$name] = $opened[] = new Runtime($hRoot . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $name, $nowhere, null, true, $quietly);
            }
            $pair['a']->partnerAdd('b', $pair['b']->keys->public);
            $pair['b']->partnerAdd('a', $pair['a']->keys->public);

            return [$pair['a'], $pair['b']];
        };

        // A bound address needs no peers, and the answer goes where the partner was heard from.
        [$portA, $portB] = $freePorts(2);
        [$a, $b] = $homes('bound');
        $bound = Transports::bind($a, 'b', 'lan', null, 'lan:127.0.0.1:' . $portB);
        Transports::bind($b, 'a', 'lan');
        $check(($bound['address'] ?? null) === 'lan:127.0.0.1:' . $portB && str_contains($bound['note'] ?? '', 'The first ask goes to lan:127.0.0.1:' . $portB)
            && ($bound['routes'] ?? null) === [['transport' => 'lan', 'profile' => 'sealed']], 'bind with an address binds, keeps the address and says where the first ask goes', json_encode($bound));
        [$aKey, $bKey, $aHome, $bHome] = [$a->keys->public, $b->keys->public, $a->home, $b->home];
        $b->close();
        $reader = $startChild(['--home', $bHome, '--host', $nowhere, 'read', '--wait', '25'], $childEnv($lanEnv($portB)));
        $up = $upIn($reader);
        $a->transportService()->environ = $withLan($lanEnv($portA));
        $sent = $up ? $try(static fn () => $a->send('b', 'hei over lan')) : null;
        [, $read, $err] = $finish($reader);
        $check(($sent['status'] ?? null) === 'confirmed' && ($sent['via'] ?? null) === 'lan' && $texts($read['messages'] ?? []) === ['hei over lan'],
            'with no peers named, the first ask goes to the address bind was given, and the message comes through', json_encode([$up, $sent, $thrown?->getMessage(), $read, substr($err, -600)]));
        $kept = (new TransportConfig($bHome))->addresses()[$aKey]['lan'] ?? [];
        $check(($kept['address'] ?? null) === 'lan:127.0.0.1:' . $portA && ($kept['from'] ?? null) === 'heard' && is_int($kept['at'] ?? null),
            'the partner\'s home keeps where it heard from fresh, as heard', json_encode($kept));
        $listed = Transports::listing($a);
        $row = array_values(array_filter($listed['bindings'] ?? [], static fn (array $row): bool => ($row['partner'] ?? null) === 'b'))[0] ?? [];
        $check(($row['addresses']['lan']['address'] ?? null) === 'lan:127.0.0.1:' . $portB && ($row['addresses']['lan']['from'] ?? null) === 'you'
            && ($transportItem($listed, 'lan')['here'] ?? null) === ['lan:127.0.0.1:' . $portA],
            'list says where the partner is reached, as given, and where this machine\'s lan said it is reached', json_encode($listed));
        $a->close();
        // A new owner of b's home, with no peers named, answers a over the address b kept.
        $reader = $startChild(['--home', $aHome, '--host', $nowhere, 'read', '--wait', '25'], $childEnv($lanEnv($portA)));
        $up = $upIn($reader);
        $b = $opened[] = new Runtime($bHome, $nowhere, null, true, $quietly);
        $b->transportService()->environ = $withLan($lanEnv($portB));
        $back = $up ? $try(static fn () => $b->send('a', 'svar over lan')) : null;
        [, $read, $err] = $finish($reader);
        $check(($back['status'] ?? null) === 'confirmed' && $texts($read['messages'] ?? []) === ['svar over lan'],
            'a new owner of the home, with no peers named, answers over the address the home kept', json_encode([$up, $back, $thrown?->getMessage(), $read, substr($err, -600)]));
        $b->close();

        // An address that stopped answering gives way to the peers on every second ask.
        [$portA, $portB, $gone] = $freePorts(3);
        [$a, $b] = $homes('stale');
        Transports::bind($a, 'b', 'lan', null, 'lan:127.0.0.1:' . $gone);
        Transports::bind($b, 'a', 'lan');
        [$bKey, $bHome] = [$b->keys->public, $b->home];
        $b->close();
        $reader = $startChild(['--home', $bHome, '--host', $nowhere, 'read', '--wait', '25'], $childEnv($lanEnv($portB)));
        $up = $upIn($reader);
        $a->transportService()->environ = $withLan($lanEnv($portA, $portB));
        $sent = $up ? $try(static fn () => $a->send('b', 'hei')) : null;
        [, $read, $err] = $finish($reader);
        $kept = (new TransportConfig($a->home))->addresses()[$bKey]['lan'] ?? [];
        $check(($sent['status'] ?? null) === 'confirmed' && ($kept['address'] ?? null) === 'lan:127.0.0.1:' . $portB && ($kept['from'] ?? null) === 'heard',
            'an address that stopped answering gives way to the peers on the second ask, and the challenge from the partner moves it there', json_encode([$up, $sent, $thrown?->getMessage(), $kept, substr($err, -600)]));
        $a->close();

        // A send that found nobody says what the transport said, and nothing of a network.
        [$portA] = $freePorts(1);
        [$a, $b] = $homes('nobody');
        Transports::bind($a, 'b', 'lan');
        $bPk = $b->keys->publicRaw();
        $b->close();
        $a->transportService()->environ = $withLan($lanEnv($portA));
        $try(static fn () => $a->transportService()->send($bPk, 'hei', 4));
        $said = (string) $thrown?->getMessage();
        $check($thrown !== null && Runtime::errorCode($thrown) === 'carry_not_sent'
            && $said === 'the partner did not answer on lan within 4 seconds, so nothing was sent. Its aamio has to be within reach of lan and running, as aamio serve or aamio read --wait, '
                . 'with this key bound to lan. lan said of the last frame it could not send: no broadcast and no peers: a frame to nobody in particular has nowhere to go',
            'a send that found nobody says what the transport said of the last frame, and nothing of the same network', $said);
        $a->close();

        // transport here starts it for a moment, keeps where it is reached, and lets the port go.
        [$port] = $freePorts(1);
        @mkdir($hRoot . DIRECTORY_SEPARATOR . 'here', 0700, true);
        $a = $opened[] = new Runtime($hRoot . DIRECTORY_SEPARATOR . 'here' . DIRECTORY_SEPARATOR . 'a', $nowhere, null, true, $quietly);
        $a->transportService()->environ = $withLan($lanEnv($port));
        $item = $transportItem(Transports::listing($a), 'lan');
        $check(array_key_exists('here', $item) && $item['here'] === null && str_contains($item['here_fix'] ?? '', 'aamio transport here lan starts it for a moment'),
            'list says nothing is known yet of where lan is reached, and how to learn it', json_encode($item));
        $said = Transports::here($a, 'lan');
        $check(($said['here'] ?? null) === ['lan:127.0.0.1:' . $port] && ($said['medium'] ?? null) === 'lan' && str_contains($said['note'] ?? '', 'It was started for a moment to say this, and stopped again.')
            && str_contains($said['note'] ?? '', '--address lan:127.0.0.1:' . $port) && str_contains($said['note'] ?? '', '--via lan:127.0.0.1:' . $port),
            'transport here starts it for a moment and says where it is reached, for a card and for a binding', json_encode($said));
        $check(((new TransportConfig($a->home))->here()['lan']['here'] ?? null) === ['lan:127.0.0.1:' . $port] && ((new TransportConfig($a->home))->here()['lan']['version'] ?? null) === $lanVersion,
            'and keeps it in the home, with the version that said it');
        $mine = @stream_socket_server('udp://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND, stream_context_create(['socket' => ['so_reuseaddr' => false]]));
        $check($mine !== false, 'and lets the port go', (string) $errstr);
        if ($mine !== false) {
            fclose($mine);
        }
        $item = $transportItem(Transports::listing($a), 'lan');
        $check(($item['here'] ?? null) === ['lan:127.0.0.1:' . $port] && is_int($item['here_at'] ?? null) && !array_key_exists('here_fix', $item), 'list says it from then on, and since when', json_encode($item));
        $check($a->transportService()->hereForAddons() === [['transport' => 'lan', 'medium' => 'lan', 'here' => ['lan:127.0.0.1:' . $port], 'at' => $item['here_at'] ?? null]],
            'an add-on that needs here gets what the home keeps');
        $check($code(static fn () => Transports::here($a, 'radio')) === 'transport_unknown', 'a transport that is not enabled is said');
        $a->close();

        // The same on the command line.
        [$port] = $freePorts(1);
        [$a, $b] = $homes('command-line');
        $aHome = $a->home;
        $a->close();
        $b->close();
        [$status, $said, $err] = $finish($startChild(['--home', $aHome, '--host', $nowhere, 'transport', 'here', 'lan'], $childEnv($lanEnv($port))));
        $check($status === 0 && ($said['transport'] ?? null) === 'lan' && ($said['here'] ?? null) === ['lan:127.0.0.1:' . $port], 'aamio transport here lan says it on the command line', json_encode([$status, $said, substr($err, -400)]));
        [$status, $said, $err] = $finish($startChild(['--home', $aHome, '--host', $nowhere, 'transport', 'bind', 'b', 'lan', '--address', 'lan:127.0.0.1:' . $port], $childEnv([])));
        $check($status === 0 && ($said['address'] ?? null) === 'lan:127.0.0.1:' . $port && ($said['routes'] ?? null) === [['transport' => 'lan', 'profile' => 'sealed']],
            'aamio transport bind PARTNER TRANSPORT --address ADDR binds and keeps where the partner is reached', json_encode([$status, $said, substr($err, -400)]));
        [$status, $said] = $finish($startChild(['--home', $aHome, '--host', $nowhere, 'transport', 'bind', 'b', 'lan', '--address', 'bt:8C:1D:55:03:7B:53'], $childEnv([])));
        $check($status === 1 && ($said['error_code'] ?? null) === 'transport_address_invalid' && str_contains($said['error'] ?? '', 'an address on lan is lan:'),
            'and an address on another medium is the command\'s answer, with its code', json_encode([$status, $said]));
        [$status, , $err] = $finish($startChild(['--home', $aHome, '--host', $nowhere, 'transport', 'here'], $childEnv([])));
        $check($status === 2 && str_contains($err, 'transport here takes TRANSPORT'), 'and transport here without a transport is a usage error', $err);

        // An add-on that needs here gets it learned by a start of a moment when the home keeps nothing.
        [$port] = $freePorts(1);
        @mkdir($hRoot . DIRECTORY_SEPARATOR . 'learn', 0700, true);
        $a = $opened[] = new Runtime($hRoot . DIRECTORY_SEPARATOR . 'learn' . DIRECTORY_SEPARATOR . 'a', $nowhere, null, true, $quietly);
        $a->transportService()->environ = $withLan($lanEnv($port));
        $said = $a->transportService()->hereForAddons();
        $check(array_map(static fn (array $item): array => [$item['transport'], $item['medium'], $item['here']], $said) === [['lan', 'lan', ['lan:127.0.0.1:' . $port]]]
            && array_keys($said[0] ?? []) === ['transport', 'medium', 'here', 'at'], 'an add-on that needs here gets it learned by a start of a moment when nothing is kept', json_encode($said));
        $a->close();

        // bind with an address checks it, and sets it for a partner bound already.
        [$a, $b] = $homes('bind-address');
        $check($code(static fn () => Transports::bind($a, 'b', 'lan', null, 'bt:8C:1D:55:03:7B:53')) === 'transport_address_invalid' && $a->transportService()->config->bindings() === [],
            'an address on another medium is refused, and nothing is bound');
        Transports::bind($a, 'b', 'lan');
        $try(static fn () => Transports::bind($a, 'b', 'lan'));
        $check($thrown !== null && Runtime::errorCode($thrown) === 'transport_already_bound' && $thrown->getMessage() === 'lan is bound to that partner already. With --address, bind says where the partner is reached on it',
            'bound already, bind says what --address does', (string) $thrown?->getMessage());
        $moved = Transports::bind($a, 'b', 'lan', null, 'lan:10.0.0.7:47474');
        $check(($moved['address'] ?? null) === 'lan:10.0.0.7:47474' && count($moved['routes'] ?? []) === 1
            && ($moved['note'] ?? null) === 'The first ask to b on lan goes to lan:10.0.0.7:47474 from now on; when it stops answering there, every second ask goes to nobody in particular, '
                . 'and a fresh envelope from b moves the address to where it came from.', 'and with --address it sets where the partner is reached, for a partner bound already', json_encode($moved));
        $try(static fn () => Transports::bind($a, 'b', 'lan', 'slow', 'lan:10.0.0.7:47474'));
        $check($thrown !== null && Runtime::errorCode($thrown) === 'transport_already_bound' && str_contains($thrown->getMessage(), 'under the profile sealed: aamio transport unbind first'),
            'another profile is not taken that way', (string) $thrown?->getMessage());
        Transports::unbind($a, 'b', 'lan');
        $check($a->transportService()->config->addresses() === [], 'an unbind takes where the partner is reached with it');
        $a->close();
        $b->close();

        // A hint file that cannot be read stops nothing.
        [$a, $b] = $homes('hints');
        Transports::bind($a, 'b', 'lan');
        $folder = $a->home . DIRECTORY_SEPARATOR . 'transports';
        file_put_contents($folder . DIRECTORY_SEPARATOR . 'addresses.json', '{not json');
        file_put_contents($folder . DIRECTORY_SEPARATOR . 'here.json', '{"version": 9}');
        $config = new TransportConfig($a->home);
        $check($config->addresses() === [] && $config->here() === [] && (Transports::listing($a)['bindings'][0]['partner'] ?? null) === 'b', 'a hint file that cannot be read is read as nothing, and stops nothing');
        $config->setAddress($b->keys->public, 'lan', 'lan:10.0.0.7:47474', 'you', 1);
        $check(($config->addresses()[$b->keys->public]['lan']['address'] ?? null) === 'lan:10.0.0.7:47474', 'and is written again whole');
        $a->close();
        $b->close();

        // A medium without addresses, as sound, says nothing of where it is reached. Unkept, every card over MCP started
        // it again for a moment, and a transport over sound opens the microphone as it starts. Transports of this test's
        // own stand in: each counts its starts beside it, writes a line in cp1252 on standard error, and says on its ready
        // the here in says.json beside it, or nothing of it when there is none.
        $package = static function (string $folder, string $name, string $version, ?array $says = null): void {
            @mkdir($folder, 0700, true);
            file_put_contents($folder . DIRECTORY_SEPARATOR . 'aamio-addon.json', json_encode(['aamio_addon' => 3, 'name' => $name, 'version' => $version,
                'transport' => ['medium' => $name, 'start' => ['php' => 'transport.php'], 'platforms' => ['linux', 'darwin', 'win32'], 'permissions' => []]]));
            if ($says !== null) {
                file_put_contents($folder . DIRECTORY_SEPARATOR . 'says.json', json_encode($says));
            }
            file_put_contents($folder . DIRECTORY_SEPARATOR . 'transport.php', '<?php
$manifest = json_decode((string) file_get_contents(__DIR__ . "/aamio-addon.json"), true);
[$name, $version] = [$manifest["name"], $manifest["version"]];
$says = is_file(__DIR__ . "/says.json") ? json_decode((string) file_get_contents(__DIR__ . "/says.json"), true) : null;
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    $kind = is_array($message) ? ($message["t"] ?? null) : null;
    if ($kind === "hello") {
        if (($message["probe"] ?? false) !== true) {
            file_put_contents(__DIR__ . "/started", $version . "\n", FILE_APPEND);
            fwrite(STDERR, $name . ": et tilkoblingsfors\xf8k\n");
            usleep(300000);
        }
        fwrite(STDOUT, json_encode(["t" => "describe", "name" => $name, "version" => $version, "aamio_addon" => 3, "medium" => $name, "properties" => ["max_frame_up" => 100, "max_frame_down" => 100,
            "rate_up" => 1000, "rate_down" => 1000, "direction" => "duplex", "addressing" => "none", "link_ack" => false, "airtime" => null, "metadata_visible_to" => "range", "medium_encrypts" => false]]) . "\n");
        if (($message["probe"] ?? false) !== true) {
            $ready = ["t" => "ready", "credit" => 4, "sends_read" => 0];
            if (is_array($says)) {
                $ready["here"] = $says;
            }
            fwrite(STDOUT, json_encode($ready) . "\n");
        }
    } elseif ($kind === "stop") {
        break;
    }
}
');
        };
        $mute = $hRoot . DIRECTORY_SEPARATOR . 'mute';
        $manifest = static fn (string $version) => $package($mute, 'mute', $version);
        $manifest('0.1.0');
        putenv('AAMIO_ADDONS=' . $mute);
        Addons::reset();
        @mkdir($hRoot . DIRECTORY_SEPARATOR . 'said-nothing', 0700, true);
        $lines = [];
        $a = $opened[] = new Runtime($hRoot . DIRECTORY_SEPARATOR . 'said-nothing' . DIRECTORY_SEPARATOR . 'a', $nowhere, null, true, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $service = $a->transportService();
        $first = $service->hereForAddons();
        $kept = (new TransportConfig($a->home))->here()['mute'] ?? null;
        $second = $service->hereForAddons();
        $starts = (string) @file_get_contents($mute . DIRECTORY_SEPARATOR . 'started');
        $withHere = static fn (array $said): array => array_map(static fn (array $item): array => [$item['transport'], $item['medium'], $item['here']], $said);
        $check($withHere($first) === [['mute', 'mute', []]],
            'it says nothing of where it is reached, and is there all the same: a card knows the medium is this machine\'s', json_encode($first));
        $check($second === [['transport' => 'mute', 'medium' => 'mute', 'here' => [], 'at' => $kept['at'] ?? null]] && ($kept['here'] ?? null) === [] && ($kept['version'] ?? null) === '0.1.0' && $starts === "0.1.0\n"
            && ($transportItem(Transports::listing($a), 'mute')['here'] ?? null) === [],
            'a transport that says nothing of where it is reached is kept as saying nothing, and not started again for the next card', json_encode([$first, $second, $kept, $starts]));
        $manifest('0.2.0');
        Addons::reset();
        $third = $service->hereForAddons();
        $starts = (string) @file_get_contents($mute . DIRECTORY_SEPARATOR . 'started');
        $check($withHere($third) === [['mute', 'mute', []]] && $starts === "0.1.0\n0.2.0\n" && ((new TransportConfig($a->home))->here()['mute']['version'] ?? null) === '0.2.0',
            'and another version of it is started once more', json_encode([$third, $starts]));
        $said = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'transport mute: mute: et tilkoblingsfors')));
        $check($said !== [] && preg_match('//u', $said[0]) === 1 && ($ansi !== 1252 || $said[0] === 'transport mute: mute: ' . $norwegian),
            'what a transport writes on standard error in the system\'s code page is read as such', json_encode($said));
        $said = Transports::here($a, 'mute');
        $check(array_key_exists('here', $said) && $said['here'] === null && str_contains($said['note'] ?? '', 'mute says nothing of where it is reached')
            && str_contains($said['note'] ?? '', 'A card can name the medium mute without an address.'), 'transport here says it of one that says nothing of where it is reached', json_encode($said));
        // A process the service has for the transport is asked, and never a second one started beside it: the two would
        // fight over the medium. One that has not readied yet says nothing yet.
        $starts = (string) @file_get_contents($mute . DIRECTORY_SEPARATOR . 'started');
        $beside = new TransportProcess('mute', '0.2.0', [], null, []);
        $service->running['mute'] = ['manifest' => null, 'process' => $beside, 'carrier' => null, 'crashes' => [], 'next' => 0.0, 'down' => null];
        $notYet = $service->learnHere('mute');
        $beside->readied = true;
        $beside->here = [];
        $readied = $service->learnHere('mute');
        $service->running = [];
        $check($notYet === [null, 'starting'] && $readied === [[], 'running'] && (string) @file_get_contents($mute . DIRECTORY_SEPARATOR . 'started') === $starts,
            'a transport the service has a process for is asked, and never started a second time beside it', json_encode([$notYet, $readied]));
        // For an add-on that needs here, the same process: one that has said ready, and nothing of where it is reached, is
        // there with an empty list; one that has not said ready yet, with nothing kept from its version, is left out.
        $manifest('0.3.0');
        Addons::reset();
        $beside = new TransportProcess('mute', '0.3.0', [], null, []);
        $service->running['mute'] = ['manifest' => null, 'process' => $beside, 'carrier' => null, 'crashes' => [], 'next' => 0.0, 'down' => null];
        $starting = $service->hereForAddons();
        $beside->readied = true;
        $running = $service->hereForAddons();
        $service->running = [];
        $check($starting === [] && $withHere($running) === [['mute', 'mute', []]] && (string) @file_get_contents($mute . DIRECTORY_SEPARATOR . 'started') === $starts,
            'an add-on that needs here gets a running transport that said nothing with an empty list, and none for one not ready yet', json_encode([$starting, $running]));
        $a->close();

        // An empty list says the same as nothing said: transport here answers it alike, and names no address.
        $blank = $hRoot . DIRECTORY_SEPARATOR . 'blank';
        $package($blank, 'blank', '0.1.0', []);
        putenv('AAMIO_ADDONS=' . $blank);
        Addons::reset();
        @mkdir($hRoot . DIRECTORY_SEPARATOR . 'said-empty', 0700, true);
        $a = $opened[] = new Runtime($hRoot . DIRECTORY_SEPARATOR . 'said-empty' . DIRECTORY_SEPARATOR . 'a', $nowhere, null, true, $quietly);
        $said = Transports::here($a, 'blank');
        $check(array_key_exists('here', $said) && $said['here'] === [] && str_contains($said['note'] ?? '', 'blank says nothing of where it is reached')
            && ((new TransportConfig($a->home))->here()['blank']['here'] ?? null) === [], 'transport here says nothing for an empty list, as for none', json_encode($said));
        $a->close();

        // A reader of a card asks only which media this machine has. Asking for here instead starts a transport that keeps
        // nothing for a moment, and one over Bluetooth LE announces the machine as it starts (19 November 2026).
        $radio = $hRoot . DIRECTORY_SEPARATOR . 'radio';
        $package($radio, 'radio', '0.1.0');
        putenv('AAMIO_ADDONS=' . $radio . ',' . $lanDir);
        Addons::reset();
        @mkdir($hRoot . DIRECTORY_SEPARATOR . 'media', 0700, true);
        $a = $opened[] = new Runtime($hRoot . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'a', $nowhere, null, true, $quietly);
        $service = $a->transportService();
        $media = $service->mediaForAddons();
        $check($media === [['transport' => 'lan', 'medium' => 'lan'], ['transport' => 'radio', 'medium' => 'radio']] && $service->running === []
            && !is_file($radio . DIRECTORY_SEPARATOR . 'started') && (new TransportConfig($a->home))->here() === [],
            'an add-on that needs media gets the enabled transports by name, from the manifests, and nothing is started or kept', json_encode($media));
        $a->close();
    } finally {
        foreach ($opened as $runtime) {
            $runtime->close();
        }
        Http::$override = $savedOverride;
        $savedAddons === false ? putenv('AAMIO_ADDONS') : putenv('AAMIO_ADDONS=' . $savedAddons);
        $savedLocal === false ? putenv('LOCALAPPDATA') : putenv('LOCALAPPDATA=' . $savedLocal);
        $savedState === false ? putenv('XDG_STATE_HOME') : putenv('XDG_STATE_HOME=' . $savedState);
        Addons::reset();
    }
})();
