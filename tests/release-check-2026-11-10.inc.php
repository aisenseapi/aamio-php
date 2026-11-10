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
        && str_contains($before['local_check'], 'on another thread, opened at ' . $fake->now . ' and expiring at ' . $inbox->expireAt . '; this receipt is for the one opened at ' . ($fake->now + 5) . ' and expiring at ' . $inbox->expireAt),
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

// An inbox a write opened again in the same second, with the service's own lifetime: told by when it expires. A state
// written by a client that kept no hash at the cursor, so the lifetime alone tells.
$pcSender->send('receiver', 'on the first thread');
$pc->read();
$pcInbox->afterSha256 = null;
$fake->threads[$pcInbox->w] = ['id' => $pcInbox->readKey, 'created_at' => $pcInbox->createdAt, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [], 'gate' => []];
$pcSender->send('receiver', 'on the new thread');
$got = $pc->read();
$restarted = array_values(array_filter($pc->attentionTaken(), static fn (array $note): bool => $note['state'] === 'restarted'));
$check(
    $textsOf($got) === ['on the new thread'] && count($restarted) === 1 && str_contains($restarted[0]['what'], 'expiring at ' . ($fake->now + 600) . ', where this channel knew one') && $pcInbox->expireAt === $fake->now + 600,
    'an inbox a write opened again in the same second is told by when it expires, read without a receipt first, and the channel follows it',
    json_encode([$textsOf($got), $restarted])
);

// The owner opening the same address again in the same second, with the same lifetime and list: told by the hash at
// the cursor, and a receipt first names nobody for a line this process did not read.
$fake->threads[$pcInbox->w] = array_replace($fake->threads[$pcInbox->w], ['messages' => []]);
$pcSender->send('receiver', 'on the third thread');
$before = $pc->receipt('inbox');
$check(
    $before['local_root_matches'] === false && $before['local_differences'] === [['seq' => 1, 'fields' => ['sha256']]] && $before['keys'] === [$pcSender->keys->public] && $before['keys_unverified_count'] === 1,
    'a receipt of a thread the fields cannot tell from the one read is compared, and names nobody for a line this process did not read',
    json_encode($before)
);
$got = $pc->read();
$check(
    $textsOf($got) === ['on the third thread'] && array_filter($pc->attentionTaken(), static fn (array $note): bool => $note['state'] === 'restarted' && str_contains($note['what'], 'seq 1, is not the one read there')) !== [],
    'and the read after it is told by the hash at the cursor, and reads the thread from the start',
    json_encode($textsOf($got))
);
$check($pc->receipt('inbox')['keys'] === ['sender'], 'after which the receipt names the partner again');

// An open thread with the service's own lifetime, opened again by a write in the same second: every field the same.
$sideW = str_repeat('d', 20);
$plain = static fn (int $seq, string $text): array => ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $text, 'sha256' => hash('sha256', $text), 'from' => null, 'sig' => null, 'verified' => false, 'sealed' => false];
$fake->threads[$sideW] = ['id' => 'side-read-key', 'created_at' => $fake->now, 'expire_at' => $fake->now + 600, 'allow' => [], 'messages' => [$plain(1, 'on the first side thread')], 'gate' => []];
$pc->channels['side'] = new Channel('side', 'side-read-key', $sideW, $fake->now + 600);
$side = $pc->channels['side'];
$got = $pc->read();
$check($textsOf($got) === ['on the first side thread'] && $side->after === 1 && $side->afterSha256 === hash('sha256', 'on the first side thread'), 'an open side thread is read, and the hash at its cursor kept');
$fake->threads[$sideW] = array_replace($fake->threads[$sideW], ['messages' => [$plain(1, 'on the new side thread')]]);
$got = $pc->read();
$check($textsOf($got) === ['on the new side thread'], 'an open thread opened again in the same second with the same lifetime is told by the hash at the cursor', json_encode($textsOf($got)));
$pc->attentionTaken();
$loaded = Channel::fromState($side->toState());
$check($loaded->afterSha256 === $side->afterSha256 && $loaded->cursorChecked === false, 'the hash at the cursor is kept in state.json, and a channel read from it is checked before its cursor is used');

// A byte budget, as messages are handed over: the service sends what its own measure lets through, and one fits here.
$fake->threads[$sideW]['messages'] = [];
$fake->threads[$sideW]['created_at'] = $fake->now + 30;
$side->forgetThread();
foreach (['h1', 'h2', 'h3'] as $n => $text) {
    $fake->threads[$sideW]['messages'][] = $plain($n + 1, $text);
}
$all = $pc->read();
$weight = strlen(Codec::json($all[0]));
$side->forgetThread();
$side->seen = [];
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

// An anchoring tool that answered with an error made no proof, whatever its text names.
$diagnostic = json_encode(['proof_id' => 'proof-diagnostic']);
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake, $diagnostic): array {
    if (str_contains($url, 'verifyum')) {
        return [200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $diagnostic]], 'isError' => true]], []];
    }

    return $fake($method, $url, $body, $headers);
};
$anchored = $pc->receipt('inbox', true)['anchor'];
Http::$override = $fake;
$check($anchored === ['error' => 200, 'detail' => $diagnostic], 'an anchoring tool that answered with an error made no proof, whatever its text names', json_encode($anchored));

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

$pc->close();
$pcSender->close();
