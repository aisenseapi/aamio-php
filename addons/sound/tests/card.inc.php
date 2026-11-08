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

use Aamio\Sound\BadRecording;
use Aamio\Sound\Card;
use Aamio\Sound\Codec;
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

echo "sound: the codec\n";

(static function () use ($check): void {
    $card = 'aamio:scope/k7rq2m4xw5tbz3yd6ena?n=rom-42&t=1794200000';
    // sha256 of Codec::encode('Hello', mode): what aisenseapi.com made for "Hello" on 8 November 2026, byte for byte, and what aamio-python's codec makes.
    $hello = [
        'audible' => 'e3f6cabcf2fc98de4efdc5b69e3e2914fd061537a15f8ad3d651aa18e42ae4b3',
        'high' => '27678826b7765fdf9ce2be8a8b48a2209c9ea5453c5bda0c8ebce47e85aeac2b',
        'ultrasound' => '487cf8493ed3e6a72daadae4d4c74ff6ba97a8768f938f22ff745220c6f9e2ac',
    ];
    $same = [];
    foreach ($hello as $mode => $sha) {
        $same[] = hash('sha256', Codec::encode('Hello', $mode)) === $sha;
    }
    $check($same === [true, true, true], 'the encoder makes what aisense made, byte for byte, in all three ranges, as aamio-python does');
    $check(bin2hex(Codec::packetBytes('Hello')) === '41530305a3465a4fab9008d748656c6c6fa49f027eea265836471d0fa69817b9f91be0dfbf' && Codec::bodyParity(1) === 16 && Codec::bodyParity(128) === 32, 'the packet is the header, the payload, the CRC and their parity');
    $round = [];
    foreach (Codec::MODES as $mode) {
        $made = Codec::encode($card, $mode);
        $got = Codec::decode($made, 'auto');
        $round[] = ($got['data'] ?? null) === $card && ($got['mode'] ?? null) === $mode && ($got['corrected_bits'] ?? null) === 0;
    }
    $check($round === [true, true, true], 'a card reads back in each range, and auto finds the range');
    $message = implode('', array_map('chr', range(0, 39)));
    $code = Codec::rsEncode($message, 16);
    foreach ([0, 5, 17, 33, 44, 55, 3, 9] as $position) {
        $code[$position] = chr(ord($code[$position]) ^ 0x5A);
    }
    $eight = Codec::rsDecode($code, 16) === $message;
    $code[20] = chr(ord($code[20]) ^ 1);
    $check($eight && Codec::rsDecode($code, 16) === null && Codec::rsDecode($code, 16, [0, 5, 17, 33, 44, 55, 3, 9, 20]) === $message, 'Reed-Solomon mends eight errors, not nine, and nine with their places known');
    $silenced = static function (int $first, int $count) use ($card): string {
        $made = Codec::encode($card, 'audible');
        $cut = 44 + 2 * (int) (0.25 * 48000) + 2 * 960 * $first;

        return substr_replace($made, str_repeat("\0\0", 960 * $count), $cut, 2 * 960 * $count);
    };
    $mended = Codec::decode($silenced(112, 40), 'audible');
    $check(($mended['data'] ?? null) === $card && ($mended['corrected_bits'] ?? 0) > 0 && Codec::decode($silenced(112, 200), 'audible') === null, 'a dropout of ten bytes is mended and said, and one of fifty lets nothing half through');
    $check(Codec::decode(Codec::wav(str_repeat("\0\0", 48000 * 3)), 'auto') === null, 'silence holds no card');
    $turned = [];
    foreach ([
        [str_repeat('ID3 not a wav at all, though long enough to be one', 2), 'uncompressed PCM16'],
        [Codec::wav(str_repeat("\0", 100), 22050, 1), '44100 or 48000'],
        [Codec::wav(str_repeat("\0\0", 41 * 48000)), 'at most 40 seconds'],
        [substr(Codec::wav(str_repeat("\0\0", 480)), 0, -2), 'does not match its RIFF header'],
    ] as [$data, $words]) {
        try {
            Codec::decode($data, 'auto');
            $turned[] = false;
        } catch (BadRecording $error) {
            $turned[] = str_contains($error->getMessage(), $words);
        }
    }
    $check($turned === [true, true, true, true], 'a file the codec does not read is turned down in aisense\'s words');
    // A room: the card quieter and late, an echo, noise, from a fixed seed.
    $clean = array_values(unpack('s*', substr(Codec::encode($card, 'ultrasound'), 44)));
    $total = 12 * 48000;
    $start = (int) (3.37 * 48000);
    $signal = array_fill(0, $total, 0.0);
    foreach ($clean as $i => $v) {
        if ($start + $i < $total) {
            $signal[$start + $i] += 0.35 * $v;
        }
    }
    $delay = (int) (0.031 * 48000);
    for ($i = $total - 1; $i >= $delay; $i--) {
        $signal[$i] += 0.4 * $signal[$i - $delay];
    }
    mt_srand(8);
    $pcm = '';
    foreach ($signal as $x) {
        $noise = (mt_rand() / mt_getrandmax() - 0.5) * 3000;
        $pcm .= pack('v', max(-32768, min(32767, (int) ($x + $noise))) & 65535);
    }
    $got = Codec::decode(Codec::wav($pcm), 'auto');
    $check(($got['data'] ?? null) === $card && ($got['mode'] ?? null) === 'ultrasound', 'and a card in a noisy room with an echo is heard, in its own range');
})();

