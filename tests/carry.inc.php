<?php

declare(strict_types=1);

/*
 * aamio-carry-v1, the carrier envelope of contract 3, against its vectors.
 *
 * tests/carry_v1_vectors.json is a copy of docs/aamio-carry-v1-vektorer-2026-11-15.json
 * in the service repository, made there by tools/carry-v1-vectors.py from the
 * specification. Every envelope built from its inputs, every refusal read to
 * its code, the frames, the limits and what a writer refuses; and every
 * scenario with a CarryState kept in a real file.
 */

use Aamio\Carry;
use Aamio\CarryRefused;
use Aamio\CarryState;
use Aamio\Keys;

$carryVectors = json_decode((string) file_get_contents(__DIR__ . '/carry_v1_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
$carryParties = $carryVectors['parties'];
$carryKeys = static fn (string $name): Keys => Keys::fromSeedHex($carryParties[$name]['seed']);
$carryPk = static fn (string $name): string => hex2bin($carryParties[$name]['pk']);
$carryModes = ['readable' => Carry::READABLE, 'sealed' => Carry::SEALED];
$carryPattern = static function (int $length): string {
    $bytes = '';
    for ($i = 0; $i < $length; $i++) {
        $bytes .= chr($i % 256);
    }

    return $bytes;
};
$carryCode = static function (callable $attempt): ?string {
    try {
        $attempt();
    } catch (CarryRefused $refused) {
        return $refused->carryCode;
    }

    return null;
};

echo "carry: constants and parties\n";
$constants = $carryVectors['constants'];
$check(bin2hex(Carry::DOMAIN) === $constants['domain'] && bin2hex(Carry::NONCE_LABEL) === $constants['nonce_label']
    && bin2hex(Carry::ESK_LABEL) === $constants['esk_label'] && bin2hex(Carry::CHALLENGE_LABEL) === $constants['challenge_label']
    && bin2hex(Carry::MAGIC) === $constants['magic'] && bin2hex(Carry::FRAME_MAGIC) === $constants['frame_magic']
    && Carry::MAX_CONTENT === $constants['max_content'] && Carry::CANDIDATES === $constants['candidates'], 'the constants are the ones the vectors were made with');
$partiesOk = true;
foreach ($carryParties as $name => $party) {
    if (!isset($party['seed'])) {
        continue;
    }
    $own = $carryKeys($name);
    $partiesOk = $partiesOk && bin2hex($own->publicRaw()) === $party['pk'] && $own->public === $party['pk_b64url']
        && bin2hex($own->curvePublicRaw()) === $party['xpk'] && bin2hex(Carry::curvePublic($own->publicRaw())) === $party['xpk']
        && bin2hex(Carry::hint($own->publicRaw())) === $party['hint'];
}
$check($partiesOk, 'every party is its seed: key, X25519 key and hint');

echo "carry: envelopes\n";
foreach ($carryVectors['envelopes'] as $row) {
    $binding = isset($row['binding']) ? hex2bin($row['binding']) : null;
    $envelope = Carry::write($carryKeys($row['sender']), $carryPk($row['recipient']), $carryModes[$row['mode']], $row['kind'], $row['flags'],
        hex2bin($row['session']), $row['counter'], $row['deadline'], hex2bin($row['content']), $binding, isset($row['rnd']) ? hex2bin($row['rnd']) : null);
    $got = Carry::read($envelope, $carryKeys($row['recipient']), [$carryPk($row['sender']), $carryPk('C')], static fn ($pk, $s) => $binding);
    $framesOk = true;
    foreach ($row['frames'] as $limit => $frames) {
        $framesOk = $framesOk && array_map('bin2hex', Carry::split($envelope, (int) $limit)) === $frames
            && Carry::reassemble(array_merge(array_reverse(array_map('hex2bin', $frames)), [hex2bin($frames[0])])) === $envelope;
    }
    $check(bin2hex($envelope) === $row['envelope'] && hash('sha256', $envelope) === $row['sha256'] && $got['sender'] === $carryPk($row['sender'])
        && $got['content'] === hex2bin($row['content']) && $framesOk, $row['name']);
}

echo "carry: readings and refusals\n";
foreach ($carryVectors['readings'] as $row) {
    $got = Carry::read(hex2bin($row['envelope']), $carryKeys($row['receiver']), array_map($carryPk, $row['known']));
    $check($got['sender'] === $carryPk($row['sender']), $row['name']);
}
$refusalsOk = 0;
foreach ($carryVectors['refusals'] as $row) {
    $table = [];
    foreach ($row['bindings'] ?? [] as $entry) {
        $table[$carryPk($entry['sender']) . hex2bin($entry['session'])] = hex2bin($entry['nonce']);
    }
    $code = $carryCode(static fn () => Carry::read(hex2bin($row['envelope']), $carryKeys($row['receiver']), array_map($carryPk, $row['known']),
        static fn ($pk, $s) => $table[$pk . $s] ?? null));
    if ($code === $row['code']) {
        $refusalsOk++;
    } else {
        $check(false, $row['name'] . ': ' . var_export($code, true) . ', not ' . $row['code']);
    }
}
$check($refusalsOk === count($carryVectors['refusals']), sprintf('all %d refusals give the code of the first check that fails', count($carryVectors['refusals'])));

echo "carry: limits and frames\n";
foreach ($carryVectors['boundaries'] as $row) {
    $content = $carryPattern($row['content_length']);
    $sender = $carryKeys($row['sender']);
    $recipient = $carryKeys($row['recipient']);
    $binding = isset($row['binding']) ? hex2bin($row['binding']) : null;
    if (isset($row['code'])) {
        $head = Carry::MAGIC . chr(Carry::READABLE) . chr($row['kind']) . chr($row['flags']) . Carry::hint($recipient->publicRaw()) . Carry::hint($sender->publicRaw())
            . hex2bin($row['session']) . pack('N', $row['counter']) . pack('N', $row['deadline']) . pack('n', strlen($content) & 0xFFFF) . $content;
        $envelope = $head . $sender->signRaw(Carry::DOMAIN . $sender->publicRaw() . $recipient->publicRaw() . Carry::bind(null) . $head);
        $check(strlen($envelope) === $row['length'] && hash('sha256', $envelope) === $row['sha256']
            && $carryCode(static fn () => Carry::read($envelope, $recipient, [$sender->publicRaw()])) === $row['code'], $row['name']);
        continue;
    }
    $envelope = Carry::write($sender, $recipient->publicRaw(), $carryModes[$row['mode']], $row['kind'], $row['flags'], hex2bin($row['session']),
        $row['counter'], $row['deadline'], $content, $binding, isset($row['rnd']) ? hex2bin($row['rnd']) : null);
    $frames = Carry::split($envelope, 512);
    $check(strlen($envelope) === $row['length'] && hash('sha256', $envelope) === $row['sha256'] && count($frames) === $row['frames_512']['count']
        && hash('sha256', implode('', $frames)) === $row['frames_512']['sha256_of_all']
        && Carry::read($envelope, $recipient, [$sender->publicRaw()], static fn ($pk, $s) => $binding)['content'] === $content, $row['name']);
}
foreach ($carryVectors['writer_refusals'] as $row) {
    $code = isset($row['frame_limit'])
        ? $carryCode(static fn () => Carry::split(str_repeat("\0", $row['envelope_length']), $row['frame_limit']))
        : $carryCode(static fn () => Carry::write($carryKeys('A'), $carryPk('B'), $carryModes[$row['mode']], Carry::MESSAGE, 0, str_repeat("\0", 8), 1, 1794700300, $carryPattern($row['content_length'])));
    $check($code === $row['code'], $row['name']);
}
foreach ($carryVectors['frame_refusals'] as $row) {
    $check($carryCode(static fn () => Carry::reassemble(array_map('hex2bin', $row['frames']))) === $row['code'], $row['name']);
}
$first = Carry::write($carryKeys('A'), $carryPk('B'), Carry::SEALED, Carry::MESSAGE, 0, str_repeat("\0", 8), 1, 1794700300, 'hei');
$second = Carry::write($carryKeys('A'), $carryPk('B'), Carry::SEALED, Carry::MESSAGE, 0, str_repeat("\0", 8), 1, 1794700300, 'hei');
$check(substr($first, 9, 32) !== substr($second, 9, 32) && Carry::read($first, $carryKeys('B'), [$carryPk('A')])['content'] === 'hei'
    && Carry::read($second, $carryKeys('B'), [$carryPk('A')])['content'] === 'hei', 'a sealed envelope takes new random bytes each time, and both open');

require __DIR__ . '/carry-state.inc.php';

require __DIR__ . '/carrier-refusals.inc.php';

require __DIR__ . '/carry-transport.inc.php';

require __DIR__ . '/transport-lan.inc.php';
