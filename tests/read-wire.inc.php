<?php

declare(strict_types=1);

/**
 * Asking the service for a small answer.
 *
 * read-limit.inc.php is the cursor: what this runtime hands over and what it
 * leaves for the next call. This is the other half, and it was missing: the
 * service has taken X-Limit and X-Max-Bytes since 0.7.2, and no PHP file sent
 * either. The whole thread arrived every time and the limit was applied here,
 * after the bytes had already crossed the network and gone into memory.
 *
 * For an agent reading through a model that is the difference between 360
 * bytes and 20 529 for the same thread. Measured on the live service on
 * 20 September 2026: a model's context went from 50 500 characters to 360.
 *
 * Whole messages only, always: a signed message cut in half does not verify,
 * so a budget smaller than the first message is answered with too_large
 * naming it, and never with a piece of it.
 */

use Aamio\Address;
use Aamio\Channel;
use Aamio\Compat;
use Aamio\McpServer;

$wireW = Address::w(Address::newId());
$wireKey = 'wire-key';
$fake->threads[$wireW] = ['id' => $wireKey, 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => []];

foreach (range(1, 6) as $n) {
    $text = str_repeat('m' . $n, 400);
    $fake->threads[$wireW]['messages'][] = ['seq' => $n, 'at' => $fake->now + $n, 'type' => 'text', 'body' => $text, 'sha256' => hash('sha256', $text), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];
}

$lastHeaders = static function () use ($fake): array {
    $last = end($fake->seen);

    return is_array($last) ? ($last['headers'] ?? []) : [];
};

$whole = $a->client->read($wireW, $wireKey);
$check(count($whole['body']['messages']) === 6 && !isset($lastHeaders()['X-Limit']) && !isset($lastHeaders()['X-Max-Bytes']), 'a read that asked for no limit sends no limit header, so an older service sees the call it always saw');

$counted = $a->client->read($wireW, $wireKey, 0, 0, null, 2);
$check(($lastHeaders()['X-Limit'] ?? null) === '2', 'a read with a count sends X-Limit', json_encode($lastHeaders()));
$check(count($counted['body']['messages']) === 2 && ($counted['body']['next'] ?? null) === 2, 'and the cursor stops at the last message handed over, not at the last one held');
$check(($counted['body']['more'] ?? null) === true, 'and the answer says something was left behind');

$budgeted = $a->client->read($wireW, $wireKey, 0, 0, null, null, 2000);
$check(($lastHeaders()['X-Max-Bytes'] ?? null) === '2000', 'a read with a budget sends X-Max-Bytes', json_encode($lastHeaders()));
// Measured on what the service sent, not on what this client handed back. The
// budget is the service's promise about its own bytes; the client adds its own
// findings to every message on top -- verified, from_key, unverified_because --
// and an object that grew here was never the thing the budget was about.
$wireSent = [];
foreach ($budgeted['body']['messages'] as $msg) {
    foreach ($fake->threads[$wireW]['messages'] as $held) {
        if ($held['seq'] === $msg['seq']) {
            $wireSent[] = $held;
        }
    }
}

$wireBytes = 0;
foreach ($wireSent as $held) {
    $wireBytes += strlen((string) json_encode($held, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$check($budgeted['body']['messages'] !== [] && $wireBytes <= 2000, 'and what the service sent fits the budget', $wireBytes . ' bytes over the wire');
$check(count($budgeted['body']['messages']) < 6 && ($budgeted['body']['more'] ?? null) === true, 'and the rest is still at the service');

$tiny = $a->client->read($wireW, $wireKey, 0, 0, null, null, 512);
$check(($tiny['body']['too_large']['seq'] ?? null) === 1 && str_contains((string) ($tiny['body']['too_large']['fix'] ?? ''), 'X-Max-Bytes'), 'a budget under the first message names it rather than cutting it, since half a signed message does not verify', json_encode($tiny['body']['too_large'] ?? null));

$wireChannel = new Channel('wire', $wireKey, $wireW, $fake->now + 600, []);
$a->channels['wire'] = $wireChannel;
$wireChannel->after = 0;
[, $polled] = $a->poll($wireChannel, 0, 3, 2000);
$check(($lastHeaders()['X-Limit'] ?? null) === '3' && ($lastHeaders()['X-Max-Bytes'] ?? null) === '2000', 'poll carries both down to the wire rather than trimming what already arrived', json_encode($lastHeaders()));
$check(count($polled) <= 3 && $polled !== [], 'and hands over what it asked for', (string) count($polled));

$wireChannel->after = 0;
$wireChannel->seen = [];
$readBack = $a->read(0, 3, 2000);
$check(($lastHeaders()['X-Max-Bytes'] ?? null) === '2000', 'and so does read, which is where a person and a model both stand');
$check(is_array($readBack), 'read still answers a list');

$wireTool = null;
foreach (json_decode((string) file_get_contents(__DIR__ . '/../src/mcp-tools.json'), true)['tools'] as $t) {
    if ($t['name'] === 'aamio_read') {
        $wireTool = $t;
    }
}

$check(isset($wireTool['inputSchema']['properties']['max_bytes']), 'the local read tool offers a byte budget');
$wireChannel->after = 0;
$wireChannel->seen = [];
$wireMcp = (new McpServer($a))->dispatch('aamio_read', ['max_bytes' => 2000]);
$check(($wireMcp['isError'] ?? true) === false && ($lastHeaders()['X-Max-Bytes'] ?? null) === '2000', 'and a model that sets one reaches the service with it, rather than being quietly ignored', json_encode($lastHeaders()));

$wireSmall = (new McpServer($a))->dispatch('aamio_read', ['max_bytes' => 100]);
$check(($wireSmall['isError'] ?? false) === true && str_contains((string) ($wireSmall['structuredContent']['fix'] ?? ''), '65536'), 'a budget too small to answer is refused with what one message can weigh', json_encode($wireSmall['structuredContent'] ?? null));

$check(array_key_exists('read-limits', Compat::USES), 'and doctor stops calling read-limits an unknown capability, three releases after this client could have used it');

// Codex, 20 September 2026, on the budget added the same day. The service
// answers a byte budget honestly: whole messages only, more when something was
// left, too_large naming a message that does not fit on its own. This runtime
// looked at neither field, so a thread holding one 70 000-byte message read at
// 4096 bytes came back as an empty inbox with nothing said.
$silentW = Address::w(Address::newId());
$fake->threads[$silentW] = ['id' => 'silent-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => []];
$huge = str_repeat('h', 70000);
$fake->threads[$silentW]['messages'][] = ['seq' => 1, 'at' => $fake->now + 1, 'type' => 'text', 'body' => $huge, 'sha256' => hash('sha256', $huge), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];

$silent = new Channel('silent', 'silent-key', $silentW, $fake->now + 600, []);
$a->channels['silent'] = $silent;
[, $nothing] = $a->poll($silent, 0, 50, 4096);
$silentNotes = $a->attentionTaken();
$check($nothing === [], 'nothing fits the budget, so nothing comes back');
$silentNote = null;
foreach ($silentNotes as $n) {
    if (($n['state'] ?? '') === 'too_large') {
        $silentNote = $n;
    }
}
$check($silentNote !== null, 'a message that will never arrive at this budget is not reported as silence', json_encode($silentNotes));
// The size is the message on the wire, not the length of its body: 70180 for a
// 70 000-byte text, because the seq, the hash and the rest are sent with it. That
// is the number a budget has to clear, so that is the number the note carries.
$silentSize = (int) ($silentNote['what'] ?? '' ? (preg_match('!is (\d+) bytes!', $silentNote['what'], $mm) ? $mm[1] : 0) : 0);
$check($silentSize > 70000 && $silentSize < 71000, 'and the note says how big it is on the wire, so the fix is not a guess', (string) $silentSize);

// And what the service held back that does fit.
$fake->threads[$silentW]['messages'] = [];
foreach (range(1, 6) as $n) {
    $small = str_repeat('s' . $n, 400);
    $fake->threads[$silentW]['messages'][] = ['seq' => $n, 'at' => $fake->now + $n, 'type' => 'text', 'body' => $small, 'sha256' => hash('sha256', $small), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];
}
$silent->after = 0;
$silent->seen = [];
$a->poll($silent, 0, 50, 2000);
$check($silent->moreAtService === true, 'the service holding some back is recorded, and it is its own fact: nothing was fetched and left here');
$check($silent->leftWaiting === 0, 'so the count of what this poll fetched and left stays zero');

// The reset path re-reads, and dropped the budget on the way.
$silent->after = 0;
$silent->seen = [];
$silent->createdAt = $fake->now - 500;
$fake->seen = [];
$a->poll($silent, 0, 50, 2000);
$resetHeaders = [];
foreach ($fake->seen as $call) {
    if (($call['method'] ?? '') === 'GET') {
        $resetHeaders[] = $call['headers']['X-Max-Bytes'] ?? 'none';
    }
}
$check($resetHeaders !== [] && !in_array('none', $resetHeaders, true), 'a re-read after a thread reset keeps the byte budget the caller asked for', implode(' / ', $resetHeaders));

// Codex, 20 September 2026, against published 0.2.14. The service said it had
// more than the budget allowed, the runtime wrote it down, and nothing ever told
// the caller: a read that stopped early looked exactly like one that finished.
$heldW = Address::w(Address::newId());
$fake->threads[$heldW] = ['id' => 'held-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => []];

foreach (range(1, 6) as $n) {
    $held = str_repeat('h' . $n, 400);
    $fake->threads[$heldW]['messages'][] = ['seq' => $n, 'at' => $fake->now + $n, 'type' => 'text', 'body' => $held, 'sha256' => hash('sha256', $held), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];
}

$heldChannel = new Channel('held', 'held-key', $heldW, $fake->now + 600, []);
$a->channels['held'] = $heldChannel;
$a->read(0, 50, 2000);
$heldNotes = $a->attentionTaken();
$heldSaid = '';

foreach ($heldNotes as $n) {
    $heldSaid .= ' ' . ($n['what'] ?? '');
}

$check(str_contains($heldSaid, 'budget') || str_contains($heldSaid, 'more'), 'a read the service cut short says so, rather than looking finished', trim($heldSaid) ?: 'nothing was said');

// And the command line, which the README of the same release says takes both.
$cliRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-cli-limits-' . getmypid();
$heldChannel->after = 0;
$heldChannel->seen = [];
$fake->seen = [];
$cliOut = [];
@exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/aamio') . ' --help 2>&1', $cliOut, $cliStatus);
$cliHelp = implode(' ', $cliOut);
$check(str_contains($cliHelp, '--limit') && str_contains($cliHelp, '--max-bytes'), 'the command line offers the two options its README says it takes', $cliHelp === '' ? 'no help output' : 'neither is in the help');

// Codex, 20 September 2026. A 500 set the status to refused beside a note saying
// the message may have been stored, and refused is not a status outboxPending
// shows: the one kind of message that most needs a decision was the one kind that
// did not appear on the list. And a 410 after an attempt that got no answer was
// reported as not stored, which this side cannot know.
$outcomeOf = static fn ( array $e ): string => \Aamio\Runtime::outboxOutcome($e);

$settled = [
    'a delivery settles it' => [['status' => 'delivered', 'last_status' => 201], 'delivered'],
    'a refusal the service will repeat settles it' => [['status' => 'refused', 'last_status' => 403], 'refused'],
    'a rate window settles it as not stored' => [['status' => 'refused', 'last_status' => 429], 'refused'],
    'a server error settles nothing' => [['status' => 'attempted', 'last_status' => 500], 'attempted'],
    'and neither does a gateway that gave up' => [['status' => 'attempted', 'last_status' => 503], 'attempted'],
    'silence settles nothing' => [['status' => 'unknown', 'last_status' => 0], 'unknown'],
    'a refusal after an open attempt settles nothing' => [['status' => 'refused', 'last_status' => 410, 'ever_open' => true], 'attempted'],
];

$wrongOutcome = [];

foreach ($settled as $what => $pair) {
    [$fields, $want] = $pair;
    $got = $outcomeOf($fields + ['id' => 'x', 'attempts' => 1]);

    if ($got !== $want) {
        $wrongOutcome[] = $what . ': ' . $got . ' where ' . $want . ' was right';
    }
}

$check($wrongOutcome === [], 'every answer is worth exactly what it proves', implode('; ', $wrongOutcome));

// And the list of what is not settled holds all of them and none of the others.
$pendingRuntime = $a;
$keptOutbox = $pendingRuntime->outbox;
$pendingRuntime->outbox = [
    'm-500' => ['id' => 'm-500', 'status' => 'attempted', 'last_status' => 500, 'attempts' => 1, 'w' => 'w'],
    'm-0' => ['id' => 'm-0', 'status' => 'unknown', 'last_status' => 0, 'attempts' => 1, 'w' => 'w'],
    'm-403' => ['id' => 'm-403', 'status' => 'refused', 'last_status' => 403, 'attempts' => 1, 'w' => 'w'],
    'm-201' => ['id' => 'm-201', 'status' => 'delivered', 'last_status' => 201, 'attempts' => 1, 'w' => 'w'],
];
$pendingIds = array_column($pendingRuntime->outboxPending(), 'id');
sort($pendingIds);
$pendingRuntime->outbox = $keptOutbox;
$check($pendingIds === ['m-0', 'm-500'], 'a message whose fate is open is on the list of open ones, and a settled one is not', implode(', ', $pendingIds));

// Driven through deliver(), because that is where the classification lives. Built
// by hand these say nothing: putting the fault back left them green.
$brokeW = Address::w(Address::newId());
$fake->threads[$brokeW] = ['id' => 'broke-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => []];
$fake->breaks = true;
$brokeId = null;

try {
    $a->send('Bea', 'to a service that breaks');
} catch (\Aamio\SendFailed $stopped) {
    $brokeId = $stopped->messageId;
}

$fake->breaks = false;
$brokeEntry = $brokeId === null ? null : ($a->outbox[$brokeId] ?? null);
$check($brokeEntry !== null && ($brokeEntry['status'] ?? '') !== 'refused', 'a service that broke is not the service saying no', $brokeEntry === null ? 'no entry' : (string) ($brokeEntry['status'] ?? '?'));
$check($brokeEntry !== null && \Aamio\Runtime::outboxOutcome($brokeEntry) === 'attempted', 'and its outcome says the attempt left and settles nothing', $brokeEntry === null ? 'no entry' : \Aamio\Runtime::outboxOutcome($brokeEntry));
$check($brokeId !== null && in_array($brokeId, array_column($a->outboxPending(), 'id'), true), 'and it is on the list of what has no settled outcome', implode(', ', array_column($a->outboxPending(), 'id')));

// And the one the service really did turn away, which must not join it there.
$fake->refuse = true;
$saidNoId = null;

try {
    $a->send('Bea', 'to a service that says no');
} catch (\Aamio\SendFailed $stopped) {
    $saidNoId = $stopped->messageId;
}

$fake->refuse = false;
$saidNo = $saidNoId === null ? null : ($a->outbox[$saidNoId] ?? null);
$check($saidNo !== null && \Aamio\Runtime::outboxOutcome($saidNo) === 'refused', 'a refusal is still a refusal', $saidNo === null ? 'no entry' : \Aamio\Runtime::outboxOutcome($saidNo));
$check($saidNoId !== null && !in_array($saidNoId, array_column($a->outboxPending(), 'id'), true), 'and a settled one stays off the list');

// Codex, 20 September 2026. Encrypt and sign `for your eyes` without wrapping it
// as JSON: the envelope opened, the bytes were exactly those words, and json_decode
// then failed -- inside the same catch, so the answer was text: null, unreadable,
// and the cursor moved on. Three cases, because they are three.
if (!function_exists(chr(115) . chr(111) . chr(100) . chr(105) . chr(117) . chr(109) . chr(95) . chr(98) . chr(105) . chr(110) . chr(50) . chr(104) . chr(101) . chr(120))) {
    $skip('encrypted plain text survives: needs sodium');
} else {
    $eyesKeys = $a->keys;
    $eyesFrom = \Aamio\Keys::fromSeed(str_repeat(chr(4), 32));
    $opener = static function (string $plain) use ($a, $eyesKeys, $eyesFrom): array {
        return $a->open([
            'body' => $eyesFrom->seal($eyesKeys->public, $plain),
            'verified' => true,
            'from' => $eyesFrom->public,
        ]);
    };

    [$eyesBody, $eyesMeta] = $opener('for your eyes');
    $check(($eyesBody['text'] ?? null) === 'for your eyes', 'encrypted plain text comes back as the text', json_encode([$eyesBody, $eyesMeta]));
    $check(($eyesMeta['format'] ?? '') === 'text' && ($eyesMeta['encrypted'] ?? false) === true, 'and it is marked encrypted text, not unreadable', json_encode($eyesMeta));

    [$jsonBody, $jsonMeta] = $opener((string) json_encode(['text' => 'hello']));
    $check(($jsonBody['text'] ?? null) === 'hello' && ($jsonMeta['format'] ?? '') === 'json', 'encrypted JSON still comes back as its fields', json_encode([$jsonBody, $jsonMeta]));

    $strangerKeys = \Aamio\Keys::fromSeed(str_repeat(chr(9), 32));
    [$shutBody, $shutMeta] = $a->open([
        'body' => $eyesFrom->seal($strangerKeys->public, 'not for us'),
        'verified' => true,
        'from' => $eyesFrom->public,
    ]);
    $check(array_key_exists('text', $shutBody) && $shutBody['text'] === null && ($shutMeta['format'] ?? '') === 'unreadable', 'an envelope that will not open is still unreadable', json_encode([$shutBody, $shutMeta]));
}
