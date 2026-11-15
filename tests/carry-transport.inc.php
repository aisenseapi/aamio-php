<?php

declare(strict_types=1);

/*
 * The host side of contract 3 and a Carrier, against the transports in the
 * aamio-python repository beside this one: tests/fake_transport.py, which can
 * loop frames back, stay silent, give no room, answer late or break the
 * contract, and addons/sim-radio, the simulated radio over UDP on 127.0.0.1
 * that the three runtimes share as their medium. They are programs on standard
 * input and output, so the language they are written in does not matter. Both
 * need a Python, from AAMIO_TEST_PYTHON or the path; without one the part is
 * passed over and says so.
 */

use Aamio\Carrier;
use Aamio\Carry;
use Aamio\CarryReassembly;
use Aamio\CarryRefused;
use Aamio\CarryState;
use Aamio\Keys;
use Aamio\TransportFailed;
use Aamio\TransportProcess;

$pythonRepo = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'aamio-python';
$python = getenv('AAMIO_TEST_PYTHON') ?: null;
if ($python === null) {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $folder) {
        foreach (PHP_OS_FAMILY === 'Windows' ? ['python.exe'] : ['python3', 'python'] as $name) {
            $candidate = rtrim($folder, '\\/') . DIRECTORY_SEPARATOR . $name;
            if ($folder !== '' && is_file($candidate) && !str_contains(strtolower($candidate), 'windowsapps')) {
                $python = $candidate;
                break 2;
            }
        }
    }
}
echo "carry: transports\n";
if ($python === null || !is_file($pythonRepo . '/tests/fake_transport.py')) {
    echo "  skip  no Python, or no aamio-python beside this repository, so the transports are not tried\n";

    return;
}

$baseEnv = [];
foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP'] as $name) {
    if (getenv($name) !== false) {
        $baseEnv[$name] = (string) getenv($name);
    }
}
$fakeClock = new class () {
    public float $now = 1000.0;

    public function __invoke(): float
    {
        return $this->now;
    }
};
$startFake = static function (string $mode, ?callable $clock = null) use ($python, $pythonRepo, $baseEnv): TransportProcess {
    $env = TransportProcess::environment([], $baseEnv);
    $env['FAKE_TRANSPORT_MODE'] = $mode;
    $process = new TransportProcess('fake', '0.1.0', [$python, '-u', $pythonRepo . '/tests/fake_transport.py'], sys_get_temp_dir(), $env, $clock);
    $process->start('0.0.0-test', 'php', sys_get_temp_dir());

    return $process;
};
$waitRoom = static function (TransportProcess $process, int $needed = 1): void {
    $until = microtime(true) + 10;
    while ($process->room() < $needed && microtime(true) < $until) {
        $process->poll(0.1);
    }
};
$carryA = Keys::fromSeedHex($carryParties['A']['seed']);
$carryB = Keys::fromSeedHex($carryParties['B']['seed']);

$loop = $startFake('loopback');
try {
    $envelope = Carry::write($carryA, $carryB->publicRaw(), Carry::READABLE, Carry::MESSAGE, 0, str_repeat("\0", 8), 1, 1794700300, str_repeat('x', 300));
    $frames = Carry::split($envelope, $loop->properties['max_frame_up']);
    $reassembly = new CarryReassembly();
    $whole = null;
    $ids = [];
    $until = microtime(true) + 10;
    while ($whole === null && microtime(true) < $until) {
        while ($frames !== [] && $loop->room() > 0) {
            $ids[] = $loop->send(array_shift($frames));
        }
        foreach ($loop->poll(0.1) as $event) {
            if ($event[0] === 'received') {
                $whole = $reassembly->add($event[2]) ?? $whole;
            }
        }
    }
    $check($whole === $envelope && $ids !== [] && array_filter($ids, static fn ($id) => $loop->status($id) !== 'sent') === [],
        'frames go out within the room a ready gave, and come back whole');
} finally {
    $loop->stop();
}

$clock = clone $fakeClock;
$silent = $startFake('silent', $clock);
try {
    $waitRoom($silent, 3);
    $ids = [$silent->send('AF' . str_repeat("\0", 20), null, 1000), $silent->send('AF' . str_repeat("\0", 20), null, 1000), $silent->send('AF' . str_repeat("\0", 20), null, 1000)];
    $silent->poll(0.5);
    $clock->now += 2;
    $hung = null;
    try {
        $silent->checkTime();
    } catch (TransportFailed $failure) {
        $hung = $failure->transportCode;
    }
    $check($hung === 'transport_hung' && array_map(static fn ($id) => $silent->status($id), $ids) === ['unknown', 'unknown', 'unknown'],
        'a transport that never answers is hung after three deadlines, whatever else it says');
} finally {
    $silent->stop();
}

$clock = clone $fakeClock;
$noRoom = $startFake('no_room', $clock);
try {
    $none = $noRoom->send('AF' . str_repeat("\0", 20));
    $noRoom->injected[] = '{"t":"status","state":"up","reason":"chatter"}';
    $chatter = $noRoom->poll(0.1);
    $clock->now += 59;
    $noRoom->checkTime();
    $clock->now += 1;
    $hung = null;
    try {
        $noRoom->checkTime();
    } catch (TransportFailed $failure) {
        $hung = $failure->transportCode;
    }
    $check($none === null && $chatter === [['status', 'up', 'chatter']] && $hung === 'transport_hung',
        'a transport that never gives room is hung after sixty seconds, whatever else it says');
} finally {
    $noRoom->stop();
}

$clock = clone $fakeClock;
$late = $startFake('late', $clock);
try {
    $waitRoom($late);
    $id = $late->send('AF' . str_repeat("\0", 20), null, 1000);
    $clock->now += 2;
    $late->checkTime();
    $before = $late->status($id);
    $until = microtime(true) + 5;
    while ($late->status($id) === 'unknown' && microtime(true) < $until) {
        $late->poll(0.1);
    }
    $check($before === 'unknown' && $late->status($id) === 'sent' && $late->frames[$id]['late'], 'a late answer decides the status after the deadline');
} finally {
    $late->stop();
}

foreach (['bad_describe' => 'transport_bad_describe', 'long_line' => 'transport_protocol'] as $mode => $code) {
    $env = TransportProcess::environment([], $baseEnv);
    $env['FAKE_TRANSPORT_MODE'] = $mode;
    $broken = new TransportProcess('fake', '0.1.0', [$python, '-u', $pythonRepo . '/tests/fake_transport.py'], sys_get_temp_dir(), $env);
    $got = null;
    try {
        $broken->start('0.0.0-test', 'php', sys_get_temp_dir());
    } catch (TransportFailed $failure) {
        $got = $failure->transportCode;
    } finally {
        $broken->stop();
    }
    $check($got === $code, 'a transport that breaks the contract is not used: ' . $mode);
}

$byHand = new TransportProcess('fake', '0.1.0', [], null, [], clone $fakeClock);
$byHand->written = 5;
$byHand->injected[] = '{"t":"ready","credit":3,"sends_read":4}';
$byHand->poll();
$roomOk = $byHand->room() === 2;
$byHand->frames['f-1'] = ['status' => 'waiting', 'deadline' => 2000.0, 'late' => false, 'answered' => false];
$byHand->injected = ['{"t":"sent","id":"f-1"}', '{"t":"error","id":"f-1","code":"cancelled"}', '{"t":"link_ack","id":"f-1"}'];
$byHand->poll();
$check($roomOk && $byHand->status('f-1') === 'sent' && ($byHand->frames['f-1']['link_ack'] ?? false), 'room is exact when lines cross, and the first answer decides');
$badReady = new TransportProcess('fake', '0.1.0', [], null, [], clone $fakeClock);
$badReady->written = 5;
$badReady->injected[] = '{"t":"ready","credit":3,"sends_read":6}';
$code = null;
try {
    $badReady->poll();
} catch (TransportFailed $failure) {
    $code = $failure->transportCode;
}
$check($code === 'transport_protocol', 'a ready that does not add up stops the transport');
$check(TransportProcess::environment(['AAMIO_HOME', 'SIM_PORT'], ['PATH' => '/bin', 'AAMIO_HOME' => '/h', 'SIM_PORT' => '4000', 'TOKEN' => 't'])
    === ['PATH' => '/bin', 'SIM_PORT' => '4000', 'AAMIO_ADDON' => '3'], 'the environment never says where the home is');

// Two homes over the simulated radio, in this one process.
$simRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-carry-sim-' . bin2hex(random_bytes(4));
$freePort = static function (): int {
    $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    $name = stream_socket_get_name($probe, false);
    fclose($probe);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
};
foreach ([['readable', []], ['readable', ['AAMIO_SIM_LOSS' => '0.2', 'AAMIO_SIM_DUPLICATE' => '0.2', 'AAMIO_SIM_REORDER' => '0.3', 'AAMIO_SIM_MAX_FRAME' => '32', 'AAMIO_SIM_SEED' => 'fase 1']],
          ['sealed', ['AAMIO_SIM_LOSS' => '0.2', 'AAMIO_SIM_DUPLICATE' => '0.2', 'AAMIO_SIM_REORDER' => '0.3', 'AAMIO_SIM_MAX_FRAME' => '32', 'AAMIO_SIM_SEED' => 'fase 1']]] as [$mode, $medium]) {
    $portA = $freePort();
    $portB = $freePort();
    $radios = [];
    try {
        foreach ([[$portA, $portB], [$portB, $portA]] as [$own, $peer]) {
            $env = TransportProcess::environment([], $baseEnv) + ['PYTHONPATH' => $pythonRepo . '/addons/sim-radio/src', 'AAMIO_SIM_PORT' => (string) $own, 'AAMIO_SIM_PEERS' => (string) $peer] + $medium;
            $radio = new TransportProcess('sim-radio', '0.1.0', [$python, '-u', '-m', 'aamio_sim_radio.transport'], sys_get_temp_dir(), $env);
            $radio->start('0.0.0-test', 'php', sys_get_temp_dir());
            $radios[] = $radio;
        }
        $profile = CarryState::profile($mode);
        $atA = new Carrier(new CarryState($carryA, $simRoot . "/$mode-$portA/a/carry.json"), $radios[0], $profile, [$carryB->publicRaw()], null, null, 1.0, 3.0);
        $atB = new Carrier(new CarryState($carryB, $simRoot . "/$mode-$portA/b/carry.json"), $radios[1], $profile, [$carryA->publicRaw()], null, null, 1.0, 3.0);
        $pumpUntil = static function (callable $condition) use ($atA, $atB): bool {
            $until = microtime(true) + 60;
            while (!$condition()) {
                if (microtime(true) > $until) {
                    return false;
                }
                $atA->pump(0.01);
                $atB->pump(0.01);
            }

            return true;
        };
        $atA->open($carryB->publicRaw());
        $ok = $pumpUntil(static fn () => $atA->openSession($carryB->publicRaw()) !== null);
        $sent = $ok ? $atA->send($carryB->publicRaw(), $atA->openSession($carryB->publicRaw()), 'hei fra A') : null;
        $ok = $ok && $pumpUntil(static fn () => $atA->confirmed($sent) && $atB->inbox !== []);
        $atB->open($carryA->publicRaw());
        $ok = $ok && $pumpUntil(static fn () => $atB->openSession($carryA->publicRaw()) !== null);
        $reply = $ok ? $atB->send($carryA->publicRaw(), $atB->openSession($carryA->publicRaw()), 'hei tilbake fra B') : null;
        $ok = $ok && $pumpUntil(static fn () => $atB->confirmed($reply) && $atA->inbox !== []);
        $until = microtime(true) + 2;
        while (microtime(true) < $until) {
            $atA->pump(0.01);
            $atB->pump(0.01);
        }
        $check($ok && array_column($atB->inbox, 3) === ['hei fra A'] && array_column($atA->inbox, 3) === ['hei tilbake fra B']
            && $atB->inbox[0][0] === $carryA->publicRaw(), "two homes over the simulated radio, $mode" . ($medium === [] ? ', clean' : ', poor') . ': a message each way, once, with its receipt');
    } finally {
        foreach ($radios as $radio) {
            $radio->stop();
        }
    }
}
$removeTree($simRoot);
