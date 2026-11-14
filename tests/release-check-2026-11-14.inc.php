<?php

declare(strict_types=1);

/*
 * What the release check of 14 November 2026 found here: a request id such as
 * 1e999 was copied into the answer, which could then not be written; --data
 * was checked as arrays and sent as objects, so a member name that starts
 * with U+0000 passed the check and stopped the command after the home was
 * opened; and a delivery read again, after a cursor that could not be
 * written, in this process or the next, was written to the archive a second
 * time.
 *
 * Included by tests/runtime.php, with $fake, $root, $check, $cliRun and
 * $childPhp in scope.
 */

use Aamio\AddonProcess;
use Aamio\Addons;
use Aamio\Codec;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

echo "the release check of 14 November 2026\n";

$rcQuiet = static function (string $line): void {
};

// The id, as the Node runtime answers it.
$rcIdHome = $root . DIRECTORY_SEPARATOR . 'rc-1114-id';
mkdir($rcIdHome, 0700, true);
$rcIdRuntime = new Runtime($rcIdHome, 'https://fake.test', null, false, $rcQuiet);
$rcServer = new McpServer($rcIdRuntime);
$rcRefused = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request id']];
foreach (['{"jsonrpc":"2.0","id":1e999,"method":"ping"}', '{"jsonrpc":"2.0","id":9007199254740992,"method":"ping"}', '{"jsonrpc":"2.0","id":1.5,"method":"ping"}', '{"jsonrpc":"2.0","id":null,"method":"ping"}', '{"jsonrpc":"2.0","id":{},"method":"ping"}'] as $rcLine) {
    $rcReply = $rcServer->handle(json_decode($rcLine, true), json_decode($rcLine, false));
    $check($rcReply === $rcRefused && Codec::json($rcReply) !== '', 'a request id no reader can hold, or none of a string or a whole number, is refused with id null: ' . $rcLine, (string) json_encode($rcReply, JSON_PARTIAL_OUTPUT_ON_ERROR));
}
$rcPong = $rcServer->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
$check(($rcPong['id'] ?? null) === 7 && array_key_exists('result', $rcPong ?? []), '... and the next request is answered');
$rcIdRuntime->close();

// --data is checked as it is sent, before the home.
$rcCliHome = $root . DIRECTORY_SEPARATOR . 'rc-1114-cli';
[$rcStatus, $rcStdout, $rcStderr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $rcCliHome, '--host', 'http://127.0.0.1:9', 'send', 'bob', 'hi', '--data', '{"\u0000x":1}']));
$check($rcStatus === 2 && $rcStdout === '' && str_contains($rcStderr, 'U+0000') && !file_exists($rcCliHome), '--data with a member name that starts with U+0000 is refused before the home with 2', json_encode([$rcStatus, $rcStdout, substr($rcStderr, 0, 200)]));

// One raw archive row a delivery, in this process and the next.
$rcHome = $root . DIRECTORY_SEPARATOR . 'rc-1114-archive';
mkdir($rcHome, 0700, true);
$rcReader = new Runtime($rcHome, 'https://fake.test', ['rc'], true, $rcQuiet);
$rcSigner = Keys::generate();
$rcW = $rcReader->openChannel('side', 600)['w'];
$rcReader->ensureInbox();
$rcWrite = static function (string $text) use ($fake, $rcW, $rcSigner): void {
    $body = Codec::json(['text' => $text]);
    $seq = count($fake->threads[$rcW]['messages']) + 1;
    $fake->threads[$rcW]['messages'][] = ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $body, 'sha256' => hash('sha256', $body), 'from' => $rcSigner->public, 'sig' => $rcSigner->sign(Keys::threadSigningInput($rcW, $body)), 'verified' => true, 'sealed' => false];
};
$rcRows = static function () use ($rcHome): array {
    $seqs = [];
    foreach (glob($rcHome . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && ($row['kind'] ?? null) === 'received' && ($row['channel'] ?? null) === 'side') {
                $seqs[] = $row['seq'];
            }
        }
    }
    sort($seqs);

    return $seqs;
};
$rcTexts = static fn (array $read): array => array_map(static fn (array $m): array => [$m['body']['text'] ?? null, $m['replay'] ?? null, $m['archived'] ?? null], array_values(array_filter($read, static fn (array $m): bool => ($m['channel'] ?? null) === 'side')));
$rcBlocked = $rcHome . DIRECTORY_SEPARATOR . 'state.json.tmp';
$rcWrite('first');
$rcWrite('second');
mkdir($rcBlocked);
$check($rcTexts($rcReader->read()) === [['first', false, true], ['second', false, true]], 'a read whose cursor cannot be written still hands its messages over');
rmdir($rcBlocked);
$check($rcTexts($rcReader->read()) === [['first', true, true], ['second', true, true]], '... the next read hands them over again, marked replay');
$check($rcRows() === [1, 2], '... and the archive holds each once', json_encode($rcRows()));
$rcWrite('third');
mkdir($rcBlocked);
$check($rcTexts($rcReader->read()) === [['third', false, true]], 'a third, whose cursor cannot be written either');
$rcReader->close();
rmdir($rcBlocked);
$rcAgain = new Runtime($rcHome, 'https://fake.test', ['rc'], true, $rcQuiet);
$check($rcTexts($rcAgain->read()) === [['third', false, true]], 'the next process hands it over again: the cursor on disk was behind it');
$check($rcRows() === [1, 2, 3], '... and the archive still holds each once, the row the earlier process wrote used again', json_encode($rcRows()));
$rcAgain->close();

// An add-on's tool gets every argument as it was sent, objects as objects and
// lists as lists, over MCP as the serving loop hands a call over, the line read
// twice: as arrays, an empty object went to the add-on as an empty list.
(static function () use ($root, $check): void {
    $folder = $root . DIRECTORY_SEPARATOR . 'rc-1114-shapes';
    $home = $folder . DIRECTORY_SEPARATOR . 'home';
    $path = $folder . DIRECTORY_SEPARATOR . 'shape';
    mkdir($path, 0700, true);
    $object = ['type' => 'object'];
    file_put_contents($path . DIRECTORY_SEPARATOR . 'composer.json', json_encode(['name' => 'acme/shape', 'type' => 'aamio-addon', 'extra' => ['aamio-addon' => ['name' => 'shape', 'contract' => 2]]]));
    file_put_contents($path . DIRECTORY_SEPARATOR . 'aamio-addon.json', json_encode([
        'aamio_addon' => 2, 'name' => 'shape', 'version' => '0.0.1', 'process' => ['php' => 'process.php'], 'deadline_seconds' => 5,
        'tools' => [['name' => 'aamio_addon_shape_echo', 'description' => 'keeps what it was asked', 'inputSchema' => ['type' => 'object', 'properties' => ['options' => $object, 'list' => ['type' => 'array'], 'nested' => $object], 'additionalProperties' => false]]],
    ]));
    file_put_contents($path . DIRECTORY_SEPARATOR . 'process.php', '<?php
$asked = (string) stream_get_contents(STDIN);
$request = json_decode($asked, true);
if ($request["kind"] === "probe") {
    echo json_encode(["result" => ["ok" => true, "name" => "shape", "version" => "0.0.1", "aamio_addon" => 2]]);
    exit(0);
}
file_put_contents(__DIR__ . "/asked.json", $asked);
echo json_encode(["result" => ["kept" => true]]);
');
    putenv('AAMIO_ADDONS');
    Addons::useHome($home);
    Addons::reset();
    AddonProcess::resetLimits();
    Addons::useInstalled([['package' => 'acme/shape', 'version' => '0.0.1', 'path' => $path]]);
    Addons::enable('shape');
    $runtime = new Runtime($home, 'https://fake.test', null, false, static function (string $line): void {
    });
    $server = new McpServer($runtime);
    $line = '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"aamio_addon_shape_echo","arguments":{"options":{},"list":[],"nested":{"inner":{},"keys":{"0":"a"}}}}}';
    $reply = $server->handle(json_decode($line, true), json_decode($line, false));
    $asked = json_decode((string) @file_get_contents($path . DIRECTORY_SEPARATOR . 'asked.json'), false);
    $check(($reply['result']['isError'] ?? null) === false && ($asked->arguments->options ?? null) instanceof \stdClass && ($asked->arguments->list ?? null) === []
        && ($asked->arguments->nested->inner ?? null) instanceof \stdClass && ($asked->arguments->nested->keys ?? null) instanceof \stdClass,
        'an add-on gets an empty object as an object, an empty list as a list, and {"0": "a"} as it was sent', json_encode([$reply['result'] ?? $reply, $asked->arguments ?? null]));
    $runtime->close();
    Addons::useInstalled([]);
    Addons::reset();
})();
