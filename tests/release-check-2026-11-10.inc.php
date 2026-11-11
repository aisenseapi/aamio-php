<?php

declare(strict_types=1);

/*
 * What the review of the Node runtime's receipt and read budget of 10 November
 * 2026 found in this runtime too (code/php/docs/aamio-node-steg-1-gjennomgang-2026-11-10.md
 * in the service). A receipt of a thread opened again at the same address,
 * after the service restarted, was compared with what had been read of the old
 * one, and its signer named as checked here. An answer that was not a receipt
 * for the channel was signed. A byte budget of nine digits went out as it was
 * and was answered 400, and a read that stopped at its count limit blamed a
 * byte budget nobody had set. The same checks as aamio-python's
 * tests/test_release_check_2026_11_10.py.
 *
 * Included by tests/runtime.php after release-check.inc.php, with $fake, $root
 * and $check in scope.
 */

use Aamio\Address;
use Aamio\Channel;
use Aamio\Codec;
use Aamio\Http;
use Aamio\Keys;
use Aamio\Runtime;

echo "the review of 10 November: a receipt belongs to its thread and is checked, a budget is held to eight digits, more says why\n";

Http::$override = $fake;
$r10Quiet = static function (string $line): void {
};
$r10Homes = 0;
$r10Open = static function () use ($root, &$r10Homes, $r10Quiet): Runtime {
    $home = $root . DIRECTORY_SEPARATOR . 'release-check-10-' . (++$r10Homes);
    mkdir($home, 0700, true);

    return new Runtime($home, 'https://fake.test', ['r10'], false, $r10Quiet);
};

$receiver = $r10Open();
$sender = $r10Open();
$receiver->partnerAdd('sender', $sender->keys->public);
$sender->partnerAdd('receiver', $receiver->keys->public);
$receiver->read();
$sender->read();
$sender->send('receiver', 'on the first thread');
$check(count($receiver->read()) === 1 && $receiver->receipt('inbox')['local_root_matches'] === true, 'a receipt taken by the process that read compares');

// The service restarts without its store, and a write opens a new thread at the same address, later.
$inbox = $receiver->channels['inbox'];
$fake->threads[$inbox->w] = ['id' => $inbox->readKey, 'created_at' => $fake->now + 5, 'expire_at' => $inbox->expireAt, 'allow' => [], 'messages' => [], 'gate' => []];
$sender->send('receiver', 'on the new thread');
$before = $receiver->receipt('inbox');
$check(
    $before['root_adds_up'] === true && $before['held_locally'] === 0 && $before['local_root_matches'] === null
        && $before['keys'] === [$sender->keys->public] && $before['keys_unverified_count'] === 1
        && str_contains($before['local_check'], 'on another thread, instance ') && str_contains($before['local_check'], 'this receipt is for the one opened at ' . ($fake->now + 5) . ' and expiring at ' . $inbox->expireAt),
    'a receipt of a thread opened again at the same address compares nothing read on the old one, and names no partner for it',
    json_encode($before)
);
$check(count($receiver->read()) === 1, 'the next read finds the new thread and reads it from the start');
$after = $receiver->receipt('inbox');
$check($after['held_locally'] === 1 && $after['local_root_matches'] === true && $after['keys'] === ['sender'], 'and after it, the receipt compares and names the partner', json_encode($after));

// An answer that is not a receipt for this channel is refused before it is compared, signed or anchored.
$tamper = null;
Http::$override = static function (string $method, string $url, ?string $body, array $headers) use ($fake, &$tamper): array {
    $answer = $fake($method, $url, $body, $headers);
    if ($tamper !== null && str_ends_with((string) parse_url($url, PHP_URL_PATH), '/receipt') && $answer[0] === 200) {
        $answer[1] = $tamper($answer[1]);
    }

    return $answer;
};
$refusals = [
    [static fn (array $r): array => array_replace($r, ['w' => str_repeat('b', 20)]), 'it is for "bbbbbbbbbbbbbbbbbbbb", not for ' . $inbox->w . ', the address asked for'],
    [static fn (array $r): array => array_replace($r, ['count' => 7]), 'it counts 7 messages and has 1 lines'],
    [static fn (array $r): array => array_replace($r, ['schema' => null]), 'its schema is null, not aamio-receipt-v1'],
    [static fn (array $r): array => array_replace($r, ['commitment' => 'sha256:' . str_repeat('0', 64)]), 'its commitment is not sha256: and its root'],
    [static fn (array $r): array => array_replace($r, ['issued_at' => 'soon']), 'its issued_at is not a time'],
    [static fn (array $r): array => array_replace($r, ['messages' => [array_replace($r['messages'][0], ['sha256' => 'x'])]]), 'line 1 is not a message line in order'],
    [static fn (array $r): array => array_replace($r, ['root' => 'ROOT', 'commitment' => 'sha256:ROOT']), 'its root is not a sha256 in hex'],
    [static fn (array $r): array => array_replace($r, ['root' => $r['root'] . "\n", 'commitment' => 'sha256:' . $r['root'] . "\n"]), 'its root is not a sha256 in hex'],
    [static fn (array $r): array => array_replace($r, ['keys' => [7]]), 'its keys are not a list of keys'],
    [static fn (array $r): array => array_replace($r, ['keys' => null]), 'its keys are not a list of keys'],
    [static fn (array $r): array => array_replace($r, ['keys' => array_merge($r['keys'], $r['keys'])]), 'its keys are not a list of keys'],
    [static fn (array $r): array => array_replace($r, ['keys' => []]), 'its keys are not the signers of its lines'],
    [static fn (array $r): array => array_replace($r, ['keys' => array_merge($r['keys'], [Keys::generate()->public])]), 'its keys are not the signers of its lines'],
];
$fake->calls = [];
$refusedRight = [];
foreach ($refusals as [$change, $why]) {
    $tamper = $change;
    try {
        $receiver->receipt('inbox', true);
        $refusedRight[] = 'signed: ' . $why;
    } catch (\RuntimeException $error) {
        if (!str_contains($error->getMessage(), $why) || !str_ends_with($error->getMessage(), 'Nothing was compared, signed or anchored.')) {
            $refusedRight[] = $error->getMessage();
        }
    }
}
$tamper = null;
$anchoredAny = array_filter(array_column($fake->calls, 1), static fn (string $url): bool => str_contains($url, 'verifyum')) !== [];
$check($refusedRight === [] && !$anchoredAny, 'an answer that is not a receipt for this channel is refused before it is compared, signed or anchored', implode(' / ', $refusedRight));
Http::$override = $fake;

// A byte budget above what the service takes goes out as the largest it takes.
$fake->seen = [];
$receiver->read(0, 1, 100000000);
$budgets = array_values(array_filter(array_map(static fn (array $seen): ?string => $seen['headers']['X-Max-Bytes'] ?? null, $fake->seen), static fn (?string $value): bool => $value !== null));
$check($budgets !== [] && array_unique($budgets) === ['99999999'], 'a byte budget above what the service takes is sent as the largest it takes', implode(',', $budgets));
$receiver->attentionTaken();

// A read that stops at its count limit says so, and blames no budget.
foreach (['one', 'two', 'three'] as $text) {
    $sender->send('receiver', $text);
}
$check(count($receiver->read(0, 1)) === 1, 'a read for one message hands over one');
$said = array_values(array_filter($receiver->attentionTaken(), static fn (array $note): bool => $note['state'] === 'more'));
$check(
    count($said) === 1 && str_starts_with($said[0]['what'], 'this read stopped at its limit of 1 message(s). The service has more waiting on inbox. Nothing was passed over') && !str_contains($said[0]['what'], 'budget'),
    'a read that stops at its count limit says so, and blames no budget',
    json_encode($said)
);

// A limit met on one channel and a budget on another are said in one note: under one key, the second replaced the first.
$signed = static fn (string $w, int $seq, string $text, ?Keys $keys): array => [
    'seq' => $seq, 'at' => 1000 + $seq, 'type' => 'text', 'body' => $text, 'sha256' => hash('sha256', $text),
    'from' => $keys?->public, 'sig' => $keys?->sign(Keys::threadSigningInput($w, $text)), 'verified' => $keys !== null, 'sealed' => false,
];
$both = $r10Open();
$both->partnerAdd('sender', $sender->keys->public);
$both->read();
$bothInbox = $both->channels['inbox'];
$otherW = str_repeat('c', 20);
$both->channels['other'] = new Channel('other', 'other-key', $otherW, time() + 600);
Http::$override = static function (string $method, string $url, ?string $body, array $headers) use ($fake, $bothInbox, $otherW, $signed, $sender): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($method === 'GET' && str_starts_with($path, '/' . $bothInbox->w)) {
        return [200, ['exists' => true, 'created_at' => 1000, 'messages' => [$signed($bothInbox->w, 1, 'one', $sender->keys)], 'next' => 1, 'more' => true], []];
    }
    if ($method === 'GET' && str_starts_with($path, '/' . $otherW)) {
        return [200, ['exists' => true, 'created_at' => 1000, 'messages' => [$signed($otherW, 1, 'two', null)], 'next' => 1, 'more' => true], []];
    }

    return $fake($method, $url, $body, $headers);
};
$both->attentionTaken();
$check(count($both->read(0, 2, 4096)) === 2, 'one message from each channel');
$said = array_values(array_filter($both->attentionTaken(), static fn (array $note): bool => $note['state'] === 'more'));
$check(
    count($said) === 1 && str_starts_with($said[0]['what'], 'this read stopped at its limit of 2 message(s). The service has more waiting on other.')
        && str_contains($said[0]['what'], 'The service had more waiting on inbox than the byte budget this read asked for'),
    'a read that met its limit on one channel and a budget on another says both, in one note',
    json_encode($said)
);
Http::$override = $fake;
$both->close();
$receiver->close();
$sender->close();

// ------------------------------------------------------------ the post-check
echo "the post-check of 10 November: a thread opened again in the same second, a budget as handed over, keys, an anchoring cut short\n";

Http::$override = $fake;
$pc = $r10Open();
$pcSender = $r10Open();
$pc->partnerAdd('sender', $pcSender->keys->public);
$pcSender->partnerAdd('receiver', $pc->keys->public);
$pc->read();
$pcSender->read();
$pcInbox = $pc->channels['inbox'];
$textsOf = static fn (array $entries): array => array_map(static fn (array $entry) => $entry['body']['text'] ?? null, $entries);

// An inbox a write opened again in the same second, with the service's own lifetime and an instance of its own: told
// by its instance, read without a receipt first.
$pcSender->send('receiver', 'on the first thread');
$pc->read();
$firstInstance = $pcInbox->instance;
$fake->threads[$pcInbox->w] = ['id' => $pcInbox->readKey, 'created_at' => $pcInbox->createdAt, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => [], 'instance' => str_repeat('a', 16)];
$pcSender->send('receiver', 'on the new thread');
$got = $pc->read();
$restarted = array_values(array_filter($pc->attentionTaken(), static fn (array $note): bool => $note['state'] === 'restarted'));
$check(
    is_string($firstInstance) && $textsOf($got) === ['on the new thread'] && count($restarted) === 1
        && str_contains($restarted[0]['what'], 'instance ' . str_repeat('a', 16) . ' where this channel knew instance ' . $firstInstance) && $pcInbox->expireAt === $fake->now + 600,
    'an inbox a write opened again in the same second is told by its instance, read without a receipt first, and the channel follows it',
    json_encode([$textsOf($got), $restarted])
);

// The owner opening the same address again in the same second, with the same lifetime and list: another thread by its
// instance alone, and a receipt first compares nothing read on the old one and names nobody for its line.
$fake->threads[$pcInbox->w] = array_replace($fake->threads[$pcInbox->w], ['messages' => [], 'instance' => str_repeat('b', 16)]);
$pcSender->send('receiver', 'on the third thread');
$before = $pc->receipt('inbox');
$check(
    $before['held_locally'] === 0 && $before['local_root_matches'] === null && $before['keys'] === [$pcSender->keys->public] && $before['keys_unverified_count'] === 1
        && str_contains($before['local_check'], 'on another thread, instance ' . str_repeat('a', 16)) && str_contains($before['local_check'], 'this receipt is for instance ' . str_repeat('b', 16)),
    'a receipt of a thread opened again with the same fields compares nothing read on the old one, and names nobody for its line',
    json_encode($before)
);
$got = $pc->read();
$check($textsOf($got) === ['on the third thread'] && array_filter($pc->attentionTaken(), static fn (array $note): bool => $note['state'] === 'restarted') !== [], 'and the read after it is told by the instance, and reads the thread from the start', json_encode($textsOf($got)));
$check($pc->receipt('inbox')['keys'] === ['sender'], 'after which the receipt names the partner again');

// An open thread with the service's own lifetime, opened again by a write in the same second: every other field the same.
$sideW = str_repeat('d', 20);
$plain = static fn (int $seq, string $text): array => ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $text, 'sha256' => hash('sha256', $text), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];
$fake->threads[$sideW] = ['id' => 'side-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$plain(1, 'on the first side thread')], 'gate' => [], 'instance' => str_repeat('c', 16)];
$pc->channels['side'] = new Channel('side', 'side-read-key', $sideW, $fake->now + 600);
$side = $pc->channels['side'];
$got = $pc->read();
$check($textsOf($got) === ['on the first side thread'] && $side->instance === str_repeat('c', 16), 'an open side thread is read, and the instance it is named by kept');
$fake->threads[$sideW] = array_replace($fake->threads[$sideW], ['messages' => [$plain(1, 'on the new side thread')], 'instance' => str_repeat('e', 16)]);
$got = $pc->read();
$check($textsOf($got) === ['on the new side thread'], 'an open thread opened again in the same second with the same lifetime is told by its instance', json_encode($textsOf($got)));
$pc->attentionTaken();
$loaded = Channel::fromState($side->toState());
$check($loaded->instance === str_repeat('e', 16), 'the instance is kept in state.json, so a process that starts on the home knows its thread');

// From a service that names no instance, the lifetime still tells a thread a write opened in the same second.
unset($fake->threads[$sideW]['instance']);
$side->forgetThread();
$fake->threads[$sideW] = ['id' => 'side-read-key', 'created_at' => $fake->now + 20, 'expire_at' => $fake->now + 3620, 'allow' => [], 'messages' => [$plain(1, 'old')], 'gate' => []];
$pc->read();
$fake->threads[$sideW] = ['id' => 'side-read-key', 'created_at' => $fake->now + 20, 'expire_at' => $fake->now + 620, 'allow' => [], 'messages' => [$plain(1, 'new')], 'gate' => []];
$got = $pc->read();
$check($textsOf($got) === ['new'] && array_filter($pc->attentionTaken(), static fn (array $note): bool => $note['state'] === 'restarted' && str_contains($note['what'], 'expiring at ' . ($fake->now + 620))) !== [], 'from a service that names no instance, a thread a write opened in the same second is told by when it expires', json_encode($textsOf($got)));

// A byte budget, as messages are handed over: the service sends what its own measure lets through, and one fits here.
$fake->threads[$sideW] = ['id' => 'side-read-key', 'created_at' => $fake->now + 30, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => [], 'instance' => str_repeat('f', 16)];
$side->forgetThread();
foreach (['h1', 'h2', 'h3'] as $n => $text) {
    $fake->threads[$sideW]['messages'][] = $plain($n + 1, $text);
}
$all = $pc->read();
$weight = strlen(Codec::json($all[0]));
$side->forgetThread();
$side->seen = [];
$side->seenFrom = [];
$pc->attentionTaken();
$got = $pc->read(0, 50, $weight * 2 - 1);
$said = array_values(array_filter($pc->attentionTaken(), static fn (array $note): bool => $note['state'] === 'more'));
$check(
    $textsOf($got) === ['h1'] && strlen(Codec::json($got[0])) === $weight && count($said) === 1 && str_starts_with($said[0]['what'], 'this read stopped at the byte budget it asked for on side, ' . ($weight * 2 - 1) . ' bytes of messages as they are handed over here')
        && $side->after === 1 && array_keys($side->observed) === [1] && array_keys($side->seen) === [hash('sha256', 'h1')],
    'a byte budget holds for messages as they are handed over, and a message not taken is not observed or remembered',
    json_encode([$textsOf($got), $said])
);
$fake->threads[$sideW]['messages'] = [$plain(1, str_repeat('x', 800)), $plain(2, 'short')];
$fake->threads[$sideW]['created_at'] = $fake->now + 60;
$side->forgetThread();
// A service that sends every row whatever the budget: the large one has come.
$fake->ignoreBudget = true;
try {
    $got = $pc->read(0, 50, 600);
} finally {
    $fake->ignoreBudget = false;
}
$notes = $pc->attentionTaken();
$check(
    array_map('strlen', $textsOf($got)) === [800] && array_filter($notes, static fn (array $note): bool => $note['state'] === 'over_budget' && str_starts_with($note['what'], 'message 1 on this channel is ')) !== []
        && array_filter($notes, static fn (array $note): bool => $note['state'] === 'more') !== [],
    'a message alone larger than the budget is handed over on its own, said, and the rest said to wait',
    json_encode($notes)
);
unset($pc->channels['side']);

// A receipt whose keys are the signers of its lines is taken: none for an unsigned line, one for two lines by one key.
$fake->threads[$sideW] = ['id' => 'side-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$plain(1, 'nobody\'s')], 'gate' => []];
$pc->channels['side'] = new Channel('side', 'side-read-key', $sideW, $fake->now + 600);
$signedBy = static fn (int $seq, string $text, Keys $keys): array => ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $text, 'sha256' => hash('sha256', $text), 'from' => $keys->public, 'sig' => $keys->sign(Keys::threadSigningInput($sideW, $text)), 'verified' => true, 'sealed' => false];
$fake->threads[$sideW]['messages'][] = $signedBy(2, 'signed one', $pcSender->keys);
$fake->threads[$sideW]['messages'][] = $signedBy(3, 'signed two', $pcSender->keys);
$pc->read();
$taken = $pc->receipt('side');
$check($taken['count'] === 3 && $taken['root_adds_up'] === true && $taken['local_root_matches'] === true && $taken['keys'] === ['sender'], 'a receipt whose keys are the signers of its lines is taken, one key for two lines', json_encode($taken));
unset($pc->channels['side']);

// An archive that fails takes no read over its budget: the mark is weighed at its longer value, and what went wrong is
// said in attention, not on the message.
$archiving = new Runtime($root . DIRECTORY_SEPARATOR . 'release-check-10-archive', 'https://fake.test', ['r10'], true, $r10Quiet);
$archiving->partnerAdd('sender', $pcSender->keys->public);
$archiving->read();
$archSide = str_repeat('h', 20);
$fake->threads[$archSide] = ['id' => 'arch-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$plain(1, 'a1'), $plain(2, 'a2')], 'gate' => [], 'instance' => str_repeat('g', 16)];
$archiving->channels['side'] = new Channel('side', 'arch-read-key', $archSide, $fake->now + 600);
@mkdir($archiving->home . DIRECTORY_SEPARATOR . 'archive', 0700, true);
@mkdir($archiving->home . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'side.jsonl', 0700, true);
$all = $archiving->read();
$sizes = array_map(static fn (array $entry): int => strlen(Codec::json($entry)), array_values(array_filter($all, static fn (array $entry): bool => ($entry['channel'] ?? null) === 'side')));
$archiveFailed = array_values(array_filter($archiving->attentionTaken(), static fn (array $note): bool => $note['state'] === 'archive_failed'));
$archiving->channels['side']->forgetThread();
$archiving->channels['side']->seen = [];
$archiving->channels['side']->seenFrom = [];
$got = array_values(array_filter($archiving->read(0, 50, array_sum($sizes) - 1), static fn (array $entry): bool => ($entry['channel'] ?? null) === 'side'));
$check(
    count($sizes) === 2 && count($archiveFailed) === 1 && ($archiveFailed[0]['count'] ?? 0) === 2 && count($got) === 1 && !array_key_exists('archive_error', $got[0]) && $got[0]['archived'] === false,
    'an archive that fails takes no read over its budget: one byte under the two, the second waits, and the failure is said in attention',
    json_encode([$sizes, $archiveFailed, count($got)])
);
$archiving->close();

// A scope share is weighed as it will be shown, and the weighing keeps nothing.
$scopeKey = Address::newScopeKey();
$share = static fn (): array => ['verified' => true, 'encrypted' => true, 'known_contact' => true, 'from_key' => $pcSender->keys->public, 'replay' => false, 'body' => ['text' => 'a scope', 'data' => ['aamio_scope' => ['name' => 'team', 'key' => $scopeKey]]]];
$take = (function (array &$entry, bool $weigh): void {
    $this->takeScopeShare($entry, true, $weigh);
})->bindTo($pc, Runtime::class);
$weighed = $share();
$scopesBefore = $pc->scopeList();
$take($weighed, true);
$scopesWeighed = $pc->scopeList();
$taken = $share();
$take($taken, false);
$check($scopesWeighed === $scopesBefore && $weighed === $taken && strlen(Codec::json($weighed)) === strlen(Codec::json($taken)), 'a scope share is weighed as it will be shown, and the weighing keeps nothing', json_encode([$weighed, $taken]));

// An anchoring tool that answered with an error made no proof, whatever its text names.
$diagnostic = json_encode(['proof_id' => 'proof-diagnostic']);
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake, $diagnostic): array {
    if (str_contains($url, 'verifyum')) {
        return [200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $diagnostic]], 'isError' => true]], []];
    }

    return $fake($method, $url, $body, $headers);
};
$anchored = $pc->receipt('inbox', true)['anchor'];
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake): array {
    if (str_contains($url, 'verifyum')) {
        return [200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode(['proof_id' => ' '])]], 'isError' => false]], []];
    }

    return $fake($method, $url, $body, $headers);
};
$blank = $pc->receipt('inbox', true)['anchor'];
Http::$override = $fake;
$check($anchored === ['error' => 200, 'detail' => $diagnostic], 'an anchoring tool that answered with an error made no proof, whatever its text names', json_encode($anchored));
$check(($blank['error'] ?? null) === 200, 'and a proof_id of blanks names no proof', json_encode($blank));

// An answer that broke off after its status line keeps the status where it is asked for: a 503 cut short was 0.
$serverScript = $root . DIRECTORY_SEPARATOR . 'broken-off-server.php';
file_put_contents($serverScript, <<<'SERVER'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
for ($i = 0; $i < 2; $i++) {
    $conn = @stream_socket_accept($server, 30);
    if ($conn === false) {
        break;
    }
    $head = '';
    while (($line = fgets($conn)) !== false) {
        $head .= $line;
        if (rtrim($line) === '') {
            break;
        }
    }
    $left = preg_match('/content-length:\s*(\d+)/i', $head, $m) === 1 ? (int) $m[1] : 0;
    while ($left > 0 && !feof($conn)) {
        $chunk = fread($conn, $left);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $left -= strlen($chunk);
    }
    fwrite($conn, "HTTP/1.1 503 Service Unavailable\r\nContent-Type: application/json\r\nContent-Length: 100\r\nConnection: close\r\n\r\n{\"error\":");
    fclose($conn);
}
SERVER);
$serverProcess = proc_open([PHP_BINARY, $serverScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes);
$serverAt = is_resource($serverProcess) ? trim((string) fgets($serverPipes[1])) : '';
Http::$override = null;
try {
    $kept = Http::call('POST', 'http://' . $serverAt . '/mcp', '{}', ['Content-Type' => 'application/json'], 20, true);
    $plainCall = Http::call('POST', 'http://' . $serverAt . '/mcp', '{}', ['Content-Type' => 'application/json'], 20);
} finally {
    Http::$override = $fake;
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    @unlink($serverScript);
}
$check(
    $serverAt !== '' && $kept[0] === 503 && str_starts_with((string) ($kept[1]['error'] ?? ''), 'the answer broke off') && $plainCall[0] === 0,
    'an answer that broke off after its status keeps the status where it is asked for, as anchoring asks, and is unknown elsewhere',
    json_encode([$serverAt, $kept[0], $plainCall[0]])
);

// --------------------------------------------- the post-check of the evening
// code/php/docs/aamio-trad-id-etterkontroll-2026-11-10.md in the service.
echo "the post-check of the evening: the expiry of the thread that is there, a cursor kept without the instance, a budget a trace or a share cannot break\n";

Http::$override = $fake;
$signedOn = static fn (string $w, int $seq, string $text, Keys $keys): array => ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $text, 'sha256' => hash('sha256', $text), 'from' => $keys->public, 'sig' => $keys->sign(Keys::threadSigningInput($w, $text)), 'verified' => true, 'sealed' => false];
$on = static fn (array $entries, string $label): array => array_values(array_filter($entries, static fn (array $entry): bool => ($entry['channel'] ?? null) === $label));
$replays = static fn (array $entries): array => array_map(static fn (array $entry): array => [$entry['seq'], $entry['body']['text'] ?? null, $entry['replay'] ?? null], $entries);
$state = static fn (Runtime $runtime, string $wanted): array => array_values(array_filter($runtime->attentionTaken(), static fn (array $note): bool => $note['state'] === $wanted));

// A thread the service read from the start after a restart: the channel takes its expiry with its instance, a shorter
// one or a longer one than it was opened with, and state.json keeps it.
$ev = $r10Open();
$evW = str_repeat('j', 20);
$fake->threads[$evW] = ['id' => 'ev-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 3600, 'allow' => [], 'messages' => [$plain(1, 'one'), $plain(2, 'two')], 'gate' => [], 'instance' => str_repeat('k', 16)];
$ev->channels['side'] = new Channel('side', 'ev-read-key', $evW, $fake->now + 3600);
$evSide = $ev->channels['side'];
$ev->read();
$fake->threads[$evW] = ['id' => 'ev-read-key', 'created_at' => $fake->now + 5, 'expire_at' => $fake->now + 605, 'allow' => [], 'messages' => [$plain(1, 'after the restart')], 'gate' => [], 'instance' => str_repeat('m', 16)];
$got = $on($ev->read(), 'side');
$restarted = $state($ev, 'restarted');
$check(
    $textsOf($got) === ['after the restart'] && count($restarted) === 1 && str_contains($restarted[0]['what'], 'past the last message')
        && $evSide->instance === str_repeat('m', 16) && $evSide->expireAt === $fake->now + 605 && Channel::fromState($evSide->toState())->expireAt === $fake->now + 605,
    'a thread the service read from the start is followed in its expiry, a shorter one, and state.json keeps it',
    json_encode([$textsOf($got), $restarted, $evSide->expireAt])
);
$fake->threads[$evW] = ['id' => 'ev-read-key', 'created_at' => $fake->now + 9, 'expire_at' => $fake->now + 7209, 'allow' => [], 'messages' => [], 'gate' => [], 'instance' => str_repeat('n', 16)];
$ev->read();
$ev->attentionTaken();
$check($evSide->instance === str_repeat('n', 16) && $evSide->expireAt === $fake->now + 7209, 'and a longer one', json_encode($evSide->expireAt));
unset($ev->channels['side']);

// A cursor kept without the thread's instance, as a client that does not keep it leaves a home: read again from the
// start once, all of it, and what came before marked replay.
$evW2 = str_repeat('p', 20);
$fake->threads[$evW2] = ['id' => 'ev2-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 3600, 'allow' => [], 'messages' => [$plain(1, 'h1'), $plain(2, 'h2'), $plain(3, 'h3')], 'gate' => [], 'instance' => str_repeat('q', 16)];
$kept = new Channel('kept', 'ev2-read-key', $evW2, $fake->now + 3600, [], 2, $fake->now);
$kept->seen = [hash('sha256', 'h1') => true, hash('sha256', 'h2') => true];
$ev->channels['kept'] = $kept;
$got = $on($ev->read(), 'kept');
$reread = $state($ev, 'reread');
$check(
    $replays($got) === [[1, 'h1', false], [2, 'h2', false], [3, 'h3', false]] && array_map(static fn (array $entry): bool => $entry['replay_unknown'] ?? false, $got) === [true, true, false] && count($reread) === 1
        && str_contains($reread[0]['what'], 'cursor, 2, was kept by a client that did not keep the thread\'s instance') && $kept->instance === str_repeat('q', 16) && $kept->after === 3,
    'a cursor kept without the thread\'s instance is read again from the start once, all of it, and what an earlier version kept without who sent it is said not to be known',
    json_encode([$replays($got), $reread])
);
$got = $on($ev->read(), 'kept');
$check($got === [] && $state($ev, 'reread') === [], 'once');
unset($ev->channels['kept']);

// Another sender's message with the same text, and so the same hash, reaches the agent when such a cursor is read
// again: on the same thread, and as the first message of one opened again in the same second.
$bob = Keys::generate();
$evW3 = str_repeat('r', 20);
$fake->threads[$evW3] = ['id' => 'ev3-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 3600, 'allow' => [], 'messages' => [$signedOn($evW3, 1, 'OK', $pcSender->keys), $signedOn($evW3, 2, 'OK', $bob)], 'gate' => [], 'instance' => str_repeat('s', 16)];
$ok = new Channel('ok', 'ev3-read-key', $evW3, $fake->now + 3600, [], 1, $fake->now);
$ok->seen = [hash('sha256', 'OK') => true];
$ev->channels['ok'] = $ok;
$senders = static fn (array $entries): array => array_map(static fn (array $entry): array => [$entry['seq'], $entry['from_key']], $entries);
$got = $on($ev->read(), 'ok');
$check($senders($got) === [[1, $pcSender->keys->public], [2, $bob->public]], 'another sender\'s OK with the same hash comes, on the same thread', json_encode($senders($got)));
$fake->threads[$evW3] = array_replace($fake->threads[$evW3], ['messages' => [$signedOn($evW3, 1, 'OK', $bob)], 'instance' => str_repeat('t', 16)]);
$ok->after = 1;
$ok->instance = null;
$got = $on($ev->read(), 'ok');
$ev->attentionTaken();
$check($senders($got) === [[1, $bob->public]], 'and as the first message of a thread opened again in the same second', json_encode($senders($got)));
unset($ev->channels['ok']);
$ev->close();

// A trace that cannot be written takes no read over its budget: said in attention, not on the message.
$tr = $r10Open();
$trW = str_repeat('u', 20);
$fake->threads[$trW] = ['id' => 'tr-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$signedOn($trW, 1, 't1', $pcSender->keys), $signedOn($trW, 2, 't2', $pcSender->keys)], 'gate' => [], 'instance' => str_repeat('v', 16)];
$tr->channels['side'] = new Channel('side', 'tr-read-key', $trW, $fake->now + 600);
@mkdir($tr->home . DIRECTORY_SEPARATOR . 'trace.json', 0700, true);
$whole = $on($tr->read(), 'side');
$untraced = $state($tr, 'untraced');
$sizes = array_map(static fn (array $entry): int => strlen(Codec::json($entry)), $whole);
$tr->channels['side']->forgetThread();
$tr->channels['side']->seen = [];
$tr->channels['side']->seenFrom = [];
$got = $on($tr->read(0, 50, array_sum($sizes) - 1), 'side');
$check(
    count($whole) === 2 && array_filter($whole, static fn (array $entry): bool => array_key_exists('trace_error', $entry)) === [] && count($untraced) === 1
        && count($got) === 1 && strlen(Codec::json($got[0])) <= array_sum($sizes) - 1,
    'a trace that cannot be written takes no read over its budget: said in attention, not on the message, and the second waits',
    json_encode([$sizes, $untraced, count($got)])
);
$tr->close();

// A scope share that cannot be saved is weighed no lighter than it is shown, kept nowhere, and said in attention.
$sc = $r10Open();
$sc->partnerAdd('sender', $pcSender->keys->public);
$scTake = (function (array &$entry, bool $weigh): void {
    $this->takeScopeShare($entry, true, $weigh);
})->bindTo($sc, Runtime::class);
@mkdir($sc->home . DIRECTORY_SEPARATOR . 'scopes.json', 0700, true);
$weighed = $share() + ['seq' => 4, 'channel' => 'inbox', 'w' => str_repeat('i', 20)];
$scTake($weighed, true);
$taken = $share() + ['seq' => 4, 'channel' => 'inbox', 'w' => str_repeat('i', 20)];
$scTake($taken, false);
$notSaved = $state($sc, 'scope_not_saved');
$check(
    $taken['body']['data']['aamio_scope'] === ['shared_as' => 'team', 'can_read' => true, 'kept' => false, 'note' => 'not kept: could not be saved']
        && strlen(Codec::json($taken)) <= strlen(Codec::json($weighed)) && $sc->scopeList() === [] && count($notSaved) === 1 && ($notSaved[0]['seqs'] ?? null) === [4],
    'a scope share that cannot be saved is weighed no lighter than it is shown, kept nowhere, and said in attention',
    json_encode([$taken['body']['data']['aamio_scope'], $notSaved])
);
$sc->close();

// Two shares of one name in one answer are weighed as they are handed over: the second with the conflict it carries.
$shareFrom = $r10Open();
$shareTo = $r10Open();
$shareFrom->partnerAdd('receiver', $shareTo->keys->public);
$shareTo->partnerAdd('sender', $shareFrom->keys->public);
$shareTo->read();
$shareFrom->read();
$shareFrom->scopeNew('team');
$shareFrom->scopeShare('team', 'receiver', 'read');
$shareFrom->scopeRemove('team');
$shareFrom->scopeNew('team');
$shareFrom->scopeShare('team', 'receiver', 'read');
$fake->ignoreBudget = true;
try {
    $whole = $shareTo->read();
    $sizes = array_map(static fn (array $entry): int => strlen(Codec::json($entry)), $whole);
    $views = array_map(static fn (array $entry) => $entry['body']['data']['aamio_scope'] ?? null, $whole);
    $shareTo->channels['inbox']->forgetThread();
    $shareTo->channels['inbox']->seen = [];
    $shareTo->channels['inbox']->seenFrom = [];
    $shareTo->scopeRemove('sender.team');
    $shareTo->attentionTaken();
    $got = $shareTo->read(0, 50, array_sum($sizes) - 1);
} finally {
    $fake->ignoreBudget = false;
}
$check(
    count($whole) === 2 && ($views[0]['kept'] ?? null) === true && ($views[1]['kept'] ?? null) === false && str_contains((string) ($views[1]['note'] ?? ''), 'already')
        && count($got) === 1 && strlen(Codec::json($got[0])) <= array_sum($sizes) - 1,
    'two shares of one name in one answer are weighed as they are handed over, the second with its conflict, and the budget holds',
    json_encode([$sizes, $views, count($got)])
);
$shareFrom->close();
$shareTo->close();

// The first save of a share fails and the next one succeeds: each message is weighed when the ones before it were
// taken. Planned ahead, the second share was weighed as held under the first one's name, which was never saved, and
// came under its own longer one: 2164 bytes through a budget of 2124 (the review of the same evening).
$blocker = null;
$unblock = static function (string $line) use (&$blocker): void {
    if ($blocker !== null && str_contains($line, 'scopes.json could not be written')) {
        @rmdir($blocker);
        $blocker = null;
    }
};
$flakyHome = $root . DIRECTORY_SEPARATOR . 'release-check-10-flaky-scopes';
mkdir($flakyHome, 0700, true);
$flaky = new Runtime($flakyHome, 'https://fake.test', ['r10'], false, $unblock);
$flakySender = $r10Open();
$flaky->partnerAdd('sender', $flakySender->keys->public);
$flakySender->partnerAdd('receiver', $flaky->keys->public);
$flaky->read();
$flakySender->read();
$flakyKey = Address::newScopeKey();
$flakySender->send('receiver', 'Scope t', ['aamio_scope' => ['name' => 't', 'key' => $flakyKey]]);
$flakySender->send('receiver', 'Scope again', ['aamio_scope' => ['name' => str_repeat('n', 58), 'key' => $flakyKey]]);
$flakySender->send('receiver', 'one short text');
$flakySender->send('receiver', 'two short text');
$fake->ignoreBudget = true;
try {
    $blocker = $flakyHome . DIRECTORY_SEPARATOR . 'scopes.json.tmp';
    mkdir($blocker);
    $whole = $flaky->read();
    $sizes = array_map(static fn (array $entry): int => strlen(Codec::json($entry)), $whole);
    $views = array_map(static fn (array $entry) => $entry['body']['data']['aamio_scope'] ?? null, $whole);
    $flaky->attentionTaken();
    $flaky->channels['inbox']->forgetThread();
    $flaky->channels['inbox']->seen = [];
    $flaky->channels['inbox']->seenFrom = [];
    foreach ($flaky->scopeList() as $kept) {
        $flaky->scopeRemove($kept['name']);
    }
    $blocker = $flakyHome . DIRECTORY_SEPARATOR . 'scopes.json.tmp';
    mkdir($blocker);
    $got = $flaky->read(0, 50, array_sum($sizes) - 1);
    $afterBudget = $flaky->channels['inbox']->after;
    $flaky->attentionTaken();
    $rest = $flaky->read(0, 50, array_sum($sizes) - 1);
} finally {
    $fake->ignoreBudget = false;
    if ($blocker !== null) {
        @rmdir($blocker);
    }
}
$textsOfFlaky = static fn (array $entries): array => array_map(static fn (array $entry) => $entry['body']['text'] ?? null, $entries);
$check(
    count($whole) === 4 && ($views[0]['kept'] ?? null) === false && ($views[1]['kept'] ?? null) === true && strlen((string) ($views[1]['name'] ?? '')) > 50
        && count($got) === 3 && array_sum(array_map(static fn (array $entry): int => strlen(Codec::json($entry)), $got)) <= array_sum($sizes) - 1 && $afterBudget === 3
        && $textsOfFlaky($rest) === ['two short text'],
    'a share whose first save failed and whose next save worked keeps the budget: the fourth waits, the cursor stops at 3, and the next read hands it over once',
    json_encode([$sizes, $views, count($got), $afterBudget, $textsOfFlaky($rest)])
);
$flaky->close();
$flakySender->close();

// A replay is the same bytes from the same signer, or the same unsigned bytes. The hash alone is of the text, and two
// signers who both sent "OK" made the second a replay of the first (a review of 10 November 2026).
echo "a replay is the same bytes from the same signer\n";
$okDigest = '565339bc4d33d72817b583024112eb7f5cdf3e5eef0252d6ec1b9c9a94e12bb3';
$check(
    Channel::seenRecord($okDigest, 'A6EHv_POEL4dcN0Y50vAmWfk1jCbpQ1fHdyGZBJVMbg') === $okDigest . ':b43cdfe1bf180ef4' && Channel::seenRecord($okDigest, null) === $okDigest . ':-'
        && hash('sha256', 'OK') === $okDigest,
    'the record of a message handed over is the one aamio-python and aamio-js write, so a home reads the same in all three'
);
$rp = $r10Open();
$carol = Keys::generate();
$rpW = str_repeat('s', 20);
$fake->threads[$rpW] = ['id' => 'rp-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$signedOn($rpW, 1, 'OK', $pcSender->keys), $signedOn($rpW, 2, 'OK', $carol), $signedOn($rpW, 3, 'OK', $pcSender->keys), $plain(4, 'OK'), $plain(5, 'OK')], 'gate' => [], 'instance' => str_repeat('u', 16)];
$rp->channels['ok'] = new Channel('ok', 'rp-read-key', $rpW, $fake->now + 600);
$marks = array_map(static fn (array $entry): array => [$entry['seq'], $entry['replay']], $on($rp->read(), 'ok'));
$rp->attentionTaken();
$check(
    $marks === [[1, false], [2, false], [3, true], [4, false], [5, true]],
    'two signers who send the same text send two messages, the same signer again is a replay, and unsigned bytes are a replay of the same unsigned bytes only',
    json_encode($marks)
);
$kept = $rp->channels['ok']->toState();
$loaded = Channel::fromState(array_replace($kept, ['seen_from' => array_merge($kept['seen_from'], ['not a record', str_repeat('a', 64) . ':xyz'])]));
$check(
    count($kept['seen_from']) === 3 && array_keys($loaded->seenFrom) === $kept['seen_from'] && $loaded->isReplay($okDigest, $carol->public) && !$loaded->isReplay($okDigest, Keys::generate()->public),
    'who sent each hash travels with the channel through state.json, and what is not a record is left out',
    json_encode($kept['seen_from'])
);
// As an earlier version left a channel after A's "OK": the hash, and nobody named for it. Then, one process each, B,
// A, B and A send "OK" again: the sequence of the review of 11 November 2026, where B's first was taken for a copy
// and A's next for a new one. Whether each of the first two is a copy cannot be told, and it is said.
unset($rp->channels['ok']);
$rpW2 = str_repeat('t', 20);
$fake->threads[$rpW2] = ['id' => 'rp2-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$signedOn($rpW2, 1, 'OK', $pcSender->keys), $signedOn($rpW2, 2, 'OK', $carol), $signedOn($rpW2, 3, 'OK', $pcSender->keys), $signedOn($rpW2, 4, 'OK', $carol), $signedOn($rpW2, 5, 'OK', $pcSender->keys)], 'gate' => [], 'instance' => str_repeat('v', 16)];
$legacy = new Channel('legacy', 'rp2-read-key', $rpW2, $fake->now + 600, [], 1, $fake->now);
$legacy->instance = str_repeat('v', 16);
$legacy->seen = [$okDigest => true];
$marks = [];
$unknownSaid = [];
for ($i = 0; $i < 4; $i++) {
    $legacy = Channel::fromState($legacy->toState());
    $rp->channels['legacy'] = $legacy;
    foreach ($on($rp->read(0, 1), 'legacy') as $entry) {
        $marks[] = [$entry['seq'], $entry['replay'], $entry['replay_unknown'] ?? false];
    }
    foreach ($state($rp, 'replay_unknown') as $note) {
        $unknownSaid[] = $note['seqs'];
    }
}
$check(
    $marks === [[2, false, true], [3, false, true], [4, true, false], [5, true, false]] && $unknownSaid === [[2], [3]] && isset($legacy->seenFrom[$okDigest . ':?']),
    'a hash kept without its signer leaves it not known whose it was: B and A each say so, and then each is a copy of its own',
    json_encode([$marks, $unknownSaid])
);
$rp->close();

// Board replies: two signers who sent the same answer to one post are two deliveries, in every process (a review of
// 11 November 2026: the second was left out once the list came from the archive).
$brHome = $root . DIRECTORY_SEPARATOR . 'release-check-10-board-replies';
@mkdir($brHome . DIRECTORY_SEPARATOR . 'archive', 0700, true);
$brLines = '';
foreach ([[1, 'a-key'], [2, 'b-key']] as [$seq, $key]) {
    $brLines .= json_encode(['kind' => 'received', 'channel' => 'board', 'w' => str_repeat('b', 20), 'seq' => $seq, 'at' => 1789470700 + $seq, 'verified' => true, 'from_key' => $key, 'sender' => 'unknown key', 'sha256' => str_repeat('c', 64), 'body' => ['post' => 'p1', 'text' => 'OK']]) . "\n";
}
file_put_contents($brHome . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'board.jsonl', $brLines);
$brSeen = [];
for ($i = 0; $i < 2; $i++) {
    $br = new Runtime($brHome, 'https://fake.test', ['r10'], true, $r10Quiet);
    $brSeen[] = array_map(static fn (array $reply): array => [$reply['seq'], $reply['from_key']], $br->boardReplies('p1'));
    $br->close();
}
$check(
    $brSeen === [[[1, 'a-key'], [2, 'b-key']], [[1, 'a-key'], [2, 'b-key']]],
    'two signers with the same answer to one post are both found in every process, once each',
    json_encode($brSeen)
);

$pc->close();
$pcSender->close();
