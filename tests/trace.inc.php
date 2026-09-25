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
 * it answers, and seen, the sha256 of the last message its sender read from the
 * recipient, inside the sealed body; and trace() lays both sides next to each
 * other as hashes. Two runtimes over the fake service, which enforces
 * allowlists as the real one does: what one sends is what the other reads.
 * Included by tests/runtime.php, with $fake, $root, $quiet and $check in scope.
 */

use Aamio\McpServer;
use Aamio\Runtime;

// A logger of its own: an earlier include reuses $quiet for a path.
$traceQuiet = static function (string $line): void {
};

echo "which message an answer is about, and a trace of both sides\n";

foreach (['t', 'u'] as $letter) {
    mkdir($root . DIRECTORY_SEPARATOR . 'trace-' . $letter, 0700, true);
}
$homeT = $root . DIRECTORY_SEPARATOR . 'trace-t';
$t = new Runtime($homeT, 'https://fake.test', ['t'], false, $traceQuiet);
$u = new Runtime($root . DIRECTORY_SEPARATOR . 'trace-u', 'https://fake.test', ['u'], false, $traceQuiet);
$t->partnerAdd('U', $u->keys->public);
$u->partnerAdd('T', $t->keys->public);
$tInbox = $t->ensureInbox();
$uInbox = $u->ensureInbox();
// Each knows the other's inbox, as it would from presence or a reply_to.
$t->peers[$uInbox->w] = $u->keys->public;
$u->peers[$tInbox->w] = $t->keys->public;

$first = $t->send($uInbox->w, 'question one');
$second = $t->send($uInbox->w, 'question two');
$traced = $t->trace('U');
$record = $traced['sent'][0];
$stored = $fake->threads[$uInbox->w]['messages'][0];
$check($record['sha256'] === $first['sha256'] && $record['seq'] === $first['seq'] && $record['w'] === $uInbox->w && $record['sealed'] === true && $record['status'] === 201 && $record['outcome'] === 'delivered', 'a send is traced as the service stored it', json_encode($record));
$check($record['fields'] === ['from', 'reply_to', 'text'] && $record['text_chars'] === strlen('question one') && $record['bytes'] === strlen($stored['body']), 'with the fields it had, the length of its text, and the size of the envelope the service stored');
$check(!str_contains((string) json_encode($traced), 'question one'), 'and no content');
$check(array_column($traced['sent'], 'seen_by_them') === [null, null] && str_contains($traced['note'], 'sealed to their key') && str_contains($traced['note'], 'envelope'), 'nothing acknowledged yet, and the note says why that can be the reader rather than the send');

$got = $u->read();
$check(array_map(static fn (array $m): ?string => $m['body']['text'] ?? null, $got) === ['question one', 'question two'], 'the other side reads both');
$u->send($tInbox->w, 'answer to one', null, null, $got[0]['sha256']);
$answer = array_values(array_filter($t->read(), static fn (array $m): bool => ($m['body']['text'] ?? null) === 'answer to one'))[0] ?? [];
$check(($answer['body']['re'] ?? null) === $first['sha256'], 're names the message answered, as the service hashed it');
$check(($answer['body']['seen'] ?? null) === $second['sha256'], 'seen names the last message the other side read from this one');

$traced = $t->trace('U');
$last = end($traced['received']);
$check(array_column($traced['sent'], 'seen_by_them') === [true, true] && $traced['unacknowledged'] === [], 'both sent messages are acknowledged now', json_encode(array_column($traced['sent'], 'seen_by_them')));
$check(($last['answers']['sha256'] ?? null) === $first['sha256'] && ($last['answers']['seq'] ?? null) === $first['seq'] && ($last['acknowledges']['sha256'] ?? null) === $second['sha256'], 'and the answer is matched to the message it answers and the one it acknowledges');
$check($last['encrypted'] === true && in_array('text', $last['fields'], true) && $last['text_chars'] === strlen('answer to one'), 'with how it arrived: sealed, opened, and what it held');

$later = $t->send($uInbox->w, 'three');
$traced = $t->trace('U');
$check(array_column($traced['sent'], 'seen_by_them') === [true, true, false] && $traced['unacknowledged'] === [$later['message_id']] && str_contains($traced['note'], '1 message(s) delivered after the last one they said they read'), 'what was sent after the last acknowledgement is said, and not called lost');

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
$check(count($everyone['counterparts']) === 1 && $everyone['counterparts'][0]['with'] === 'U' && $everyone['counterparts'][0]['sent'] === 3, 'without a name, one line per counterpart');

$mcp = new McpServer($u);
$overMcp = $mcp->dispatch('aamio_send', ['to' => $tInbox->w, 'text' => 'over mcp', 're' => $later['sha256']]);
$check(($overMcp['isError'] ?? true) === false, 'aamio_send takes re');
$t->read();
$traceTool = (new McpServer($t))->dispatch('aamio_trace', ['who' => 'U', 'limit' => 5]);
$check(($traceTool['isError'] ?? true) === false && ($traceTool['structuredContent']['received'][count($traceTool['structuredContent']['received']) - 1]['answers']['sha256'] ?? null) === $later['sha256'], 'aamio_trace shows it answered', json_encode($traceTool['structuredContent']['received'] ?? null));
$check(($mcp->dispatch('aamio_send', ['to' => 'T', 'text' => 'x', 're' => 'not a hash'])['isError'] ?? false) === true && ($mcp->dispatch('aamio_trace', ['who' => 'nobody'])['isError'] ?? false) === true, 'a bad re and an unknown counterpart are tool errors');

$t->close();
$again = new Runtime($homeT, 'https://fake.test', null, false, $traceQuiet);
$check(count($again->trace('U')['sent']) === 3, 'the trace survives a restart, in trace.json');
$again->close();
$u->close();
