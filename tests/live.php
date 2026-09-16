<?php

declare(strict_types=1);

/**
 * One thread end to end against a running aamio, then presence, a gate with
 * work, and the board's read side. Nothing secret is left behind: every thread
 * is closed at the end and would expire in two minutes anyway; presence is
 * deleted. The board is only read: a post that nobody needs is noise in the
 * numbers the board is measured by.
 *
 *   php tests/live.php [https://aamio.at]
 */

require __DIR__ . '/bootstrap.php';

use Aamio\Board;
use Aamio\Client;
use Aamio\Gate;
use Aamio\Keys;

$host = $argv[1] ?? Client::DEFAULT_HOST;
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
};

$me = Keys::generate();
$partner = Keys::generate();
$client = new Client($host, $me);
$other = new Client($host, $partner);

echo "thread\n";
$thread = $client->open(120, [$me->public, $partner->public]);
$check($thread['status'] === 201, 'PUT opens a thread for 120 s with two keys allowed', (string) $thread['status']);
$w = $thread['w'];
$id = $thread['id'];

$sent = $client->send($w, 'hello from php');
$check($sent['status'] === 201 && ($sent['body']['verified'] ?? null) === true, 'a signed text write lands, verified', (string) $sent['status']);
$sealed = $other->send($w, ['tender' => 'ARC-4471', 'note' => 'sealed from the partner'], true, $me->public);
$check($sealed['status'] === 201 && ($sealed['body']['sealed'] ?? null) === true && ($sealed['body']['verified'] ?? null) === true, 'a sealed, signed JSON write from the partner lands as sealed and verified', (string) $sealed['status']);
$stranger = (new Client($host, Keys::generate()))->send($w, 'from a stranger');
$check($stranger['status'] === 403, 'a key outside the allowlist is refused with 403 and a fix', ($stranger['body']['fix'] ?? '') !== '' ? 'fix present' : 'no fix');
$unsigned = (new Client($host))->send($w, 'unsigned');
$check($unsigned['status'] === 403, 'an unsigned write to an allowlisted thread is refused', (string) $unsigned['status']);

$read = $client->read($w, $id);
$messages = $read['body']['messages'] ?? [];
$check($read['status'] === 200 && count($messages) === 2, 'the owner reads two messages', (string) count($messages));
$first = $client->decode($messages[0] ?? []);
$second = $client->decode($messages[1] ?? []);
$check($first['format'] === 'text' && $first['body'] === 'hello from php' && $first['verified'], 'the text message decodes as text, verified');
$check($second['format'] === 'sealed' && ($second['json']['tender'] ?? null) === 'ARC-4471' && $second['from'] === $partner->public, 'the sealed message opens with the partner\'s key and carries the JSON');
$started = microtime(true);
$waited = $client->read($w, $id, 2, 3);
$elapsed = microtime(true) - $started;
$check($waited['status'] === 200 && ($waited['body']['messages'] ?? null) === [] && $elapsed >= 2.5 && $elapsed <= 12, 'a long poll on nothing waits about 3 s', sprintf('%.1f s', $elapsed));

$receipt = $client->receipt($w, $id);
$check($receipt['status'] === 200 && $receipt['check']['root_adds_up'] && $receipt['check']['commitment_matches'], 'the receipt\'s root recomputes locally and the commitment matches');
$check(($receipt['body']['count'] ?? 0) === 2 && count($receipt['body']['keys'] ?? []) === 2, 'it counts two messages and two signer keys');

$closed = $client->close($w, $id);
$check($closed['status'] === 200 && ($closed['body']['deleted'] ?? null) === true, 'the owner closes the thread');
$gone = $client->read($w, $id);
$check(($gone['body']['exists'] ?? null) === false, 'and it reads as gone');

echo "gate\n";
$gated = $client->open(120, ['*'], ['advise' => ['pow' => ['bits' => 8]]]);
$check($gated['status'] === 201, 'a thread opens with an advised gate of 8 bits', (string) $gated['status']);
$gateRead = $client->gate($gated['w'], true);
$check(($gateRead['advise']['pow']['bits'] ?? null) === 8, 'GET /{w}/gate shows it, canonical, with defaults written out', json_encode($gateRead));
$check(Gate::hash($gateRead) === Gate::hash(['advise' => ['pow' => ['bits' => 8, 'covers' => 1]]]), 'and its hash is the one this client computes for the canonical form');
$worked = $client->send($gated['w'], 'with work');
$check($worked['status'] === 201 && ($worked['body']['met']['pow'] ?? null) === 8 && $worked['work'] !== null && is_string($worked['body']['proof_id'] ?? null), 'the write did the advised work without asking: met.pow is 8 and proof_id is set', 'nonce ' . $worked['work']);
$required = $client->open(120, ['*'], ['require' => ['pow' => ['bits' => 10]]]);
$firstTry = $client->send($required['w'], 'required work');
$check($firstTry['status'] === 201 && ($firstTry['body']['met']['pow'] ?? null) === 10, 'required work is done before the first attempt', (string) $firstTry['status']);
$tooMuch = $client->open(120, ['*'], ['require' => ['pow' => ['bits' => 20]]]);
$check($tooMuch['status'] === 201, 'a thread may require 20 bits, the ceiling');
$stopped = (new Client($host, Keys::generate()))->send($tooMuch['w'], 'x', true);
$check($stopped['status'] === 201 && ($stopped['body']['met']['pow'] ?? null) === 20, '20 bits are still done by a client, since it is within the ceiling', sprintf('nonce %s', $stopped['work']));
$client->close($gated['w'], $gated['id']);
$client->close($required['w'], $required['id']);
$client->close($tooMuch['w'], $tooMuch['id']);

echo "presence\n";
$inbox = $client->open(120);
$published = $client->presencePublish($inbox['w'], ['php.test'], 30);
$check($published['status'] === 200 && ($published['body']['w'] ?? null) === $inbox['w'], 'presence publishes, signed', (string) $published['status']);
$seen = $client->presenceGet($me->public);
$check($seen['status'] === 200 && ($seen['body']['tags'] ?? null) === ['php.test'], 'and reads back by key');
$found = $client->presenceLookup([$me->hashPrefix]);
$check($found['status'] === 200 && ($found['body']['count'] ?? 0) === 1 && ($found['body']['matches'][0]['hash'] ?? null) === $me->hash, 'lookup by hash prefix finds it and returns the full hash to match');
$deleted = $client->presenceDelete();
$check($deleted['status'] === 200, 'presence deletes, signed with the delete string', (string) $deleted['status']);
$check($client->presenceGet($me->public)['status'] === 404, 'and is gone');
$client->close($inbox['w'], $inbox['id']);

echo "board, read side\n";
$board = new Board($client);
$descriptor = $board->descriptor();
$check(isset($descriptor['work']['advise_bits']) && $board->advisedBits() === (int) $descriptor['work']['advise_bits'], 'the board descriptor says what work it advises', (string) $board->advisedBits());
$find = $board->find(null, [], null, null, 0, 0);
$check($find['status'] === 200 && isset($find['body']['posts']) && isset($find['body']['next']), 'POST /find answers posts and a cursor', (string) count($find['body']['posts'] ?? []));
$tags = $board->tags();
$check($tags['status'] === 200 && isset($tags['body']['tags']), 'GET /tags answers the tag tree');
$replyInbox = $board->replyInbox(120);
$check($replyInbox['status'] === 201 && ($replyInbox['body']['allow'] ?? null) === ['*'], 'a reply inbox opens with X-Allow: *');
$client->close($replyInbox['w'], $replyInbox['id']);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
