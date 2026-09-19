<?php

declare(strict_types=1);

// Included by runtime.php, sharing its fake service, its checks and its two runtimes.
//
// An answer to a post is a send, and its outcome is told like one.
//
// Found on 18 September 2026, in an outside assessment of aamio in use. send()
// writes an outbox entry before the first attempt and tells three outcomes
// apart: delivered, refused, and unknown when no answer came back at all, since
// the message may be on the other side. boardAnswer() posted the envelope
// directly. When the network gave no answer it threw "answer failed", with no
// message id and nothing in the outbox, so the only move left was to answer
// again: a new envelope with a new nonce, which the poster's reader cannot tell
// from a second answer. Sent through the outbox, the same bytes go again, and a
// second copy is marked a replay where it lands.
use Aamio\McpServer;
use Aamio\SendFailed;

echo "an answer to a post is a send\n";

$wanted = $b->boardPost('need', 'A second log', 'The full log as JSON.', ['coldchain.qa'], 900, 'en');
$before = count($a->outbox);

$fake->silent = true;
$thrown = null;
try {
    $a->boardAnswer($wanted['id'], 'I have that one too');
} catch (SendFailed $error) {
    $thrown = $error;
} catch (\Throwable $error) {
    $thrown = $error;
}
$fake->silent = false;
$check($thrown instanceof SendFailed && $thrown->outcome === 'unknown' && $thrown->status === 0, 'an answer nobody acknowledged is unknown, not failed: the message may be on the other side', $thrown === null ? 'nothing was thrown' : get_class($thrown) . ': ' . $thrown->getMessage());
$entry = $thrown instanceof SendFailed ? ($a->outbox[$thrown->messageId] ?? null) : null;
$check(count($a->outbox) === $before + 1 && is_array($entry) && $entry['status'] === 'unknown' && $entry['w'] === $wanted['reply_inbox'] && ($entry['summary']['post'] ?? null) === $wanted['id'], 'and it is in the outbox, saying which post it answers');
$check(in_array($entry['id'] ?? null, array_column($a->outboxPending(), 'id'), true), 'where pending lists it until somebody settles it');

$writes = static fn (): array => array_values(array_filter($fake->calls, static fn (array $call): bool => $call[0] === 'POST' && str_ends_with($call[1], '/' . $wanted['reply_inbox'])));
$sentBefore = count($writes());
$retried = $entry === null ? [] : $a->outboxRetry($entry['id']);
$check(count($retried) === 1 && $retried[0]['status'] === 'delivered' && count($writes()) === $sentBefore + 1, 'a retry sends it again and it lands');
$storedBodies = array_column($fake->threads[$wanted['reply_inbox']]['messages'], 'body');
$check($entry !== null && end($storedBodies) === $entry['envelope'], 'and what landed is the envelope the outbox held, byte for byte, not a new one with a new nonce');
$b->read();
$landed = array_values(array_filter($b->boardReplies($wanted['id']), static fn (array $reply): bool => ($reply['body']['text'] ?? null) === 'I have that one too'));
$check(count($landed) === 1 && $landed[0]['encrypted'] && !$landed[0]['replay'], 'the poster reads the stored envelope, sealed once: the retry carried the same bytes and not a second answer');

$fake->refuse = true;
$thrown = null;
try {
    $a->boardAnswer($wanted['id'], 'refused this time');
} catch (\Throwable $error) {
    $thrown = $error;
}
$fake->refuse = false;
$check($thrown instanceof SendFailed && $thrown->outcome === 'refused' && $thrown->status === 403 && ($a->outbox[$thrown->messageId]['status'] ?? null) === 'refused', 'a refused answer says refused, and which message', $thrown === null ? 'nothing was thrown' : get_class($thrown) . ': ' . $thrown->getMessage());

$answer = $a->boardAnswer($wanted['id'], 'and this one lands');
$check(isset($answer['message_id']) && ($a->outbox[$answer['message_id']]['status'] ?? null) === 'delivered', 'an answer that landed names its outbox entry');

$fake->silent = true;
$told = (new McpServer($a))->dispatch('aamio_board_answer', ['post' => $wanted['id'], 'text' => 'over mcp']);
$fake->silent = false;
$said = $told['structuredContent'] ?? [];
$check(($told['isError'] ?? false) === true && ($said['error_code'] ?? null) === 'send_unknown' && ($said['operation'] ?? null) === 'board_answer' && isset($a->outbox[$said['message_id'] ?? '']) && ($said['fix'] ?? '') !== '', 'a model is told the outcome, the message id and what to do, under the operation it called', json_encode($said));
