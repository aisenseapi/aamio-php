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
use Aamio\Codec;
use Aamio\Keys;
use Aamio\TransportFailed;
use Aamio\TransportProcess;

$pythonRepo = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'aamio-python';
// AAMIO_TEST_PYTHON is JSON, the program and its options, as the other tests of the clients read it.
$python = getenv('AAMIO_TEST_PYTHON') ? (array) json_decode((string) getenv('AAMIO_TEST_PYTHON'), true) : null;
if ($python === null) {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $folder) {
        foreach (PHP_OS_FAMILY === 'Windows' ? ['python.exe'] : ['python3', 'python'] as $name) {
            $candidate = rtrim($folder, '\\/') . DIRECTORY_SEPARATOR . $name;
            if ($folder !== '' && is_file($candidate) && !str_contains(strtolower($candidate), 'windowsapps')) {
                $python = [$candidate];
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
    $process = new TransportProcess('fake', '0.1.0', [...$python, '-u', $pythonRepo . '/tests/fake_transport.py'], sys_get_temp_dir(), $env, $clock);
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
    $broken = new TransportProcess('fake', '0.1.0', [...$python, '-u', $pythonRepo . '/tests/fake_transport.py'], sys_get_temp_dir(), $env);
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

// A message whose tries run out without a receipt is unknown to the Carrier, never confirmed.
$clock = clone $fakeClock;
$profile = CarryState::profile('sealed');
$stateA = new CarryState($carryA);
$stateB = new CarryState($carryB);
$now = 1794700000;
[$session, $ask] = $stateA->ask($carryB->publicRaw(), $profile, $clock());
[, $challenge] = $stateB->deliver($ask, [$carryA->publicRaw()], $profile, $clock(), $now);
[, $opened] = $stateA->deliver($challenge, [$carryB->publicRaw()], $profile, $clock(), $now);
$taken = $stateB->deliver($opened, [$carryA->publicRaw()], $profile, $clock(), $now)[0];
// The loopback gives every frame back to A, which it is not sealed to: no receipt can come.
$loop = $startFake('loopback', $clock);
try {
    $carrier = new Carrier($stateA, $loop, $profile, [$carryB->publicRaw()], $clock, static fn (): int => $now, 1.0, 5.0, 3);
    $key = $carrier->send($carryB->publicRaw(), $session, 'hei');
    $first = [$carrier->outcome($key), $carrier->confirmed($key)];
    $until = microtime(true) + 20;
    while ($carrier->outcome($key) === 'waiting' && microtime(true) < $until) {
        $clock->now += 0.5;
        $carrier->pump(0.05);
    }
    $check($taken === 'accepted' && $first === ['waiting', false] && $carrier->outcome($key) === 'unknown' && !$carrier->confirmed($key)
        && $carrier->refused !== [] && $carrier->inbox() === [], 'a message whose tries run out without a receipt is unknown, never confirmed');
} finally {
    $loop->stop();
}

// The review of 2026-11-15, findings 1, 4, 5, 6 and 14.
$T = 1794700000;
$reviewProfile = CarryState::profile('readable');
$openedPair = static function (CarryState $atA, CarryState $atB) use ($carryA, $carryB, $reviewProfile, $T): string {
    [$session, $ask] = $atA->ask($carryB->publicRaw(), $reviewProfile, 0.0);
    [, $challenge] = $atB->deliver($ask, [$carryA->publicRaw()], $reviewProfile, 0.0, $T);
    [, $opening] = $atA->deliver($challenge, [$carryB->publicRaw()], $reviewProfile, 0.0, $T);
    $atB->deliver($opening, [$carryA->publicRaw()], $reviewProfile, 0.0, $T);

    return $session;
};

// Finding 4: before anything is verified, copies take no more room and the room is checked at every frame.
$reassembly = new CarryReassembly(clone $fakeClock);
$firstFrame = 'AF' . "\0" . 'tagA' . chr(0) . chr(2) . str_repeat('x', 32768);
$copies = true;
for ($i = 0; $i < 10; $i++) {
    $copies = $copies && $reassembly->add($firstFrame) === null;
}
$copies = $copies && $reassembly->held() === strlen($firstFrame);
$within = true;
for ($n = 1; $n < 40; $n++) {
    $reassembly->add('AF' . "\0" . 'tagB' . chr($n) . chr(255) . str_repeat(chr($n), 32768));
    $within = $within && $reassembly->held() <= CarryReassembly::BYTES;
}
for ($n = 0; $n < 40; $n++) {
    $reassembly->add('AF' . "\0" . chr($n) . "\1\2\3" . chr(0) . chr(2) . str_repeat(chr($n), 20000));
    $within = $within && $reassembly->held() <= CarryReassembly::BYTES && count($reassembly->groups) <= CarryReassembly::GROUPS;
}
$check($copies && $within, 'reassembly holds a copy once and never more than its room');

// Finding 5: a transport that stops reading never holds up the owner, its deadlines or its stop. The socket pair of
// proc_open on Windows takes a whole line of many megabytes at once, so there a line is written as soon as it is given,
// and its outcome is unknown; whether a line can be left unbegun depends on what the system buffers, so that part is
// tried on a host with no process, where nothing is ever written.
$clock = clone $fakeClock;
$deaf = $startFake('deaf', $clock);
try {
    $waitRoom($deaf, 2);
    $began = microtime(true);
    $big = $deaf->send('AF' . str_repeat("\0", 1000000), null, 100);
    $small = $deaf->send('AF' . str_repeat("\0", 20), null, 100);
    $quick = microtime(true) - $began < 0.5;
    $clock->now += 1;
    $deaf->checkTime();
    $decided = array_diff([$deaf->status($big), $deaf->status($small)], ['unknown', 'not_sent']) === [];
    $began = microtime(true);
    $deaf->stop();
    $check($quick && $decided && microtime(true) - $began < TransportProcess::STOP_SECONDS + 1,
        'a transport that stops reading never holds up the owner, its deadlines or its stop');
} finally {
    $deaf->stop();
}
$clock = clone $fakeClock;
$unread = new TransportProcess('fake', '0.1.0', [], null, [], $clock);
$unread->injected[] = '{"t":"ready","credit":4,"sends_read":0}';
$unread->poll();
$begun = $unread->send('AF' . str_repeat("\0", 20), null, 100);
$unbegun = $unread->send('AF' . str_repeat("\1", 20), null, 100);
$out = new ReflectionProperty(TransportProcess::class, 'out');
$lines = $out->getValue($unread);
$lines[0]['offset'] = 1;
$out->setValue($unread, $lines);
$clock->now += 1;
$unread->checkTime();
$check([$unread->status($begun), $unread->status($unbegun), $unread->room()] === ['unknown', 'not_sent', 4 - 1],
    'a send line never begun by its deadline is not sent and gives its room back; one begun is unknown');

// Finding 6: the host hears from the Carrier that frames wait for room, so B3.2 holds through the Carrier too.
$clock = clone $fakeClock;
$stateA = new CarryState($carryA);
$session = $openedPair($stateA, new CarryState($carryB));
$noRoomProcess = $startFake('no_room', $clock);
try {
    $carrier = new Carrier($stateA, $noRoomProcess, $reviewProfile, [$carryB->publicRaw()], $clock, static fn (): int => $T, 1000.0);
    $carrier->send($carryB->publicRaw(), $session, 'hei');
    $carrier->pump(0.05);
    $started = $noRoomProcess->waitingForRoomSince === $clock->now;
    $clock->now += 59;
    $carrier->pump(0.05);
    $clock->now += 2;
    $code = null;
    try {
        $carrier->pump(0.05);
    } catch (TransportFailed $failure) {
        $code = $failure->transportCode;
    }
    $check($started && $code === 'transport_hung', 'frames that wait in the carrier make the transport hung after sixty seconds');
} finally {
    $noRoomProcess->stop();
}

// Finding 14: down takes the room away, up alone gives none back, and sixty seconds of down start it again.
$downByHand = new TransportProcess('fake', '0.1.0', [], null, [], clone $fakeClock);
$rooms = [];
foreach (['{"t":"ready","credit":3,"sends_read":0}', '{"t":"status","state":"down","reason":"the receiver stopped"}',
          '{"t":"ready","credit":3,"sends_read":0}', '{"t":"status","state":"up","reason":"back"}', '{"t":"ready","credit":3,"sends_read":0}'] as $line) {
    $downByHand->injected[] = $line;
    $downByHand->poll();
    $rooms[] = $downByHand->room();
}
$check($rooms === [3, 0, 0, 0, 3], 'a transport that says down gets no room until up and a new ready');
$clock = clone $fakeClock;
$downByHand = new TransportProcess('fake', '0.1.0', [], null, [], $clock);
$downByHand->injected[] = '{"t":"status","state":"down","reason":"the receiver stopped"}';
$downByHand->poll();
$clock->now += 59;
$downByHand->checkTime();
$downByHand->injected[] = '{"t":"status","state":"up","reason":"back"}';
$downByHand->poll();
$clock->now += 120;
$downByHand->checkTime();
$downByHand->injected[] = '{"t":"status","state":"down","reason":"stopped again"}';
$downByHand->poll();
$clock->now += 60;
$code = null;
try {
    $downByHand->checkTime();
} catch (TransportFailed $failure) {
    $code = $failure->transportCode;
}
$check($code === 'transport_down', 'a transport that says down is started again after sixty seconds, also with nothing to send');

// Finding 1: a carrier on the same home after a restart sends the same bytes, with the mark and the tries left.
$clock = clone $fakeClock;
$resumeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-carry-resume-' . bin2hex(random_bytes(4));
$path = $resumeRoot . DIRECTORY_SEPARATOR . 'a.json';
$stateA = new CarryState($carryA, $path);
$stateB = new CarryState($carryB);
[$session, $ask] = $stateA->ask($carryB->publicRaw(), $reviewProfile, 0.0);
[, $challenge] = $stateB->deliver($ask, [$carryA->publicRaw()], $reviewProfile, 0.0, $T);
$stateA->deliver($challenge, [$carryB->publicRaw()], $reviewProfile, 0.0, $T);
$envelope = $stateA->send($carryB->publicRaw(), $session, 'hei', $reviewProfile, 0, null, 5);
$key = CarryState::mark($carryB->publicRaw(), $session, 1);
$loopback = $startFake('loopback', $clock);
try {
    $carrier = new Carrier(new CarryState($carryA, $path), $loopback, $reviewProfile, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, 5);
    $same = ($carrier->messages[$key]['envelope'] ?? null) === $envelope;
    $carrier->pump(0.05);
    $after = new CarryState($carryA, $path);
    $check($same && $after->durable['outbox'][$key]['tries'] === 2 && $after->counterOf($carryB->publicRaw(), $session) === 1 && $carrier->outcome($key) === 'waiting',
        'a carrier on the same home after a restart sends the same bytes with the tries left');
} finally {
    $loopback->stop();
    $removeTree($resumeRoot);
}

// The follow-up check of 2026-11-15, findings 2 and 5. The transport is a host with no process: what a Carrier needs of
// one, with the room in the test's hands and every frame given to it counted. Nothing is written, and no answer comes.
$hostByHand = static function (callable $clock, int $room): TransportProcess {
    $process = new TransportProcess('medium', '0.1.0', [], null, [], $clock);
    $process->properties = ['max_frame_up' => 64];
    $process->injected[] = '{"t":"ready","credit":' . $room . ',"sends_read":0}';
    $process->poll();

    return $process;
};
/** Room for $room frames more, from the next pump on. */
$roomByHand = static function (TransportProcess $process, int $room): void {
    $process->injected[] = '{"t":"ready","credit":' . $room . ',"sends_read":' . $process->written . '}';
};
$queueOf = new ReflectionProperty(Carrier::class, 'queue');
/** Whether a try at the message waits in the carrier's queue, not one frame of it given to the transport yet. */
$waitsUnbegun = static fn (Carrier $carrier, string $key): bool => array_filter($queueOf->getValue($carrier), static fn (array $item): bool => $item[2] === $key && $item[3] === []) !== [];

// Finding 5: with a budget of two and no room, three rounds used both tries and left two copies in the queue, which went
// out when room came, after unknown had been said.
$clock = clone $fakeClock;
$stateA = new CarryState($carryA);
$session = $openedPair($stateA, new CarryState($carryB));
$wire = $hostByHand($clock, 0);
$carrier = new Carrier($stateA, $wire, $reviewProfile, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, 2);
$key = $carrier->send($carryB->publicRaw(), $session, 'hei');
foreach ([0.0, 2.1, 2.1] as $step) {
    $clock->now += $step;
    $carrier->pump(0.0);
}
$check([$carrier->outcome($key), $stateA->durable['outbox'][$key]['tries'], count($queueOf->getValue($carrier)), $wire->written] === ['waiting', 1, 1, 0],
    'no try is counted while the first waits for room, and no copy is queued behind it');
$roomByHand($wire, 1000);
$carrier->pump(0.0);
$first = $wire->written;
$went = $first > 0 && $queueOf->getValue($carrier) === [];
$clock->now += 2.1;
$carrier->pump(0.0);
$counted = $wire->written === 2 * $first && $stateA->durable['outbox'][$key]['tries'] === 2;
$clock->now += 2.1;
$carrier->pump(0.0);
$over = $carrier->outcome($key) === 'unknown' && $stateA->durable['outbox'][$key]['envelope'] === null;
for ($i = 0; $i < 3; $i++) {
    $clock->now += 2.1;
    $carrier->pump(0.0);
}
$check($went && $counted && $over && $wire->written === 2 * $first, 'the one try goes when there is room, the second is counted as it begins, and nothing goes after the tries are used');

// Finding 5: a try that has not begun is not begun after the message is over.
$clock = clone $fakeClock;
$stateA = new CarryState($carryA);
$stateB = new CarryState($carryB);
$session = $openedPair($stateA, $stateB);
$wire = $hostByHand($clock, 1000);
$carrier = new Carrier($stateA, $wire, $reviewProfile, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, 5);
$key = $carrier->send($carryB->publicRaw(), $session, 'hei');
$carrier->pump(0.0);
$first = $wire->written;
$roomByHand($wire, 0);
$clock->now += 2.1;
$carrier->pump(0.0);
$waits = $waitsUnbegun($carrier, $key) && $stateA->durable['outbox'][$key]['tries'] === 2;
[, $receipt] = $stateB->deliver($carrier->messages[$key]['envelope'], [$carryA->publicRaw()], $reviewProfile, 1.0, $T);
foreach (Carry::split($receipt, 64) as $frame) {
    $wire->injected[] = json_encode(['t' => 'received', 'from' => null, 'frame' => Codec::b64url($frame)]);
}
$carrier->pump(0.0);
$confirmed = $carrier->confirmed($key);
$roomByHand($wire, 1000);
$carrier->pump(0.0);
$check($first > 0 && $waits && $confirmed && $wire->written === $first && $queueOf->getValue($carrier) === [], 'a try that waits for room is not begun once its receipt has come');

// Finding 2: a new owner compared only the partner's key, and sent the old readable bytes though the profile said sealed.
$clock = clone $fakeClock;
$sealedProfile = CarryState::profile('sealed');
$modeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-carry-mode-' . bin2hex(random_bytes(4));
$path = $modeRoot . DIRECTORY_SEPARATOR . 'a.json';
$stateA = new CarryState($carryA, $path);
$stateB = new CarryState($carryB);
[$session, $ask] = $stateA->ask($carryB->publicRaw(), $reviewProfile, 0.0);
[, $challenge] = $stateB->deliver($ask, [$carryA->publicRaw()], $reviewProfile, 0.0, $T);
$stateA->deliver($challenge, [$carryB->publicRaw()], $reviewProfile, 0.0, $T);
$envelope = $stateA->send($carryB->publicRaw(), $session, 'lesbar tekst', $reviewProfile, 0, null, 5);
$key = CarryState::mark($carryB->publicRaw(), $session, 1);
$readable = ord($envelope[4]) === Carry::READABLE && str_contains($envelope, 'lesbar tekst');
$said = [];
$note = static function (string $text) use (&$said): void {
    $said[] = $text;
};
try {
    $wire = $hostByHand($clock, 1000);
    $carrier = new Carrier(new CarryState($carryA, $path), $wire, $sealedProfile, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, 5, $note);
    $leftAlone = !isset($carrier->messages[$key]);
    for ($i = 0; $i < 4; $i++) {
        $clock->now += 3;
        $carrier->pump(0.0);
    }
    $kept = new CarryState($carryA, $path);
    $check($readable && $leftAlone && $wire->written === 0 && $kept->outcome($key) === 'unknown' && $kept->durable['outbox'][$key]['envelope'] === null,
        'bytes written readable are not taken up under a sealed profile: nothing readable goes out, and the message is given up with its mark and hash kept');
    $check(count($said) === 1 && str_contains($said[0], $key) && str_contains($said[0], 'readable') && str_contains($said[0], 'sealed') && str_contains($said[0], 'not sent again'),
        'and the user is told, once, that it is not sent again and why');
    // The same mode is taken up as before. One state at a time on the file, as one owner has the home.
    [$session, $ask] = $kept->ask($carryB->publicRaw(), $sealedProfile, 10.0);
    [, $challenge] = $stateB->deliver($ask, [$carryA->publicRaw()], $sealedProfile, 10.0, $T);
    $kept->deliver($challenge, [$carryB->publicRaw()], $sealedProfile, 10.0, $T);
    $kept->send($carryB->publicRaw(), $session, 'forseglet', $sealedProfile, 0, null, 5);
    $carrier = new Carrier(new CarryState($carryA, $path), $hostByHand($clock, 1000), $sealedProfile, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, 5, $note);
    $check(array_keys($carrier->messages) === [CarryState::mark($carryB->publicRaw(), $session, 1)] && count($said) === 1, 'bytes written under the mode the profile has now are taken up as before');
} finally {
    $removeTree($modeRoot);
}

// Finding 2, while it runs: the profile changed while a try waited for room, and the try is not begun.
$modeNow = 'readable';
$profileNow = static function (string $pk) use (&$modeNow, $reviewProfile, $sealedProfile): array {
    return $modeNow === 'sealed' ? $sealedProfile : $reviewProfile;
};
$clock = clone $fakeClock;
$stateA = new CarryState($carryA);
$session = $openedPair($stateA, new CarryState($carryB));
$wire = $hostByHand($clock, 0);
$said = [];
$carrier = new Carrier($stateA, $wire, $profileNow, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, CarryState::TRIES_DEFAULT, $note);
$key = $carrier->send($carryB->publicRaw(), $session, 'lesbar');
$carrier->pump(0.0);
$waits = $waitsUnbegun($carrier, $key);
$modeNow = 'sealed';
$roomByHand($wire, 1000);
$carrier->pump(0.0);
$check($waits && $wire->written === 0 && $queueOf->getValue($carrier) === [] && !isset($carrier->messages[$key]) && $carrier->outcome($key) === 'unknown'
    && count($said) === 1 && str_contains($said[0], 'not sent again'), 'a try that waits for room is not begun once the profile is of another mode, and the user is told');

// Finding 2, between two tries: the profile changed after the first try went, and no try is counted for a message given up.
$modeNow = 'readable';
$clock = clone $fakeClock;
$stateA = new CarryState($carryA);
$session = $openedPair($stateA, new CarryState($carryB));
$wire = $hostByHand($clock, 1000);
$said = [];
$carrier = new Carrier($stateA, $wire, $profileNow, [$carryB->publicRaw()], $clock, static fn (): int => $T, 2.0, 5.0, CarryState::TRIES_DEFAULT, $note);
$key = $carrier->send($carryB->publicRaw(), $session, 'lesbar');
$carrier->pump(0.0);
$first = $wire->written;
$modeNow = 'sealed';
$clock->now += 2.1;
$carrier->pump(0.0);
$check($first > 0 && $wire->written === $first && $stateA->durable['outbox'][$key]['tries'] === 1 && $carrier->outcome($key) === 'unknown' && !isset($carrier->messages[$key])
    && count($said) === 1, 'no try is counted or sent once the profile is of another mode than the bytes were written under');

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
            $radio = new TransportProcess('sim-radio', '0.1.0', [...$python, '-u', '-m', 'aamio_sim_radio.transport'], sys_get_temp_dir(), $env);
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
        $ok = $ok && $pumpUntil(static fn () => $atA->confirmed($sent) && $atB->inbox() !== []);
        $atB->open($carryA->publicRaw());
        $ok = $ok && $pumpUntil(static fn () => $atB->openSession($carryA->publicRaw()) !== null);
        $reply = $ok ? $atB->send($carryA->publicRaw(), $atB->openSession($carryA->publicRaw()), 'hei tilbake fra B') : null;
        $ok = $ok && $pumpUntil(static fn () => $atB->confirmed($reply) && $atA->inbox() !== []);
        $until = microtime(true) + 2;
        while (microtime(true) < $until) {
            $atA->pump(0.01);
            $atB->pump(0.01);
        }
        $check($ok && array_column($atB->inbox(), 3) === ['hei fra A'] && array_column($atA->inbox(), 3) === ['hei tilbake fra B']
            && $atB->inbox()[0][0] === $carryA->publicRaw(), "two homes over the simulated radio, $mode" . ($medium === [] ? ', clean' : ', poor') . ': a message each way, once, with its receipt');
    } finally {
        foreach ($radios as $radio) {
            $radio->stop();
        }
    }
}
$removeTree($simRoot);
