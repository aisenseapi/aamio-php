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

use Aamio\Http;
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

$p->close();
$q->close();
$r->close();
