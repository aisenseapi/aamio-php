<?php

declare(strict_types=1);

/*
 * A message no reader can hold, and arguments no tool can take (a security
 * review of 13 November 2026). A body is read as JSON only when every number
 * in it is finite and it is nested at most 64 deep, as in the other two
 * runtimes, and anything else is handed over as text. 1e999 decoded to INF
 * here, the read that weighed it threw, and its channel answered "could not
 * be read" for as long as the thread lived, the messages behind it with it.
 * Over MCP, arguments holding such a number or nesting are refused before any
 * tool runs, an add-on's included, where one such number made every argument
 * go missing; and a line with a member name that starts with U+0000, which
 * this PHP cannot hold with its objects as objects, is refused rather than
 * judged by the arrays' reading. --data and --gate are refused before the home.
 *
 * Included by tests/runtime.php, with $fake, $root and $check in scope.
 */

use Aamio\Codec;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

echo "a message no reader can hold, and arguments no tool can take\n";

$unfitQuiet = static function (string $line): void {
};
$unfitNested = static fn (int $n): string => str_repeat('[', $n) . '1' . str_repeat(']', $n);
$unfitHomes = [];
foreach (['a', 'b'] as $letter) {
    $unfitHomes[$letter] = $root . DIRECTORY_SEPARATOR . 'unfit-' . $letter;
    mkdir($unfitHomes[$letter], 0700, true);
}
$unfitReader = new Runtime($unfitHomes['a'], 'https://fake.test', ['a'], false, $unfitQuiet);
$unfitWriter = new Runtime($unfitHomes['b'], 'https://fake.test', ['b'], false, $unfitQuiet);
$unfitReader->partnerAdd('b', $unfitWriter->keys->public);
$unfitWriter->partnerAdd('a', $unfitReader->keys->public);
$unfitReader->ensureInbox();
$unfitInbox = $unfitReader->channels['inbox']->w;
/** The bytes as they are, signed by the writer, at the reader's inbox: what any writer the inbox admits can send. */
$unfitWrite = static function (string $body, bool $sealed = false) use ($fake, $unfitWriter, $unfitReader, $unfitInbox): void {
    $wire = $sealed ? $unfitWriter->keys->seal($unfitReader->keys->public, $body) : $body;
    $seq = count($fake->threads[$unfitInbox]['messages']) + 1;
    $fake->threads[$unfitInbox]['messages'][] = ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $wire, 'sha256' => hash('sha256', $wire), 'from' => $unfitWriter->keys->public, 'sig' => $unfitWriter->keys->sign(Keys::threadSigningInput($unfitInbox, $wire)), 'verified' => true, 'sealed' => $sealed];
};

$check(Codec::unfitJson(json_decode($unfitNested(Codec::BODY_MAX_DEPTH), true)) === null && (Codec::unfitJson(json_decode($unfitNested(Codec::BODY_MAX_DEPTH + 1), true))[1] ?? null) === 'too deep'
    && Codec::unfitJson(json_decode('{"a": 1, "b": {"c": 1e999}}', true)) === ['b.c', 'not finite'] && Codec::unfitJson(json_decode('1' . str_repeat('0', 400))) === ['', 'not finite']
    && Codec::unfitJson(json_decode('{"data":{"0":"x","e":{}}}', false)) === null, 'what no reader can hold is the first thing unfitJson names, either way PHP decodes it, and 64 deep is still JSON');

foreach (['a number that is not finite' => '{"text":"x","data":{"n":1e999}}', 'nesting 510 deep' => '{"text":"x","data":' . $unfitNested(509) . '}', 'nesting 5 000 deep' => '{"text":"x","data":' . $unfitNested(5000) . '}'] as $unfitLabel => $unfitPoison) {
    $unfitWrite('{"text":"before"}');
    $unfitWrite($unfitPoison);
    $unfitWrite('{"text":"after"}');
    $unfitWrite($unfitPoison, true);
    $unfitServer = new McpServer($unfitReader);
    $unfitResult = $unfitServer->dispatch('aamio_read', []);
    $unfitLine = null;
    try {
        $unfitLine = json_decode(Codec::json(['jsonrpc' => '2.0', 'id' => 1, 'result' => $unfitResult]), true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $unfitError) {
        $unfitLine = $unfitError::class;
    }
    $unfitRead = is_array($unfitLine) ? ($unfitLine['result']['structuredContent']['messages'] ?? []) : [];
    $unfitSeen = array_map(static fn (array $m): array => [$m['format'] ?? null, ($m['format'] ?? null) === 'text' ? $m['body']['text'] : ($m['body']['text'] ?? null)], $unfitRead);
    $check($unfitSeen === [['json', 'before'], ['text', $unfitPoison], ['json', 'after'], ['text', $unfitPoison]], $unfitLabel . ' between two ordinary messages is handed over as text, both ordinary ones arrive, and the answer is JSON a strict reader takes', json_encode(is_array($unfitLine) ? $unfitSeen : $unfitLine));
    $check(($unfitServer->dispatch('aamio_read', [])['structuredContent']['messages'] ?? null) === [], '... and the cursor is past all four');
}

// A logger that fails costs the log line, never the read (the release check of
// 14 November 2026). It fails here at the note the second message makes, which
// names an address the first one bound to another key: it threw out of the
// read, and a note made after the cursor was written down lost what that read
// had passed. A read that throws for another reason leaves nothing of itself in
// memory, as aamio-python's and the Node runtime's tests show; nothing in this
// read is known to throw once the logger cannot.
$halfThrows = false;
$halfHome = $root . DIRECTORY_SEPARATOR . 'unfit-half';
mkdir($halfHome, 0700, true);
$halfReader = new Runtime($halfHome, 'https://fake.test', ['half'], false, static function (string $line) use (&$halfThrows): void {
    if ($halfThrows && str_contains($line, 'is already bound to')) {
        throw new \RuntimeException('the test made the second message fail');
    }
});
$halfW = $halfReader->openChannel('side', 600)['w'];
foreach ([[$unfitWriter, 'first on the side'], [$unfitReader, 'second on the side']] as [$halfSigner, $halfText]) {
    $halfBody = Codec::json(['text' => $halfText, 'reply_to' => $unfitInbox]);
    $halfSeq = count($fake->threads[$halfW]['messages']) + 1;
    $fake->threads[$halfW]['messages'][] = ['seq' => $halfSeq, 'at' => $fake->now + $halfSeq, 'type' => 'text', 'body' => $halfBody, 'sha256' => hash('sha256', $halfBody), 'from' => $halfSigner->keys->public, 'sig' => $halfSigner->keys->sign(Keys::threadSigningInput($halfW, $halfBody)), 'verified' => true, 'sealed' => false];
}
$halfServer = new McpServer($halfReader);
$halfThrows = true;
$halfFirst = $halfServer->dispatch('aamio_read', [])['structuredContent'] ?? [];
$check(array_map(static fn (array $m): array => [$m['body']['text'] ?? null, $m['replay'] ?? null], $halfFirst['messages'] ?? []) === [['first on the side', false], ['second on the side', false]]
    && array_filter($halfFirst['attention'] ?? [], static fn (array $note): bool => ($note['state'] ?? null) === 'unread') === []
    && array_filter($halfFirst['attention'] ?? [], static fn (array $note): bool => ($note['state'] ?? null) === 'binding_conflict') !== [], 'a logger that fails at a note costs the log line, never the read: both are handed over, and the note is in attention', json_encode($halfFirst));
$halfThrows = false;
$check(($halfServer->dispatch('aamio_read', [])['structuredContent']['messages'] ?? null) === [], '... and nothing comes twice');
$halfReader->close();

// Over MCP, as the serving loop hands a call over: the line read twice.
$unfitServer = new McpServer($unfitReader);
$unfitServer->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
$unfitCall = static fn (string $line): ?array => $unfitServer->handle(json_decode($line, true), json_decode($line, false));
foreach ([
    ['{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"aamio_send","arguments":{"to":"nobody","text":"x","data":{"n":1e999}}}}', 'data.n is a number that is not finite, as 1e999 is'],
    ['{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"aamio_send","arguments":{"to":"nobody","text":"x","data":' . $unfitNested(Codec::BODY_MAX_DEPTH) . '}}}', 'is nested deeper than 64: a reader hands a message nested that deep over as text'],
    ['{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"aamio_addon_hello_wave","arguments":{"to":1e999}}}', 'to is a number that is not finite'],
] as [$unfitLineSent, $unfitSaid]) {
    $unfitBefore = count($fake->threads[$unfitInbox]['messages']);
    $unfitAnswer = $unfitCall($unfitLineSent)['result'] ?? [];
    $unfitTold = $unfitAnswer['structuredContent'] ?? [];
    $check(($unfitAnswer['isError'] ?? null) === true && ($unfitTold['error_code'] ?? null) === 'invalid_arguments' && str_contains((string) ($unfitTold['error'] ?? ''), $unfitSaid), 'over MCP arguments no reader can hold are refused before any tool runs: ' . $unfitSaid, json_encode($unfitTold));
}

// A member name that starts with U+0000: valid JSON this PHP cannot hold with
// its objects as objects. Judged by the arrays' reading, names: {} asked for
// every partner.
$unfitIn = fopen('php://memory', 'r+');
$unfitOut = fopen('php://memory', 'r+');
fwrite($unfitIn, '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"aamio_presence_lookup","arguments":{"names":{},"\u0000x":1}}}' . "\n" . '{"jsonrpc":"2.0","id":6,"method":"ping"}' . "\n");
rewind($unfitIn);
(new McpServer(new Runtime($root . DIRECTORY_SEPARATOR . 'unfit-nul', 'http://127.0.0.1:9', null, false, $unfitQuiet)))->serve($unfitIn, $unfitOut);
rewind($unfitOut);
$unfitLines = array_values(array_filter(explode("\n", (string) stream_get_contents($unfitOut)), static fn (string $line): bool => trim($line) !== ''));
$unfitFirst = json_decode($unfitLines[0] ?? 'null', true);
$check(($unfitFirst['id'] ?? null) === 5 && ($unfitFirst['error']['code'] ?? null) === -32600 && str_contains((string) ($unfitFirst['error']['message'] ?? ''), 'U+0000') && (json_decode($unfitLines[1] ?? 'null', true)['id'] ?? null) === 6, 'a line with a member name that starts with U+0000 is refused as it was sent, and the next line is served', json_encode($unfitLines));

// On the command line, before the home, with 2, as in the other two. --data
// one level shallower than the limit is JSON every reader holds, with the
// message around it.
foreach ([
    [['send', 'bob', 'hi', '--data', '{"n":1e999}'], '--data holds a number that is not finite'],
    [['send', 'bob', 'hi', '--data', $unfitNested(Codec::BODY_MAX_DEPTH)], '--data is nested too deep'],
    [['send', 'bob', 'hi', '--data', $unfitNested(5000)], '--data is nested too deep'],
    [['board', 'answer', 'post', 'hi', '--data', '[1e999]'], '--data holds a number that is not finite'],
    [['channel', 'open', 'room', '--gate', '{"require":{"pow":{"bits":1e999}}}'], '--gate holds a number that is not finite'],
] as $unfitAt => [$unfitWords, $unfitSaid]) {
    $unfitCliHome = $root . DIRECTORY_SEPARATOR . 'unfit-cli-' . $unfitAt;
    [$unfitStatus, $unfitStdout, $unfitStderr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $unfitCliHome, '--host', 'http://127.0.0.1:9'], $unfitWords));
    $check($unfitStatus === 2 && $unfitStdout === '' && str_contains($unfitStderr, $unfitSaid) && !file_exists($unfitCliHome), 'on the command line ' . $unfitSaid . ', refused before the home with 2', json_encode([$unfitStatus, $unfitStdout, substr($unfitStderr, 0, 200)]));
}
[$unfitStatus, , $unfitStderr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $root . DIRECTORY_SEPARATOR . 'unfit-cli-fine', '--host', 'http://127.0.0.1:9', 'send', 'bob', 'hi', '--data', $unfitNested(Codec::BODY_MAX_DEPTH - 1)]));
$check($unfitStatus !== 2, 'and one level less is JSON every reader holds, with the message around it', substr($unfitStderr, 0, 200));

$unfitReader->close();
$unfitWriter->close();
