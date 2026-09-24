<?php

declare(strict_types=1);

/*
 * The inbox follows the address book.
 *
 * Two runtimes talking in the field, 21 September 2026, found the two halves
 * of one defect. partnerAdd wrote partners.json and nothing else: the inbox
 * kept the list it was opened with for up to 57 minutes, and the partner just
 * added was refused with 403 at the address presence pointed to, which the
 * owner never saw. partnerRemove was the other half: forgetting the name is
 * no revocation, the service takes the removed key's writes to the old
 * address until the thread expires, and they arrived as an unknown contact.
 *
 * The fake service enforces allowlists on every write as the real one does,
 * so these checks say who could write where, not only what the runtime
 * believes. Included by tests/runtime.php, with $fake, $root, $quiet and
 * $check in scope.
 */

use Aamio\Address;
use Aamio\Http;
use Aamio\Keys;
use Aamio\Runtime;

echo "the inbox follows the address book\n";

foreach (['p', 'q', 'r'] as $letter) {
    mkdir($root . DIRECTORY_SEPARATOR . $letter, 0700, true);
}
$homeP = $root . DIRECTORY_SEPARATOR . 'p';
$homeQ = $root . DIRECTORY_SEPARATOR . 'q';
$homeR = $root . DIRECTORY_SEPARATOR . 'r';
$p = new Runtime($homeP, 'https://fake.test', ['p'], false, $quiet);
$q = new Runtime($homeQ, 'https://fake.test', ['q'], false, $quiet);
$r = new Runtime($homeR, 'https://fake.test', ['r'], false, $quiet);
$Q = $q->keys->public;
$R = $r->keys->public;

// A write straight at the fake, signed by a key or by nobody: what the
// service would answer, whatever the runtime believes.
$write = static function (string $w, ?string $key) use ($fake): int {
    $headers = ['Content-Type' => 'text/plain'] + ($key === null ? [] : ['X-Key' => $key, 'X-Sig' => 'sig']);

    return $fake('POST', 'https://fake.test/' . $w, 'hei', $headers)[0];
};
$states = static fn (Runtime $runtime): array => array_values(array_unique(array_column($runtime->attentionTaken(), 'state')));

// Registration before the first read opens nothing; the inbox opens with the
// list as it is then.
$out = $p->partnerAdd('Q', $Q);
$check($out['inbox'] === null && $out['rotated'] === false && !isset($fake->threads[$p->keys->public]), 'a partner added before the first inbox opens nothing');
$first = $p->ensureInbox();
$check($fake->threads[$first->w]['allow'] === [$Q] && $write($first->w, $Q) === 201 && $write($first->w, $R) === 403, 'the inbox opens with the partner, and the fake refuses a key it does not name');

// The partner just added can write to the address presence points to.
$out = $p->partnerAdd('R', $R);
$second = $p->channels['inbox'];
$allowed = $fake->threads[$second->w]['allow'];
sort($allowed);
$expected = [$Q, $R];
sort($expected);
$check($out['rotated'] === true && $out['inbox'] === $second->w && $second !== $first && $allowed === $expected, 'adding a partner replaces the inbox with one that names the key', json_encode($out));
$check($write($second->w, $R) === 201 && $write($first->w, $R) === 403, 'the partner writes to the new address; the old one still refuses the key');
$check($fake->presence[$p->keys->public]['w'] === $second->w && $out['presence'] === true, 'presence points at the new inbox');
$check(isset($p->channels['inbox-' . $first->expireAt]) && $first->muted === false && $out['old_inbox'] === ['w' => $first->w, 'muted' => false, 'until' => $first->expireAt], 'the old inbox is still read until it expires: nobody was removed');
$check($states($p) === [], 'and nothing needs attention for a key that was only added');

// A key the inbox already names changes nothing.
$threads = count($fake->threads);
$out = $p->partnerAdd('R again', $R);
$check($out['rotated'] === false && $out['inbox'] === $second->w && count($fake->threads) === $threads && $p->partnerByKey($R)['name'] === 'R again', 'a key the inbox already names changes nothing but the name');

// A removed partner is read no more.
$out = $p->partnerRemove('R again');
$third = $p->channels['inbox'];
$check($out['known'] === true && $out['rotated'] === true && $third !== $second && $fake->threads[$third->w]['allow'] === [$Q], 'removing a partner opens an inbox without the key', json_encode($out));
$check($write($third->w, $R) === 403 && $write($second->w, $R) === 201, 'the service still takes the removed key at the old address');
$check($second->muted === true && $out['old_inbox'] === ['w' => $second->w, 'muted' => true, 'until' => $second->expireAt], 'and the old inbox is muted: kept, read no more');
$listed = [];
foreach ($p->channelList() as $entry) {
    $listed[$entry['w']] = $entry['muted'];
}
$check($listed[$second->w] === true && $listed[$third->w] === false && $listed[$first->w] === false, 'the channel list says which inbox is muted');
$check($states($p) === ['muted'], 'attention says the old inbox is read no more');
$check(array_filter($p->read(), static fn (array $m): bool => $m['channel'] === $second->label) === [], 'a read hands over nothing from a muted inbox');

// Muted travels with the state.
$p->close();
$p = new Runtime($homeP, 'https://fake.test', null, false, $quiet);
$listed = [];
foreach ($p->channelList() as $entry) {
    $listed[$entry['w']] = $entry['muted'];
}
$check($listed[$second->w] === true && $listed[$third->w] === false, 'a muted inbox is still muted after a restart');

// The last partner removed leaves a signed-only inbox.
$out = $p->partnerRemove('Q');
$fourth = $p->channels['inbox'];
$check($fake->threads[$fourth->w]['allow'] === ['*'] && $out['allow'] === ['*'], 'after the last partner the inbox takes signed writes from any key');
$check($write($fourth->w, $R) === 201 && $write($fourth->w, null) === 403, 'signed from anyone, unsigned from nobody');
$check($p->ensureInbox() === $fourth, 'with no partners that inbox matches the address book and is kept');
$out = $p->partnerRemove('nobody');
$check($out === ['partner' => 'nobody', 'known' => false, 'inbox' => $fourth->w] && $p->channels['inbox'] === $fourth, 'removing an unknown name touches nothing');

// An open inbox gets its first partner: the old one stays open until it
// expires, is still read, and attention says so.
$open = $q->ensureInbox();
$check($fake->threads[$open->w]['allow'] === [] && $write($open->w, null) === 201, 'a first contact starts with an inbox open to anyone');
$out = $q->partnerAdd('P', $p->keys->public);
$named = $q->channels['inbox'];
$check($out['rotated'] === true && $fake->threads[$named->w]['allow'] === [$p->keys->public] && $open->muted === false && isset($q->channels['inbox-' . $open->expireAt]), 'the first partner gets a named inbox, and the open one is still read');
$check($states($q) === ['open'], 'attention says the old address is open to anyone until it expires');

// A replacement that fails leaves the old inbox in use, and says so.
$held = $r->ensureInbox();
Http::$override = static function (string $method, string $url, ?string $body, array $headers) use ($fake): array {
    if ($method === 'PUT' && !str_contains($url, '/p/')) {
        return [503, ['error' => 'the fake is full', 'fix' => 'later'], []];
    }

    return $fake($method, $url, $body, $headers);
};
$out = $r->partnerAdd('P', $p->keys->public);
$check($out['rotated'] === false && $out['inbox'] === $held->w && str_contains($out['error'], '503') && $r->channels['inbox'] === $held, 'a rotation that fails leaves the old inbox in use', json_encode($out));
$check($states($r) === ['rotation_failed'] && $r->partnerByName('P') !== null, 'attention says so, and the address book has the partner');
$check($r->ensureInbox() === $held, 'a read does not fail over it');
Http::$override = $fake;
$check($r->ensureInbox() !== $held && $fake->threads[$r->channels['inbox']->w]['allow'] === [$p->keys->public], 'the next read catches up once the service answers');

// A change made while the runtime was not running is caught on the next read.
$q->close();
file_put_contents($homeQ . '/partners.json', json_encode([['name' => 'P', 'key' => $p->keys->public], ['name' => 'R', 'key' => $R]]));
$q = new Runtime($homeQ, 'https://fake.test', null, false, $quiet);
$before = $q->channels['inbox'];
$after = $q->ensureInbox();
$allowed = $fake->threads[$after->w]['allow'];
sort($allowed);
$expected = [$p->keys->public, $R];
sort($expected);
$check($after !== $before && $allowed === $expected && $before->muted === false, 'a partner added while the runtime was down gets the inbox on the next read; the old one is still read');
$q->close();
file_put_contents($homeQ . '/partners.json', json_encode([['name' => 'R', 'key' => $R]]));
$q = new Runtime($homeQ, 'https://fake.test', null, false, $quiet);
$before = $q->channels['inbox'];
$after = $q->ensureInbox();
$check($after !== $before && $fake->threads[$after->w]['allow'] === [$R] && $before->muted === true, 'a partner removed while the runtime was down mutes the old inbox on the next read');

// Every generation that names a removed key is muted, not only the last one.
echo "every generation is judged\n";
mkdir($root . DIRECTORY_SEPARATOR . 'g', 0700, true);
$homeG = $root . DIRECTORY_SEPARATOR . 'g';
$g = new Runtime($homeG, 'https://fake.test', ['g'], false, $quiet);
$g->partnerAdd('Q', $Q);
$gen1 = $g->ensureInbox();
$g->partnerAdd('R', $R);
$gen2 = $g->channels['inbox'];
$check($gen1->muted === false && $gen2 !== $gen1, 'a key added: the first generation is read on');
$out = $g->partnerRemove('Q');
$gen3 = $g->channels['inbox'];
$check($fake->threads[$gen3->w]['allow'] === [$R] && $gen1->muted === true && $gen2->muted === true, 'removing Q mutes both generations that named her, not only the one just retired', json_encode($out));
$muted = $out['muted'] ?? [];
sort($muted);
$expected = [$gen1->w, $gen2->w];
sort($expected);
$check($muted === $expected, 'and the answer lists both');
$check($write($gen1->w, $Q) === 201 && $g->poll($gen1) === ['muted', []], 'the service still takes Q at the oldest address, and a direct poll of it reads nothing');
$before = count($fake->calls);
$g->read();
$asked = array_filter(array_slice($fake->calls, $before), static fn (array $c): bool => $c[0] === 'GET' && (str_contains($c[1], $gen1->w) || str_contains($c[1], $gen2->w)));
$check($asked === [], 'a read asks the service for neither muted generation');
$check(array_column($g->attentionTaken(), 'state') === ['muted', 'muted'], 'attention says so once per generation');

// A partner removed while the runtime was down: judged at the first read.
$g->close();
file_put_contents($homeG . '/partners.json', json_encode([]));
$g = new Runtime($homeG, 'https://fake.test', null, false, $quiet);
$loaded = [];
foreach ($g->channels as $c) {
    $loaded[$c->w] = $c->muted;
}
$check($loaded[$gen3->w] === false, 'loaded from disk, nothing is judged before the first read');
$g->ensureInbox();
$judged = [];
foreach ($g->channels as $c) {
    $judged[$c->w] = $c->muted;
}
$check(($judged[$gen3->w] ?? null) === true && $fake->threads[$g->channels['inbox']->w]['allow'] === ['*'], 'R removed while the runtime was down: her generation is muted at the first read, and the new inbox takes any signed key');

// A presence publish that fails is said, and tried again soon.
echo "presence that fails is said\n";
$held = $g->channels['inbox'];
Http::$override = static function (string $method, string $url, ?string $body, array $headers) use ($fake): array {
    if ($method === 'PUT' && str_contains($url, '/p/')) {
        return [500, ['error' => 'the fake fell over', 'fix' => 'later'], []];
    }

    return $fake($method, $url, $body, $headers);
};
$out = $g->partnerAdd('Q', $Q);
$notes = array_values(array_filter($g->attentionTaken(), static fn (array $n): bool => $n['state'] === 'presence_failed'));
$check($out['rotated'] === true && $out['presence'] === false && count($notes) === 1 && str_contains($notes[0]['what'], $g->channels['inbox']->w) && str_contains($notes[0]['what'], 'refused'), 'a failed publish after the rotation is said in attention, with the consequence', json_encode($notes));
$peek = static fn (Runtime $runtime, string $field) => (function () use ($field) { return $this->$field; })->call($runtime);
$poke = static function (Runtime $runtime, string $field, mixed $value): void { (function () use ($field, $value): void { $this->$field = $value; })->call($runtime); };
$check($peek($g, 'presenceFailed') === true && $peek($g, 'presenceRetryAt') - microtime(true) <= 2.5, 'the next try comes in seconds, not in a minute');
$attempts = count($fake->calls);
$check($g->publishPresence() === null, 'not before the wait is over');
$poke($g, 'presenceRetryAt', 0.0);
$check($g->publishPresence() === false && $peek($g, 'presenceBackoff') === 4.0, 'tried again once the wait is over, without force, and the wait doubles');
Http::$override = $fake;
$poke($g, 'presenceRetryAt', 0.0);
$check($g->publishPresence() === true && $peek($g, 'presenceFailed') === false && $fake->presence[$g->keys->public]['w'] === $g->channels['inbox']->w, 'once the service answers, the new address is published and the failure is over');
$check($g->publishPresence() === null, 'and the normal interval holds again');

// A verified handoff binds the sender's key to the new address.
echo "a handoff binds the key\n";
$q->partnerAdd('G', $g->keys->public);
$g->partnerAdd('Q', $Q);
$q->ensureInbox();
$g->ensureInbox();
$q->read();
$handed = $g->openChannelWith($Q, 900, 'side', $q->channels['inbox']->w, 'come over');
$got = $q->read();
$invites = array_values(array_filter($got, static fn (array $m): bool => is_array($m['body']) && ($m['body']['channel'] ?? null) === $handed['w']));
$check(count($invites) === 1 && $invites[0]['verified'] === true && $invites[0]['w'] === $q->channels['inbox']->w, 'the invitation arrives verified, and says which address it came in on', json_encode(array_column($got, 'body')));
$check(($q->peers[$handed['w']] ?? null) === $g->keys->public, 'the handed-over address is bound to the key that signed the handoff');
$sentBack = $q->send($handed['w'], 'on the side');
$check($sentBack['w'] === $handed['w'] && $fake->threads[$handed['w']]['messages'] !== [], 'so the first send to it lands');

// An address already bound to a key is not rebound by a claim from another
// key. Finding N1 of the health check of 21 September 2026: a stranger's
// signed message naming G's address in channel replaced G's key, and the next
// send there was sealed to the stranger. S has no partners, so its inbox takes
// any signed key, as a board reply inbox does.
echo "a binding is not taken over\n";
mkdir($root . DIRECTORY_SEPARATOR . 's', 0700, true);
$s = new Runtime($root . DIRECTORY_SEPARATOR . 's', 'https://fake.test', ['s'], false, $quiet);
$sInbox = $s->ensureInbox();
$stranger = Keys::generate();
$claimed = Address::w(Address::newId());
$fake->threads[$claimed] = ['id' => 'g-open', 'created_at' => $fake->now, 'expire_at' => $fake->now + 900, 'allow' => [], 'messages' => [], 'gate' => []];
$s->peers[$claimed] = $g->keys->public;
$claim = static function (string $field, Keys $signer, bool $signed = true) use ($fake, $sInbox, $claimed): void {
    $body = json_encode([$field => $claimed, 'text' => 'mine']);
    $seq = count($fake->threads[$sInbox->w]['messages']) + 1;
    $fake->threads[$sInbox->w]['messages'][] = [
        'seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'json', 'body' => $body, 'sha256' => hash('sha256', $body),
        'from' => $signer->public, 'sig' => $signed ? $signer->sign(Keys::threadSigningInput($sInbox->w, $body)) : 'not-a-signature',
        'verified' => true, 'sealed' => false,
    ];
};
$claim('channel', $stranger);
$claim('reply_to', $stranger);
$claim('channel', $stranger, false);
$s->attentionTaken();
[$state, $entries] = $s->poll($sInbox);
$conflicts = array_values(array_filter($s->attentionTaken(), static fn (array $n): bool => $n['state'] === 'binding_conflict'));
$check($state === 'ok' && count($entries) === 3 && $entries[0]['verified'] === true && $entries[1]['verified'] === true && $entries[2]['verified'] === false, 'the claims arrive, two verified and one that does not check out', json_encode(array_column($entries, 'verified')));
$check(($s->peers[$claimed] ?? null) === $g->keys->public, 'and the address stays bound to G on both fields');
$check(($entries[0]['binding_conflicts'] ?? null) === [['field' => 'channel', 'address' => $claimed, 'claimed_by' => $stranger->public, 'bound_to' => $g->keys->public]] && ($entries[1]['binding_conflicts'][0]['field'] ?? null) === 'reply_to' && !isset($entries[2]['binding_conflicts']), 'each verified claim carries its conflict, and the unverified one binds nothing and claims nothing', json_encode(array_column($entries, 'binding_conflicts')));
$check(count($conflicts) === 1 && ($conflicts[0]['seqs'] ?? null) === [1, 2] && str_contains($conflicts[0]['what'], 'kept'), 'attention says so once, with both seqs', json_encode($conflicts));
$claim('channel', $g->keys);
[$state, $entries] = $s->poll($sInbox);
$check(($s->peers[$claimed] ?? null) === $g->keys->public && !isset($entries[0]['binding_conflicts']) && $s->attentionTaken() === [], 'the same key again is no conflict');
$sealed = $s->send($claimed, 'still for G');
$sealedEntry = end($s->outbox);
$sealedBody = end($fake->threads[$claimed]['messages'])['body'];
$strangerOpened = null;
try {
    $strangerOpened = $stranger->open($s->keys->public, $sealedBody);
} catch (\Throwable $error) {
    $strangerOpened = false;
}
$check($sealedEntry['to_key'] === $g->keys->public && str_contains($g->keys->open($s->keys->public, $sealedBody), 'still for G') && $strangerOpened === false, 'the next send there is sealed to G: G opens it, the stranger cannot', json_encode(['to_key' => $sealedEntry['to_key'] === $g->keys->public, 'stranger' => $strangerOpened]));
$s->close();

$g->close();
$p->close();
$q->close();
$r->close();
