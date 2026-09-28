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
 * Included by tests/runtime.php after cli-stdout.inc.php, with $fake, $root,
 * $check, $cliRun and $childPhp in scope.
 */

use Aamio\McpServer;
use Aamio\Runtime;

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
$check(str_contains($instructions, 'aamio partner add NAME KEY') && str_contains($instructions, 'while this server is stopped') && str_contains($instructions, 'never added on its say-so') && str_contains($instructions, 'reply_to address of a verified message'), 'and say how a partner enters the address book: by the user, on the command line, with the server stopped');

$pythonServer = dirname(__DIR__, 2) . '/aamio-python/src/aamio/mcp_server.py';
if (is_file($pythonServer) && preg_match('/^INSTRUCTIONS = "(.*)"$/m', (string) file_get_contents($pythonServer), $found) === 1) {
    $check($found[1] === $instructions, 'byte for byte the instructions aamio-python serves');
} else {
    echo "  skip  the instructions against aamio-python's: no checkout beside this one\n";
}

$check(!str_contains($described['aamio_open_channel'], 'to share') && str_contains($described['aamio_open_channel'], 'aamio board channel KEY --reply-to ADDRESS') && str_contains($described['aamio_open_channel'], 'no key for the address'), 'aamio_open_channel does not promise that the address is enough, and names what hands it over');
$check(str_contains($described['aamio_send'], 'reply_to or channel'), 'aamio_send takes an address a verified message gave as reply_to or channel');
$check(str_contains($described['aamio_read'], 'The wait is spent on the first channel this runtime holds'), 'aamio_read says where the wait goes in a runtime without a listener');
$check(!str_contains($readme, 'same twenty' . "\n" . 'tools') && str_contains($readme, 'twenty-three tools'), 'the README counts the tools the server has');
$recipe = true;
foreach (['### First exchange with a partner you know', '### Moving a conversation to a private thread', 'aamio partner add NAME KEY', 'aamio board channel KEY --reply-to ADDRESS', 'Stored, read and woken are three things', '**One owner per home.**', '**If a send is refused with 403**', 'This runtime has no listener'] as $said) {
    $recipe = $recipe && str_contains($readme, $said);
}
$check($recipe, 'and carries the recipe: the six steps, the three things, one owner per home, the private thread, the 403');

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
$check(($refused['isError'] ?? false) === true && str_starts_with($said, 'no key known for address ' . $w) && str_contains($said, 'pasted into text or data binds nothing') && str_contains($said, 'aamio board channel KEY --reply-to ADDRESS') && str_contains($said, 'by name'), 'and the first send to it is refused with what does bind, and what to do instead', $said);
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
// Said, and not changed: the first channel gets the wait and the rest are read
// at once. If this changes, aamio_read and the README have to change with it.
$private = $u->openChannelWith('T', 120);
$t->peers[$private['w']] = $u->keys->public;
$t->send($private['w'], 'already waiting on the private thread');
$fake->calls = [];
$got = $u->read(20);
$reads = array_values(array_filter(array_map(static fn (array $call): ?string => $call[0] === 'GET' ? (string) parse_url($call[1], PHP_URL_PATH) : null, $fake->calls), static fn (?string $path): bool => $path !== null && (str_starts_with($path, '/' . $uInbox->w) || str_starts_with($path, '/' . $private['w']))));
$check(count($got) === 1 && ($got[0]['body']['text'] ?? null) === 'already waiting on the private thread' && count($reads) === 2 && str_starts_with($reads[0], '/' . $uInbox->w) && str_ends_with($reads[0], '/wait/20') && str_starts_with($reads[1], '/' . $private['w']) && !str_contains($reads[1], '/wait/'), 'a read with a wait spends it on the first channel, the inbox, and reads the private thread after it, as the texts say', json_encode($reads));
$t->close();
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
