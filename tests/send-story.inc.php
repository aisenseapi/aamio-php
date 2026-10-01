<?php

declare(strict_types=1);

/*
 * What a send says about itself: never sent, sent and refused, or sent and open.
 *
 * A health check of 30 September 2026 found two sends told wrongly. A message
 * too large for any inbox was stored first and refused by the client
 * afterwards, so it waited as a send that might have landed, and a restart
 * offered it again, though no byte ever left. And a message that went once and
 * was refused with 428 was called never sent when the client chose not to meet
 * the gate the refusal named. The same checks as aamio-python's
 * tests/test_a_send_is_told_as_it_went.py.
 *
 * Included by tests/runtime.php, with $fake, $root and $check in scope.
 */

use Aamio\Gate;
use Aamio\Http;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;
use Aamio\SendFailed;

echo "a send is told as it went\n";

$storyQuiet = static function (string $line): void {
};
$storyCount = 0;
/** A runtime that knows one other runtime's inbox by its key, both in homes of their own. */
$storyPair = static function () use ($root, $storyQuiet, &$storyCount): array {
    $homes = [];
    foreach (['s', 'o'] as $side) {
        $homes[$side] = $root . DIRECTORY_SEPARATOR . 'story-' . (++$storyCount) . '-' . $side;
        mkdir($homes[$side], 0700, true);
    }
    $s = new Runtime($homes['s'], 'https://fake.test', ['s'], false, $storyQuiet);
    $o = new Runtime($homes['o'], 'https://fake.test', ['o'], false, $storyQuiet);
    $s->ensureInbox();
    $oInbox = $o->ensureInbox();
    $s->peers[$oInbox->w] = $o->keys->public;

    return [$s, $o, $oInbox->w, $homes['s']];
};
$postsTo = static fn (string $w): int => count(array_filter($fake->calls, static fn (array $call): bool => $call[0] === 'POST' && str_contains($call[1], '/' . $w)));

// ---------------------------------------------------------------- too large for any inbox
[$s, $o, $to, $home] = $storyPair();
$before = $postsTo($to);
try {
    $s->send($to, str_repeat('x', 70000));
    $tooLarge = null;
} catch (\InvalidArgumentException $refused) {
    $tooLarge = $refused->getMessage();
}
$check($tooLarge !== null && str_contains($tooLarge, 'at most 65536') && str_contains($tooLarge, 'nothing was stored or sent') && $s->outbox === [] && $postsTo($to) === $before, 'a message too large for any inbox is not stored and not sent', (string) $tooLarge);
$said = (new McpServer($s))->dispatch('aamio_send', ['to' => $to, 'text' => str_repeat('x', 70000)]);
$check(($said['isError'] ?? false) === true && str_contains((string) ($said['structuredContent']['error'] ?? ''), 'at most 65536') && $s->outboxPending() === [] && $postsTo($to) === $before, 'and over MCP the answer says why, and nothing waits');
$s->close();
$again = new Runtime($home, 'https://fake.test', ['s'], false, $storyQuiet);
$check($again->outboxPending() === [] && $again->outbox === [], 'nor after a restart');
$again->close();
$o->close();

// ---------------------------------------------------------------- sent once, refused with 428
$refusedForWork = [428, ['error' => 'This inbox requires proof of work.', 'fix' => 'Compute the work the gate asks for and send again.', 'gate' => ['require' => ['pow' => ['bits' => 32]]], 'seconds_left' => 1], []];
$script = [];
$scriptedTo = null;
$scriptedPosts = 0;
$storyHeld = Http::$override;
Http::$override = static function (string $method, string $url, ?string $body, array $headers, mixed ...$rest) use ($fake, &$script, &$scriptedTo, &$scriptedPosts): array {
    if ($method === 'POST' && $scriptedTo !== null && str_contains($url, '/' . $scriptedTo) && $script !== []) {
        $scriptedPosts++;

        return array_shift($script);
    }

    return $fake($method, $url, $body, $headers);
};
try {
    [$s, $o, $to] = $storyPair();
    $scriptedTo = $to;
    $script = [$refusedForWork];
    $scriptedPosts = 0;
    try {
        $s->send($to, 'hello');
        $refusal = null;
    } catch (\Throwable $notStored) {
        // Anything else thrown is the fault this checks for, and fails below.
        $refusal = $notStored instanceof SendFailed ? $notStored : null;
    }
    $entry = $refusal === null ? null : ($s->outbox[$refusal->messageId] ?? null);
    $check($refusal !== null && $refusal->outcome === 'refused' && $refusal->status === 428 && $scriptedPosts === 1 && ($entry['status'] ?? null) === 'refused' && Runtime::outboxOutcome($entry) === 'refused', 'a message refused with 428 and not sent again is refused, not never sent', $refusal === null ? 'no failure' : $refusal->outcome);
    $check(is_array($refusal?->detail) && str_contains((string) ($refusal->detail['fix'] ?? ''), 'it was not sent again') && !str_contains((string) ($refusal->detail['fix'] ?? ''), 'nothing was sent'), 'and the reason it was not met says it went once', json_encode($refusal?->detail));
    $check($s->outboxPending() === [] && $s->outboxRetry((string) $refusal?->messageId) === [] && ($s->outboxForget((string) $refusal?->messageId)['outcome'] ?? null) === 'refused', 'pending, retry and forget tell the same story');
    $s->close();
    $o->close();

    [$s, $o, $to] = $storyPair();
    $scriptedTo = $to;
    $script = [$refusedForWork];
    try {
        $said = (new McpServer($s))->dispatch('aamio_send', ['to' => $to, 'text' => 'hello'])['structuredContent'] ?? [];
    } catch (\Throwable) {
        $said = [];
    }
    $check(($said['error_code'] ?? null) === 'send_refused' && ($said['status'] ?? null) === 428 && ($said['outcome'] ?? null) === 'refused' && ($said['retryable'] ?? null) === false && !str_contains((string) ($said['error'] ?? ''), 'nothing was sent'), 'over MCP a 428 is told as a refusal, not as nothing sent', json_encode($said));
    $s->close();
    $o->close();

    // An attempt that got no answer may be on the other side: a 428 after it does not settle it.
    [$s, $o, $to] = $storyPair();
    $scriptedTo = $to;
    $script = [[0, ['error' => 'no answer', 'fix' => 'the request may have landed'], []], $refusedForWork];
    try {
        $s->send($to, 'hello');
        $first = null;
    } catch (SendFailed $notStored) {
        $first = $notStored;
    }
    try {
        $retried = $s->outboxRetry((string) $first?->messageId);
    } catch (\Throwable) {
        $retried = [];
    }
    $entry = $s->outbox[(string) $first?->messageId] ?? null;
    $check($first?->outcome === 'unknown' && ($retried[0]['http'] ?? null) === 428 && Runtime::outboxOutcome($entry) === 'attempted' && in_array($first?->messageId, array_column($s->outboxPending(), 'id'), true), 'an earlier attempt left open stays open whatever the 428 after it', json_encode($entry['status'] ?? null));
    $s->close();
    $o->close();

    // Every way the gate named by a 428 can stop the second post. The stop texts
    // carried "nothing was sent" in more than one wording, and the 428 path
    // reworded only one of them (a check of 30 September 2026).
    $stopsAfter428 = [
        'a requirement this client does not know' => [['gate' => ['require' => ['captcha' => ['site' => 'x']]]], null],
        'more work than any inbox may ask for' => [['gate' => ['require' => ['pow' => ['bits' => 40]]]], null],
        'work that would not be done in time' => [['gate' => ['require' => ['pow' => ['bits' => 32]]], 'seconds_left' => 1], null],
        'work longer than a tool call is given' => [['gate' => ['require' => ['pow' => ['bits' => 24]]]], 0.001],
    ];
    foreach ($stopsAfter428 as $what => [$gate, $budget]) {
        [$s, $o, $to] = $storyPair();
        $s->client->workBudget = $budget;
        $scriptedTo = $to;
        $script = [[428, array_merge($refusedForWork[1], ['seconds_left' => 600], $gate), []]];
        $scriptedPosts = 0;
        try {
            $s->send($to, 'hello');
            $refusal = null;
        } catch (\Throwable $notStored) {
            $refusal = $notStored instanceof SendFailed ? $notStored : null;
        }
        $fix = (string) ($refusal?->detail['fix'] ?? '');
        $check($refusal?->outcome === 'refused' && $refusal->status === 428 && $scriptedPosts === 1 && str_starts_with($fix, 'The message went once and the inbox refused it with 428, and it was not sent again.') && !str_contains(strtolower($fix), 'nothing'), 'after a 428, ' . $what . ' is told as sent once and never as nothing sent', $fix);
        $s->close();
        $o->close();
    }

    // Saved as an older version saved them, with no mark for an attempt left
    // open: only a send in flight was marked on load. A 428 on the retry then
    // called the message refused, and it left the pending list though the first
    // attempt may have landed (a check of 30 September 2026).
    $leftOpen = [
        'unknown after a restart' => ['status' => 'unknown', 'note' => 'the process stopped while this was in flight', 'last_status' => null],
        'attempted after a 500' => ['status' => 'attempted', 'last_status' => 500],
        'refused after a 500, as before 20 September' => ['status' => 'refused', 'last_status' => 500],
    ];
    $settled = ['status' => 'refused', 'last_status' => 410];
    [$s, $o, $to, $home] = $storyPair();
    $envelope = $s->keys->seal($o->keys->public, '{"text":"x"}');
    foreach ($leftOpen + ['settled by a 410' => $settled] as $what => $fields) {
        $id = 'm-older-' . substr(md5($what), 0, 12);
        $s->outbox[$id] = array_merge(['id' => $id, 'w' => $to, 'to_key' => $o->keys->public, 'envelope' => $envelope, 'summary' => [], 'created_at' => time(), 'attempts' => 1, 'replaces' => null, 'shape' => []], $fields);
    }
    (new \ReflectionMethod(Runtime::class, 'saveOutbox'))->invoke($s);
    $s->close();
    $again = new Runtime($home, 'https://fake.test', ['s'], false, $storyQuiet);
    $scriptedTo = $to;
    foreach ($leftOpen as $what => $fields) {
        $id = 'm-older-' . substr(md5($what), 0, 12);
        $loaded = $again->outbox[$id] ?? [];
        $script = [$refusedForWork];
        $scriptedPosts = 0;
        try {
            $retried = $again->outboxRetry($id);
        } catch (\Throwable) {
            $retried = [];
        }
        $entry = $again->outbox[$id] ?? null;
        $check(($loaded['ever_open'] ?? false) === true && ($retried[0]['http'] ?? null) === 428 && $scriptedPosts === 1 && ($entry['status'] ?? null) === 'attempted' && Runtime::outboxOutcome($entry) === 'attempted' && in_array($id, array_column($again->outboxPending(), 'id'), true), 'an entry ' . $what . ', saved by an older version, stays open after a 428 on its retry', json_encode([$entry['status'] ?? null, $entry['ever_open'] ?? null]));
    }
    $settledEntry = $again->outbox['m-older-' . substr(md5('settled by a 410'), 0, 12)] ?? [];
    $check(!array_key_exists('ever_open', $settledEntry) && Runtime::outboxOutcome($settledEntry) === 'refused', 'and one a refusal had settled is not reopened by the restart', json_encode($settledEntry));
    $again->close();
    $o->close();

    // The work for a second post runs out in the real solver, so the one post
    // that went is the answer. Key, address and message are fixed, and no nonce
    // below 65536 reaches 17 bits for them: the solver looks at its deadline
    // after the 65536th, and with five seconds left the deadline is already
    // there. Until a review of 1 October 2026 this branch was tried only by
    // hand, since Gate::solve cannot be replaced in a test.
    $timeoutHome = $root . DIRECTORY_SEPARATOR . 'story-timeout';
    mkdir($timeoutHome, 0700, true);
    file_put_contents($timeoutHome . DIRECTORY_SEPARATOR . 'key', str_repeat('5a', 32) . "\n");
    $timeoutRuntime = new Runtime($timeoutHome, 'https://fake.test', ['t'], false, $storyQuiet);
    $timeoutTo = 'aamiotimeoutcheckaaa';
    $timeoutMessage = 'a second post that never goes';
    $timeoutPrefix = "aamio-pow-v1\n" . $timeoutTo . "\n" . $timeoutRuntime->keys->public . "\n" . hash('sha256', $timeoutMessage) . "\n";
    $timeoutNonce = null;
    for ($candidate = 0; $candidate < 65536 && $timeoutNonce === null; $candidate++) {
        if (Gate::zeroBits(hash('sha256', $timeoutPrefix . $candidate, true)) >= 17) {
            $timeoutNonce = $candidate;
        }
    }
    $check($timeoutNonce === null, 'for the fixed key, address and message no nonce below 65536 reaches 17 bits', $timeoutNonce === null ? 'none found' : 'nonce ' . $timeoutNonce . ' does');
    $check(Gate::expectedSeconds(17) < 4.0, 'and 17 bits is estimated to take less than the inbox gives, so the solver starts and no estimate stops it first', sprintf('%.2f s', Gate::expectedSeconds(17)));
    $scriptedTo = $timeoutTo;
    $script = [[428, ['error' => 'This inbox requires proof of work.', 'fix' => 'Compute the work the gate asks for and send again.', 'gate' => ['require' => ['pow' => ['bits' => 17, 'covers' => 1]]], 'seconds_left' => 5], []]];
    $scriptedPosts = 0;
    try {
        $timeoutEntry = (new \ReflectionMethod(Runtime::class, 'outboxAdd'))->invoke($timeoutRuntime, $timeoutTo, Keys::fromSeedHex(str_repeat('a5', 32))->public, $timeoutMessage, ['text' => $timeoutMessage]);
        [$timeoutStatus, $timeoutAnswer] = (new \ReflectionMethod(Runtime::class, 'deliver'))->invoke($timeoutRuntime, $timeoutEntry);
    } catch (\Throwable $notStored) {
        $timeoutEntry = $timeoutEntry ?? ['id' => ''];
        [$timeoutStatus, $timeoutAnswer] = [null, ['error' => $notStored->getMessage()]];
    }
    $timeoutFix = (string) ($timeoutAnswer['fix'] ?? '');
    $timeoutStored = $timeoutRuntime->outbox[$timeoutEntry['id']] ?? null;
    $check($scriptedPosts === 1 && $timeoutStatus === 428, 'when the work for the second post runs out, one post went and its 428 is the answer', json_encode([$scriptedPosts, $timeoutStatus, $timeoutAnswer['error'] ?? null]));
    $check(str_starts_with($timeoutFix, 'The message went once and the inbox refused it with 428, and it was not sent again.') && str_contains($timeoutFix, 'was not done before the inbox stops taking writes') && !str_contains($timeoutFix, 'not started') && !str_contains(strtolower($timeoutFix), 'nothing'), 'its fix says it went once and the work ran out, and never that nothing was sent', $timeoutFix);
    $check(($timeoutStored['status'] ?? null) === 'refused' && Runtime::outboxOutcome($timeoutStored) === 'refused' && !in_array($timeoutEntry['id'], array_column($timeoutRuntime->outboxPending(), 'id'), true), 'and the outbox has it refused and off the pending list', json_encode($timeoutStored['status'] ?? null));
    $timeoutRuntime->close();
} finally {
    Http::$override = $storyHeld;
}

// ---------------------------------------------------------------- left in flight by a stopped process
[$s, $o, $to, $home] = $storyPair();
$s->outbox['m-left-in-flight'] = ['id' => 'm-left-in-flight', 'w' => $to, 'to_key' => $o->keys->public, 'envelope' => $s->keys->seal($o->keys->public, '{"text":"x"}'), 'summary' => [], 'created_at' => time(), 'attempts' => 1, 'status' => 'sending', 'last_status' => null, 'replaces' => null, 'shape' => []];
(new \ReflectionMethod(Runtime::class, 'saveOutbox'))->invoke($s);
$s->close();
$again = new Runtime($home, 'https://fake.test', ['s'], false, $storyQuiet);
$left = $again->outbox['m-left-in-flight'] ?? [];
$check(($left['status'] ?? null) === 'unknown' && ($left['ever_open'] ?? false) === true && Runtime::outboxOutcome($left) === 'unknown', 'a send left in flight by a stopped process is unknown and open, so no later stop settles it as never sent');
$again->close();
$o->close();
