<?php

declare(strict_types=1);

// Included by runtime.php, sharing its fake transport hook, its checks and its temporary homes.
//
// A read with a limit stops at the limit, and loses nothing by it.
//
// Found on 18 September 2026, in an outside assessment of aamio in use: with 60
// messages waiting, read(limit: 50) handed over 50, the cursor stood at 60, and
// the last ten never came back. read() polled every channel first, and poll()
// moves the cursor and saves it before the caller sees a message, so what read()
// then cut off the end was already behind the cursor. The note about it said
// the rest was in the archive, also with the archive turned off.
use Aamio\Channel;
use Aamio\Http;
use Aamio\Keys;
use Aamio\Runtime;

echo "a read with a limit\n";

/** One message as GET /{w} returns it, really signed; by null is an unsigned one. */
$held = static function (string $w, int $seq, string $body, ?Keys $by): array {
    return [
        'seq' => $seq, 'at' => $seq, 'type' => 'text', 'body' => $body, 'sha256' => hash('sha256', $body),
        'from' => $by?->public, 'sig' => $by?->sign(Keys::threadSigningInput($w, $body)), 'verified' => $by !== null, 'sealed' => false,
    ];
};
$numbered = static function (string $w, int $first, int $last) use ($held): array {
    $messages = [];
    for ($n = $first; $n <= $last; $n++) {
        $messages[] = $held($w, $n, 'message ' . $n, null);
    }

    return $messages;
};

/** A service that holds these threads and answers from the cursor it is asked with, as the real one does. */
$asked = [];
$serve = static function (array $threads) use (&$asked): \Closure {
    return static function (string $method, string $url) use ($threads, &$asked): array {
        if ($method !== 'GET' || preg_match('!/([a-z2-7]{20})(?:/after/(\d+))?!', $url, $found) !== 1 || !isset($threads[$found[1]])) {
            return [200, ['ok' => true]];
        }
        $after = (int) ($found[2] ?? 0);
        $asked[] = [$found[1], $after];
        $all = $threads[$found[1]];
        $answer = ['exists' => true, 'created_at' => 1000, 'messages' => array_values(array_filter($all, static fn (array $m): bool => $m['seq'] > $after))];
        // What the service does when the cursor is past everything it holds:
        // it reads from the start and says so.
        if ($all !== [] && $after > end($all)['seq']) {
            $answer['messages'] = $all;
            $answer['reset'] = ['after' => $after, 'newest' => end($all)['seq'], 'what' => 'after ' . $after . ' is past the last message'];
        }
        $answer['next'] = $answer['messages'] === [] ? $after : end($answer['messages'])['seq'];

        return [200, $answer];
    };
};

/** A runtime with no archive, whose only channels are the ones given. */
$limited = static function (string $name, array $channels) use ($root, $quiet): Runtime {
    $runtime = new Runtime($root . '/limit-' . $name, 'https://fake.test', null, false, $quiet);
    $runtime->channels = [];
    foreach ($channels as $label => [$w, $allow]) {
        $runtime->channels[$label] = new Channel($label, 'read-key', $w, time() + 3000, $allow);
    }

    return $runtime;
};
$seqs = static fn (array $entries): array => array_column($entries, 'seq');
$states = static fn (array $notes): array => array_column($notes, 'state');
$inboxW = str_repeat('i', 20);
$sideW = str_repeat('s', 20);

// Sixty waiting and a limit of fifty is two reads, not ten lost.
Http::$override = $serve([$inboxW => $numbered($inboxW, 1, 60)]);
$runtime = $limited('sixty', ['inbox' => [$inboxW, []]]);
$first = $runtime->read(0, 50);
$notes = $runtime->attentionTaken();
$check($seqs($first) === range(1, 50) && $runtime->channels['inbox']->after === 50, 'fifty of sixty are handed over, and the cursor stops where the handing over stopped, not at the service\'s next');
$check($states($notes) === ['more'] && str_contains($notes[0]['what'], 'limit of 50') && str_contains($notes[0]['what'], '10 more') && str_contains($notes[0]['what'], 'Nothing was passed over') && !str_contains($notes[0]['what'], 'archive'), 'and the read says that it stopped, without pointing at an archive that is turned off: ' . ($notes[0]['what'] ?? ''));
$runtime->close();
$runtime = new Runtime($root . '/limit-sixty', 'https://fake.test', null, false, $quiet);
$check(($runtime->channels['inbox']->after ?? null) === 50, 'the cursor that was saved is the one the caller was given, so a one-shot command continues from it');
$second = $runtime->read(0, 50);
$check($seqs($second) === range(51, 60) && $runtime->attentionTaken() === [] && $runtime->read(0, 50) === [], 'the ten the first read left are the second read, and then there is nothing more to say');
$runtime->close();

// A channel there was no room for is not asked, and keeps its messages.
$asked = [];
Http::$override = $serve([$inboxW => $numbered($inboxW, 1, 30), $sideW => $numbered($sideW, 1, 30)]);
$runtime = $limited('room', ['inbox' => [$inboxW, []], 'side' => [$sideW, []]]);
$first = $runtime->read(0, 30);
$notes = $runtime->attentionTaken();
$check(count($first) === 30 && array_unique(array_column($first, 'channel')) === ['inbox'] && array_column($asked, 0) === [$inboxW] && $runtime->channels['side']->after === 0, 'a channel the read had no room for is never asked, so its cursor cannot have moved');
$check($states($notes) === ['more'] && str_contains($notes[0]['what'], 'side'), 'and the note names the channel that was not asked');
$second = $runtime->read(0, 30);
$check(count($second) === 30 && array_unique(array_column($second, 'channel')) === ['side'], 'the next read is that channel\'s thirty');
$runtime->close();

// The room one channel leaves is what the next is asked for.
Http::$override = $serve([$inboxW => $numbered($inboxW, 1, 30), $sideW => $numbered($sideW, 1, 30)]);
$runtime = $limited('share', ['inbox' => [$inboxW, []], 'side' => [$sideW, []]]);
$first = $runtime->read(0, 50);
$check(count($first) === 50 && $runtime->channels['inbox']->after === 30 && $runtime->channels['side']->after === 20 && $seqs($runtime->read(0, 50)) === range(21, 30), 'thirty from one channel leave room for twenty from the next, and its last ten come with the next read');
$runtime->close();

// A message the list kept out does not count against the limit, and is not read twice.
$alice = Keys::fromSeed(str_repeat("\x01", 32));
$stranger = Keys::fromSeed(str_repeat("\x09", 32));
$mixed = [$held($inboxW, 1, 'one', $alice), $held($inboxW, 2, 'from a stranger', $stranger), $held($inboxW, 3, 'three', $alice), $held($inboxW, 4, 'four', $alice)];
Http::$override = $serve([$inboxW => $mixed]);
$runtime = $limited('kept', ['inbox' => [$inboxW, [$alice->public]]]);
$first = $runtime->read(0, 2);
$notes = [];
foreach ($runtime->attentionTaken() as $note) {
    $notes[$note['state']] = $note;
}
$check($seqs($first) === [1, 3] && $runtime->channels['inbox']->after === 3, 'the cursor goes past the one that was kept out, and not past the one that was never looked at');
$check(($notes['kept_out']['seqs'] ?? null) === [2] && str_contains($notes['more']['what'] ?? '', '1 more'), 'the kept-out message is said once, and the one left waiting is counted');
$second = $runtime->read(0, 2);
$check($seqs($second) === [4] && !in_array('kept_out', $states($runtime->attentionTaken()), true), 'the next read hands over the last one and does not meet the stranger again');
$runtime->close();

// A thread read from the start again stops at the limit too.
Http::$override = $serve([$inboxW => $numbered($inboxW, 1, 60)]);
$runtime = $limited('reset', ['inbox' => [$inboxW, []]]);
$runtime->channels['inbox']->after = 90;
$runtime->channels['inbox']->createdAt = 1000;
$first = $runtime->read(0, 50);
$check($seqs($first) === range(1, 50) && $runtime->channels['inbox']->after === 50 && $seqs($runtime->read(0, 50)) === range(51, 60), 'after a reset the cursor is neither the old 90 nor the service\'s next of 60, but the fifty that were handed over');
$runtime->close();

// poll without a limit reads everything, as before.
Http::$override = $serve([$inboxW => $numbered($inboxW, 1, 60)]);
$runtime = $limited('whole', ['inbox' => [$inboxW, []]]);
[$state, $entries] = $runtime->poll($runtime->channels['inbox']);
$check($state === 'ok' && count($entries) === 60 && $runtime->channels['inbox']->after === 60 && $runtime->channels['inbox']->leftWaiting === 0, 'a poll without a limit reads everything, as before');
$runtime->close();

Http::$override = $fake;
