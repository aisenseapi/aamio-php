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
        && str_contains($before['local_check'], 'on an earlier thread, opened at ' . $fake->now . '; this receipt is for the one opened at ' . ($fake->now + 5)),
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
