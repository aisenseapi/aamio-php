<?php

declare(strict_types=1);

/*
 * The QR code the qrcast add-on makes on this machine, and its card: the modules,
 * the mask, the three drawings, and the card against the earshot add-on's.
 * Offline. The fingerprints are aamio-python's too (tests/test_qrcast_symbol.py
 * there), so a code made by one runtime is the code made by the other, module
 * for module.
 *
 * Included by the core's tests/run.php, with $check in scope.
 */

require_once dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__, 2) . '/earshot/autoload.php';

use Aamio\Qrcast\Card;
use Aamio\Qrcast\NotUnderstood;
use Aamio\Qrcast\Process;
use Aamio\Qrcast\Refused;
use Aamio\Qrcast\Symbol;
use Aamio\Qrcast\TooLong;

echo "qrcast: the code and the card\n";

(static function () use ($check): void {
    $address = 'k7rq2m4xw5tbz3yd6ena';
    $longest = 'aamio:scope/' . $address . '?n=stue-tv-' . str_repeat('a', 24) . '&t=1794150000';
    $fingerprints = [
        [$longest, 5, 4, 'c80e34254889d0abbbb0e2d68760fec352fd2e458a990790c1f43eea99ffd510'],
        ['aamio:scope/' . $address, 3, 3, '20f3fbe17a0696a692b92463bce836d06c5d71912232db90355b26103a6f055d'],
        ['aamio:scope/' . $address . '?n=stue-tv&t=1794150000', 4, 2, '27deb2321902abd642a7730bad86121fc1f1106985e126c834b9a74d580e35d6'],
        ['aamio:' . str_repeat('z', 207), 10, 1, '2329f3f5446585d68029889d04fcffe6e27d7235d8dcae222eb9a4ffef00cd68'],
    ];
    foreach ($fingerprints as [$text, $version, $mask, $fingerprint]) {
        $code = Symbol::encode($text);
        $check($code->version === $version && $code->mask === $mask && hash('sha256', Symbol::fingerprint($code)) === $fingerprint, 'the code for ' . strlen($text) . ' bytes is the one aamio-python makes, version ' . $version . ' with mask ' . $mask);
    }
    $code = Symbol::encode($longest);
    $scale = max(4, intdiv(Process::PICTURE_PIXELS, $code->size + 2 * Symbol::QUIET));
    $check($scale === 22 && hash('sha256', Symbol::scanlines($code, 22)) === '6f4d72248b97fcaa2d6699d0470fec1e1f6610ecc99316ff7a6e2fc60e0279d2' && hash('sha256', Symbol::scanlines($code, 10)) === '6bdbdf892f2108ce4c528108681086e42467fadd49dd6cae9aa1c6782f90d1b0', 'the PNG rows are aamio-python\'s, pixel for pixel');
    $check(hash('sha256', Symbol::svg($code)) === 'f409c9bbe5290eb91ff020223a61d6a3a92c4ade36f0279be3955efc000fe017' && hash('sha256', Symbol::text($code)) === 'eb18d63eff382e15e4aea76c62add5698af8a2cb29c70bd6496ed20fecec88bb', 'and so are the SVG and the text');
    $check(Symbol::dataCodewords('a', 1) === array_merge([64, 22, 16], array_merge(...array_fill(0, 6, [236, 17])), [236]) && Symbol::parity(array_merge([64, 22, 16], array_merge(...array_fill(0, 6, [236, 17])), [236]), 10) === [252, 160, 39, 162, 98, 107, 50, 59, 111, 28], 'one byte is the codewords of the standard, with its Reed-Solomon');
    $check(hash('sha256', Symbol::fingerprint(Symbol::encode('a', 1, 0))) === '850bb7579ec61475a94a68e7c87801f2b16c24b0cd4f5e9c8635a749bb3e4b7c', 'and the code for it with mask 0 is the same as there');
    $versions = array_map(static fn (int $n): int => Symbol::smallestVersion($n), [1, 14, 15, 26, 27, 42, 43, 62, 63, 84, 85]);
    $check($versions === [1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6] && Symbol::maxBytes() === 213 && Symbol::encode(str_repeat('x', 213))->version === 10, 'the smallest version is taken, and 213 bytes is the most');
    $error = null;
    try {
        Symbol::encode(str_repeat('x', 214));
    } catch (TooLong $caught) {
        $error = $caught;
    }
    $check($error !== null && str_contains($error->getMessage(), '213 bytes at most'), 'and one byte more is too long, with the limit said');
    $scores = [];
    for ($mask = 0; $mask < 8; $mask++) {
        $scores[] = Symbol::penalty(Symbol::encode($longest, 5, $mask)->modules);
    }
    $check(array_search(min($scores), $scores, true) === $code->mask, 'the mask chosen has the lowest penalty, the first on a tie');
    $rows = $code->modules;
    $finder = static function (int $x0, int $y0) use ($rows): array {
        $out = [];
        for ($y = 0; $y < 7; $y++) {
            $line = '';
            for ($x = 0; $x < 7; $x++) {
                $line .= $rows[$y0 + $y][$x0 + $x] ? '1' : '0';
            }
            $out[] = $line;
        }

        return $out;
    };
    $pattern = ['1111111', '1000001', '1011101', '1011101', '1011101', '1000001', '1111111'];
    $check($finder(0, 0) === $pattern && $finder(30, 0) === $pattern && $finder(0, 30) === $pattern && $rows[29][8] === true, 'the finder patterns and the dark module are where the standard puts them');

    // The PNG: one bit a pixel, ten by ten pixels a module, four modules of white around.
    $png = Symbol::png($code, 10);
    $at = 8;
    $chunks = [];
    $crcs = true;
    while ($at < strlen($png)) {
        $length = unpack('N', substr($png, $at, 4))[1];
        $kind = substr($png, $at + 4, 4);
        $body = substr($png, $at + 8, $length);
        $crcs = $crcs && unpack('N', substr($png, $at + 8 + $length, 4))[1] === crc32($kind . $body);
        $chunks[$kind] = $body;
        $at += 12 + $length;
    }
    $header = unpack('Nwidth/Nheight/Cdepth/Ccolour', $chunks['IHDR']);
    $raw = gzuncompress($chunks['IDAT']);
    $stride = 1 + intdiv(450 + 7, 8);
    $sampled = [];
    for ($y = 0; $y < 45; $y++) {
        $line = [];
        for ($x = 0; $x < 45; $x++) {
            $px = $x * 10 + 5;
            $byte = ord($raw[($y * 10 + 5) * $stride + 1 + intdiv($px, 8)]);
            $line[] = (($byte >> (7 - $px % 8)) & 1) === 0;
        }
        $sampled[] = $line;
    }
    $check(substr($png, 0, 8) === "\x89PNG\r\n\x1a\n" && $crcs && $header['width'] === 450 && $header['height'] === 450 && $header['depth'] === 1 && $header['colour'] === 0 && $sampled === $code->rows(), 'the PNG is one bit a pixel with the quiet zone, and every module is where it belongs');
    $back = array_fill(0, 45, array_fill(0, 45, false));
    preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\d+z/', Symbol::svg($code), $runs, PREG_SET_ORDER);
    foreach ($runs as [, $x, $y, $run]) {
        for ($i = 0; $i < (int) $run; $i++) {
            $back[(int) $y][(int) $x + $i] = true;
        }
    }
    $lines = explode("\n", rtrim(Symbol::text($code), "\n"));
    $fromText = [];
    foreach ($lines as $line) {
        $top = $bottom = [];
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $top[] = $ch !== Symbol::TEXT_FULL && $ch !== Symbol::TEXT_UPPER;
            $bottom[] = $ch !== Symbol::TEXT_FULL && $ch !== Symbol::TEXT_LOWER;
        }
        $fromText[] = $top;
        $fromText[] = $bottom;
    }
    $check($back === $code->rows() && array_slice($fromText, 0, 45) === $code->rows() && count($lines) === 23, 'the SVG and the text give the modules back');

    // The card: the earshot add-on's, outcome for outcome.
    $same = true;
    foreach (['k7rq2m4xw5tbz3yd6ena', 'm2n3p4q5r6s7t2u3v4w5', 'abcdefghijklmnopqrstuvwxyz', str_repeat('a', 64), str_repeat('A', 20), 'k7rq2m4xw5tbz3yd6en1', '', null, 7] as $one) {
        foreach ([null, '', 'stue-tv', 'R' . str_repeat('x', 31), 'R' . str_repeat('x', 32), '-bad'] as $name) {
            foreach ([null, 1794150000, 12345678, true] as $expires) {
                $outcome = [];
                foreach ([[Card::class, Refused::class], [\Aamio\Earshot\Card::class, \Aamio\Earshot\Refused::class]] as [$class, $refusal]) {
                    try {
                        $outcome[] = $class::make($one, $name, $expires);
                    } catch (\Throwable $error) {
                        $outcome[] = $error instanceof $refusal ? $error->reason : get_class($error);
                    }
                }
                $same = $same && $outcome[0] === $outcome[1];
            }
        }
    }
    foreach (['aamio:scope/' . $address . '?n=stue-tv&t=1794153600', 'aamio:scope/' . $address, 'aamio:scope/' . $address . '?n=a&n=b', 'aamio:scope/' . $address . '?x=1', 'aamio:scope/' . $address . '?t=12', 'aamio:scope/abcdefghijklmnopqrstuvwxyz', 'https://example.org', '', null] as $text) {
        $outcome = [];
        foreach ([[Card::class, NotUnderstood::class], [\Aamio\Earshot\Card::class, \Aamio\Earshot\NotUnderstood::class]] as [$class, $why]) {
            try {
                $outcome[] = $class::read($text);
            } catch (\Throwable $error) {
                $outcome[] = $error instanceof $why ? $error->reason : get_class($error);
            }
        }
        $same = $same && $outcome[0] === $outcome[1];
    }
    $check($same, 'the card is the earshot add-on\'s, made and read the same way');
    $refusedKey = null;
    try {
        Card::make('abcdefghijklmnopqrstuvwxyz');
    } catch (Refused $error) {
        $refusedKey = $error;
    }
    $check($refusedKey !== null && $refusedKey->reason === 'a_key_not_an_address' && str_contains($refusedKey->fix, 'never shown'), 'a key is never shown');
})();
