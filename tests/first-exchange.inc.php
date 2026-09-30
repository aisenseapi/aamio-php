<?php

declare(strict_types=1);

/*
 * The first exchange with a partner: what the texts say, and that it is so.
 *
 * Item 5 of the acceptance list from round 2 (21 September 2026),
 * built on 28 September. No surface said how a partner enters the address book
 * of an agent that only has MCP, and the tool that opens a channel said its
 * address was "to share", which leads to a send the partner's runtime cannot
 * make. Nothing about what binds or who may write is changed here. What is
 * checked is that the dead ends are said where an agent meets them, that the
 * way that works still works, and that the texts describe this runtime and not
 * the Python one where the two differ: there is no listener here.
 *
 * On 30 September 2026 two of the dead ends got a way through over MCP:
 * aamio_open_channel hands an address over with to, and a read asks every
 * channel before it waits. aamio_partner_add is checked in partner-add.inc.php.
 *
 * Included by tests/runtime.php after cli-stdout.inc.php, with $fake, $root,
 * $check, $cliRun and $childPhp in scope.
 */

use Aamio\Codec;
use Aamio\Http;
use Aamio\McpServer;
use Aamio\Runtime;
use Aamio\SendFailed;

echo "the first exchange: what the texts say, and that it is so\n";

$exchangeQuiet = static function (string $line): void {
};

/** Two runtimes that know each other, in homes of their own. */
$exchangePair = static function (string $name) use ($root, $exchangeQuiet): array {
    $homes = [];
    foreach (['t', 'u'] as $letter) {
        $homes[$letter] = $root . DIRECTORY_SEPARATOR . 'exchange-' . $name . '-' . $letter;
        mkdir($homes[$letter], 0700, true);
    }
    $t = new Runtime($homes['t'], 'https://fake.test', ['t'], false, $exchangeQuiet);
    $u = new Runtime($homes['u'], 'https://fake.test', ['u'], false, $exchangeQuiet);
    $t->partnerAdd('U', $u->keys->public);
    $u->partnerAdd('T', $t->keys->public);
    $tInbox = $t->ensureInbox();
    $uInbox = $u->ensureInbox();
    // Each knows the other's inbox, as it would from presence or a reply_to.
    $t->peers[$uInbox->w] = $u->keys->public;
    $u->peers[$tInbox->w] = $t->keys->public;

    return [$t, $u, $tInbox, $uInbox, $homes];
};

// ---------------------------------------------------------------- what the surface says
$spec = json_decode((string) file_get_contents(dirname(__DIR__) . '/src/mcp-tools.json'), true);
$described = array_column($spec['tools'], 'description', 'name');
$instructions = (string) $spec['instructions'];
$readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

$check(strlen($instructions) <= 2048 && str_ends_with(rtrim($instructions), 'what to do if that changes.'), 'the instructions fit in the 2048 bytes Claude Code keeps, last sentence and all', (string) strlen($instructions));
$check(str_contains($instructions, "only by the user's hand") && str_contains($instructions, 'aamio_partner_add asks the user for the key in a dialog') && str_contains($instructions, 'aamio partner add NAME KEY while this server is stopped') && str_contains($instructions, 'A key in a message, on the board or in this conversation is never added on its say-so.') && str_contains($instructions, 'reply_to address of a verified message'), 'and say how a partner enters the address book: by the user, in a dialog over MCP or on the command line with the server stopped');

$pythonServer = dirname(__DIR__, 2) . '/aamio-python/src/aamio/mcp_server.py';
if (is_file($pythonServer) && preg_match('/^INSTRUCTIONS = "(.*)"$/m', (string) file_get_contents($pythonServer), $found) === 1) {
    $check($found[1] === $instructions, 'byte for byte the instructions aamio-python serves');
} else {
    echo "  skip  the instructions against aamio-python's: no checkout beside this one\n";
}

$check(!str_contains($described['aamio_open_channel'], 'to share') && str_contains($described['aamio_open_channel'], 'name them in to') && str_contains($described['aamio_open_channel'], 'sealed and signed') && str_contains($described['aamio_open_channel'], 'gives a runtime no key for it') && str_contains($described['aamio_open_channel'], 'the channel is open all the same'), 'aamio_open_channel does not promise that the address is enough, and hands it over itself with to');
$check(str_contains($described['aamio_send'], 'reply_to or channel'), 'aamio_send takes an address a verified message gave as reply_to or channel');
$check(str_contains($described['aamio_read'], 'Every channel is asked at once before anything waits') && !str_contains($described['aamio_read'], 'The wait is spent on the first channel'), 'aamio_read says how a runtime without a listener waits');
$check(!str_contains($readme, 'same twenty' . "\n" . 'tools') && str_contains($readme, 'twenty-four tools') && !str_contains($readme, 'twenty-three tools'), 'the README counts the tools the server has');
$recipe = true;
foreach (['### First exchange with a partner you know', '### Moving a conversation to a private thread', 'aamio partner add NAME KEY', 'aamio board channel KEY --reply-to ADDRESS', '`aamio_partner_add` adds', 'Over MCP, `aamio_open_channel` does the same with `to`', 'Stored, read and woken are three things', '**One owner per home.**', '**If a send is refused with 403**', 'This runtime has no listener and no background thread. A read asks every open'] as $said) {
    $recipe = $recipe && str_contains($readme, $said);
}
foreach (['spends its wait on the first channel', 'There is no tool for it'] as $gone) {
    $recipe = $recipe && !str_contains($readme, $gone);
}
$check($recipe, 'and carries the recipe: the six steps, the three things, one owner per home, the private thread over both surfaces, the 403');

// ---------------------------------------------------------------- an address that binds nothing
[$t, $u, $tInbox, $uInbox] = $exchangePair('dead-end');
$opened = (new McpServer($t))->dispatch('aamio_open_channel', ['label' => 'tender', 'ttl' => 120, 'allow' => ['U']]);
$w = (string) ($opened['structuredContent']['w'] ?? '');
$check(($opened['isError'] ?? true) === false && isset($fake->threads[$w]), 'a channel opened over MCP is there, and its address comes back');
$asText = (new McpServer($t))->dispatch('aamio_send', ['to' => $uInbox->w, 'text' => 'write to ' . $w]);
$inData = (new McpServer($t))->dispatch('aamio_send', ['to' => $uInbox->w, 'text' => 'the address is in data', 'data' => ['channel' => $w]]);
[, $got] = $u->poll($uInbox);
$check(($asText['isError'] ?? true) === false && ($inData['isError'] ?? true) === false && count($got) === 2 && ($got[1]['body']['data'] ?? null) === ['channel' => $w] && !isset($got[1]['body']['channel']), 'passed on as text and in data, the two ways an agent on MCP has, it arrives as content');
$refused = (new McpServer($u))->dispatch('aamio_send', ['to' => $w, 'text' => 'first send to the address I was given']);
$said = (string) ($refused['structuredContent']['error'] ?? '');
$check(($refused['isError'] ?? false) === true && str_starts_with($said, 'no key known for address ' . $w) && str_contains($said, 'pasted into text or data binds nothing') && str_contains($said, 'aamio_open_channel with to') && str_contains($said, 'aamio board channel KEY --reply-to ADDRESS') && str_contains($said, 'by name'), 'and the first send to it is refused with what does bind, and what to do instead', $said);
$check($fake->threads[$w]['messages'] === [] && array_filter($u->outbox, static fn (array $e): bool => $e['w'] === $w) === [], 'with nothing written to the channel and nothing kept to be sent later');
$t->close();
$u->close();

// ---------------------------------------------------------------- an address that was handed over
[$t, $u, $tInbox, $uInbox] = $exchangePair('handed');
$channel = $t->openChannelWith('U', 120, null, $uInbox->w, 'moving here');
[, $invitation] = $u->poll($uInbox);
$check($channel['address_sent_to'] === $uInbox->w && count($invitation) === 1 && ($invitation[0]['body']['channel'] ?? null) === $channel['w'] && $invitation[0]['verified'] === true && ($invitation[0]['encrypted'] ?? null) === true, 'handed over with an address to send it to, the invitation arrives sealed, signed and with channel in its body');
$first = (new McpServer($u))->dispatch('aamio_send', ['to' => $channel['w'], 'text' => 'on the private thread']);
[, $arrived] = $t->poll($t->channels[$channel['label']]);
$check(($first['isError'] ?? true) === false && count($arrived) === 1 && ($arrived[0]['body']['text'] ?? null) === 'on the private thread' && $arrived[0]['verified'] === true && $arrived[0]['sender'] === 'U', 'and the first send to it arrives, over MCP, from a sender the address book knows', json_encode($first['structuredContent'] ?? null));
$t->close();
$u->close();

[$t, $u, $tInbox, $uInbox] = $exchangePair('not-sent');
$channel = $t->openChannelWith('U', 120);
$check($channel['address_sent_to'] === null && isset($fake->threads[$channel['w']]) && $fake->threads[$uInbox->w]['messages'] === [] && $t->outbox === [], 'without an address to send it to the channel is opened and nothing is sent');

// ---------------------------------------------------------------- where the wait goes
// Every channel is asked at once before anything waits, since 30 September
// 2026: mail already waiting on a private thread sat behind a quiet inbox for
// the whole wait. If this changes, aamio_read and the README change with it.
$readsOf = static fn (array $calls, array $addresses): array => array_values(array_filter(array_map(static fn (array $call): ?string => $call[0] === 'GET' ? (string) parse_url($call[1], PHP_URL_PATH) : null, $calls), static function (?string $path) use ($addresses): bool {
    if ($path === null || str_ends_with($path, '/gate') || str_ends_with($path, '/receipt')) {
        return false;
    }
    foreach ($addresses as $w) {
        if (str_starts_with($path, '/' . $w)) {
            return true;
        }
    }

    return false;
}));
$private = $u->openChannelWith('T', 120);
$t->peers[$private['w']] = $u->keys->public;
$t->send($private['w'], 'already waiting on the private thread');
$fake->calls = [];
$got = $u->read(20);
$reads = $readsOf($fake->calls, [$uInbox->w, $private['w']]);
$check(count($got) === 1 && ($got[0]['body']['text'] ?? null) === 'already waiting on the private thread' && count($reads) === 2 && str_starts_with($reads[0], '/' . $uInbox->w) && !str_contains($reads[0], '/wait/') && str_starts_with($reads[1], '/' . $private['w']) && !str_contains($reads[1], '/wait/'), 'a read with a wait asks the inbox and the private thread at once, and does not wait when something is there', json_encode($reads));

// Nothing waiting: the inbox waits, and a message written to the private thread
// meanwhile comes with the same answer, when the wait ends.
$fake->calls = [];
$written = false;
$exchangeHeld = Http::$override;
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake, $t, $private, &$written): array {
    if (!$written && $method === 'GET' && str_contains($url, '/wait/')) {
        $written = true;
        $t->send($private['w'], 'arrived during the wait');
    }

    return $fake($method, $url, $body, $headers);
};
try {
    $got = $u->read(20);
} finally {
    Http::$override = $exchangeHeld;
}
$reads = $readsOf($fake->calls, [$uInbox->w, $private['w']]);
$check(count($got) === 1 && ($got[0]['body']['text'] ?? null) === 'arrived during the wait' && count($reads) === 4 && str_starts_with($reads[0], '/' . $uInbox->w) && !str_contains($reads[0], '/wait/') && str_starts_with($reads[1], '/' . $private['w']) && !str_contains($reads[1], '/wait/') && str_starts_with($reads[2], '/' . $uInbox->w) && str_ends_with($reads[2], '/wait/20') && str_starts_with($reads[3], '/' . $private['w']) && !str_contains($reads[3], '/wait/'), 'with nothing waiting the inbox waits, and the private thread is asked again when the wait ends', json_encode($reads));
$check($u->attentionTaken() === [], 'and nothing about it is worth attention');
$t->close();
$u->close();

// ---------------------------------------------------------------- handed over over MCP
// What only the command line could do until 30 September 2026, with the server stopped.
[$t, $u, $tInbox, $uInbox] = $exchangePair('handed-mcp');
$opened = (new McpServer($t))->dispatch('aamio_open_channel', ['label' => 'tender', 'ttl' => 120, 'to' => 'U', 'note' => 'moving the tender here']);
$channel = $opened['structuredContent'] ?? [];
$check(($opened['isError'] ?? true) === false && ($channel['handed_to'] ?? null) === 'U' && ($channel['address_sent_to'] ?? null) === $uInbox->w && ($channel['allow'] ?? null) === ['U'] && ($fake->threads[$channel['w'] ?? '']['allow'] ?? null) === [$u->keys->public], 'aamio_open_channel with to finds the partner through presence and lets that key write', json_encode($channel));
[, $invitation] = $u->poll($uInbox);
$check(count($invitation) === 1 && ($invitation[0]['body']['channel'] ?? null) === ($channel['w'] ?? '') && ($invitation[0]['body']['text'] ?? null) === 'moving the tender here' && $invitation[0]['verified'] === true && ($invitation[0]['encrypted'] ?? null) === true && $invitation[0]['sender'] === 'T', 'the address arrives sealed and signed, with the note beside it');
$first = (new McpServer($u))->dispatch('aamio_send', ['to' => $channel['w'], 'text' => 'on the private thread']);
[, $arrived] = $t->poll($t->channels['tender']);
$check(($first['isError'] ?? true) === false && count($arrived) === 1 && ($arrived[0]['body']['text'] ?? null) === 'on the private thread' && $arrived[0]['sender'] === 'U', 'and the first send to it arrives, over MCP both ways', json_encode($first['structuredContent'] ?? null));

// A stranger who wrote to a board inbox is handed a channel at the address it gave.
$strangerHome = $root . DIRECTORY_SEPARATOR . 'exchange-stranger';
mkdir($strangerHome, 0700, true);
$stranger = new Runtime($strangerHome, 'https://fake.test', ['s'], false, $exchangeQuiet);
$strangerInbox = $stranger->ensureInbox();
$board = $t->ensureBoardInbox();
$stranger->peers[$board->w] = $t->keys->public;
$stranger->send($board->w, 'I have the log');
[, $heard] = $t->poll($t->channels['board']);
$deal = (new McpServer($t))->dispatch('aamio_open_channel', ['label' => 'deal', 'ttl' => 120, 'to' => $strangerInbox->w]);
[, $dealt] = $stranger->poll($strangerInbox);
$check(count($heard) === 1 && ($heard[0]['known_contact'] ?? true) === false && ($deal['isError'] ?? true) === false && ($deal['structuredContent']['allow'] ?? null) === [$stranger->keys->public] && ($deal['structuredContent']['handed_to'] ?? null) === $stranger->keys->public && ($dealt[0]['body']['channel'] ?? null) === ($deal['structuredContent']['w'] ?? ''), 'to takes an address a verified message gave as reply_to, as aamio_send does, and hands it to a stranger', json_encode($deal['structuredContent'] ?? null));
$stranger->close();

// Who and where are settled before a thread is opened.
$t->partnerAdd('D', \Aamio\Keys::generate()->public);
$threadsBefore = count($fake->threads);
$channelsBefore = array_keys($t->channels);
$tMcp = new McpServer($t);
$nobody = $tMcp->dispatch('aamio_open_channel', ['label' => 'one', 'ttl' => 120, 'to' => 'abcdefghijklmnopqrst']);
$away = $tMcp->dispatch('aamio_open_channel', ['label' => 'two', 'ttl' => 120, 'to' => 'D']);
$unknown = $tMcp->dispatch('aamio_open_channel', ['label' => 'three', 'ttl' => 120, 'to' => 'zed']);
$alone = $tMcp->dispatch('aamio_open_channel', ['label' => 'four', 'ttl' => 120, 'note' => 'moving here']);
$check(($nobody['isError'] ?? false) && str_starts_with((string) ($nobody['structuredContent']['error'] ?? ''), 'no key known for address abcdefghijklmnopqrst') && ($away['isError'] ?? false) && str_contains((string) ($away['structuredContent']['error'] ?? ''), 'not online right now') && ($unknown['isError'] ?? false) && str_contains((string) ($unknown['structuredContent']['error'] ?? ''), 'unknown partner') && ($alone['isError'] ?? false) && str_contains((string) ($alone['structuredContent']['error'] ?? ''), 'without to'), 'a handover that cannot reach anyone is refused with the reason: an address nobody bound, a partner not online, a name not in the book, a note with nobody to carry it to');
$check(count($fake->threads) === $threadsBefore && array_keys($t->channels) === $channelsBefore, 'and no thread was opened for any of them');

// The handover refused: the channel is open, and the answer says both.
$fake->refuse = true;
try {
    $refused = $tMcp->dispatch('aamio_open_channel', ['label' => 'late', 'ttl' => 120, 'to' => 'U']);
} finally {
    $fake->refuse = false;
}
$said = $refused['structuredContent'] ?? [];
$check(($refused['isError'] ?? false) === true && ($said['operation'] ?? null) === 'open_channel' && ($said['outcome'] ?? null) === 'refused' && ($said['opened']['label'] ?? null) === 'late' && str_starts_with((string) ($said['fix'] ?? ''), 'The channel late is open and listed by aamio_channels: only the message carrying its address did not go.') && isset($t->channels['late']), 'a handover the service refuses says the channel is open, and what did not go', json_encode($said));

// Whatever else stops it once the channel is open, the answer says the channel
// is there and whether anything left this machine. A review of 30 September
// 2026 found a file that would not write left the channel out of the answer.
$outboxFile = $t->home . DIRECTORY_SEPARATOR . 'outbox.json';
$toU = static fn (): int => count($fake->threads[$uInbox->w]['messages']);
$beforeU = $toU();
@unlink($outboxFile);
mkdir($outboxFile, 0700);
try {
    $partial = $tMcp->dispatch('aamio_open_channel', ['label' => 'partial', 'ttl' => 120, 'to' => 'U']);
} finally {
    rmdir($outboxFile);
}
$said = $partial['structuredContent'] ?? [];
$check(($partial['isError'] ?? false) === true && ($said['outcome'] ?? null) === 'never_sent' && ($said['error_code'] ?? null) === 'send_never_sent' && ($said['operation'] ?? null) === 'open_channel' && ($said['opened']['label'] ?? null) === 'partial' && isset($t->channels['partial']) && array_key_exists('message_id', $said) && $said['message_id'] === null, 'a handover whose record cannot be written says the channel is open and that nothing left', json_encode($said));
$check(str_starts_with((string) ($said['fix'] ?? ''), 'The channel partial is open and listed by aamio_channels') && str_contains((string) ($said['fix'] ?? ''), 'Close it with aamio_close_channel') && $toU() === $beforeU, 'and says what to do about a channel whose address nobody has, with nothing sent to the partner', json_encode($said));

// Stored by the service, and the record here could not be written after it.
$exchangeHeld = Http::$override;
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake, $uInbox, $outboxFile): array {
    $answer = $fake($method, $url, $body, $headers);
    if ($method === 'POST' && str_contains($url, '/' . $uInbox->w)) {
        @unlink($outboxFile);
        @mkdir($outboxFile, 0700);
    }

    return $answer;
};
try {
    $landed = $tMcp->dispatch('aamio_open_channel', ['label' => 'landed', 'ttl' => 120, 'to' => 'U']);
} finally {
    Http::$override = $exchangeHeld;
    @rmdir($outboxFile);
}
[, $arrivedU] = $u->poll($uInbox);
$check(($landed['isError'] ?? true) === false && str_contains((string) ($landed['structuredContent']['outbox_error'] ?? ''), 'outbox.json') && in_array($landed['structuredContent']['w'] ?? '', array_map(static fn (array $e): mixed => $e['body']['channel'] ?? null, $arrivedU), true), 'a handover the service stored is a handover, and the answer says the record here was not written', json_encode($landed['structuredContent'] ?? null));

// Posted, and the line broke before an answer came back.
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake, $uInbox): array {
    $answer = $fake($method, $url, $body, $headers);
    if ($method === 'POST' && str_contains($url, '/' . $uInbox->w)) {
        throw new \RuntimeException('reset by the remote host');
    }

    return $answer;
};
try {
    $unsure = $tMcp->dispatch('aamio_open_channel', ['label' => 'unsure', 'ttl' => 120, 'to' => 'U']);
} finally {
    Http::$override = $exchangeHeld;
}
$said = $unsure['structuredContent'] ?? [];
$check(($said['outcome'] ?? null) === 'unknown' && ($said['opened']['label'] ?? null) === 'unsure' && isset($t->outbox[$said['message_id'] ?? '']) && str_contains((string) ($said['fix'] ?? ''), 'aamio_outbox_retry'), 'a handover whose post broke on the way is unknown, keeps its id, and says the channel is open', json_encode($said));

// A note too long for one message opens nothing: asked before the channel is
// opened, since the second look of 30 September 2026 found it opened, refused
// here, and counted as a message that might have landed.
$threadsBefore = count($fake->threads);
$outboxBefore = array_keys($t->outbox);
$long = $tMcp->dispatch('aamio_open_channel', ['label' => 'long', 'ttl' => 120, 'to' => 'U', 'note' => str_repeat('x', 70000)]);
$said = (string) ($long['structuredContent']['error'] ?? '');
$check(($long['isError'] ?? false) === true && str_contains($said, 'no channel was opened') && str_contains($said, '65536') && count($fake->threads) === $threadsBefore && !isset($t->channels['long']) && array_keys($t->outbox) === $outboxBefore, 'a note too long to carry the address opens no channel and stores nothing', $said);
$fits = $tMcp->dispatch('aamio_open_channel', ['label' => 'short', 'ttl' => 120, 'to' => 'U', 'note' => str_repeat('x', 40000)]);
[, $arrivedU] = $u->poll($uInbox);
$check(($fits['isError'] ?? true) === false && in_array(str_repeat('x', 40000), array_map(static fn (array $e): mixed => $e['body']['text'] ?? null, $arrivedU), true), 'and one that fits goes with the address');

// Refused here before anything left is not a post, however the handover got there.
$handOver = new \ReflectionMethod(Runtime::class, 'handOver');
$bare = $t->openChannelWith('U', 120, 'bare');
try {
    $handOver->invoke($t, $t->channels[$bare['label']], $u->keys->public, $uInbox->w, str_repeat('x', 70000));
    $refusedHere = null;
} catch (SendFailed $notSent) {
    // Not $failed: the runner counts failures in that name.
    $refusedHere = $notSent;
}
$check($refusedHere !== null && $refusedHere->outcome === 'never_sent' && ($t->outbox[$refusedHere->messageId]['status'] ?? null) === 'stopped' && Runtime::outboxOutcome($t->outbox[$refusedHere->messageId]) === 'never_sent', 'a message the client refuses before it leaves is never sent, not unknown', $refusedHere === null ? 'no failure' : $refusedHere->outcome);
$t->close();
$u->close();

// The entry that could not be written is taken out again, and nothing is left
// to be sent after a restart: the second look of 30 September 2026 found it
// kept in memory, written by the next save that worked, made unknown by a
// restart, and offered for a retry of a channel already closed.
[$t, $u, $tInbox, $uInbox, $ghostHomes] = $exchangePair('ghost');
$tMcp = new McpServer($t);
$outboxFile = $t->home . DIRECTORY_SEPARATOR . 'outbox.json';
@unlink($outboxFile);
mkdir($outboxFile, 0700);
try {
    $partial = $tMcp->dispatch('aamio_open_channel', ['label' => 'partial', 'ttl' => 120, 'to' => 'U']);
    try {
        $t->send('U', 'not today either');
        $sendRefused = false;
    } catch (\RuntimeException) {
        $sendRefused = true;
    }
} finally {
    rmdir($outboxFile);
}
$check(($partial['structuredContent']['outcome'] ?? null) === 'never_sent' && $sendRefused && $t->outbox === [] && $t->outboxPending() === [], 'an entry whose outbox will not write is taken out again, for a handover and for a send');
$tMcp->dispatch('aamio_close_channel', ['label' => 'partial']);
$again = $tMcp->dispatch('aamio_open_channel', ['label' => 'again', 'ttl' => 120, 'to' => 'U']);
$t->close();
$restarted = new Runtime($ghostHomes['t'], 'https://fake.test', ['t'], false, $exchangeQuiet);
$check(($again['isError'] ?? true) === false && $restarted->outboxPending() === [] && count($restarted->outbox) === 1, 'after the advice is followed and the process restarts, nothing waits to be sent again');

// The save just before the post fails: the entry is stopped, and stays so.
$outboxAdd = new \ReflectionMethod(Runtime::class, 'outboxAdd');
$deliver = new \ReflectionMethod(Runtime::class, 'deliver');
$envelope = $restarted->keys->seal($u->keys->public, Codec::json(['text' => 'held']));
$held = $outboxAdd->invoke($restarted, $uInbox->w, $u->keys->public, $envelope, ['text' => 'held']);
$outboxFile = $restarted->home . DIRECTORY_SEPARATOR . 'outbox.json';
@unlink($outboxFile);
mkdir($outboxFile, 0700);
try {
    $deliver->invoke($restarted, $held);
    $stoppedHere = false;
} catch (\RuntimeException) {
    $stoppedHere = true;
} finally {
    rmdir($outboxFile);
}
$check($stoppedHere && ($restarted->outbox[$held['id']]['status'] ?? null) === 'stopped' && Runtime::outboxOutcome($restarted->outbox[$held['id']]) === 'never_sent' && !in_array($held['id'], array_column($restarted->outboxPending(), 'id'), true), 'a save that fails just before the post stops the message, and pending does not list it');
// Written by the next save that works, as it would be.
(new \ReflectionMethod(Runtime::class, 'saveOutbox'))->invoke($restarted);
$restarted->close();
$restartedAgain = new Runtime($ghostHomes['t'], 'https://fake.test', ['t'], false, $exchangeQuiet);
$check($restartedAgain->outboxPending() === [] && Runtime::outboxOutcome($restartedAgain->outbox[$held['id']] ?? null) === 'never_sent', 'and a restart keeps it stopped rather than offering it again');
$restartedAgain->close();
$u->close();

// ---------------------------------------------------------------- one owner per home
if (!isset($cliRun, $childPhp)) {
    echo "  skip  the command line beside a runtime that holds the home: no child PHP here\n";
} else {
    $heldHome = $root . DIRECTORY_SEPARATOR . 'exchange-held';
    mkdir($heldHome, 0700, true);
    $holder = new Runtime($heldHome, 'https://fake.test', ['held'], false, $exchangeQuiet);
    $heldLock = (string) file_get_contents($heldHome . DIRECTORY_SEPARATOR . 'lock');
    $friend = \Aamio\Keys::generate()->public;
    // A port where nothing listens: the command has to stop before it asks anyone.
    $beside = static fn (array $command): array => $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $heldHome, '--host', 'http://127.0.0.1:9'], $command));
    [$addStatus, $addOut, $addErr] = $beside(['partner', 'add', 'friend', $friend]);
    [$listStatus, $listOut, $listErr] = $beside(['partner', 'list']);
    $wanted = 'another aamio (pid ' . getmypid() . ') is using';
    $unknown = 'could not determine whether aamio (pid ' . getmypid() . ') is still using';
    $lockRefused = static fn (string $error): bool => str_contains($error, $wanted) || (str_contains($error, $unknown) && str_contains($error, 'the lock is left untouched'));
    $check($addStatus === 1 && $addOut === '' && $lockRefused($addErr) && $listStatus === 1 && $listOut === '' && $lockRefused($listErr), 'beside a runtime that holds the home, partner add and partner list stop with the owner or inconclusive inspection', trim($addErr . ' | ' . $listErr));
    $check(file_get_contents($heldHome . DIRECTORY_SEPARATOR . 'lock') === $heldLock && $holder->partnerList() === [] && !str_contains((string) @file_get_contents($heldHome . DIRECTORY_SEPARATOR . 'partners.json'), $friend), 'and the lock and address book are as they were');
    $holder->close();
}
