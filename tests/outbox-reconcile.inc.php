<?php

declare(strict_types=1);

// Included by runtime.php, sharing its fake service, its checks and its two runtimes.
//
// A model can settle an unsettled send, not only look at it.
//
// From a conversation Astra had over aamio with an agent we do not control, 20
// September 2026. Both sides arrived at the same rule without being told it: an
// ambiguous send must stop for reconciliation, never be retried blindly, and a
// pending intent must never be redirected to a new recipient.
//
// aamio_pending said exactly that, "say so rather than sending the same request
// again", and then offered no way to say it. outbox retry and outbox forget
// lived only on the command line, so a model reading that sentence was asked for
// something this surface could not do. It had the stop and not the reconciliation.
use Aamio\McpServer;

echo "settling a send whose outcome never came\n";

$mcp = new McpServer($a);
$catalogue = [];

$listing = $mcp->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

foreach ($listing['result']['tools'] as $listed) {
    $catalogue[$listed['name']] = $listed;
}

$check(isset($catalogue['aamio_outbox_retry']), 'a model that must not resend blindly can retry the stored bytes');
$check(isset($catalogue['aamio_outbox_forget']), 'and can stop waiting for one');
$check(count($catalogue) === 23, 'twenty-three tools, the same names the Python runtime serves', (string) count($catalogue));

// A sweep is the blind retry both agents ruled out. One id is one decision.
$check(($catalogue['aamio_outbox_retry']['inputSchema']['required'] ?? null) === ['id'], 'retry takes one id and never sweeps');
$check(($catalogue['aamio_outbox_forget']['inputSchema']['required'] ?? null) === ['id'], 'forget takes one id too');
$check(($catalogue['aamio_outbox_retry']['annotations']['readOnlyHint'] ?? null) === false
    && ($catalogue['aamio_outbox_retry']['annotations']['idempotentHint'] ?? null) === false, 'retry is annotated as a send, and a second call as a second attempt');
$check(($catalogue['aamio_outbox_forget']['annotations']['destructiveHint'] ?? null) === true, 'forget is annotated destructive: the entry is gone and nothing is sent after it');

$retryText = $catalogue['aamio_outbox_retry']['description'] ?? '';
$check(str_contains($retryText, 'replay'), 'retry says the transport repeats safely, since a second copy is marked a replay');
$check(str_contains($retryText, 'recipient') && str_contains($retryText, 'changed'), 'and that an intent whose recipient changed must not go out under the old id');
$forgetText = $catalogue['aamio_outbox_forget']['description'] ?? '';
$check(str_contains($forgetText, 'stopped waiting') && str_contains($forgetText, 'delivered'), 'forget says what it is not saying: neither delivered nor not');

$pendingText = $catalogue['aamio_pending']['description'] ?? '';
$check(!str_contains($pendingText, 'say so rather than sending the same request again'), 'pending no longer asks for a way out that did not exist');
$check(str_contains($pendingText, 'aamio_outbox_retry') && str_contains($pendingText, 'aamio_outbox_forget'), 'it names the way out it now has');

[, $advice] = \Aamio\Runtime::sendAdvice('unknown', 0);
$check(!str_contains($advice, 'no retry-by-id tool'), 'a send with no answer no longer denies the tool exists');
$check(str_contains($advice, 'aamio_outbox_retry') && str_contains($advice, 'do not compose a replacement'), 'it names the tool and keeps the rule');

// ----------------------------------------------------------------- it works --

$settled = $a->boardPost('offer', 'Reconcile me', 'A send that never got an answer.', ['coldchain'], 900, 'en');
$fake->silent = true;
$hanging = null;

try {
    $a->boardAnswer($settled['id'], 'this one hangs');
} catch (\Throwable $error) {
    $hanging = $error;
}

$fake->silent = false;
$hangingId = $hanging instanceof \Aamio\SendFailed ? $hanging->messageId : '';
$check(($a->outbox[$hangingId]['status'] ?? null) === 'unknown', 'a send nobody acknowledged waits in the outbox', (string) ($a->outbox[$hangingId]['status'] ?? 'nothing'));

$sentAgain = $mcp->dispatch('aamio_outbox_retry', ['id' => $hangingId]);
$check(($sentAgain['isError'] ?? false) !== true && ($sentAgain['structuredContent']['id'] ?? null) === $hangingId, 'retry sends the stored bytes for that id', json_encode($sentAgain['structuredContent'] ?? []));

$never = $mcp->dispatch('aamio_outbox_retry', ['id' => 'a-message-this-outbox-never-had']);
$check(($never['isError'] ?? false) === true && str_contains($never['structuredContent']['why'] ?? '', 'aamio_pending'), 'an id this outbox never had is refused, and the refusal says where to look', json_encode($never['structuredContent'] ?? []));

$dropped = $mcp->dispatch('aamio_outbox_forget', ['id' => $hangingId]);
$again = $mcp->dispatch('aamio_outbox_forget', ['id' => $hangingId]);
$check(($dropped['structuredContent']['forgotten'] ?? null) === true, 'forget drops the entry');
$check(($again['structuredContent']['forgotten'] ?? null) === false, 'and says the second call found nothing, rather than claiming it dropped it again');
$check(!isset($a->outbox[$hangingId]), 'what forget dropped is gone from the outbox');
$check(!in_array($hangingId, array_column($a->outboxPending(), 'id'), true), 'and gone from pending');


// The tool text promises already_sending, and the answer has to carry it.
//
// Found in a second review, 20 September 2026. The description was copied from the
// Python surface so both runtimes say the same thing to a model; outboxForget was
// not, so a model was told to read a field that was never there. This runtime sends
// on the calling thread, so the signal is the status: unknown means a post went out.
$hangingTwo = $b->boardPost('offer', 'Contract check', 'A send with no answer.', ['coldchain'], 900, 'en');
$fake->silent = true;
$stuck = null;

try {
    $a->boardAnswer($hangingTwo['id'], 'no answer for this one');
} catch (\Throwable $error) {
    $stuck = $error;
}

$fake->silent = false;
$stuckId = $stuck instanceof \Aamio\SendFailed ? $stuck->messageId : '';
$check(($a->outbox[$stuckId]['status'] ?? null) === 'unknown', 'a send with no answer waits as unknown');

$told = $mcp->dispatch('aamio_outbox_forget', ['id' => $stuckId])['structuredContent'] ?? [];
$check(array_key_exists('already_sending', $told), 'forget returns the field its own description promises', json_encode($told));
$check(($told['already_sending'] ?? null) === true, 'and says true for a message that was posted, so nobody composes a replacement');
$check(str_contains(strtolower($told['note'] ?? ''), 'unknown'), 'with a note that says the outcome stays unknown', (string) ($told['note'] ?? ''));


// Five outcomes, and the two that shipped wrong.
//
// Until 20 September a send answered 500 was reported as 'nothing had left this
// machine', and so was one the service had already stored. Both invite a second copy.
// v0.2.11 went to Packagist with this; only a stop before the first post may be called
// safely unsent.
$outcomes = [
    ['working',   null, 1, 'never_sent'],
    ['sending',   null, 0, 'never_sent'],
    ['refused',    500, 1, 'attempted'],
    ['refused',    503, 1, 'attempted'],
    ['refused',    403, 1, 'refused'],
    ['refused',    410, 1, 'refused'],
    ['delivered',  201, 1, 'delivered'],
    ['unknown',      0, 1, 'unknown'],
];
$outcomeWrong = [];

foreach ($outcomes as [$status, $http, $tries, $want]) {
    $id = 'm-outcome-' . $status . '-' . (string) $http;
    $a->outbox[$id] = ['id' => $id, 'w' => str_repeat('w', 20), 'status' => $status,
        'last_status' => $http, 'attempts' => $tries, 'envelope' => [], 'to_key' => null];
    $said = $a->outboxForget($id);
    $expectAlready = !in_array($want, ['never_sent', 'not_found'], true);

    if (($said['outcome'] ?? '') !== $want || ($said['already_sending'] ?? null) !== $expectAlready) {
        $outcomeWrong[] = sprintf('%s/%s gave %s', $status, (string) $http, $said['outcome'] ?? '?');
    }
}

$check($outcomeWrong === [], 'forget tells five outcomes apart, so a server error is not called unsent', implode('; ', $outcomeWrong));
$missing = $a->outboxForget('m-never-existed');
$check(($missing['outcome'] ?? '') === 'not_found' && ($missing['forgotten'] ?? null) === false && ($missing['already_sending'] ?? null) === false, 'and an id this outbox never had is its own outcome');
