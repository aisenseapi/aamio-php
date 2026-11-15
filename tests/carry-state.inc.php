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
} finally {
    $removeTree($stateRoot);
}
