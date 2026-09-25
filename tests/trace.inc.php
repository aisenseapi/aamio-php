<?php

declare(strict_types=1);

/*
 * Which message an answer is about, what the other side last read, and a trace
 * of both.
 *
 * 25 September 2026: a participant answered, more than once, that the text of
 * our messages was missing, while the send log said non-empty and delivered.
 * Both can be true -- every message this runtime sends is sealed to the
 * recipient's key, and a reader without it sees an envelope -- and nothing on
 * either side could say which. So a message carries re, the sha256 of the one
 * it answers, and seen, the sha256 of the last message its sender read and
 * opened from the recipient, inside the sealed body; and trace() lays both
 * sides next to each other as hashes. Two runtimes over the fake service,
 * which enforces allowlists as the real one does: what one sends is what the
 * other reads.
 *
 * The review of the same evening (docs/client-trace-review-2026-09-25.md in the
 * service) found the first version saying more than it knew. Each of its
 * reproductions is a check here, next to the case that has to keep working: a
 * claim covers the one message it names (R1), only a message that opened is
 * named as read and a replay moves nothing (R2), and a damaged trace never
 * turns a delivered send or a read into an error (R3).
 *
 * Included by tests/runtime.php, with $fake, $root and $check in scope.
 */

use Aamio\Codec;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

// A logger of its own: an earlier include reuses $quiet for a path.
$traceQuiet = static function (string $line): void {
};

echo "which message an answer is about, and a trace of both sides\n";

/** Two runtimes that know each other, in homes of their own. */
$tracePair = static function (string $name, ?callable $log = null) use ($root, $traceQuiet): array {
    $homes = [];
    foreach (['t', 'u'] as $letter) {
        $homes[$letter] = $root . DIRECTORY_SEPARATOR . 'trace-' . $name . '-' . $letter;
        mkdir($homes[$letter], 0700, true);
    }
    $t = new Runtime($homes['t'], 'https://fake.test', ['t'], false, $log ?? $traceQuiet);
    $u = new Runtime($homes['u'], 'https://fake.test', ['u'], false, $log ?? $traceQuiet);
    $t->partnerAdd('U', $u->keys->public);
    $u->partnerAdd('T', $t->keys->public);
    $tInbox = $t->ensureInbox();
    $uInbox = $u->ensureInbox();
    // Each knows the other's inbox, as it would from presence or a reply_to.
    $t->peers[$uInbox->w] = $u->keys->public;
    $u->peers[$tInbox->w] = $t->keys->public;

    return [$t, $u, $tInbox, $uInbox, $homes];
};

/** A signed message written straight at a thread, the way any client could write it. */
$traceDirect = static function (Runtime $from, string $w, string $sealTo, array $body) use ($fake): string {
    $wire = $from->keys->seal($sealTo, Codec::json($body));
    $seq = count($fake->threads[$w]['messages']) + 1;
    $digest = hash('sha256', $wire);
    $fake->threads[$w]['messages'][] = ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $wire, 'sha256' => $digest, 'from' => $from->keys->public, 'sig' => $from->keys->sign(Keys::threadSigningInput($w, $wire)), 'verified' => true, 'sealed' => true];

    return $digest;
};

$texts = static fn (array $messages): array => array_map(static fn (array $m): ?string => $m['body']['text'] ?? null, $messages);

// ---------------------------------------------------------------- what is kept, and what is named
[$t, $u, $tInbox, $uInbox, $homes] = $tracePair('basic');
$first = $t->send($uInbox->w, 'question one');
$second = $t->send($uInbox->w, 'question two');
$traced = $t->trace('U');
$record = $traced['sent'][0];
$stored = $fake->threads[$uInbox->w]['messages'][0];
$check($record['sha256'] === $first['sha256'] && $record['seq'] === $first['seq'] && $record['w'] === $uInbox->w && $record['sealed'] === true && $record['status'] === 201 && $record['outcome'] === 'delivered', 'a send is traced as the service stored it', json_encode($record));
$check($record['fields'] === ['from', 'reply_to', 'text'] && $record['text_chars'] === strlen('question one') && $record['bytes'] === strlen($stored['body']), 'with the fields it had, the length of its text, and the size of the envelope the service stored');
$check(!str_contains((string) json_encode($traced), 'question one'), 'and no content');
$check(array_column($traced['sent'], 'seen_by_them') === [null, null] && $traced['no_read_claim'] === [$first['message_id'], $second['message_id']] && str_contains($traced['note'], 'sealed to their key') && str_contains($traced['note'], 'envelope') && str_contains($traced['note'], 'unknown, not unread'), 'nothing named yet, and the note says why that can be the reader rather than the send');

$got = $u->read();
$check($texts($got) === ['question one', 'question two'], 'the other side reads both');
$u->send($tInbox->w, 'answer to one', null, null, $got[0]['sha256']);
$answer = array_values(array_filter($t->read(), static fn (array $m): bool => ($m['body']['text'] ?? null) === 'answer to one'))[0] ?? [];
$check(($answer['body']['re'] ?? null) === $first['sha256'], 're names the message answered, as the service hashed it');
$check(($answer['body']['seen'] ?? null) === $second['sha256'], 'seen names the last message the other side read and opened from this one');

$traced = $t->trace('U');
$last = end($traced['received']);
$check(array_column($traced['sent'], 'seen_by_them') === [null, true] && array_column($traced['sent'], 'answered_by_them') === [true, null], 'seen covers the one message it names, and re the one it answers', json_encode(array_column($traced['sent'], 'seen_by_them')));
$check($traced['no_read_claim'] === [$first['message_id']] && str_contains($traced['note'], '2 delivered, and their runtime says it read and opened 1 of them') && str_contains($traced['note'], 'unknown, not unread'), 'the other is unknown, not unread', $traced['note']);
$check(($last['answers']['sha256'] ?? null) === $first['sha256'] && ($last['answers']['seq'] ?? null) === $first['seq'] && ($last['acknowledges']['sha256'] ?? null) === $second['sha256'], 'and the answer is matched to the message it answers and the one it names as read');
$check($last['encrypted'] === true && in_array('text', $last['fields'], true) && $last['text_chars'] === strlen('answer to one'), 'with how it arrived: sealed, opened, and what it held');

$later = $t->send($uInbox->w, 'three');
$traced = $t->trace('U');
$check(array_column($traced['sent'], 'seen_by_them') === [null, true, null] && $traced['no_read_claim'] === [$first['message_id'], $later['message_id']] && str_contains($traced['note'], 'For 2 there is no claim kept here'), 'what was sent after the last claim is unknown, and not called lost');

$refused = 0;
foreach (['', 'abc', str_repeat('G', 64), str_repeat('0', 63)] as $bad) {
    try {
        $t->send($uInbox->w, 'x', null, null, $bad);
    } catch (\InvalidArgumentException $error) {
        $refused += str_contains($error->getMessage(), 'sha256') ? 1 : 0;
    }
}
$check($refused === 4, 're is a message hash or it is refused, before anything is sent');

$everyone = $t->trace();
$check(count($everyone['counterparts']) === 1 && $everyone['counterparts'][0]['with'] === 'U' && $everyone['counterparts'][0]['sent'] === 3 && $everyone['counterparts'][0]['no_read_claim'] === 2, 'without a name, one line per counterpart');

$mcp = new McpServer($u);
$overMcp = $mcp->dispatch('aamio_send', ['to' => $tInbox->w, 'text' => 'over mcp', 're' => $later['sha256']]);
$check(($overMcp['isError'] ?? true) === false, 'aamio_send takes re');
$t->read();
$traceTool = (new McpServer($t))->dispatch('aamio_trace', ['who' => 'U', 'limit' => 5]);
$toolSent = $traceTool['structuredContent']['sent'] ?? [];
$check(($traceTool['isError'] ?? true) === false && ($traceTool['structuredContent']['received'][count($traceTool['structuredContent']['received']) - 1]['answers']['sha256'] ?? null) === $later['sha256'] && (end($toolSent)['answered_by_them'] ?? null) === true, 'aamio_trace shows it answered', json_encode($traceTool['structuredContent']['received'] ?? null));
$check(($mcp->dispatch('aamio_send', ['to' => 'T', 'text' => 'x', 're' => 'not a hash'])['isError'] ?? false) === true && ($mcp->dispatch('aamio_trace', ['who' => 'nobody'])['isError'] ?? false) === true, 'a bad re and an unknown counterpart are tool errors');

$t->close();
$again = new Runtime($homes['t'], 'https://fake.test', null, false, $traceQuiet);
$check(count($again->trace('U')['sent']) === 3, 'the trace survives a restart, in trace.json');
$again->close();
$u->close();

// ---------------------------------------------------------------- R1: a claim covers the message it names
[$t, $u, $tInbox, $uInbox] = $tracePair('side');
$side = $u->openChannel('side', 600, ['T']);
$t->peers[$side['w']] = $u->keys->public;
$unread = $t->send($side['w'], 'on the side channel');
$read = $t->send($uInbox->w, 'in the inbox');
[, $entries] = $u->poll($uInbox);
$check(array_column($entries, 'sha256') === [$read['sha256']] && $u->channels['side']->after === 0, 'the other side reads its inbox and nothing else');
$u->send($tInbox->w, 'answer');
$t->poll($tInbox);
$traced = $t->trace('U');
$check(array_column($traced['sent'], 'seen_by_them') === [null, true] && $traced['no_read_claim'] === [$unread['message_id']] && !str_contains($traced['note'], 'Everything'), 'R1: the side channel nobody read stays unknown, and one seen does not cover it', json_encode(array_column($traced['sent'], 'seen_by_them')));
$t->close();
$u->close();

[$t, $u, $tInbox, $uInbox] = $tracePair('elsewhere');
$sent = $t->send($uInbox->w, 'unread');
$traceDirect($u, $tInbox->w, $t->keys->public, ['seen' => str_repeat('f', 64), 'text' => 'a claim about something else']);
$t->poll($tInbox);
$traced = $t->trace('U');
$check(array_column($traced['sent'], 'seen_by_them') === [null] && $traced['no_read_claim'] === [$sent['message_id']] && !str_contains($traced['note'], 'Everything') && str_contains($traced['note'], 'read and opened 0 of them') && str_contains($traced['note'], '1 message(s) not recorded here'), 'R1: a claim to a message not recorded here is not everything read', $traced['note']);
$check((end($traced['received'])['acknowledges'] ?? null) === ['sha256' => str_repeat('f', 64), 'note' => 'not one of the messages recorded here'], 'and the claim is shown as one about a message not recorded here');
$t->close();
$u->close();

[$t, $u, $tInbox, $uInbox] = $tracePair('later');
$first = $t->send($uInbox->w, 'one');
$u->poll($uInbox);
$u->send($tInbox->w, 'read one');
$traceDirect($u, $tInbox->w, $t->keys->public, ['seen' => str_repeat('e', 64), 'text' => 'names something not recorded here']);
$t->poll($tInbox);
$traced = $t->trace('U');
$check(array_column($traced['sent'], 'seen_by_them') === [true] && $traced['no_read_claim'] === [], 'R1: a later claim does not erase an earlier one');
$t->close();
$u->close();

// ---------------------------------------------------------------- an old client, and an unsigned one
[$t, $u, $tInbox, $uInbox] = $tracePair('old');
$sent = $t->send($uInbox->w, 'hello');
$u->poll($uInbox);
$traceDirect($u, $tInbox->w, $t->keys->public, ['text' => 'hello back']);
$t->poll($tInbox);
$traced = $t->trace('U');
$check(end($traced['received'])['fields'] === ['text'] && end($traced['received'])['seen'] === null && array_column($traced['sent'], 'seen_by_them') === [null] && str_starts_with($traced['note'], 'Nothing from them has named a message of yours as read'), 'a client without seen leaves everything unknown, and the note says so', $traced['note']);
$mine = $sent['sha256'];
$t->channels['inbox']->allow = [];
$fake->threads[$tInbox->w]['messages'][] = ['seq' => count($fake->threads[$tInbox->w]['messages']) + 1, 'at' => $fake->now, 'type' => 'json', 'body' => json_encode(['seen' => $mine, 're' => $mine]), 'sha256' => str_repeat('0', 64), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];
$t->poll($tInbox);
$check(array_column($t->trace('U')['sent'], 'seen_by_them') === [null], 'an unsigned message claims nothing');
$t->close();
$u->close();

// ---------------------------------------------------------------- R2: only what opened is named as read
[$t, $u, $tInbox, $uInbox] = $tracePair('unreadable');
$stranger = Keys::generate();
$unopened = $traceDirect($t, $uInbox->w, $stranger->public, ['text' => 'sealed to a key the reader does not hold']);
[, $entries] = $u->poll($uInbox);
$check(($entries[0]['verified'] ?? null) === true && ($entries[0]['format'] ?? null) === 'unreadable' && ($entries[0]['sha256'] ?? null) === $unopened, 'a signed message sealed to another key arrives, verified and unreadable');
$u->send($tInbox->w, 'I could not open that');
$reply = array_values(array_filter($t->poll($tInbox)[1], static fn (array $m): bool => ($m['body']['text'] ?? null) === 'I could not open that'))[0] ?? [];
$check(!array_key_exists('seen', $reply['body'] ?? []), 'R2: a message that did not open is not named as read', json_encode($reply['body'] ?? null));
$onU = $u->trace('T');
$row = end($onU['received']);
$check($row['format'] === 'unreadable' && is_string($row['error']) && $row['fields'] === null && $row['text_chars'] === null && $onU['last_read'] === null, 'and the reader\'s trace keeps it, with what it held unknown', json_encode($row));
$readable = $t->send($uInbox->w, 'this one opens');
$u->poll($uInbox);
$u->send($tInbox->w, 'that one I read');
$t->poll($tInbox);
$check(array_column($t->trace('U')['sent'], 'seen_by_them') === [true] && ($u->trace('T')['last_read']['sha256'] ?? null) === $readable['sha256'], 'and one that opens is named');
$t->close();
$u->close();

[$t, $u, $tInbox, $uInbox] = $tracePair('replay');
$firstSent = $t->send($uInbox->w, 'synthetic 0');
for ($n = 1; $n <= Runtime::TRACE_KEEP; $n++) {
    $lastSent = $t->send($uInbox->w, 'synthetic ' . $n);
}
[, $entries] = $u->poll($uInbox);
$check(count($entries) === Runtime::TRACE_KEEP + 1 && ($u->traces[$t->keys->public]['last_read']['sha256'] ?? null) === $lastSent['sha256'], 'fifty-one messages read, the last one named');
$fake->threads[$uInbox->w]['messages'][] = ['seq' => Runtime::TRACE_KEEP + 2] + $fake->threads[$uInbox->w]['messages'][0];
[, $again] = $u->poll($uInbox);
$check(($again[0]['replay'] ?? null) === true && ($again[0]['sha256'] ?? null) === $firstSent['sha256'], 'an old message sent again is a replay');
$check(($u->traces[$t->keys->public]['last_read']['sha256'] ?? null) === $lastSent['sha256'], 'R2: and past the fifty rows a trace keeps, it does not move seen back');
$u->send($tInbox->w, 'after a replay');
$reply = array_values(array_filter($t->poll($tInbox)[1], static fn (array $m): bool => ($m['body']['text'] ?? null) === 'after a replay'))[0] ?? [];
$check(($reply['body']['seen'] ?? null) === $lastSent['sha256'], 'the next message names the last one read, not the replay');
$t->close();
$u->close();

// ---------------------------------------------------------------- R3: the trace never costs a send or a read
$damaged = [
    'a string where the rows go' => '{"KEY":{"sent":"not a list","received":[],"last_read":null,"seen_by_them":null}}',
    'rows of the wrong type' => '{"KEY":{"sent":[1,"x",null,{"sha256":5,"status":"201","message_id":["m"],"fields":"text"}],"received":{"a":1},"last_read":"nope"}}',
    'a claim and a read marker that are not hashes' => '{"KEY":{"sent":[],"received":[null,{"seen":"' . str_repeat('g', 64) . '","sha256":[],"at":true}],"last_read":{"sha256":"short"}}}',
    'an object where the rows go' => '{"KEY":{"sent":{"0":{"message_id":"m-x","status":201,"sha256":"' . str_repeat('a', 64) . '"}},"received":[],"last_read":null}}',
    'a book that is not a book' => '{"KEY":"a string where a book goes","not a key":{"sent":[]}}',
    'a list where the object goes' => '[["a list"]]',
];
$n = 0;
foreach ($damaged as $what => $json) {
    [$t, $u, $tInbox, $uInbox, $homes] = $tracePair('damaged-' . ++$n);
    $t->close();
    file_put_contents($homes['t'] . DIRECTORY_SEPARATOR . 'trace.json', str_replace('KEY', $u->keys->public, $json));
    $t = new Runtime($homes['t'], 'https://fake.test', null, false, $traceQuiet);
    $t->peers[$uInbox->w] = $u->keys->public;
    $sent = null;
    $error = null;
    try {
        $sent = $t->send($uInbox->w, 'delivered whatever the trace holds');
    } catch (\Throwable $thrown) {
        $error = get_class($thrown) . ': ' . $thrown->getMessage();
    }
    $outbox = json_decode((string) file_get_contents($homes['t'] . DIRECTORY_SEPARATOR . 'outbox.json'), true);
    $check($error === null && count($fake->threads[$uInbox->w]['messages']) === 1 && array_column($outbox, 'status') === ['delivered'], 'R3: ' . $what . ': the send comes back delivered, sent once', (string) $error);
    $traced = $error === null ? $t->trace('U') : ['sent' => []];
    $check(count($traced['sent']) === 1 && ($traced['sent'][0]['sha256'] ?? null) === ($sent['sha256'] ?? false), 'and the trace starts again from what it could read');
    $u->poll($uInbox);
    $u->send($tInbox->w, 'read it');
    $check(array_map(static fn (array $m): ?string => $m['body']['seen'] ?? null, $t->poll($tInbox)[1]) === [$sent['sha256'] ?? false], 'and the conversation goes on');
    $t->close();
    $u->close();
}

$logged = [];
$capture = static function (string $line) use (&$logged): void {
    $logged[] = $line;
};
[$t, $u, $tInbox, $uInbox, $homes] = $tracePair('unsaved', $capture);
mkdir($homes['t'] . DIRECTORY_SEPARATOR . 'trace.json');
$sent = $t->send($uInbox->w, 'one');
$check(Runtime::isMessageHash($sent['sha256'] ?? null) && count(array_filter($logged, static fn (string $line): bool => str_starts_with($line, 'trace.json: '))) > 0, 'R3: a trace that cannot be saved costs the trace: the send comes back, and the log says so');
$t->traces[$u->keys->public] = ['sent' => new \stdClass(), 'received' => new \stdClass(), 'last_read' => null, 'active' => 0];
$second = $t->send($uInbox->w, 'two');
$check(Runtime::isMessageHash($second['sha256'] ?? null) && count($fake->threads[$uInbox->w]['messages']) === 2 && count(array_filter($logged, static fn (string $line): bool => str_starts_with($line, 'trace sent: not recorded: '))) > 0, 'a trace that cannot be updated costs the trace: the send comes back, sent once');
$u->traces[$t->keys->public] = ['sent' => new \stdClass(), 'received' => new \stdClass(), 'last_read' => null, 'active' => 0];
[, $entries] = $u->poll($uInbox);
$check($texts($entries) === ['one', 'two'] && count(array_filter($logged, static fn (string $line): bool => str_starts_with($line, 'trace received: not recorded: '))) > 0, 'and a read hands over the whole batch');
$t->close();
$u->close();
