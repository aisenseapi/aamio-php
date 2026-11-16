<?php

declare(strict_types=1);

/*
 * The sessions of aamio-carry-v1 against the scenarios in its vectors, each
 * party with a CarryState kept in a file of its own, so a restart is a new
 * CarryState on the same file. Included from carry.inc.php.
 */

use Aamio\Carry;
use Aamio\CarryRefused;
use Aamio\CarryState;
use Aamio\Keys;

echo "carry: scenarios\n";
$stateRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-carry-state-' . bin2hex(random_bytes(4));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    } elseif (is_file($path)) {
        @unlink($path);
    }
};
$hexOrNull = static fn (?string $hex): ?string => $hex === null ? null : hex2bin($hex);
try {
    foreach ($carryVectors['scenarios'] as $number => $scenario) {
        $parties = [];
        foreach ($scenario['parties'] as $row) {
            $p = $row['profile'];
            $parties[$row['name']] = [
                'state' => new CarryState(Keys::fromSeedHex($carryParties[$row['name']]['seed']), $stateRoot . DIRECTORY_SEPARATOR . $number . DIRECTORY_SEPARATOR . $row['name'] . DIRECTORY_SEPARATOR . 'carry.json'),
                'known' => array_map($carryPk, $row['known']),
                'profile' => CarryState::profile($p['mode'], $p['challenge'], $p['accept_unchallenged'], $p['lifetime'], $p['challenge_window'], $p['clock_trusted']),
            ];
        }
        $wrong = null;
        foreach ($scenario['steps'] as $index => $step) {
            $party = &$parties[$step['party']];
            $state = $party['state'];
            $profile = $party['profile'];
            switch ($step['op']) {
                case 'restart':
                    $party['state'] = new CarryState($state->keys, $state->path);
                    break;
                case 'prune':
                    // The scenarios are about the guard against replays, and no program in them takes a message. A
                    // session with a message nobody has taken is kept whatever its age, so the program takes what has
                    // come before it tidies.
                    foreach ($state->pending() as [$waiting]) {
                        $state->handedOver($waiting);
                    }
                    $state->prune($step['now']);
                    break;
                case 'ask':
                    [, $envelope] = $state->ask($carryPk($step['to']), $profile, $step['mono'], hex2bin($step['session']), $hexOrNull($step['seal_rnd'] ?? null));
                    if (bin2hex($envelope) !== $step['envelope']) {
                        $wrong ??= "step $index: the ask";
                    }
                    break;
                case 'send':
                    $envelope = $state->send($carryPk($step['to']), hex2bin($step['session']), hex2bin($step['content']), $profile, $step['deadline'], $hexOrNull($step['seal_rnd'] ?? null));
                    if (bin2hex($envelope) !== $step['envelope']) {
                        $wrong ??= "step $index: the message";
                    }
                    break;
                case 'sent':
                    $state->rememberSent($carryPk($step['to']), hex2bin($step['session']), $step['counter'], hex2bin($step['envelope']));
                    break;
                default:
                    try {
                        [$result, $reply] = $state->deliver(hex2bin($step['envelope']), $party['known'], $profile, $step['mono'], $step['now'],
                            $hexOrNull($step['rnd'] ?? null), $hexOrNull($step['seal_rnd'] ?? null));
                        if ($result !== $step['result'] || ($reply === null ? null : bin2hex($reply)) !== ($step['reply'] ?? null)) {
                            $wrong ??= "step $index: $result, not " . $step['result'] . ($result === $step['result'] ? ', and another reply' : '');
                        }
                    } catch (CarryRefused $refused) {
                        if ($step['result'] !== 'refused' || $refused->carryCode !== ($step['code'] ?? null)) {
                            $wrong ??= "step $index: refused with " . $refused->carryCode . ', not ' . $step['result'] . ' ' . ($step['code'] ?? '');
                        }
                    }
            }
            unset($party);
        }
        $check($wrong === null, $scenario['name'] . ($wrong === null ? '' : ' (' . $wrong . ')'));
    }

    echo "carry: keeping\n";
    $brokenPath = $stateRoot . DIRECTORY_SEPARATOR . 'broken' . DIRECTORY_SEPARATOR . 'carry.json';
    @mkdir(dirname($brokenPath), 0700, true);
    file_put_contents($brokenPath, '{not json');
    $check($carryCode(static fn () => new CarryState(Keys::fromSeedHex($carryParties['B']['seed']), $brokenPath)) === 'carry_state_unreadable',
        'a state that cannot be read stops everything rather than start from nothing');
    // Another runtime may take the home over: the maps go out as objects also when empty, and any other shape is
    // refused whole, since what was not read would be written away at the next save.
    $shapePath = $stateRoot . DIRECTORY_SEPARATOR . 'shape' . DIRECTORY_SEPARATOR . 'carry.json';
    @mkdir(dirname($shapePath), 0700, true);
    $empty = '{"version":1,"floor":null,"challenges":0,"incoming":{},"used":[],"stored":{},"outgoing":{},"outbox":{}}';
    file_put_contents($shapePath, $empty);
    (new CarryState(Keys::fromSeedHex($carryParties['B']['seed']), $shapePath))->prune(1794700000);
    $check((string) file_get_contents($shapePath) === str_replace('"floor":null', '"floor":1794700000', $empty),
        'the kept state is written with its maps as JSON objects, also when they are empty');
    $otherShapes = [
        'a list where a map goes' => '{"version":1,"floor":null,"challenges":0,"incoming":{},"used":[],"stored":{},"outgoing":["a"],"outbox":{}}',
        'a record that is not one' => '{"version":1,"floor":null,"challenges":0,"incoming":{"a:b":"x"},"used":[],"stored":{},"outgoing":{},"outbox":{}}',
        'a hash that is a number' => '{"version":1,"floor":null,"challenges":0,"incoming":{},"used":[],"stored":{"a:b:1":5},"outgoing":{},"outbox":{}}',
        'used as a map' => '{"version":1,"floor":null,"challenges":0,"incoming":{},"used":{"a":"b"},"stored":{},"outgoing":{},"outbox":{}}',
        'used with a number' => '{"version":1,"floor":null,"challenges":0,"incoming":{},"used":[1],"stored":{},"outgoing":{},"outbox":{}}',
        'a floor as text' => '{"version":1,"floor":"1794700000","challenges":0,"incoming":{},"used":[],"stored":{},"outgoing":{},"outbox":{}}',
        'a floor below zero' => '{"version":1,"floor":-1,"challenges":0,"incoming":{},"used":[],"stored":{},"outgoing":{},"outbox":{}}',
        'a count that is true' => '{"version":1,"floor":null,"challenges":true,"incoming":{},"used":[],"stored":{},"outgoing":{},"outbox":{}}',
        'no outbox' => '{"version":1,"floor":null,"challenges":0,"incoming":{},"used":[],"stored":{},"outgoing":{}}',
        'no floor' => '{"version":1,"challenges":0,"incoming":{},"used":[],"stored":{},"outgoing":{},"outbox":{}}',
    ];
    $wrongShapes = [];
    foreach ($otherShapes as $name => $text) {
        file_put_contents($shapePath, $text);
        if ($carryCode(static fn () => new CarryState(Keys::fromSeedHex($carryParties['B']['seed']), $shapePath)) !== 'carry_state_unreadable') {
            $wrongShapes[] = $name;
        }
    }
    $check($wrongShapes === [], 'a kept state of another shape is refused whole rather than read in part' . ($wrongShapes === [] ? '' : ': ' . implode(', ', $wrongShapes)));
    $blocked = $stateRoot . DIRECTORY_SEPARATOR . 'file-in-the-way';
    file_put_contents($blocked, 'not a folder');
    $receiver = new CarryState(Keys::fromSeedHex($carryParties['B']['seed']), $blocked . DIRECTORY_SEPARATOR . 'carry.json');
    $oneWay = CarryState::profile('readable', false, true);
    $envelope = Carry::write(Keys::fromSeedHex($carryParties['A']['seed']), $carryPk('B'), Carry::READABLE, Carry::MESSAGE, 0, str_repeat("\0", 8), 1, 1794700300, 'hei');
    $check($carryCode(static fn () => $receiver->deliver($envelope, [$carryPk('A')], $oneWay, 0, 1794700000)) === 'carry_storage_failed',
        'a write that fails gives no receipt');
    $saved = CarryState::$maxRecords;
    CarryState::$maxRecords = 3;
    try {
        $full = new CarryState(Keys::fromSeedHex($carryParties['B']['seed']), $stateRoot . DIRECTORY_SEPARATOR . 'full' . DIRECTORY_SEPARATOR . 'carry.json');
        $firstOk = $full->deliver($envelope, [$carryPk('A')], $oneWay, 0, 1794700000)[0] === 'accepted';
        $second = Carry::write(Keys::fromSeedHex($carryParties['A']['seed']), $carryPk('B'), Carry::READABLE, Carry::MESSAGE, 0, str_repeat("\1", 8), 1, 1794700300, 'to');
        $check($firstOk && $carryCode(static fn () => $full->deliver($second, [$carryPk('A')], $oneWay, 1, 1794700001)) === 'carry_storage_full'
            && $full->deliver($envelope, [$carryPk('A')], $oneWay, 2, 1794700002)[0] === 'duplicate', 'a full state refuses a new session without deleting what it keeps');
    } finally {
        CarryState::$maxRecords = $saved;
    }

    echo "carry: the review of 2026-11-15\n";
    $keysA = Keys::fromSeedHex($carryParties['A']['seed']);
    $keysB = Keys::fromSeedHex($carryParties['B']['seed']);
    $pkA = $keysA->publicRaw();
    $pkB = $keysB->publicRaw();
    $T = 1794700000;
    $challenged = CarryState::profile('readable');
    $review = $stateRoot . DIRECTORY_SEPARATOR . 'review';
    $throws = static function (callable $attempt, string $class): bool {
        try {
            $attempt();
        } catch (\Throwable $thrown) {
            return $thrown instanceof $class;
        }

        return false;
    };
    $opened = static function (CarryState $atA, CarryState $atB, array $profile, float $mono = 0.0) use ($pkA, $pkB, $T): string {
        [$session, $ask] = $atA->ask($pkB, $profile, $mono);
        [, $challenge] = $atB->deliver($ask, [$pkA], $profile, $mono, $T);
        [, $opening] = $atA->deliver($challenge, [$pkB], $profile, $mono, $T);
        $atB->deliver($opening, [$pkA], $profile, $mono, $T);

        return $session;
    };

    // Finding 1: kept with its content until the program says it has it; a crash before offers it again.
    $path = $review . DIRECTORY_SEPARATOR . 'kept' . DIRECTORY_SEPARATOR . 'b.json';
    $sender = new CarryState($keysA);
    $session = $sender->oneWay($pkB, $T + 300);
    $envelope = $sender->send($pkB, $session, 'hei', $oneWay);
    $first = (new CarryState($keysB, $path))->deliver($envelope, [$pkA], $oneWay, 0, $T)[0];
    $key = CarryState::mark($pkA, $session, 1);
    $again = new CarryState($keysB, $path);
    $offered = $again->pending();
    $again->handedOver($key);
    $after = new CarryState($keysB, $path);
    [$result, $receipt] = $after->deliver($envelope, [$pkA], $oneWay, 0, $T);
    $check($first === 'accepted' && $offered === [[$key, $pkA, $session, 1, 'hei']] && $after->pending() === [] && $result === 'duplicate' && $receipt !== null,
        'a message is kept with its content until the program says it has it, and only its mark after that');

    // Finding 1, the sender: the same bytes and the tries left after a restart; used up is unknown, a late receipt confirms.
    $path = $review . DIRECTORY_SEPARATOR . 'sent' . DIRECTORY_SEPARATOR . 'a.json';
    $atA = new CarryState($keysA, $path);
    $session = $atA->oneWay($pkB, $T + 300);
    $envelope = $atA->send($pkB, $session, 'hei', $oneWay, 0, null, 3);
    $key = CarryState::mark($pkB, $session, 1);
    $again = new CarryState($keysA, $path);
    $resumed = $again->unconfirmed() === [[$key, $pkB, $session, 1, $envelope]] && $again->outcome($key) === 'waiting';
    $twice = $again->tried($key) && $again->tried($key);
    $kept = (new CarryState($keysA, $path))->durable['outbox'][$key]['tries'] === 3;
    $spent = !$again->tried($key) && $again->outcome($key) === 'unknown' && $again->unconfirmed() === [];
    [, $receipt] = (new CarryState($keysB))->deliver($envelope, [$pkA], $oneWay, 0, $T);
    $late = $again->deliver($receipt, [$pkB], $oneWay, 0, $T)[0] === 'accepted' && (new CarryState($keysA, $path))->outcome($key) === 'confirmed';
    $check($resumed && $twice && $kept && $spent && $late, 'a message sent keeps its bytes and tries until its receipt, and a receipt that comes late still confirms it');

    // Finding 2: a failed write never turns into a duplicate with a receipt for what was never kept.
    $inTheWay = $review . DIRECTORY_SEPARATOR . 'in-the-way';
    @mkdir($review, 0700, true);
    $broken = new CarryState($keysB, $inTheWay . DIRECTORY_SEPARATOR . 'carry.json');
    file_put_contents($inTheWay, 'not a folder');
    $envelope = Carry::write($keysA, $pkB, Carry::READABLE, Carry::MESSAGE, 0, str_repeat("\0", 8), 1, $T + 300, 'hei');
    $codes = [];
    for ($i = 0; $i < 2; $i++) {
        $codes[] = $carryCode(static fn () => $broken->deliver($envelope, [$pkA], $oneWay, 0, $T));
    }
    $codes[] = $carryCode(static fn () => $broken->prune($T));
    $check($codes === ['carry_storage_failed', 'carry_storage_failed', 'carry_storage_failed'], 'after a write fails nothing is answered until the state is read again');

    // Finding 3: a higher floor is kept before the answer, also a refusal, and also for bytes that are no envelope.
    $path = $review . DIRECTORY_SEPARATOR . 'floor' . DIRECTORY_SEPARATOR . 'b.json';
    (new CarryState($keysB, $path))->prune($T);
    $sender = new CarryState($keysA);
    $session = $sender->oneWay($pkB, $T + 300);
    $envelope = $sender->send($pkB, $session, 'sent', $oneWay);
    $deadlinePassed = $carryCode(static fn () => (new CarryState($keysB, $path))->deliver($envelope, [$pkA], $oneWay, 0, $T + 421)) === 'carry_deadline_passed';
    $again = new CarryState($keysB, $path);
    $kept = $again->durable['floor'] === $T + 421 && $carryCode(static fn () => $again->deliver($envelope, [$pkA], $oneWay, 0, $T)) === 'carry_deadline_passed';
    $jump = $carryCode(static fn () => $again->deliver('XXXX' . str_repeat("\0", 200), [$pkA], $oneWay, 0, $T + 900)) === 'carry_bad_magic'
        && (new CarryState($keysB, $path))->durable['floor'] === $T + 900;
    $check($deadlinePassed && $kept && $jump, 'the time floor is kept also when what came is refused');

    // Finding 7: 60 messages a minute from one partner, copies too, and 256 KiB; ten asks a minute.
    $atB = new CarryState($keysB);
    $sender = new CarryState($keysA);
    $session = $sender->oneWay($pkB, $T + 3000);
    $firstMessage = $sender->send($pkB, $session, '0', $oneWay);
    $results = [$atB->deliver($firstMessage, [$pkA], $oneWay, 0.0, $T)[0]];
    for ($i = 0; $i < 59; $i++) {
        $results[] = $atB->deliver($firstMessage, [$pkA], $oneWay, 0.5, $T)[0];
    }
    $copyLimited = $carryCode(static fn () => $atB->deliver($firstMessage, [$pkA], $oneWay, 0.5, $T)) === 'carry_rate_limited';
    $second = $sender->send($pkB, $session, '1', $oneWay);
    $newLimited = $carryCode(static fn () => $atB->deliver($second, [$pkA], $oneWay, 59.9, $T)) === 'carry_rate_limited';
    $later = $atB->deliver($second, [$pkA], $oneWay, 60.0, $T)[0] === 'accepted';
    $check($results === array_merge(['accepted'], array_fill(0, 59, 'duplicate')) && $copyLimited && $newLimited && $later,
        'sixty messages a minute from one partner, copies too, then carry_rate_limited');
    $atB = new CarryState($keysB);
    $session = $sender->oneWay($pkB, $T + 3000);
    $big = [];
    for ($n = 0; $n < 5; $n++) {
        $big[] = $sender->send($pkB, $session, str_repeat(chr($n), 60000), $oneWay);
    }
    $fits = true;
    for ($n = 0; $n < 4; $n++) {
        $fits = $fits && $atB->deliver($big[$n], [$pkA], $oneWay, 1.0, $T)[0] === 'accepted';
    }
    $check($fits && $carryCode(static fn () => $atB->deliver($big[4], [$pkA], $oneWay, 2.0, $T)) === 'carry_rate_limited', '256 KiB a minute from one partner');
    $atA = new CarryState($keysA);
    $atB = new CarryState($keysB);
    for ($n = 0; $n < 10; $n++) {
        $opened($atA, $atB, $challenged, (float) $n);
    }
    [, $ask] = $atA->ask($pkB, $challenged, 10.0);
    $limited = $carryCode(static fn () => $atB->deliver($ask, [$pkA], $challenged, 10.0, $T)) === 'carry_rate_limited';
    [, $ask] = $atA->ask($pkB, $challenged, 61.0);
    $check($limited && $atB->deliver($ask, [$pkA], $challenged, 61.0, $T)[0] === 'accepted', 'ten asks a minute from one partner');

    // Finding 8: only challenges that wait inside their window count; spent ones keep their code while remembered.
    $atA = new CarryState($keysA);
    $atB = new CarryState($keysB);
    $spread = true;
    for ($n = 0; $n < 9; $n++) {
        [, $ask] = $atA->ask($pkB, $challenged, 100.0 * $n);
        $spread = $spread && $atB->deliver($ask, [$pkA], $challenged, 100.0 * $n, $T)[0] === 'accepted';
    }
    for ($n = 0; $n < 8; $n++) {
        [, $ask] = $atA->ask($pkB, $challenged, 1000.0);
        $atB->deliver($ask, [$pkA], $challenged, 1000.0, $T);
    }
    [, $ask] = $atA->ask($pkB, $challenged, 1000.0);
    $check($spread && $carryCode(static fn () => $atB->deliver($ask, [$pkA], $challenged, 1000.0, $T)) === 'carry_rate_limited',
        'challenges used or past their window do not fill the limit, and eight that wait do');
    $atA = new CarryState($keysA);
    $atB = new CarryState($keysB);
    [$session, $ask] = $atA->ask($pkB, $challenged, 0.0);
    [, $challenge] = $atB->deliver($ask, [$pkA], $challenged, 0.0, $T);
    [, $opening] = $atA->deliver($challenge, [$pkB], $challenged, 0.0, $T);
    $codes = [$carryCode(static fn () => $atB->deliver($opening, [$pkA], $challenged, 61.0, $T))];
    for ($n = 1; $n <= CarryState::SPENT_KEPT_PER_PARTNER; $n++) {
        [, $ask] = $atA->ask($pkB, $challenged, 61.0 * $n);
        $atB->deliver($ask, [$pkA], $challenged, 61.0 * $n, $T);
    }
    $codes[] = $carryCode(static fn () => $atB->deliver($opening, [$pkA], $challenged, 61.0 * 65, $T));
    [, $ask] = $atA->ask($pkB, $challenged, 61.0 * 66);
    $atB->deliver($ask, [$pkA], $challenged, 61.0 * 66, $T);
    $codes[] = $carryCode(static fn () => $atB->deliver($opening, [$pkA], $challenged, 61.0 * 66, $T));
    $check($codes === ['carry_nonce_expired', 'carry_nonce_expired', 'carry_nonce_unknown'],
        'a spent challenge has its own code while it is remembered, and is unknown after: refused either way');

    // Finding 10: the count of challenges stops at 2^53 - 1, never rounded or wrapped.
    $path = $review . DIRECTORY_SEPARATOR . 'count' . DIRECTORY_SEPARATOR . 'b.json';
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, str_replace('"challenges":0', '"challenges":' . (CarryState::SAFE - 1), $empty));
    $atA = new CarryState($keysA);
    $atB = new CarryState($keysB, $path);
    [, $ask] = $atA->ask($pkB, $challenged, 0.0);
    $last = $atB->deliver($ask, [$pkA], $challenged, 0.0, $T)[0] === 'accepted' && json_decode((string) file_get_contents($path), true)['challenges'] === CarryState::SAFE;
    [, $ask] = $atA->ask($pkB, $challenged, 1.0);
    $check($last && $carryCode(static fn () => $atB->deliver($ask, [$pkA], $challenged, 1.0, $T)) === 'carry_storage_full'
        && (new CarryState($keysB, $path))->durable['challenges'] === CarryState::SAFE, 'the count of challenges stops at 2^53 - 1');

    // Finding 11: numbers outside their four bytes are refused, never cut down by pack.
    $outside = [[2 ** 32, 0], [-1, 0], [1, 2 ** 32], [1, -1]];
    $allRefused = true;
    foreach ($outside as [$counter, $deadline]) {
        $allRefused = $allRefused && $throws(static fn () => Carry::write($keysA, $pkB, Carry::READABLE, Carry::MESSAGE, 0, str_repeat("\0", 8), $counter, $deadline, 'x'), \ValueError::class);
    }
    $atA = new CarryState($keysA);
    $session = $opened($atA, new CarryState($keysB), $challenged);
    $unchanged = $throws(static fn () => $atA->send($pkB, $session, 'x', $challenged, 2 ** 32), \ValueError::class)
        && $atA->counterOf($pkB, $session) === 0 && $atA->durable['outbox'] === [];
    $check($allRefused && $unchanged, 'numbers outside their four bytes are refused, and a deadline outside them changes nothing in the state');

    // Finding 12: the first message without a challenge needs room for its session, its used id and its mark.
    $sender = new CarryState($keysA);
    $session = $sender->oneWay($pkB, $T + 300);
    $envelope = $sender->send($pkB, $session, 'hei', $oneWay);
    $saved = CarryState::$maxRecords;
    try {
        CarryState::$maxRecords = 2;
        $atB = new CarryState($keysB);
        $tooFull = $carryCode(static fn () => $atB->deliver($envelope, [$pkA], $oneWay, 0, $T)) === 'carry_storage_full' && $atB->durable['incoming'] === [];
        CarryState::$maxRecords = 3;
        $check($tooFull && $atB->deliver($envelope, [$pkA], $oneWay, 0, $T)[0] === 'accepted', 'the first message without a challenge needs room for its three records');
    } finally {
        CarryState::$maxRecords = $saved;
    }

    // Finding 13: a profile changed later can shorten the lifetime of a session, never lengthen it.
    $short = CarryState::profile('readable', true, false, 600);
    $long = CarryState::profile('readable', true, false, 86400);
    $atA = new CarryState($keysA);
    $atB = new CarryState($keysB);
    $session = $opened($atA, $atB, $short, 0.0);
    $envelope = $atA->send($pkB, $session, 'x', $short);
    $notLonger = $carryCode(static fn () => $atB->deliver($envelope, [$pkA], $long, 601.0, $T)) === 'carry_session_expired';
    $session = $opened($atA, $atB, $long, 1000.0);
    $envelope = $atA->send($pkB, $session, 'y', $long);
    $shorter = $carryCode(static fn () => $atB->deliver($envelope, [$pkA], $short, 1601.0, $T)) === 'carry_session_expired';
    $check($notLonger && $shorter && $atB->deliver($envelope, [$pkA], $long, 1601.0, $T)[0] === 'accepted', 'a session keeps the lifetime it was opened with');

    // A sealed receipt for the same message has the same bytes every time, so the frames of several copies add up.
    $sealedOneWay = CarryState::profile('sealed', false, true);
    $sender = new CarryState($keysA);
    $atB = new CarryState($keysB);
    $session = $sender->oneWay($pkB, $T + 300);
    $first = $sender->send($pkB, $session, 'en', $sealedOneWay);
    $second = $sender->send($pkB, $session, 'to', $sealedOneWay);
    $receiptOne = $atB->deliver($first, [$pkA], $sealedOneWay, 0.0, $T)[1];
    $receiptTwo = $atB->deliver($first, [$pkA], $sealedOneWay, 1.0, $T)[1];
    $check($receiptOne === $receiptTwo && $atB->deliver($second, [$pkA], $sealedOneWay, 2.0, $T)[1] !== $receiptOne,
        'a sealed receipt for the same message has the same bytes every time, and another message gets another');

    echo "carry: the follow-up check of 2026-11-15\n";

    // Finding 3: tidying takes what guards against a replay when its time is up, never a message the program has not taken.
    $path = $review . DIRECTORY_SEPARATOR . 'untaken' . DIRECTORY_SEPARATOR . 'b.json';
    $sender = new CarryState($keysA);
    $session = $sender->oneWay($pkB, $T + 300);
    $envelope = $sender->send($pkB, $session, 'hei', $oneWay);
    $atB = new CarryState($keysB, $path);
    $first = $atB->deliver($envelope, [$pkA], $oneWay, 0, $T)[0];
    $key = CarryState::mark($pkA, $session, 1);
    $whole = [[$key, $pkA, $session, 1, 'hei']];
    $atB->prune($T + 300 + CarryState::SKEW);
    $before = (new CarryState($keysB, $path))->pending() === $whole;
    $atB->prune($T + 300 + CarryState::SKEW + 1);
    $again = new CarryState($keysB, $path);
    $check($first === 'accepted' && $before && $again->pending() === $whole,
        'a message nobody has taken outlives the guard against replays: the receipt said it was kept, and the program has not said it has it');
    try {
        $copy = $again->deliver($envelope, [$pkA], $oneWay, 0, $T + 300 + CarryState::SKEW + 1)[0];
    } catch (CarryRefused $refused) {
        $copy = $refused->carryCode;
    }
    $again->handedOver($key);
    $again->prune($T + 300 + CarryState::SKEW + 1);
    $after = new CarryState($keysB, $path);
    $check($copy === 'duplicate' && $after->pending() === [] && $after->durable['incoming'] === [] && $after->durable['stored'] === []
        && $carryCode(static fn () => $after->deliver($envelope, [$pkA], $oneWay, 0, $T)) === 'carry_deadline_passed',
        'it is kept with its session, so a copy is still known as one, and once taken it goes with its session at the next tidying');

    // Finding 4: a receipt whose save failed is not shown as confirmed; what only reads refuses as deliver does.
    $path = $review . DIRECTORY_SEPARATOR . 'unsaved' . DIRECTORY_SEPARATOR . 'a.json';
    $atA = new CarryState($keysA, $path);
    $receiver = new CarryState($keysB);
    $session = $atA->oneWay($pkB, $T + 300);
    $envelope = $atA->send($pkB, $session, 'hei', $oneWay);
    $key = CarryState::mark($pkB, $session, 1);
    [, $receipt] = $receiver->deliver($envelope, [$pkA], $oneWay, 0, $T);
    mkdir($path . '.tmp');
    $codes = [$carryCode(static fn () => $atA->deliver($receipt, [$pkB], $oneWay, 0, $T))];
    foreach ([static fn () => $atA->outcome($key), static fn () => $atA->unconfirmed(), static fn () => $atA->pending(), static fn () => $atA->kept($key),
              static fn () => $atA->giveUp($key)] as $attempt) {
        $codes[] = $carryCode($attempt);
    }
    rmdir($path . '.tmp');
    $onFile = (new CarryState($keysA, $path))->outcome($key);
    $atA->restart();
    $check($codes === array_fill(0, 6, 'carry_storage_failed') && $onFile === 'waiting' && $atA->outcome($key) === 'waiting' && $atA->deliver($receipt, [$pkB], $oneWay, 0, $T)[0] === 'accepted'
        && (new CarryState($keysA, $path))->outcome($key) === 'confirmed',
        'after a write fails nothing is read from memory either: outcome, unconfirmed, pending, kept and giveUp refuse as deliver does, until the state is read again');

    // Finding 4, the receiver: deliver refuses with carry_storage_failed, and pending shows nothing of what never reached the file.
    $inTheWay = $review . DIRECTORY_SEPARATOR . 'in-the-way-of-pending';
    $receiver = new CarryState($keysB, $inTheWay . DIRECTORY_SEPARATOR . 'carry.json');
    file_put_contents($inTheWay, 'not a folder');
    $envelope = Carry::write($keysA, $pkB, Carry::READABLE, Carry::MESSAGE, 0, str_repeat("\0", 8), 1, $T + 300, 'hei');
    $check($carryCode(static fn () => $receiver->deliver($envelope, [$pkA], $oneWay, 0, $T)) === 'carry_storage_failed'
        && $carryCode(static fn () => $receiver->pending()) === 'carry_storage_failed', 'a message that was not kept is not listed as waiting after the write failed');

    // Finding 2, in the state: given up, the bytes go and the outcome is unknown; the hash stays, so a late receipt confirms it.
    $path = $review . DIRECTORY_SEPARATOR . 'given-up' . DIRECTORY_SEPARATOR . 'a.json';
    $atA = new CarryState($keysA, $path);
    $receiver = new CarryState($keysB);
    $session = $atA->oneWay($pkB, $T + 300);
    $envelope = $atA->send($pkB, $session, 'hei', $oneWay);
    $key = CarryState::mark($pkB, $session, 1);
    $once = $atA->giveUp($key) && !$atA->giveUp($key);
    $again = new CarryState($keysA, $path);
    $gone = $again->outcome($key) === 'unknown' && $again->unconfirmed() === [] && $again->durable['outbox'][$key]['envelope'] === null && !$again->tried($key);
    [, $receipt] = $receiver->deliver($envelope, [$pkA], $oneWay, 0, $T);
    $check($once && $gone && $again->deliver($receipt, [$pkB], $oneWay, 0, $T)[0] === 'accepted' && $again->outcome($key) === 'confirmed',
        'a message given up is never sent again and keeps its mark and hash, so a receipt that comes late confirms it');

    // Finding 10: the 64 in all are counted with each challenge's own window, not with the window of the one who asks now.
    $wide = CarryState::profile('readable', true, false, 3600, 600);
    $narrow = CarryState::profile('readable', true, false, 600, 60);
    $partners = [];
    for ($n = 1; $n <= 9; $n++) {
        $partners[] = Keys::fromSeedHex(str_repeat(sprintf('%02x', $n), 32));
    }
    $ninth = $partners[8]->publicRaw();
    $known = array_map(static fn (Keys $keys): string => $keys->publicRaw(), $partners);
    $profileOf = static fn (string $from): array => $from === $ninth ? $narrow : $wide;
    $atB = new CarryState($keysB);
    $openings = [];
    $given = true;
    foreach (array_slice($partners, 0, 8) as $partner) {
        $asking = new CarryState($partner);
        for ($n = 0; $n < CarryState::MAX_WAITING_PER_PARTNER; $n++) {
            [, $ask] = $asking->ask($pkB, $wide, 0.0);
            [$result, $challenge] = $atB->deliver($ask, $known, $profileOf, 0.0, $T);
            $given = $given && $result === 'accepted';
        }
        $openings[] = [$asking, $challenge];
    }
    $late = new CarryState($partners[8]);
    [, $ask] = $late->ask($pkB, $narrow, 100.0);
    $full = $carryCode(static fn () => $atB->deliver($ask, $known, $profileOf, 100.0, $T)) === 'carry_rate_limited';
    // The 64 still wait: an open for one of them is taken at the same moment.
    [$asking, $challenge] = $openings[0];
    [, $opening] = $asking->deliver($challenge, [$pkB], $wide, 100.0, $T);
    $taken = $atB->deliver($opening, $known, $profileOf, 100.0, $T)[0] === 'accepted';
    // One is used now, so there is room for one more.
    [, $ask] = $late->ask($pkB, $narrow, 100.0);
    $room = $atB->deliver($ask, $known, $profileOf, 100.0, $T)[0] === 'accepted';
    // And past their own window the old ones no longer count, whatever window the asker has.
    for ($n = 0; $n < CarryState::MAX_WAITING_PER_PARTNER - 1; $n++) {
        [, $ask] = $late->ask($pkB, $narrow, 700.0);
        $room = $room && $atB->deliver($ask, $known, $profileOf, 700.0, $T)[0] === 'accepted';
    }
    $check($given && $full && $taken && $room, 'every challenge waits in the window it was given under, whatever window the one who asks now has');
} finally {
    $removeTree($stateRoot);
}
