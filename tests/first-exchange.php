<?php

declare(strict_types=1);

/**
 * First exchange, on the wire: two runtimes, two homes, one aamio.
 *
 * The PHP counterpart of aamio-python's tests/test_first_exchange.py covers
 * item 5 of the round-2 acceptance list. A partner is registered from a key the
 * user handed over out of band, and the first message goes both ways, sealed,
 * signed and verified on arrival, with one receipt per side compared with what
 * that side saw. Three scenarios, because the order of the steps decides what
 * the runtime has to do:
 *
 *   registration before the inbox opens    the inbox opens with its allowlist
 *   registration after the inbox is open   the inbox is replaced at once and presence points to the new one
 *   a handoff ends in a first send         an address handed over in a verified message can be written to
 *
 *   php tests/first-exchange.php [https://aamio.at]
 *
 * It writes to the service: about ten threads, one presence record per
 * runtime and a few messages, and it closes the threads at the end. The
 * service takes thirty opens and closes of threads a minute from one client,
 * so each of those waits its turn, and a run started right after another one
 * waits for the room it needs. It is a smoke test a person starts before a
 * release, not a gate: a service that is down is not a failed release.
 *
 * This runtime has no listener, so a read spends its wait on the first
 * channel. Where mail is expected on a private thread the test reads with no
 * wait first, which is what the README tells a user to do.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/live-pace.inc.php';

use Aamio\Client;
use Aamio\Runtime;
use Aamio\SendFailed;

ini_set('display_errors', 'stderr');

$host = $argv[1] ?? Client::DEFAULT_HOST;
// Every open and close of a thread waits its turn: live-pace.inc.php says why.
LivePace::install($host);
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . (!$ok && $detail !== '' ? '  [' . $detail . ']' : '') . "\n";
};

const WAIT = 20;

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-php-first-exchange-' . getmypid();
$made = [];
$make = static function (string $scenario, string $name) use ($base, $host, &$made): Runtime {
    $home = $base . DIRECTORY_SEPARATOR . $scenario . DIRECTORY_SEPARATOR . $name;
    mkdir($home, 0700, true);
    $runtime = new Runtime($home, $host, ['test.smoke.' . $name], false, static function (string $line): void {
    });
    $made[] = $runtime;

    return $runtime;
};

/** The inbox as the service holds it: the address and the allowlist it really enforces. */
$wire = static function (Runtime $runtime): array {
    $inbox = $runtime->channels['inbox'];
    $read = $runtime->client->read($inbox->w, $inbox->readKey);

    return [$inbox, $read['status'] === 200 ? (array) $read['body'] : ['status' => $read['status']]];
};

/** What the receiver must be able to say about one message, and not the service on its behalf. */
$isMail = static function (array $entry, Runtime $sender, string $name, string $token): bool {
    return ($entry['from_key'] ?? null) === $sender->keys->public
        && ($entry['verified'] ?? null) === true && ($entry['encrypted'] ?? null) === true && ($entry['replay'] ?? null) === false
        && ($entry['known_contact'] ?? null) === true && ($entry['sender'] ?? null) === $name
        && str_contains((string) ($entry['body']['text'] ?? ''), $token) && ($entry['body']['data']['corr'] ?? null) === $token;
};

/** The receipt adds up, matches what this process saw, and names the other side as its signer. */
$isReceipt = static function (array $receipt, string $other, int $count = 1): bool {
    return ($receipt['count'] ?? null) === $count && ($receipt['root_adds_up'] ?? null) === true && ($receipt['local_root_matches'] ?? null) === true
        && ($receipt['keys'] ?? null) === [$other] && ($receipt['keys_unverified_count'] ?? null) === 0;
};

$online = static function (Runtime $runtime, string $name): ?string {
    $seen = $runtime->lookup([$name]);
    $names = array_column($seen['online'] ?? [], 'w', 'name');

    return $names[$name] ?? null;
};

/** One message each way by partner name, then a receipt on each side. */
$exchange = static function (Runtime $alice, Runtime $bob) use ($check, $isMail, $isReceipt): void {
    $first = bin2hex(random_bytes(6));
    $sent = $alice->send('bob', 'first message ' . $first, ['corr' => $first]);
    $got = $bob->read(WAIT);
    $check(is_string($sent['message_id'] ?? null) && is_string($sent['sha256'] ?? null) && count($got) === 1 && $isMail($got[0], $alice, 'alice', $first), 'alice sends by name, and bob reads it sealed, signed, verified here and from a partner', json_encode($got));
    $second = bin2hex(random_bytes(6));
    $reply = $bob->send('alice', 'second message ' . $second, ['corr' => $second]);
    $back = $alice->read(WAIT);
    $check(is_string($reply['sha256'] ?? null) && count($back) === 1 && $isMail($back[0], $bob, 'bob', $second), 'and the other way', json_encode($back));
    $check($isReceipt($alice->receipt('inbox'), 'bob') && $isReceipt($bob->receipt('inbox'), 'alice'), 'each side\'s receipt counts one message, adds up, matches what that side read and names the other as its signer');
};

try {
    echo "registration before the inbox opens\n";
    $alice = $make('before', 'alice');
    $bob = $make('before', 'bob');
    $carol = $make('before', 'carol');
    // The keys change hands out of band. Nothing here reads a key from a message.
    $alice->partnerAdd('bob', $bob->keys->public);
    $bob->partnerAdd('alice', $alice->keys->public);
    $check(!isset($alice->channels['inbox']) && !isset($bob->channels['inbox']), 'no inbox is open yet, so there is nothing to replace');
    $alice->ensureInbox();
    $bob->ensureInbox();
    [, $aliceWire] = $wire($alice);
    [$bobInbox, $bobWire] = $wire($bob);
    $check(($aliceWire['allow'] ?? null) === [$bob->keys->public] && ($bobWire['allow'] ?? null) === [$alice->keys->public], 'each inbox opens naming the other, as the service holds it', json_encode([$aliceWire['allow'] ?? $aliceWire, $bobWire['allow'] ?? $bobWire]));
    $check($online($alice, 'bob') === $bob->channels['inbox']->w && $online($bob, 'alice') === $alice->channels['inbox']->w, 'and presence points to it, both ways');
    // A writer nobody registered is refused at the wire, and is told so.
    $carol->peers[$bobInbox->w] = $bob->keys->public;
    $refused = null;
    try {
        $carol->send($bobInbox->w, 'uninvited');
    } catch (SendFailed $error) {
        $refused = $error;
    }
    $check($refused !== null && $refused->outcome === 'refused' && $refused->status === 403, 'a key nobody registered is refused with 403', $refused === null ? 'it was let in' : $refused->getMessage());
    // The refused write left nothing in the thread: the receipts count one message each.
    $exchange($alice, $bob);

    echo "registration after the inbox is open\n";
    $alice = $make('after', 'alice');
    $bob = $make('after', 'bob');
    $alice->ensureInbox();
    $bob->ensureInbox();
    foreach ([[$alice, $bob, 'bob'], [$bob, $alice, 'alice']] as [$runtime, $other, $name]) {
        [$open, $held] = $wire($runtime);
        $first = $open->w;
        $answer = $runtime->partnerAdd($name, $other->keys->public);
        // What the service enforces first, and what the answer says about it after.
        [$fresh, $now] = $wire($runtime);
        $check(($held['allow'] ?? null) === [] && ($now['allow'] ?? null) === [$other->keys->public] && $fresh->w !== $first, 'the open inbox is replaced at once by one that names ' . $name . ', as the service holds it', json_encode([$held['allow'] ?? $held, $now['allow'] ?? $now]));
        $check(($answer['rotated'] ?? null) === true && ($answer['presence'] ?? null) === true && ($answer['inbox'] ?? null) === $fresh->w && ($answer['allow'] ?? null) === [$name]
            && ($answer['old_inbox']['w'] ?? null) === $first && ($answer['old_inbox']['muted'] ?? null) === false, 'and the answer says so: the new address, presence published, the old inbox still read', json_encode($answer));
    }
    $check($online($alice, 'bob') === $bob->channels['inbox']->w && $online($bob, 'alice') === $alice->channels['inbox']->w, 'presence points to the new inboxes');
    $exchange($alice, $bob);

    echo "a handoff ends in a first send\n";
    $alice = $make('handoff', 'alice');
    $bob = $make('handoff', 'bob');
    $alice->partnerAdd('bob', $bob->keys->public);
    $bob->partnerAdd('alice', $alice->keys->public);
    $alice->ensureInbox();
    $bob->ensureInbox();
    // The invitation is sent only when an address to send it to is given, and bob's inbox is that address.
    $bobInbox = (string) $online($alice, 'bob');
    $channel = $alice->openChannelWith('bob', 120, null, $bobInbox, 'moving here');
    $check($alice->channels[$channel['label']]->allow === [$bob->keys->public] && ($channel['address_sent_to'] ?? null) === $bobInbox, 'a thread only bob may write to is opened, and its address sent to his inbox');
    $handed = array_values(array_filter($bob->read(WAIT), static fn (array $e): bool => is_array($e['body'] ?? null) && isset($e['body']['channel'])));
    $invitation = $handed === [] ? [] : $handed[count($handed) - 1];
    $check(($invitation['body']['channel'] ?? null) === $channel['w'] && ($invitation['verified'] ?? null) === true && ($invitation['encrypted'] ?? null) === true && ($invitation['from_key'] ?? null) === $alice->keys->public, 'bob reads the invitation: sealed, signed by alice, with channel in its body', json_encode($handed));
    // The first ordinary send to the address handed over: no lookup, no partner name.
    $token = bin2hex(random_bytes(6));
    $sent = $bob->send($channel['w'], 'on the private thread ' . $token, ['corr' => $token]);
    // No wait: the wait would be spent on alice's quiet inbox before the private thread was asked.
    $private = array_values(array_filter($alice->read(0), static fn (array $e): bool => $e['channel'] === $channel['label']));
    $check(is_string($sent['sha256'] ?? null) && count($private) === 1 && $isMail($private[0], $bob, 'bob', $token), 'his first send to that address arrives on the private thread', json_encode($private));
    $check($isReceipt($alice->receipt($channel['label']), 'bob'), 'and the receipt of the private thread names bob');
    // The other direction is a thread of its own: alice writes to bob's inbox, and bob is the one who reads it.
    $back = bin2hex(random_bytes(6));
    $alice->send('bob', 'and back ' . $back, ['corr' => $back]);
    $answered = array_values(array_filter($bob->read(WAIT), static fn (array $e): bool => $e['channel'] === 'inbox'));
    $check(count($answered) === 1 && $isMail($answered[0], $alice, 'alice', $back), 'the other direction is bob\'s own inbox', json_encode($answered));
} catch (\Throwable $error) {
    $check(false, 'the run went through', get_class($error) . ': ' . $error->getMessage());
} finally {
    foreach ($made as $runtime) {
        foreach ($runtime->channels as $held) {
            try {
                $runtime->client->close($held->w, $held->readKey);
            } catch (\Throwable) {
            }
        }
        try {
            $runtime->close();
        } catch (\Throwable) {
        }
    }
    exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /S /Q "' . $base . '"' : 'rm -rf ' . escapeshellarg($base));
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
