<?php

declare(strict_types=1);

/*
 * Sound: the card, and the one rule that keeps every key out of the air. Pure,
 * with no network, no speaker and no microphone. The same checks as the card
 * tests in aamio-python's tests/test_sound_process.py.
 *
 * Included by the core's tests/run.php, with $check in scope.
 */

require_once dirname(__DIR__) . '/autoload.php';

use Aamio\Sound\Card;
use Aamio\Sound\NotUnderstood;
use Aamio\Sound\Process;
use Aamio\Sound\Refused;
use Aamio\Sound\Service;

echo "sound: the card\n";

(static function () use ($check): void {
    $address = 'k7rq2m4xw5tbz3yd6ena';
    $refusedFor = static function (callable $try): ?Refused {
        try {
            $try();
        } catch (Refused $error) {
            return $error;
        }

        return null;
    };
    $notUnderstood = static function (mixed $text): ?string {
        try {
            Card::read($text);
        } catch (NotUnderstood $why) {
            return $why->reason;
        }

        return null;
    };

    $now = 1794150000;
    $check(Card::make($address) === 'aamio:scope/' . $address && Card::read(Card::make($address)) === ['kind' => 'scope', 'address' => $address, 'name' => null, 'expires_at' => null], 'a card is the address, and reads back');
    $named = Card::make($address, 'rom-42');
    $check($named === 'aamio:scope/' . $address . '?n=rom-42' && Card::read($named) === ['kind' => 'scope', 'address' => $address, 'name' => 'rom-42', 'expires_at' => null], 'with a name to keep the scope under');
    $timed = Card::make($address, 'rom-42', $now + 3600);
    $check($timed === 'aamio:scope/' . $address . '?n=rom-42&t=' . ($now + 3600) && Card::read($timed)['expires_at'] === $now + 3600 && Card::read('aamio:scope/' . $address . '?t=' . $now . '&n=rom-42')['name'] === 'rom-42', 'and the time it stops being good, in either order');
    $check(strlen(Card::make($address, 'R' . str_repeat('x', 31), $now)) === 80 && Card::make($address, '') === Card::make($address), 'the longest card is 80 bytes of the 128 a sound carries, and an empty name is no name');
    $badTimes = 0;
    foreach ([-1, 12345678, 123456789012, '1794150000', true, 1.5] as $wrong) {
        $error = $refusedFor(static fn () => Card::make($address, null, $wrong));
        $badTimes += $error !== null && $error->reason === 'invalid_expiry' ? 1 : 0;
    }
    $check($badTimes === 6, 'a time that is not Unix seconds is refused');

    $cases = [
        ['abcdefghijklmnopqrstuvwxyz', 'a_key_not_an_address'],
        [str_repeat('a1', 32), 'a_key_not_an_address'],
        ['0123456789abcdefghijklmnop', 'a_key_not_an_address'],
        [substr($address, 0, 19), 'not_a_write_address'],
        [$address . 'a', 'not_a_write_address'],
        [strtoupper($address), 'not_a_write_address'],
        ['k7rq2m4xw5tbz3yd6en1', 'not_a_write_address'],
        ['k7rq2m4xw5tbz3yd6en8', 'not_a_write_address'],
        [$address . "\n", 'not_a_write_address'],
        [' ' . $address, 'not_a_write_address'],
        [null, 'not_a_write_address'],
        [12345, 'not_a_write_address'],
    ];
    $wrong = [];
    foreach ($cases as [$given, $reason]) {
        $error = $refusedFor(static fn () => Card::make($given));
        if ($error === null || $error->reason !== $reason || !str_contains($error->fix, 'aamio scope list')) {
            $wrong[] = json_encode($given);
        }
    }
    $check($wrong === [], 'only a write address of exactly 20 characters goes into a card: keys, other lengths, other letters and a trailing line break are refused' . ($wrong === [] ? '' : ': ' . implode(', ', $wrong)));

    $badNames = 0;
    foreach (['-rom', 'rom 42', str_repeat('r', 33), 'rom/42', 'røm', "rom\n"] as $name) {
        $error = $refusedFor(static fn () => Card::make($address, $name));
        $badNames += $error !== null && $error->reason === 'invalid_name' ? 1 : 0;
    }
    $check($badNames === 6, 'a name that is not a scope name is refused');

    $reads = [
        [null, 'not_an_aamio_card'],
        ['hello there', 'not_an_aamio_card'],
        ['aamio:inbox/' . $address, 'not_an_aamio_card'],
        ['aamio:scope/' . substr($address, 0, 19), 'not_a_write_address'],
        ['aamio:scope/abcdefghijklmnopqrstuvwxyz', 'not_a_write_address'],
        ['aamio:scope/' . $address . '?h=aamio.la', 'not_this_version'],
        ['aamio:scope/' . $address . '?n=a&h=aamio.la', 'not_this_version'],
        ['aamio:scope/' . $address . '?n=a&n=b', 'not_this_version'],
        ['aamio:scope/' . $address . '?n', 'not_this_version'],
        ['aamio:scope/' . $address . '?n=bad name', 'invalid_name'],
        ['aamio:scope/' . $address . '?n=', 'invalid_name'],
        ['aamio:scope/' . $address . '?t=soon', 'invalid_expiry'],
        ['aamio:scope/' . $address . '?t=12345678', 'invalid_expiry'],
        ['aamio:scope/' . $address . '?t=1&t=2', 'invalid_expiry'],
        ['aamio:scope/' . $address . '?t=1794150000&t=1794150000', 'not_this_version'],
    ];
    $misread = [];
    foreach ($reads as [$text, $reason]) {
        if ($notUnderstood($text) !== $reason) {
            $misread[] = json_encode($text);
        }
    }
    $check($misread === [], 'a card this version does not read is not read in part' . ($misread === [] ? '' : ': ' . implode(', ', $misread)));

    $wav = static function (float $seconds, int $rate = 48000): string {
        $data = str_repeat("\0\0", (int) ($rate * $seconds));

        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
    };
    $check(Service::isWav($wav(0.1)) && !Service::isWav('ID3 not a wav') && Process::secondsOf($wav(8.1)) === 8.1 && Process::secondsOf('nope') === null, 'a WAV is known by its header, and its length read from it');
})();
