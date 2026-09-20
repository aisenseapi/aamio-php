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
