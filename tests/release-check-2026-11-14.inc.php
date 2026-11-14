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

// The second release check of 14 November 2026. Every answer's id can be
// written, a refusal's included, and a whole number written 1.0 is the id 1,
// as the Node runtime reads it.
$rcIn = fopen('php://memory', 'r+');
$rcOut = fopen('php://memory', 'r+');
fwrite($rcIn, '{"jsonrpc":"2.0","id":1e999,"method":"tools/call","params":{"name":"aamio_presence_lookup","arguments":{"\u0000x":1}}}' . "\n" . '{"jsonrpc":"2.0","id":1.0,"method":"ping"}' . "\n" . '{"jsonrpc":"2.0","id":"1","method":"ping"}' . "\n");
rewind($rcIn);
(new McpServer(new Runtime($root . DIRECTORY_SEPARATOR . 'rc-1114-serve', 'http://127.0.0.1:9', null, false, $rcQuiet)))->serve($rcIn, $rcOut);
rewind($rcOut);
$rcLines = array_values(array_filter(explode("\n", (string) stream_get_contents($rcOut)), static fn (string $line): bool => trim($line) !== ''));
$rcIds = array_map(static fn (string $line): mixed => array_key_exists('id', (array) json_decode($line, true)) ? json_decode($line, true)['id'] : 'unreadable', $rcLines);
$check(count($rcLines) === 3 && $rcIds === [null, 1, '1'], 'a refusal answers an id no reader can hold with null, 1.0 is the id 1, and "1" stays a string', json_encode($rcLines));

// The rows used again are the ones the archive's reader counts, and the end of
// the file is read again when a prune has replaced the file. A line that is not
// JSON a reader takes was passed by here before as well, and is held here as in
// the other two runtimes.
$rcCrafted = static function (string $name, array $texts) use ($root, $fake, $rcQuiet): array {
    $home = $root . DIRECTORY_SEPARATOR . 'rc-1114-' . $name;
    mkdir($home, 0700, true);
    $reader = new Runtime($home, 'https://fake.test', ['rc'], true, $rcQuiet);
    $reader->ensureInbox();
    $w = $reader->openChannel('side', 600)['w'];
    $reader->close();
    $signer = Keys::generate();
    $rows = [];
    foreach ($texts as $at => $text) {
        $body = Codec::json(['text' => $text]);
        $seq = $at + 1;
        $fake->threads[$w]['messages'][] = ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $body, 'sha256' => hash('sha256', $body), 'from' => $signer->public, 'sig' => $signer->sign(Keys::threadSigningInput($w, $body)), 'verified' => true, 'sealed' => false];
        $rows[] = Codec::json(['kind' => 'received', 'channel' => 'side', 'w' => $w, 'instance' => $fake->threads[$w]['instance'], 'seq' => $seq, 'sha256' => hash('sha256', $body), 'body' => ['text' => $text]]);
    }

    return [$home, $rows];
};
$rcWritten = static function (string $home): array {
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($home . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'side.jsonl')), static fn (string $line): bool => $line !== ''));
    $seqs = [];
    foreach ($lines as $number => $line) {
        $row = json_decode($number === 0 && str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line, true);
        if (is_array($row) && ($row['kind'] ?? null) === 'received') {
            $seqs[] = $row['seq'];
        }
    }
    sort($seqs);

    return [$seqs, count($lines)];
};
$rcSide = static fn (array $read): array => array_map(static fn (array $m): array => [$m['body']['text'] ?? null, $m['archived'] ?? null], array_values(array_filter($read, static fn (array $m): bool => ($m['channel'] ?? null) === 'side')));
foreach (['a byte order mark' => [['first', 'second'], [1, 2], 2], 'a line that is not JSON a reader takes' => [['first', 'second'], [1, 2], 3], 'a line that starts where the end starts' => [['first', 'second'], [1, 1, 2], 3]] as $rcCase => [$rcTexts, $rcSeqs, $rcCount]) {
    [$rcHome2, $rcRows] = $rcCrafted(substr(md5($rcCase), 0, 8), $rcTexts);
    if ($rcCase === 'a line that is not JSON a reader takes') {
        $rcRows[0] = str_replace('"first"', "\"fir\xFFst\"", $rcRows[0]);
    }
    file_put_contents($rcHome2 . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'side.jsonl', ($rcCase === 'a byte order mark' ? "\xEF\xBB\xBF" : '') . $rcRows[0] . "\n" . $rcRows[1] . "\n");
    $rcAgain2 = new Runtime($rcHome2, 'https://fake.test', ['rc'], true, $rcQuiet);
    if ($rcCase === 'a line that starts where the end starts') {
        $rcAgain2->archiveTailBytes = strlen($rcRows[1]) + 1;
    }
    $check($rcSide($rcAgain2->read()) === [['first', true], ['second', true]] && $rcWritten($rcHome2) === [$rcSeqs, $rcCount], 'a row used again is one the archive reader counts: ' . $rcCase, json_encode($rcWritten($rcHome2)));
    $rcAgain2->close();
}
[$rcHome3, $rcRows3] = $rcCrafted('pruned', ['first']);
$rcPath3 = $rcHome3 . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'side.jsonl';
file_put_contents($rcPath3, $rcRows3[0] . "\n");
$rcAgain3 = new Runtime($rcHome3, 'https://fake.test', ['rc'], true, $rcQuiet);
$check($rcSide($rcAgain3->read()) === [['first', true]] && $rcWritten($rcHome3) === [[1], 1], 'the row an earlier process wrote is used');
// A prune writes the file again beside itself and renames it over: here, without the row.
file_put_contents($rcPath3 . '.tmp', '');
rename($rcPath3 . '.tmp', $rcPath3);
$rcAgain3->channels['side']->after = 0;
$check($rcSide($rcAgain3->read()) === [['first', true]] && $rcWritten($rcHome3) === [[1], 1], '... and after a prune removed it, it is written again: the archive has what the message says', json_encode($rcWritten($rcHome3)));
$rcAgain3->close();

// The third release check: in one process, a row written down, its cursor not
// written, the row pruned, and the message read again. The mark is the row
// that is there.
[$rcHome4] = $rcCrafted('pruned-in-process', ['first']);
$rcReader4 = new Runtime($rcHome4, 'https://fake.test', ['rc'], true, $rcQuiet);
$rcBlocked4 = $rcHome4 . DIRECTORY_SEPARATOR . 'state.json.tmp';
mkdir($rcBlocked4);
$check($rcSide($rcReader4->read()) === [['first', true]] && $rcWritten($rcHome4) === [[1], 1], 'a row written down at a read whose cursor could not be written');
rmdir($rcBlocked4);
$rcReader4->archivePolicy = ['mode' => 'keep', 'days' => null, 'max_mb' => 0.000000001];
(\Closure::bind(fn () => $this->prune(), $rcReader4, Runtime::class))();
$check(!is_file($rcHome4 . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'side.jsonl') || $rcWritten($rcHome4) === [[], 0], '... a prune in the same process takes it');
$check($rcSide($rcReader4->read()) === [['first', true]] && is_file($rcHome4 . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'side.jsonl') && $rcWritten($rcHome4) === [[1], 1], '... and the message read again is written down again: the mark is the row that is there');
$rcReader4->close();
