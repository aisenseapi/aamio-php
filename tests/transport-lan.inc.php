<?php

declare(strict_types=1);

/*
 * The transport over the local network (addons/lan), on 127.0.0.1, through the host of contract 3.
 *
 * Two carriers, each with its own lan transport on a UDP port of its own and the other named as its peer, broadcast
 * off so nothing leaves 127.0.0.1. A probe opens no port, a port another program holds is down until it opens, and
 * then up with room; a message goes each way with its receipt, readable and sealed; and a datagram of another kind is
 * dropped.
 */

use Aamio\Carrier;
use Aamio\CarryState;
use Aamio\Keys;
use Aamio\TransportProcess;

echo "carry: the local network\n";

$lanScript = dirname(__DIR__) . '/addons/lan/transport.php';
$lanFreePorts = static function (int $count): array {
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
$lanBase = [];
foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP'] as $name) {
    if (getenv($name) !== false) {
        $lanBase[$name] = (string) getenv($name);
    }
}
$lanProcess = static function (int $port, int $peer) use ($lanScript, $lanBase): TransportProcess {
    $env = TransportProcess::environment(['AAMIO_LAN_PORT', 'AAMIO_LAN_BIND', 'AAMIO_LAN_BROADCAST', 'AAMIO_LAN_PEERS'], $lanBase + [
        'AAMIO_LAN_PORT' => (string) $port, 'AAMIO_LAN_BIND' => '127.0.0.1', 'AAMIO_LAN_BROADCAST' => 'none', 'AAMIO_LAN_PEERS' => '127.0.0.1:' . $peer]);

    return new TransportProcess('lan', '0.1.0', [PHP_BINARY, $lanScript], sys_get_temp_dir(), $env);
};
/** Events until $condition holds of them, all of them kept; null when it did not within $seconds. */
$lanWaitFor = static function (TransportProcess $process, callable $condition, float $seconds = 10.0): ?array {
    $events = [];
    $until = hrtime(true) / 1e9 + $seconds;
    while (!$condition($process, $events)) {
        if (hrtime(true) / 1e9 >= $until) {
            return null;
        }
        foreach ($process->poll(0.05) as $event) {
            $events[] = $event;
        }
    }

    return $events;
};
$lanStarted = static function (int $port, int $peer) use ($lanProcess, $lanWaitFor): TransportProcess {
    $process = $lanProcess($port, $peer);
    $process->start('0.0.0-test', 'php', sys_get_temp_dir());
    $lanWaitFor($process, static fn (TransportProcess $p): bool => $p->room() > 0);

    return $process;
};

$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/addons/lan/aamio-addon.json'), true);
$pythonManifest = dirname(__DIR__, 2) . '/aamio-python/addons/lan/src/aamio_lan/aamio-addon.json';
$check(($manifest['aamio_addon'] ?? null) === 3 && ($manifest['transport']['start']['php'] ?? null) === 'transport.php'
    && (!is_file($pythonManifest) || file_get_contents($pythonManifest) === file_get_contents(dirname(__DIR__) . '/addons/lan/aamio-addon.json')),
    'the manifest is a transport under contract 3, the same file as in aamio-python');

[$lanPort, $lanPeer] = $lanFreePorts(2);
$probe = $lanProcess($lanPort, $lanPeer);
try {
    $probe->start('0.0.0-test', 'php', sys_get_temp_dir(), true);
    usleep(1000000);
    $events = $probe->poll(0.1);
    $mine = @stream_socket_server('udp://127.0.0.1:' . $lanPort, $errno, $errstr, STREAM_SERVER_BIND);
    $check($probe->medium === 'lan' && $probe->room() === 0 && array_filter($events, static fn (array $e): bool => $e[0] === 'status') === [] && $mine !== false,
        'a probe describes itself, opens no port and gives no room');
    if ($mine !== false) {
        fclose($mine);
    }
} finally {
    $probe->stop();
}

[$heldPort, $heldPeer] = $lanFreePorts(2);
// PHP sets SO_REUSEADDR on every socket it binds, so the port is held here by a program that does not, as the other
// runtimes' transports do not: a Python of its own. On Windows SO_REUSEADDR lets the transport bind the port all the
// same, so there a transport cannot see that another holds its port: the README says so, and this part is passed over.
$holder = null;
$holderPipes = [];
if (PHP_OS_FAMILY !== 'Windows' && isset($python) && $python !== null) {
    $holder = proc_open(array_merge($python, ['-c', 'import socket, sys; s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.bind(("127.0.0.1", int(sys.argv[1]))); print("held", flush=True); sys.stdin.read()', (string) $heldPort]),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holderPipes);
    if (!is_resource($holder) || trim((string) fgets($holderPipes[1])) !== 'held') {
        $holder = null;
    }
}
if ($holder === null) {
    echo "  skip  a port another program holds: on Windows PHP cannot hold a UDP port alone, or no Python here to hold one\n";
}
$held = $holder === null ? null : $lanProcess($heldPort, $heldPeer);
try {
    if ($held === null) {
        throw new \LogicException('passed over');
    }
    $held->start('0.0.0-test', 'php', sys_get_temp_dir());
    $down = $lanWaitFor($held, static fn (TransportProcess $p, array $seen): bool => array_filter($seen, static fn (array $e): bool => $e[0] === 'status' && $e[1] === 'down') !== []);
    $reason = $down === null ? '' : (string) (array_values(array_filter($down, static fn (array $e): bool => $e[0] === 'status'))[0][2] ?? '');
    $check($down !== null && $held->room() <= 0 && str_contains($reason, 'could not be opened'), 'a port another program holds is said as down, with no room', $reason);
    fclose($holderPipes[0]);
    proc_close($holder);
    $holder = null;
    $up = $lanWaitFor($held, static fn (TransportProcess $p): bool => $p->room() > 0);
    $check($up !== null && array_filter($up, static fn (array $e): bool => $e[0] === 'status' && $e[1] === 'up') !== [], 'and once it opens, up and room');
} catch (\LogicException) {
} finally {
    if ($holder !== null) {
        proc_terminate($holder);
    }
    $held?->stop();
}

$lanA = Keys::fromSeedHex(str_repeat('01', 32));
$lanB = Keys::fromSeedHex(str_repeat('02', 32));
$lanRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-php-lan-' . getmypid();
@mkdir($lanRoot, 0700, true);
foreach (['readable', 'sealed'] as $mode) {
    [$portA, $portB] = $lanFreePorts(2);
    $radios = [$lanStarted($portA, $portB), $lanStarted($portB, $portA)];
    try {
        $profile = CarryState::profile($mode);
        $atA = new Carrier(new CarryState($lanA, $lanRoot . '/a-' . $mode . '.json'), $radios[0], $profile, [$lanB->publicRaw()], null, null, 1.0, 3.0);
        $atB = new Carrier(new CarryState($lanB, $lanRoot . '/b-' . $mode . '.json'), $radios[1], $profile, [$lanA->publicRaw()], null, null, 1.0, 3.0);
        $pumpUntil = static function (callable $condition) use ($atA, $atB): bool {
            $until = hrtime(true) / 1e9 + 30;
            while (!$condition()) {
                if (hrtime(true) / 1e9 >= $until) {
                    return false;
                }
                $atA->pump(0.01);
                $atB->pump(0.01);
            }

            return true;
        };
        $atA->open($lanB->publicRaw());
        $opened = $pumpUntil(static fn (): bool => $atA->openSession($lanB->publicRaw()) !== null);
        $sent = $opened ? $atA->send($lanB->publicRaw(), (string) $atA->openSession($lanB->publicRaw()), 'hei over nettet') : '';
        $there = $opened && $pumpUntil(static fn (): bool => $atA->confirmed($sent) && $atB->inbox() !== []);
        $atB->open($lanA->publicRaw());
        $back = $there && $pumpUntil(static fn (): bool => $atB->openSession($lanA->publicRaw()) !== null);
        $reply = $back ? $atB->send($lanA->publicRaw(), (string) $atB->openSession($lanA->publicRaw()), 'svar over nettet') : '';
        $answered = $back && $pumpUntil(static fn (): bool => $atB->confirmed($reply) && $atA->inbox() !== []);
        $check($answered && array_column($atB->inbox(), 3) === ['hei over nettet'] && array_column($atA->inbox(), 3) === ['svar over nettet'] && $atA->refused === [] && $atB->refused === [],
            $mode . ': a message each way over the local network with its receipt, and nothing it sent itself taken in');
        $check($atA->handedOver($sent) && $atB->handedOver($reply), $mode . ': every frame of each was handed over, as the transport said');
    } finally {
        foreach ($radios as $radio) {
            $radio->stop();
        }
    }
}
exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /S /Q "' . $lanRoot . '"' : 'rm -rf "' . $lanRoot . '"');

[$noisePort, $noisePeer] = $lanFreePorts(2);
$noisy = $lanStarted($noisePort, $noisePeer);
try {
    $other = stream_socket_client('udp://127.0.0.1:' . $noisePort);
    fwrite($other, 'not aamio at all, just noise on the port');
    fwrite($other, 'AL1' . str_repeat("\0", 8) . 'AF' . str_repeat("\0", 20));
    fclose($other);
    $heard = $lanWaitFor($noisy, static fn (TransportProcess $p, array $seen): bool => array_filter($seen, static fn (array $e): bool => $e[0] === 'received') !== [], 5.0);
    $received = $heard === null ? [] : array_values(array_filter($heard, static fn (array $e): bool => $e[0] === 'received'));
    $check(count($received) === 1 && $received[0][2] === 'AF' . str_repeat("\0", 20) && str_starts_with((string) $received[0][1], 'lan:127.0.0.1:'), 'a datagram of another kind is dropped, and one of this kind comes with its address');
} finally {
    $noisy->stop();
}
