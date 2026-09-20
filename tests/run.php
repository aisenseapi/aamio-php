<?php

declare(strict_types=1);

/**
 * The offline tests: every vector the service and the other clients share,
 * reproduced here with nothing in between, then the client's own judgement
 * around them. No network. Run with a PHP that has sodium and curl:
 *
 *   php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

use Aamio\Address;
use Aamio\Codec;
use Aamio\Gate;
use Aamio\Keys;
use Aamio\Receipt;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  ok    $label\n";
    } else {
        $failed++;
        echo "  FAIL  $label\n";
    }
};

$vectors = json_decode((string) file_get_contents(__DIR__ . '/vectors.json'), true, 512, JSON_THROW_ON_ERROR);

echo "encodings\n";
$check(Codec::sha256hex('abc') === $vectors['sha256_abc'], 'sha256 of abc');
$check(Codec::base32(hash('sha256', 'abc', true)) === Codec::base32(hash('sha256', 'abc', true)) && strlen(Codec::base32('')) === 0, 'base32 is deterministic and empty for nothing');
$check(Codec::b64url("\xfb\xff") === '-_8' && Codec::unb64url('-_8') === "\xfb\xff", 'base64url without padding, both ways');
$check(Codec::unb64url('+/8=') === "\xfb\xff", 'standard base64 with padding is accepted too, as the service does');

echo "addresses\n";
$check(Address::w($vectors['id']) === $vectors['w'], 'w(' . $vectors['id'] . ') = ' . $vectors['w']);
$check(Address::w('aamio0000000000000000000ok') === '5d6gubrpdewztqru2nmi', 'w(aamio…ok) matches the reference vector');
$check(Address::w('foobar' . str_repeat('0', 14)) !== '' && Address::isId(Address::newId()) && strlen(Address::newId()) === 26, 'a fresh id is 26 characters of the alphabet');
$check(Address::isW(Address::w(Address::newId())) && !Address::isW('not-an-address'), 'w is 20 characters of a-z and 2-7');
$threw = false;
try {
    Address::w('short');
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check($threw, 'an id under 20 characters is refused before it is hashed');
$check(Address::scope($vectors['scope']['key']) === $vectors['scope']['address'] && Address::w($vectors['scope']['key']) === $vectors['scope']['thread_w_of_the_same_string'], 'scope(' . $vectors['scope']['key'] . ') = ' . $vectors['scope']['address'] . ', never the thread address of the same string');
$scopeKey = Address::newScopeKey();
$check(Address::isScopeKey($scopeKey) && strlen($scopeKey) === 26 && Address::isW(Address::scope($scopeKey)) && !Address::isScopeKey(Address::scope($scopeKey)), 'a new scope key has the form, and its address is never a key');
$check(!Address::isScopeKey($scopeKey . "
") && !Address::isW(Address::scope($scopeKey) . "
") && !Address::isId(Address::newId() . "
") && !Codec::isKey(Keys::generate()->public . "
"), 'a key, an address, an id or a public key with a line break after it is not one: $ matched before a trailing newline');
$threw = false;
try {
    Address::scope($vectors['scope']['address']);
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check($threw, 'an address is refused where the key goes');

echo "keys\n";
$a = Keys::fromSeedHex($vectors['a']['seed']);
$b = Keys::fromSeedHex($vectors['b']['seed']);
$check($a->public === $vectors['a']['public'], 'key A from its seed');
$check($a->hash === $vectors['a']['hash'] && $a->hashPrefix === substr($vectors['a']['hash'], 0, 8), 'hash of A over the raw bytes, and its prefix');
$check($b->public === $vectors['b']['public'], 'key B from its seed');
$check(bin2hex(Keys::curvePublic($a->public)) === $vectors['a']['curvePublic'], 'X25519 public of A, derived the libsodium way');
$check(bin2hex(Keys::curvePublic($b->public)) === $vectors['b']['curvePublic'], 'X25519 public of B');
$check(Keys::threadSigningInput($vectors['w'], $vectors['body']) === $vectors['signInput'], 'the thread signing input');
$check($a->sign($vectors['signInput']) === $vectors['signature'], 'the signature of A over it, byte for byte');
$check(Keys::verify($a->public, $vectors['signature'], $vectors['signInput']), 'and it verifies');
$check(!Keys::verify($b->public, $vectors['signature'], $vectors['signInput']), 'not with B');
$check(!Keys::verify($a->public, $vectors['signature'], $vectors['signInput'] . 'x'), 'not over other bytes');
$check(Keys::generate()->public !== Keys::generate()->public && Codec::isKey(Keys::generate()->public), 'generated keys differ and have the shape');
$check(Keys::fromSeed($a->seed())->public === $a->public, 'a key rebuilt from its seed is the same key');

echo "sealing\n";
$opened = $b->open($a->public, $vectors['envelopeFromAToB']);
$check($opened === $vectors['plaintext'], 'B opens the envelope A sealed, made by PyNaCl');
$envelope = $a->seal($b->public, 'fra php til hvem som helst');
$parsed = json_decode($envelope, true);
$check(array_keys($parsed) === ['e2ee', 'to', 'nonce', 'ct'] && $parsed['e2ee'] === 'nacl.box.v1' && $parsed['to'] === $b->hashPrefix, 'an envelope has exactly the four fields, in the documented order');
$check(strlen(Codec::unb64url($parsed['nonce'])) === 24, 'the nonce is 24 bytes');
$check($b->open($a->public, $envelope) === 'fra php til hvem som helst', 'B opens what A sealed here');
$check($a->seal($b->public, 'x') !== $a->seal($b->public, 'x'), 'a fresh nonce every time');
$threw = false;
try {
    $a->open($b->public, $envelope);
} catch (\InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'sealed to');
}
$check($threw, 'A cannot open an envelope sealed to B, and is told whom it was sealed to');
$threw = false;
try {
    $b->open(Keys::generate()->public, $envelope);
} catch (\RuntimeException) {
    $threw = true;
}
$check($threw, 'and it does not open with the wrong sender key');
$check(Keys::isEnvelope($envelope) && !Keys::isEnvelope('{"hello":"plain"}') && !Keys::isEnvelope('text'), 'an envelope is known by its shape');

echo "receipt\n";
$receipt = $vectors['receipt'];
$check(Receipt::root($receipt['messages']) === $receipt['root'], 'the root recomputed from the lines is the published root');
$verify = Receipt::verify($receipt);
$check($verify['root_adds_up'] && $verify['commitment_matches'], 'root_adds_up and commitment_matches on a real receipt');
$shuffled = array_reverse($receipt['messages']);
$check(Receipt::root($shuffled) === $receipt['root'], 'the order the lines arrive in does not matter, seq does');
$broken = $receipt;
$broken['messages'][0]['sha256'] = str_repeat('0', 64);
$check(!Receipt::verify($broken)['root_adds_up'], 'one changed hash breaks the root');
$hashes = array_map(static fn (array $m): string => $m['sha256'], $receipt['messages']);
$check(Receipt::verify($receipt, $hashes)['local_hashes_match'] === true, 'local hashes that match say so');
$check(Receipt::verify($receipt, [$hashes[0]])['local_hashes_match'] === null, 'fewer local hashes than the receipt counts is null, not a failure');
$check(Receipt::verify($receipt, [str_repeat('1', 64), $hashes[1]])['local_hashes_match'] === false, 'and a different local hash is false');

echo "gate: the vectors\n";
$g = [
    'w' => 'b4netymg7r5nnt2yiscp',
    'key' => str_repeat('A', 43),
    'body' => '{"post":"abc","reply_to":"xyz","text":"hei"}',
    'body_sha256' => '36751f20147f74e3dfbaf829fb8f04ea9ed596268bd685a69fdfa5992fddd6b8',
];
$check(Codec::sha256hex($g['body']) === $g['body_sha256'], 'the vector body hashes to the published sha256');
$signed = Gate::powDigest($g['w'], $g['key'], $g['body_sha256'], '7036');
$check(bin2hex($signed) === '00003a2ac769f2265d621969d9ff1feaaa2b9dcc6f006b6adae1d22c2db8a842' && Gate::zeroBits($signed) === 18, 'signed: nonce 7036 gives the published proof_id with 18 leading zero bits');
$unsigned = Gate::powDigest($g['w'], '', $g['body_sha256'], '91617');
$check(bin2hex($unsigned) === '000018b5cc286cf27d2c97296aff9e2e60db0c165e7cf3af7a44857423c08612' && Gate::zeroBits($unsigned) === 19, 'unsigned: nonce 91617 gives the published proof_id with 19 bits');
$check(str_contains(Gate::powInput($g['w'], '', $g['body_sha256'], '1'), "\n\n"), 'an unsigned message puts an empty key in the input, so two line breaks meet');
$elsewhere = [
    Gate::zeroBits(Gate::powDigest($g['w'], str_repeat('B', 43), $g['body_sha256'], '7036')),
    Gate::zeroBits(Gate::powDigest($g['w'], '', $g['body_sha256'], '7036')),
    Gate::zeroBits(Gate::powDigest('aaaaaaaaaaaaaaaaaaaa', $g['key'], $g['body_sha256'], '7036')),
    Gate::zeroBits(Gate::powDigest($g['w'], $g['key'], str_repeat('0', 64), '7036')),
];
$check(max($elsewhere) < 16, 'the signed nonce is worth nothing with another key, no key, another address or another body');
$check(Gate::zeroBits(str_repeat("\0", 32)) === 256 && Gate::zeroBits("\x00\x0f" . str_repeat("\xff", 30)) === 12 && Gate::zeroBits("\x80" . str_repeat("\0", 31)) === 0 && Gate::zeroBits("\x01" . str_repeat("\xff", 31)) === 7, 'zero bits are counted from the top bit of the first byte');
$nonce = Gate::solve($g['w'], $g['key'], $g['body'], 8);
$check(Gate::zeroBits(Gate::powDigest($g['w'], $g['key'], $g['body_sha256'], $nonce)) >= 8 && Gate::isNonce($nonce), 'solve finds a nonce that reaches the bits');
$boardNonce = Gate::solveBoard($g['key'], $g['body'], 6);
$check(Gate::zeroBits(Gate::boardPowDigest($g['key'], $g['body_sha256'], $boardNonce)) >= 6, 'board work has its own input and solves the same way');
$check(str_starts_with(Gate::boardPowInput($g['key'], $g['body_sha256'], '1'), "aamio-board-pow-v1\n"), 'and it is computed over aamio-board-pow-v1');

echo "gate: canonical form\n";
$check(Gate::canonical(['advise' => ['pow' => ['covers' => 1, 'bits' => 16]], 'require' => []]) === '{"advise":{"pow":{"bits":16,"covers":1}}}', 'keys sorted, empty bucket removed');
$check(Gate::hash(['advise' => ['pow' => ['covers' => 1, 'bits' => 16]], 'require' => []]) === 'de2a8fd4c8d7cbf9f6839c810632caf2c4b40fd8ba99ad19678f6c5b063d4d64', 'and its hash is the published gate_hash');
$check(Gate::canonical(['require' => [], 'advise' => []]) === '{}' && Gate::hash(['require' => [], 'advise' => []]) === '44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a', 'an empty gate is {} with the hash of {}');
$check(Gate::canonical(['require' => new stdClass(), 'advise' => ['pow' => ['bits' => 16, 'covers' => 1]]]) === '{"advise":{"pow":{"bits":16,"covers":1}}}', 'an empty object bucket is removed like an empty array');
$points = [0x61, 0x22, 0x62, 0x5C, 0x63, 0x2F, 0x64, 0x01, 0x65, 0x1F, 0x66, 0x0A, 0x67, 0xE6, 0x68, 0x2028, 0x69, 0x1F600, 0x6A, 0x7F];
$string = implode('', array_map(static fn (int $p): string => mb_chr_polyfill($p), $points));
$canonical = Gate::canonical(['s' => $string]);
$check(bin2hex($canonical) === '7b2273223a22615c22625c5c632f645c7530303031655c7530303166665c6e67c3a668e280a869f09f98806a7f227d', 'a string escapes only where JSON requires it: quotes, backslash, controls; slash, æ, U+2028, an emoji and DEL stay raw');
$check(hash('sha256', $canonical) === 'b089754be373b84fb2b5fd2d6db856363986712915c98a5f7a412ff38b313b63', 'and hashes to the published value');

echo "gate: the plan\n";
$plan = Gate::plan(null);
$check($plan['bits'] === null && $plan['stop'] === null && $plan['notes'] === [], 'no gate: nothing to do');
$plan = Gate::plan(['advise' => ['pow' => ['bits' => 16, 'covers' => 1]]]);
$check($plan['bits'] === 16 && $plan['stop'] === null, 'advised 16 bits are done without asking');
$plan = Gate::plan(['advise' => ['pow' => ['bits' => 19]]]);
$check($plan['bits'] === null && count($plan['notes']) === 1, 'advised 19 bits are above the 18 a client does unasked, and passed over with a note');
$plan = Gate::plan(['require' => ['pow' => ['bits' => 20, 'covers' => 1], 'per_key' => 3, 'write_until' => 1800000000]]);
$check($plan['bits'] === 20 && $plan['stop'] === null, 'required 20 bits are done, per_key and write_until are known and left to the service');
$plan = Gate::plan(['require' => ['pow' => ['bits' => 33]]]);
$check($plan['stop'] !== null && str_contains($plan['stop'], '33') && str_contains($plan['stop'], '32'), 'required 33 bits stop the send, with the number and the ceiling');
// Up to 32 bits, for an inbox that means to meet only writers with compute.
// The inbox is the judge: work that would not be done before it closes is
// not started, and work that runs over is stopped.
// A minute: no loop like this one does 32 bits in that, on any machine.
$plan = Gate::plan(['require' => ['pow' => ['bits' => 32]]], 60.0);
$check($plan['stop'] !== null && str_contains($plan['stop'], 'not started') && str_contains($plan['stop'], 'nothing was sent'), '32 bits with a minute left is not started, and says how long it would take', (string) $plan['stop']);
$plan = Gate::plan(['require' => ['pow' => ['bits' => 20]]], 3600.0);
$check($plan['stop'] === null && $plan['bits'] === 20 && $plan['expected_seconds'] > 0, 'work that fits goes ahead, with how long it takes here');
$plan = Gate::plan(['require' => ['pow' => ['bits' => 24]]], null, 0.001);
$check($plan['stop'] !== null && str_contains($plan['stop'], 'command line'), 'work longer than a tool call is given points at the command line, since this server cannot do it in the background', (string) $plan['stop']);
$started = microtime(true);
$check(Gate::solve(str_repeat('w', 20), str_repeat('k', 43), 'body', 30, microtime(true) + 0.3) === null && microtime(true) - $started < 5, 'work past its deadline is stopped');
$check(abs(Gate::expectedSeconds(21) - 2 * Gate::expectedSeconds(20)) < 1e-9 && Gate::describe(600) === '10 minutes', 'the estimate doubles with each bit, and a time reads as a time');
$plan = Gate::plan(['require' => ['captcha' => true]]);
$check($plan['stop'] !== null && str_contains($plan['stop'], 'captcha'), 'an unknown requirement stops the send and names it');
$plan = Gate::plan(['advise' => ['captcha' => true, 'pow' => ['bits' => 8]]]);
$check($plan['stop'] === null && $plan['bits'] === 8 && count($plan['notes']) === 1, 'an unknown advice is passed over, the known one is done');
$check(Gate::REQUIRE_MAX_BITS === 32 && Gate::ADVISE_MAX_BITS === 18, 'the ceilings are the services');


// Codex, 20 September 2026. The helper was called local_root_matches, and a receipt
// with the same content hashes but different times and senders still answered true,
// while the whole local root -- seq, at, sha256, from -- was different. The name
// promised a comparison nobody was making.
$sameHashes = [
    ['seq' => 1, 'at' => 100, 'sha256' => str_repeat('a', 64), 'from' => 'one'],
    ['seq' => 2, 'at' => 200, 'sha256' => str_repeat('b', 64), 'from' => 'two'],
];
$movedAbout = [
    ['seq' => 1, 'at' => 999, 'sha256' => str_repeat('a', 64), 'from' => 'somebody else'],
    ['seq' => 2, 'at' => 888, 'sha256' => str_repeat('b', 64), 'from' => 'and another'],
];
$ourHashes = [str_repeat('a', 64), str_repeat('b', 64)];
$movedCheck = Receipt::verify(['messages' => $movedAbout, 'root' => Receipt::root($movedAbout), 'commitment' => 'sha256:' . Receipt::root($movedAbout)], $ourHashes);
$check(($movedCheck['local_hashes_match'] ?? null) === true, 'the helper compares hashes, and says so in its name');
$check(Receipt::root($sameHashes) !== Receipt::root($movedAbout), 'and the root it does not compare is a different number');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

function mb_chr_polyfill(int $point): string
{
    if ($point < 0x80) {
        return chr($point);
    }
    if ($point < 0x800) {
        return chr(0xC0 | ($point >> 6)) . chr(0x80 | ($point & 0x3F));
    }
    if ($point < 0x10000) {
        return chr(0xE0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
    }

    return chr(0xF0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3F)) . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
}
