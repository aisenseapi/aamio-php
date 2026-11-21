<?php

declare(strict_types=1);

/*
 * A readable message to an inbox whose owner holds no key, and the secrets
 * that never go in one unasked.
 *
 * On 19 November 2026 a scope's key went to an agent that had only the hosted
 * endpoint, in a message that was signed and not sealed. The runtime refused
 * the address, since no key was known for it, so the message was written
 * around the runtime, and nothing asked anyone. Now the runtime writes to such
 * an address when told readable, says how the inbox was opened, and stops a
 * readable message that carries a scope key, a read key or the home's private
 * key, unless the user says yes: --allow-secret on the command line, a dialog
 * over MCP. The same checks as aamio-python's
 * tests/test_readable_to_open_channel.py.
 *
 * Included by tests/runtime.php, with $fake, $root and $check in scope.
 */

use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;
use Aamio\SecretInReadable;

echo "a readable message to an inbox without a key\n";

$readableQuiet = static function (string $line): void {
};
$readableCount = 0;
/** A runtime with an inbox, and an inbox at the fake whose owner it does not know. $hosted: opened through the hosted endpoint. */
$readableWriter = static function (bool $hosted = false) use ($root, $readableQuiet, &$readableCount, $fake): array {
    $readableCount++;
    $home = $root . DIRECTORY_SEPARATOR . 'readable-' . $readableCount;
    mkdir($home, 0700, true);
    $r = new Runtime($home, 'https://fake.test', ['readable'], false, $readableQuiet);
    $r->ensureInbox();
    // Twenty characters of a to z, one address per writer.
    $w = 'readablexinboxatfak' . chr(ord('a') + $readableCount);
    $fake->threads[$w] = ['id' => 'read-' . bin2hex(random_bytes(12)), 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => []]
        + ($hosted ? ['opened_via' => 'hosted'] : []);

    return [$r, $w];
};
$readablePosted = static fn (string $w): array => $fake->threads[$w]['messages'] ?? [];
$readableScope = static function (Runtime $r, string $name = 'team'): string {
    $r->scopeNew($name);
    foreach ($r->scopes as $scope) {
        if (($scope['name'] ?? null) === $name) {
            return (string) $scope['key'];
        }
    }

    return '';
};

// ---------------------------------------------------------------- the runtime
[$r, $w] = $readableWriter();
$sent = $r->send($w, 'hei fra en lokal runtime', null, null, null, true);
$message = $readablePosted($w)[0] ?? null;
$body = is_array($message) ? json_decode((string) $message['body'], true) : null;
$check(($sent['readable'] ?? null) === true && ($sent['to'] ?? null) === $w && str_contains((string) ($sent['readable_note'] ?? ''), 'the service can read it') && !isset($sent['opened_via'])
    && is_array($body) && ($body['text'] ?? null) === 'hei fra en lokal runtime' && ($body['reply_to'] ?? null) === $r->channels['inbox']->w
    && ($message['from'] ?? null) === $r->keys->public && ($message['sealed'] ?? null) === false,
    'a readable message goes signed and not sealed to an address without a key', json_encode([$sent, $message]));

$first = $r->send($w, 'ok', null, null, null, true);
$second = $r->send($w, 'ok', null, null, null, true);
$check($first['message_id'] !== $second['message_id'] && $first['sha256'] !== $second['sha256'] && count($readablePosted($w)) === 3,
    'the same words sent twice readable are two messages, not a replay of one', json_encode([$first['message_id'], $second['message_id']]));

try {
    $r->send($w, 'hei');
    $refused = null;
} catch (\Throwable $error) {
    $refused = $error;
}
$check($refused !== null && Runtime::errorCode($refused) === 'unknown_address' && str_contains($refused->getMessage(), '--readable') && str_contains($refused->getMessage(), 'hosted endpoint')
    && count($readablePosted($w)) === 3, 'without readable the address is refused, and the refusal names the way', $refused === null ? 'sent' : $refused->getMessage());

$bob = Keys::generate()->public;
$r->partnerAdd('bob', $bob);
$r->peers[$w] = $bob;
$codes = [];
foreach (['bob', $bob, $w] as $to) {
    try {
        $r->send($to, 'hei', null, null, null, true);
        $codes[] = 'sent';
    } catch (\Throwable $error) {
        $codes[] = Runtime::errorCode($error);
    }
}
unset($r->peers[$w]);
$check($codes === ['readable_with_key', 'readable_with_key', 'readable_with_key'] && count($readablePosted($w)) === 3, 'readable is refused where a key is known: a partner, a key, an address whose key is known', json_encode($codes));

$scopeKey = $readableScope($r);
try {
    $r->send($scopeKey, 'hei', null, null, null, true);
    $notAddress = null;
} catch (\Throwable $error) {
    $notAddress = $error;
}
$check($notAddress !== null && Runtime::errorCode($notAddress) === 'unknown_address' && !str_contains($notAddress->getMessage(), $scopeKey), 'what is not an address is refused without being said back');

$inboxKey = $r->channels['inbox']->readKey;
$seed = bin2hex($r->keys->seed());
$carrying = [
    'the key of a scope' => ['the key is ' . $scopeKey . ', keep it', null, 'the key of scope team', $scopeKey],
    'the read key of the inbox' => ['read it with ' . $inboxKey, null, 'the read key of your inbox', $inboxKey],
    'the private key' => ['my seed ' . $seed, null, "this home's private key", $seed],
    'a scope key in capitals' => ['THE KEY IS ' . strtoupper($scopeKey), null, 'the key of scope team', $scopeKey],
    'a scope key in data' => ['see data', ['nested' => ['scope_key' => $scopeKey]], 'the key of scope team', $scopeKey],
];
foreach ($carrying as $what => [$text, $data, $named, $value]) {
    $before = $r->outbox;
    $posts = count($readablePosted($w));
    try {
        $r->send($w, $text, $data, null, null, true);
        $stopped = null;
    } catch (SecretInReadable $error) {
        $stopped = $error;
    }
    $check($stopped !== null && $stopped->errorCode() === 'secret_in_readable' && in_array($named, $stopped->found, true) && $stopped->w === $w && $stopped->hosted === false
        && !str_contains(strtolower($stopped->getMessage()), strtolower($value)) && str_contains($stopped->getMessage(), 'Nothing was stored or sent') && str_contains($stopped->getMessage(), '--allow-secret')
        && $r->outbox === $before && count($readablePosted($w)) === $posts,
        'a readable message that carries ' . $what . ' is not stored or sent, and the error names it without saying it', $stopped === null ? 'sent' : json_encode($stopped->found));
}

$sent = $r->send($w, 'the key is ' . $scopeKey, null, null, null, true, true);
$last = $readablePosted($w)[count($readablePosted($w)) - 1] ?? [];
$check(($sent['readable'] ?? null) === true && str_contains((string) ($last['body'] ?? ''), $scopeKey), 'with allowSecret it is sent, and says it went readable');

// The service's answer says hosted when the gate read before did not.
$fake->threads[$w]['opened_via'] = 'hosted';
$sent = $r->send($w, 'hei igjen', null, null, null, true);
$check(($sent['opened_via'] ?? null) === 'hosted', "the service's answer says hosted when the gate read earlier did not", json_encode($sent));
$r->close();

[$h, $hw] = $readableWriter(true);
$hostedKey = $readableScope($h);
try {
    $h->send($hw, $hostedKey, null, null, null, true);
    $stopped = null;
} catch (SecretInReadable $error) {
    $stopped = $error;
}
$sent = $h->send($hw, 'hei', null, null, null, true);
$check($stopped !== null && $stopped->hosted === true && str_contains($stopped->getMessage(), 'hosted endpoint') && $h->openedVia($hw) === 'hosted'
    && ($sent['opened_via'] ?? null) === 'hosted' && str_contains((string) ($sent['readable_note'] ?? ''), 'send no secret there'),
    'an inbox opened through the hosted endpoint is named in the stop and in the answer', json_encode($sent));

try {
    $h->send($hw, 'hei', null, null, null, false, true);
    $loose = 'sent';
} catch (\Throwable $error) {
    $loose = Runtime::errorCode($error);
}
$check($loose === 'unknown_address', 'allowSecret alone writes nothing readable: the address is refused as before', $loose);

// ---------------------------------------------------------------- MCP
$server = new McpServer($h);
$asked = [];
$answering = static function (?string $action, ?bool $send) use (&$asked): callable {
    return static function (array $question, string $about, string $tool = 'aamio_partner_add', string $key = McpServer::PARTNER_QUESTION, mixed $held = null, bool $again = false) use ($action, $send, &$asked): array {
        $asked[] = ['question' => $question, 'about' => $about, 'tool' => $tool, 'key' => $key];

        return ['answer' => ['action' => $action] + ($send === null ? [] : ['content' => ['send' => $send]])];
    };
};
$arguments = ['to' => $hw, 'text' => 'the key is ' . $hostedKey, 'readable' => true];
$posts = count($readablePosted($hw));

$no = $server->dispatch('aamio_send', $arguments, $answering('decline', null));
$question = $asked[0]['question'] ?? [];
$check(($no['isError'] ?? true) === false && ($no['structuredContent']['sent'] ?? null) === false && ($no['structuredContent']['action'] ?? null) === 'decline'
    && ($asked[0]['tool'] ?? null) === 'aamio_send' && ($asked[0]['key'] ?? null) === McpServer::SECRET_QUESTION
    && str_contains((string) ($question['message'] ?? ''), 'the key of scope team') && str_contains((string) ($question['message'] ?? ''), 'hosted endpoint') && !str_contains(json_encode($question), $hostedKey)
    && ($question['requestedSchema']['required'] ?? null) === ['send'] && count($readablePosted($hw)) === $posts,
    'over MCP a secret in a readable message is put to the user, by name, and a no sends nothing', json_encode($no));

$unticked = $server->dispatch('aamio_send', $arguments, $answering('accept', false));
$check(($unticked['structuredContent']['sent'] ?? null) === false && count($readablePosted($hw)) === $posts, 'an accept with the box unticked sends nothing');

$yes = $server->dispatch('aamio_send', $arguments, $answering('accept', true));
$last = $readablePosted($hw)[count($readablePosted($hw)) - 1] ?? [];
$check(($yes['isError'] ?? true) === false && ($yes['structuredContent']['readable'] ?? null) === true && count($readablePosted($hw)) === $posts + 1 && str_contains((string) ($last['body'] ?? ''), $hostedKey),
    'and the yes sends it', json_encode($yes));

$posts = count($readablePosted($hw));
$noDialog = $server->dispatch('aamio_send', $arguments, null);
$said = $noDialog['structuredContent'] ?? [];
$check(($noDialog['isError'] ?? false) === true && ($said['error_code'] ?? null) === 'secret_in_readable' && ($said['sent'] ?? null) === false && ($said['carries'] ?? null) === ['the key of scope team']
    && str_contains((string) ($said['fix'] ?? ''), '--allow-secret') && !str_contains((string) json_encode($noDialog), $hostedKey) && count($readablePosted($hw)) === $posts,
    'over MCP without a dialog nothing is sent and the way is said', json_encode($said));

$asked = [];
$byModel = $server->dispatch('aamio_send', $arguments + ['allow_secret' => true], $answering('accept', true));
$wrongType = $server->dispatch('aamio_send', ['to' => $hw, 'text' => 'hei', 'readable' => 'yes'], $answering('accept', true));
$check(($byModel['isError'] ?? false) === true && ($byModel['structuredContent']['error_code'] ?? null) === 'invalid_arguments' && $asked === []
    && ($wrongType['structuredContent']['error_code'] ?? null) === 'invalid_arguments' && count($readablePosted($hw)) === $posts,
    'over MCP the model cannot say yes for the user, and readable is true or false');

$plain = $server->dispatch('aamio_send', ['to' => $hw, 'text' => 'hei', 'readable' => true], $answering('decline', null));
$check(($plain['structuredContent']['readable'] ?? null) === true && $asked === [] && count($readablePosted($hw)) === $posts + 1, 'a readable message without a secret goes without a question');

// 2026-07-28: the question is the result, and the answer belongs to that message.
$form = ['elicitation' => ['form' => new \stdClass()]];
$modern = static fn (array $params, int $id): array => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params + ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'check', 'version' => '1'], 'io.modelcontextprotocol/clientCapabilities' => $form]]];
$posts = count($readablePosted($hw));
$first = $server->handle($modern(['name' => 'aamio_send', 'arguments' => $arguments], 1));
$state = $first['result']['requestState'] ?? null;
$yesAnswer = [McpServer::SECRET_QUESTION => ['action' => 'accept', 'content' => ['send' => true]]];
$misused = $server->handle($modern(['name' => 'aamio_send', 'arguments' => ['text' => 'another text with ' . $hostedKey] + $arguments, 'inputResponses' => $yesAnswer, 'requestState' => $state], 2));
$second = $server->handle($modern(['name' => 'aamio_send', 'arguments' => $arguments, 'inputResponses' => $yesAnswer, 'requestState' => $state], 3));
$check(($first['result']['resultType'] ?? null) === 'input_required' && isset($first['result']['inputRequests'][McpServer::SECRET_QUESTION]) && !str_contains((string) json_encode($first), $hostedKey)
    && ($misused['result']['structuredContent']['sent'] ?? null) === false
    && ($second['result']['structuredContent']['readable'] ?? null) === true && count($readablePosted($hw)) === $posts + 1,
    'over 2026-07-28 the question is the result, and the yes belongs to that message and no other', json_encode([$first['result']['resultType'] ?? null, $misused['result']['structuredContent'] ?? null, $second['result']['structuredContent'] ?? null]));

// A no stands although the home no longer holds the key: the answer is read before anything that sends, and the
// scope that went while the question was open does not make the message pass (a review of 21 November 2026).
$postsTo = static fn (string $w): int => count(array_filter($fake->calls, static fn (array $call): bool => $call[0] === 'POST' && str_contains($call[1], '/' . $w)));
foreach (['decline' => ['action' => 'decline'], 'cancel' => ['action' => 'cancel'], 'an unticked accept' => ['action' => 'accept', 'content' => ['send' => false]]] as $how => $no) {
    [$nr, $nw] = $readableWriter();
    $noServer = new McpServer($nr);
    $noKey = $readableScope($nr);
    $noArguments = ['to' => $nw, 'text' => 'the key is ' . $noKey, 'readable' => true];
    $asking = $noServer->handle($modern(['name' => 'aamio_send', 'arguments' => $noArguments], 1));
    $nr->scopeRemove('team');
    $outboxBefore = $nr->outbox;
    $postsBefore = $postsTo($nw);
    $answered = $noServer->handle($modern(['name' => 'aamio_send', 'arguments' => $noArguments, 'inputResponses' => [McpServer::SECRET_QUESTION => $no], 'requestState' => $asking['result']['requestState'] ?? null], 2));
    $check(($asking['result']['resultType'] ?? null) === 'input_required' && ($answered['result']['structuredContent']['sent'] ?? null) === false
        && $postsTo($nw) === $postsBefore && $nr->outbox === $outboxBefore && $readablePosted($nw) === [],
        'over 2026-07-28 ' . $how . ' stands although the home no longer holds the key, and nothing goes to the service', json_encode($answered['result']['structuredContent'] ?? null));
    $nr->close();
}

[$yr, $yw] = $readableWriter();
$yesServer = new McpServer($yr);
$yesKey = $readableScope($yr);
$yesArguments = ['to' => $yw, 'text' => 'the key is ' . $yesKey, 'readable' => true];
$asking = $yesServer->handle($modern(['name' => 'aamio_send', 'arguments' => $yesArguments], 1));
$yr->scopeRemove('team');
$sentAfter = $yesServer->handle($modern(['name' => 'aamio_send', 'arguments' => $yesArguments, 'inputResponses' => $yesAnswer, 'requestState' => $asking['result']['requestState'] ?? null], 2));
$check(($sentAfter['result']['structuredContent']['readable'] ?? null) === true && count($readablePosted($yw)) === 1, 'and a yes still sends it when the home no longer holds the key');

$yesKey = $readableScope($yr, 'second');
$yesArguments = ['to' => $yw, 'text' => 'the key is ' . $yesKey, 'readable' => true];
$about = substr(hash('sha256', (string) json_encode(['data' => null, 're' => null, 'readable' => true, 'text' => $yesArguments['text'], 'to' => $yw], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);
$late = $yesServer->handle($modern(['name' => 'aamio_send', 'arguments' => $yesArguments, 'inputResponses' => $yesAnswer, 'requestState' => $yesServer->sealState($about, time() - 1, 'aamio_send')], 3));
$fresh = $yesServer->handle($modern(['name' => 'aamio_send', 'arguments' => $yesArguments], 4))['result']['requestState'] ?? null;
$firstYes = $yesServer->handle($modern(['name' => 'aamio_send', 'arguments' => $yesArguments, 'inputResponses' => $yesAnswer, 'requestState' => $fresh], 5));
$againYes = $yesServer->handle($modern(['name' => 'aamio_send', 'arguments' => $yesArguments, 'inputResponses' => $yesAnswer, 'requestState' => $fresh], 6));
$check(($late['result']['structuredContent']['error_code'] ?? null) === 'no_dialog' && ($late['result']['structuredContent']['sent'] ?? null) === false
    && ($firstYes['result']['structuredContent']['readable'] ?? null) === true
    && ($againYes['result']['structuredContent']['error_code'] ?? null) === 'no_dialog' && count($readablePosted($yw)) === 2,
    'an answer out of its time, or one used before, sends nothing', json_encode([$late['result']['structuredContent'] ?? null, $againYes['result']['structuredContent'] ?? null]));

$yr->partnerAdd($yw, Keys::generate()->public);
try {
    $yr->send($yw, 'hei', null, null, null, true);
    $namedLike = 'sent';
} catch (\Throwable $error) {
    $namedLike = Runtime::errorCode($error);
}
$check($namedLike === 'readable_with_key' && count($readablePosted($yw)) === 2, 'a partner named like an address is not written to readable', $namedLike);
$yr->close();

$instructions = json_decode((string) file_get_contents(dirname(__DIR__) . '/src/mcp-tools.json'), true)['instructions'] ?? '';
$check(str_contains($instructions, "unsealed with readable, where a secret goes only on the user's yes"), "the instructions say readable and the user's yes");

$tools = array_column(json_decode((string) file_get_contents(dirname(__DIR__) . '/src/mcp-tools.json'), true)['tools'], null, 'name');
$sendTool = $tools['aamio_send'] ?? [];
$check(($sendTool['inputSchema']['properties']['readable']['type'] ?? null) === 'boolean' && !isset($sendTool['inputSchema']['properties']['allow_secret'])
    && str_contains((string) ($sendTool['description'] ?? ''), 'readable') && str_contains((string) ($sendTool['description'] ?? ''), 'dialog'),
    'aamio_send says readable and takes it, and takes no allow_secret');
$h->close();

// ---------------------------------------------------------------- command line
if (isset($cliRun, $childPhp, $able) && trim((string) $able) === 'yes') {
    $cliHome = $root . DIRECTORY_SEPARATOR . 'readable-cli';
    [$status, $out] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $cliHome, '--host', 'http://127.0.0.1:9', 'send', 'abcdefghijklmnopqrst', 'hei', '--allow-secret']));
    $said = json_decode((string) $out, true);
    $check($status === 1 && ($said['error_code'] ?? null) === 'invalid_arguments' && str_contains((string) ($said['error'] ?? ''), '--readable'), '--allow-secret without --readable is refused on the command line', (string) $out);
} else {
    echo "  skip  --allow-secret on the command line: no child PHP with curl and sodium here\n";
}
