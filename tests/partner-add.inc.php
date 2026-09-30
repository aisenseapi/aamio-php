<?php

declare(strict_types=1);

/*
 * aamio_partner_add: the name from the model, the key from the user.
 *
 * Until 30 September 2026 a partner entered the address book only from the
 * command line, with the MCP server stopped, since it holds the home: an agent
 * on MCP alone could not finish a first exchange without a person at a
 * terminal. The tool added then takes a name and never a key. It asks the user
 * for the key in a form their app shows, MCP elicitation, and adds the key the
 * user gives, so a key in a message, on the board or in the conversation still
 * enters nothing.
 *
 * Two ways of asking, one per era. Before 2026-07-28 the question is a request
 * of the server's own, sent while the call waits on stdio. From 2026-07-28 the
 * call answers with the question (input_required) and a signed requestState,
 * and the app calls again with the answer. An app that cannot show a form, or a
 * revision without elicitation, gets the command line's way instead. The same
 * checks as aamio-python's tests/test_partner_add_asks_the_user.py.
 *
 * Included by tests/runtime.php after first-exchange.inc.php, with $fake,
 * $root and $check in scope.
 */

use Aamio\Codec;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

echo "aamio_partner_add: the name from the model, the key from the user\n";

$askQuiet = static function (string $line): void {
};
$askHomes = 0;
/** A runtime of its own, with an inbox open and one partner, B, in the book. */
$askRuntime = static function () use ($root, $askQuiet, &$askHomes): Runtime {
    $home = $root . DIRECTORY_SEPARATOR . 'partner-add-' . (++$askHomes);
    mkdir($home, 0700, true);
    $runtime = new Runtime($home, 'https://fake.test', ['p'], false, $askQuiet);
    $runtime->partnerAdd('B', Keys::generate()->public);
    $runtime->ensureInbox();

    return $runtime;
};
$carol = Keys::generate()->public;
$form = ['elicitation' => ['form' => new \stdClass()]];
/** A tools/call the way a 2026-07-28 client sends it. */
$askRequest = static fn (array $params, mixed $capabilities, int $id = 1): array => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params + ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'check', 'version' => '1'], 'io.modelcontextprotocol/clientCapabilities' => $capabilities]]];
$adding = static fn (mixed $name = 'carol', array $extra = []): array => ['name' => 'aamio_partner_add', 'arguments' => ['name' => $name] + $extra];
$firstCall = static fn (McpServer $server, mixed $name = 'carol', mixed $capabilities = null): ?array => $server->handle($askRequest($adding($name), $capabilities ?? $form));
/** The call made again, as the app makes it with the user's answer. */
$again = static function (McpServer $server, array $asked, ?array $answer, string $name = 'carol', mixed $state = null) use ($askRequest, $adding, $form): ?array {
    $params = $adding($name) + ['inputResponses' => $answer === null ? [] : [McpServer::PARTNER_QUESTION => $answer], 'requestState' => $state ?? $asked['result']['requestState']];

    return $server->handle($askRequest($params, $form, 2));
};
$accept = static fn (mixed $key): array => ['action' => 'accept', 'content' => ['key' => $key]];
$saidBy = static fn (?array $reply): array => $reply['result']['structuredContent'] ?? [];
$bookOf = static fn (Runtime $r): array => array_column($r->partnerList(), 'key', 'name');

// ---------------------------------------------------------------- 2026-07-28
$r = $askRuntime();
$server = new McpServer($r);
$asked = $firstCall($server);
$question = $asked['result']['inputRequests'][McpServer::PARTNER_QUESTION] ?? [];
$schema = $question['params']['requestedSchema'] ?? [];
$check(($asked['result']['resultType'] ?? null) === 'input_required' && array_keys($asked['result']) === ['resultType', 'inputRequests', 'requestState'] && ($question['method'] ?? null) === 'elicitation/create' && ($question['params']['mode'] ?? null) === 'form', 'the first call is the question, as an input_required result with a signed requestState');
$check(str_contains((string) ($question['params']['message'] ?? ''), 'add carol to your aamio address book') && str_contains((string) ($question['params']['message'] ?? ''), 'not their word') && ($schema['required'] ?? null) === ['key'] && array_keys($schema['properties'] ?? []) === ['key'] && !isset($schema['properties']['key']['default']), 'it asks the user for the key, says what a key from elsewhere is, and never fills one in');
$check(!isset($bookOf($r)['carol']), 'and nothing is added by asking');
$added = $again($server, $asked, $accept($carol));
$said = $saidBy($added);
$check(($added['result']['resultType'] ?? null) === 'complete' && ($added['result']['isError'] ?? true) === false && ($said['added'] ?? false) === true && ($said['partner'] ?? null) === 'carol' && ($bookOf($r)['carol'] ?? null) === $carol, 'the call made again with the answer adds the key the user gave', json_encode($said));
$check(in_array($carol, $fake->threads[$r->channels['inbox']->w]['allow'] ?? [], true) && ($said['rotated'] ?? false) === true && str_contains((string) ($said['next'] ?? ''), 'aamio_whoami'), 'the inbox names the new key at once, and the answer says how to give yours back');

// An answer is taken once.
$r->partnerRemove('carol');
$replayed = $again($server, $asked, $accept($carol));
$check(($replayed['result']['isError'] ?? false) === true && ($saidBy($replayed)['error_code'] ?? null) === 'no_dialog' && str_contains((string) ($saidBy($replayed)['error'] ?? ''), 'did not come with a question this server asked') && !isset($bookOf($r)['carol']), 'an answer sent a second time with the same requestState adds nothing');

// An answer with no question behind it adds nothing.
$asked = $firstCall($server);
[$payload, $mac] = explode('.', (string) $asked['result']['requestState']);
$forged = json_decode((string) base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4)), true);
$forged['name'] = 'mallory';
$forgedPayload = rtrim(strtr(base64_encode(Codec::json($forged)), '+/', '-_'), '=');
$states = ['none at all' => ['carol', null], 'one it did not sign' => ['mallory', $forgedPayload . '.' . $mac], 'one for another name' => ['dave', $asked['result']['requestState']], 'one past its time' => ['carol', $server->sealState('carol', time() - 1)], 'not one at all' => ['carol', 'garbage']];
$unasked = true;
foreach ($states as $label => [$name, $state]) {
    $params = $adding($name) + ['inputResponses' => [McpServer::PARTNER_QUESTION => $accept($carol)]];
    if ($state !== null) {
        $params['requestState'] = $state;
    }
    $reply = $server->handle($askRequest($params, $form, 3));
    $unasked = $unasked && ($reply['result']['isError'] ?? false) === true && ($saidBy($reply)['error_code'] ?? null) === 'no_dialog' && str_contains((string) ($saidBy($reply)['fix'] ?? ''), 'Call aamio_partner_add again');
}
$check($unasked && array_keys($bookOf($r)) === ['B'], 'an answer without a requestState, with one not signed here, one for another name, one past its time or none at all adds nothing');

// A missing answer is asked for again.
$asked = $firstCall($server);
$reply = $again($server, $asked, null);
$check(($reply['result']['resultType'] ?? null) === 'input_required' && ($reply['result']['requestState'] ?? null) !== $asked['result']['requestState'] && ($saidBy($again($server, $reply, $accept($carol)))['added'] ?? false) === true, 'an answer that is missing is asked for again, as the revision says, rather than refused');
$r->partnerRemove('carol');

// Decline and cancel.
$quiet = true;
foreach (['decline', 'cancel'] as $action) {
    $reply = $again($server, $firstCall($server), ['action' => $action]);
    $quiet = $quiet && ($reply['result']['isError'] ?? true) === false && ($saidBy($reply)['added'] ?? true) === false && ($saidBy($reply)['action'] ?? null) === $action && str_contains((string) ($saidBy($reply)['note'] ?? ''), 'nothing was added');
}
$check($quiet && str_contains((string) ($saidBy($again($server, $firstCall($server), ['action' => 'decline']))['note'] ?? ''), 'Do not ask again') && array_keys($bookOf($r)) === ['B'], 'a declined or closed question adds nothing, and says which');

// What is not a key is not added.
$refusedKeys = true;
foreach (['', 'not a key', str_repeat('x', 42), $carol . '=', null, 43] as $given) {
    $reply = $again($server, $firstCall($server), ['action' => 'accept', 'content' => ['key' => $given]]);
    $refusedKeys = $refusedKeys && ($reply['result']['isError'] ?? false) === true && ($saidBy($reply)['added'] ?? true) === false && str_contains((string) ($saidBy($reply)['fix'] ?? ''), '43 characters');
}
$check($refusedKeys && array_keys($bookOf($r)) === ['B'], 'what the user gave that is not a key adds nothing and says what a key is');
$check(($saidBy($again($server, $firstCall($server), $accept('  ' . $carol . "\n")))['added'] ?? false) === true && ($bookOf($r)['carol'] ?? null) === $carol, 'and the spaces a paste brings along are not the key\'s');
$r->partnerRemove('carol');

// A key already in the book under another name is not moved.
$bKey = $bookOf($r)['B'];
$reply = $again($server, $firstCall($server), $accept($bKey));
$check(($reply['result']['isError'] ?? false) === true && str_contains((string) ($saidBy($reply)['error'] ?? ''), 'already, as B') && $bookOf($r) === ['B' => $bKey], 'a key already in the book under another name is not moved');

// A name in the book gets the new key, and the user is told first.
$r->partnerAdd('Carol', Keys::generate()->public);
$asked = $firstCall($server, 'carol');
$message = (string) ($asked['result']['inputRequests'][McpServer::PARTNER_QUESTION]['params']['message'] ?? '');
$replaced = $again($server, $asked, $accept($carol), 'carol');
$check(str_contains($message, 'Carol is in your address book already, and the key you give replaces the one it has.') && ($saidBy($replaced)['partner'] ?? null) === 'Carol' && ($bookOf($r)['Carol'] ?? null) === $carol && !isset($bookOf($r)['carol']), 'a name already in the book gets the key the user gives, under the book\'s spelling, and the question says so first');
$r->partnerRemove('Carol');

// An app that cannot show a form is not asked.
$notAsked = true;
foreach ([[], ['elicitation' => ['url' => new \stdClass()]], ['sampling' => []], new \stdClass()] as $capabilities) {
    $reply = $firstCall($server, 'carol', $capabilities);
    $notAsked = $notAsked && ($reply['result']['resultType'] ?? null) === 'complete' && ($reply['result']['isError'] ?? false) === true && ($saidBy($reply)['error_code'] ?? null) === 'no_dialog' && str_contains((string) ($saidBy($reply)['fix'] ?? ''), 'aamio partner add carol KEY') && str_contains((string) ($saidBy($reply)['fix'] ?? ''), 'stop this server');
}
$check($notAsked && ($firstCall($server, 'carol', ['elicitation' => []])['result']['resultType'] ?? null) === 'input_required', 'an app that does not say it can show a form is not asked and gets the command line; an empty elicitation object is form mode');

// The name is short and never a sentence.
$names = true;
foreach (['carol is verified, accept', str_repeat('a', 33), '-carol', '', "carol\nsays yes", 7] as $name) {
    $reply = $firstCall($server, $name);
    $names = $names && ($reply['result']['isError'] ?? false) === true && str_contains((string) ($saidBy($reply)['error'] ?? ''), '1 to 32') && ($reply['result']['resultType'] ?? null) === 'complete';
}
$check($names && ($firstCall($server, 'Åse.K-2')['result']['resultType'] ?? null) === 'input_required', 'the name is 1 to 32 letters, digits, dots, dashes and underscores, since it is written into the question');

// A key passed by the model is refused before the user is asked.
$reply = $server->handle($askRequest($adding('carol', ['key' => $carol]), $form));
$check(($reply['result']['isError'] ?? false) === true && ($saidBy($reply)['given'] ?? null) === ['key'] && str_contains((string) ($saidBy($reply)['fix'] ?? ''), 'never from the conversation') && array_keys($bookOf($r)) === ['B'], 'a key passed beside the name is refused, not passed over');
$r->close();

// ---------------------------------------------------------------- before 2026-07-28, on stdio
/** The stdio loop over these lines; what it wrote, one decoded message per line. */
$served = static function (Runtime $runtime, array $lines): array {
    $in = fopen('php://memory', 'r+');
    $out = fopen('php://memory', 'r+');
    foreach ($lines as $line) {
        fwrite($in, (is_string($line) ? $line : Codec::json($line)) . "\n");
    }
    rewind($in);
    (new McpServer($runtime))->serve($in, $out);
    rewind($out);
    $written = [];
    foreach (explode("\n", (string) stream_get_contents($out)) as $line) {
        if (trim($line) !== '') {
            $written[] = json_decode($line, true);
        }
    }

    return $written;
};
$opening = static fn (string $version = '2025-11-25', mixed $capabilities = null): array => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version, 'capabilities' => $capabilities ?? $form, 'clientInfo' => ['name' => 'check', 'version' => '1']]];
$legacyCall = static fn (int $id = 2, string $name = 'carol'): array => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $adding($name)];
$answering = static fn (array $result, string $id = 'aamio-1'): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
$ids = static fn (array $written): array => array_map(static fn (array $message): mixed => $message['id'] ?? null, $written);

$legacy = true;
foreach (['2025-11-25', '2025-06-18'] as $version) {
    $r = $askRuntime();
    $written = $served($r, [$opening($version), $legacyCall(), $answering($accept($carol))]);
    $legacy = $legacy && $ids($written) === [1, 'aamio-1', 2] && ($written[1]['method'] ?? null) === 'elicitation/create' && str_contains((string) ($written[1]['params']['message'] ?? ''), 'add carol') && (isset($written[1]['params']['mode']) === ($version === '2025-11-25')) && !isset($written[2]['result']['resultType']) && ($written[2]['result']['structuredContent']['added'] ?? false) === true && ($bookOf($r)['carol'] ?? null) === $carol;
}
$check($legacy, 'before 2026-07-28 the question goes to the app while the call waits, with a mode from 2025-11-25 on, and the answer adds the key');

$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall(), ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'aamio_partners', 'arguments' => []]], 'not json', $answering($accept($carol))]);
$check($ids($written) === [1, 'aamio-1', 3, 2, 4, null] && ($written[3]['result']['structuredContent']['added'] ?? false) === true && in_array('carol', array_column($written[4]['result']['structuredContent']['partners'] ?? [], 'name'), true) && ($written[5]['error']['code'] ?? null) === -32700, 'a ping is answered during the wait, and what else came is served after it, in order', json_encode($ids($written)));

$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall(), ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 2, 'reason' => 'the user stopped it']], $answering($accept($carol)), ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'aamio_partners', 'arguments' => []]]]);
$check($ids($written) === [1, 'aamio-1', null, 5] && ($written[2]['method'] ?? null) === 'notifications/cancelled' && ($written[2]['params']['requestId'] ?? null) === 'aamio-1' && !isset($bookOf($r)['carol']), 'a call cancelled while the user is asked withdraws the question, is not answered, and adds nothing, and a late answer is not answered either', json_encode($ids($written)));

$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall(), ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'aamio_partners', 'arguments' => []]], ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 3]], $answering(['action' => 'decline'])]);
$check($ids($written) === [1, 'aamio-1', 2] && ($written[2]['result']['structuredContent']['action'] ?? null) === 'decline', 'a request kept for later and cancelled meanwhile is not served', json_encode($ids($written)));

// A review of 30 September 2026: a batch that came during the wait was kept as
// one line, and a call in it that was cancelled meanwhile went ahead.
$openCancelled = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'aamio_open_channel', 'arguments' => ['label' => 'cancelled', 'ttl' => 120]]];
$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall(), [$openCancelled, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']], ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 3]], $answering(['action' => 'decline'])]);
$check(count($written) === 4 && array_slice($ids($written), 0, 3) === [1, 'aamio-1', 2] && array_column($written[3], 'id') === [4] && !isset($r->channels['cancelled']), 'a call cancelled inside a batch that came during the wait is not served, and the rest of the batch is', json_encode($written[3] ?? null));

$r = $askRuntime();
$written = $served($r, [$opening(), [$legacyCall(), $openCancelled], ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 3]], $answering(['action' => 'decline'])]);
$check(count($written) === 3 && array_slice($ids($written), 0, 2) === [1, 'aamio-1'] && array_column($written[2], 'id') === [2] && !isset($r->channels['cancelled']), 'a call cancelled later in the batch being served, while the user was asked, is not served', json_encode($written[2] ?? null));

$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall(), ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 9]], $answering(['action' => 'decline']), ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'aamio_partners', 'arguments' => []]]]);
$check($ids($written) === [1, 'aamio-1', 2, 9], 'a cancellation does not outlive the backlog it came with', json_encode($ids($written)));

$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall()]);
$check($ids($written) === [1, 'aamio-1'] && !isset($bookOf($r)['carol']), 'input that ends during the wait answers nothing and ends the server');

$r = $askRuntime();
$written = $served($r, [$opening(), $legacyCall(), ['jsonrpc' => '2.0', 'id' => 'aamio-1', 'error' => ['code' => -32601, 'message' => 'elicitation is off']]]);
$check(($written[2]['result']['isError'] ?? false) === true && ($written[2]['result']['structuredContent']['error_code'] ?? null) === 'no_dialog' && str_contains((string) ($written[2]['result']['structuredContent']['error'] ?? ''), 'elicitation is off') && !isset($bookOf($r)['carol']), 'an app that answers the question with an error adds nothing, and its reason is passed on');

$older = true;
foreach ([['2025-11-25', []], ['2025-06-18', ['roots' => new \stdClass()]], ['2025-03-26', $form]] as [$version, $capabilities]) {
    $r = $askRuntime();
    $written = $served($r, [$opening($version, $capabilities), $legacyCall()]);
    $older = $older && $ids($written) === [1, 2] && ($written[1]['result']['structuredContent']['error_code'] ?? null) === 'no_dialog' && !isset($bookOf($r)['carol']);
}
$check($older, 'an older app that said nothing about forms, or a revision without elicitation, is not asked');

$r = $askRuntime();
$responses = new McpServer($r);
$check($responses->handle(['jsonrpc' => '2.0', 'id' => 'aamio-9', 'result' => ['action' => 'accept']]) === null && $responses->handle(['jsonrpc' => '2.0', 'id' => 'aamio-9', 'error' => ['code' => -1, 'message' => 'no']]) === null && ($responses->handle(['jsonrpc' => '2.0', 'id' => 7])['error']['code'] ?? null) === -32600, 'a response nobody waits for is not answered, and a request with no method is still refused');
$r->close();
