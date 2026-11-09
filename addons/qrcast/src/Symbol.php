<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * QR codes made on this machine: byte mode, error correction level M, versions 1 to 10.
 *
 * ISO/IEC 18004. The longest card is 80 bytes, version 5 at level M, 37
 * modules wide; the largest version here, 10, holds 213 bytes. The quiet zone
 * is the four modules the standard asks for. Reed-Solomon over GF(256) with the
 * primitive polynomial 0x11d and first consecutive root 0. Of the eight masks,
 * the one with the lowest penalty by the four rules of the standard is used,
 * the first of them on a tie, the rules counted as ZXing counts them. Drawn as
 * PNG, one bit a pixel; SVG, the dark modules as one path; and text, two rows of
 * modules to a line, the light ones drawn for a terminal with a dark
 * background. The same as aamio-python's aamio_qrcast.symbol, module for module:
 * the fingerprints in the tests of both are the same.
 */
final class Symbol
{
    public const LEVEL = 'M';
    /** M is 00 in the format information: L 01, M 00, Q 11, H 10. */
    public const LEVEL_BITS = 0;
    public const MAX_VERSION = 10;
    public const QUIET = 4;
    /** Per version at level M: error correction codewords per block, and the blocks as [count, data codewords]. */
    public const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
    /** The centres of the alignment patterns, rows and columns alike. */
    public const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];
    public const PAD = [0xEC, 0x11];
    public const N1 = 3;
    public const N2 = 3;
    public const N3 = 40;
    public const N4 = 10;
    public const FINDER_LIKE = [true, false, true, true, true, false, true];
    public const TEXT_FULL = "\u{2588}";
    public const TEXT_UPPER = "\u{2580}";
    public const TEXT_LOWER = "\u{2584}";
    public const TEXT_EMPTY = ' ';

    /** @var list<int> */
    private static array $exp = [];
    /** @var list<int> */
    private static array $log = [];

    public readonly int $size;

    /** @param list<list<bool>> $modules row by row, true for dark */
    public function __construct(public readonly int $version, public readonly int $mask, public readonly array $modules)
    {
        $this->size = count($modules);
    }

    // ------------------------------------------------------------------ Reed-Solomon over GF(256)

    private static function tables(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return list<int> */
    private static function generator(int $nsym): array
    {
        self::tables();
        $g = [1];
        for ($i = 0; $i < $nsym; $i++) {
            $product = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $a) {
                $product[$j] ^= $a;
                $product[$j + 1] ^= self::mul($a, self::$exp[$i]);
            }
            $g = $product;
        }

        return $g;
    }

    /**
     * The nsym error correction codewords of a block.
     *
     * @param list<int> $message
     * @return list<int>
     */
    public static function parity(array $message, int $nsym): array
    {
        $g = self::generator($nsym);
        $out = array_merge($message, array_fill(0, $nsym, 0));
        $length = count($message);
        for ($i = 0; $i < $length; $i++) {
            $c = $out[$i];
            if ($c !== 0) {
                for ($j = 1, $n = count($g); $j < $n; $j++) {
                    $out[$i + $j] ^= self::mul($g[$j], $c);
                }
            }
        }

        return array_slice($out, $length);
    }

    // ------------------------------------------------------------------ data

    /** The data codewords of a version at level M. */
    public static function capacity(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS[$version][1] as [$count, $size]) {
            $total += $count * $size;
        }

        return $total;
    }

    private static function countBits(int $version): int
    {
        return $version < 10 ? 8 : 16;
    }

    public static function fits(int $length, int $version): bool
    {
        return 4 + self::countBits($version) + 8 * $length <= 8 * self::capacity($version);
    }

    public static function maxBytes(): int
    {
        return intdiv(8 * self::capacity(self::MAX_VERSION) - 4 - self::countBits(self::MAX_VERSION), 8);
    }

    public static function smallestVersion(int $length): int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            if (self::fits($length, $version)) {
                return $version;
            }
        }
        throw new TooLong($length . ' bytes is more than a version ' . self::MAX_VERSION . ' code at level M holds: ' . self::maxBytes() . ' bytes at most.');
    }

    /** @return list<int> mode, count, the bytes, the terminator and the padding */
    public static function dataCodewords(string $data, int $version): array
    {
        $room = 8 * self::capacity($version);
        $bits = [];
        $put = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $put(0b0100, 4);
        $put(strlen($data), self::countBits($version));
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $put(ord($data[$i]), 8);
        }
        $put(0, min(4, $room - count($bits)));
        $put(0, (8 - count($bits) % 8) % 8);
        $words = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $value = 0;
            for ($j = $i; $j < $i + 8; $j++) {
                $value = ($value << 1) | $bits[$j];
            }
            $words[] = $value;
        }
        $turn = 0;
        while (count($words) < self::capacity($version)) {
            $words[] = self::PAD[$turn % 2];
            $turn++;
        }

        return $words;
    }

    /** @return list<int> the data and error correction codewords, split into blocks and interleaved */
    public static function codewords(string $data, int $version): array
    {
        [$ec, $groups] = self::BLOCKS[$version];
        $words = self::dataCodewords($data, $version);
        $blocks = [];
        $at = 0;
        foreach ($groups as [$count, $size]) {
            for ($k = 0; $k < $count; $k++) {
                $blocks[] = array_slice($words, $at, $size);
                $at += $size;
            }
        }
        $checks = array_map(static fn (array $block): array => self::parity($block, $ec), $blocks);
        $out = [];
        $longest = max(array_map('count', $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($checks as $check) {
                $out[] = $check[$i];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ the matrix

    private static function formatBits(int $mask): int
    {
        $data = (self::LEVEL_BITS << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }

        return (($data << 10) | $rem) ^ 0x5412;
    }

    private static function versionBits(int $version): int
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }

        return ($version << 12) | $rem;
    }

    /**
     * @param list<list<bool>> $dark
     * @param list<list<bool>> $function
     */
    private static function put(array &$dark, array &$function, int $x, int $y, bool $on): void
    {
        $dark[$y][$x] = $on;
        $function[$y][$x] = true;
    }

    /**
     * @param list<list<bool>> $dark
     * @param list<list<bool>> $function
     */
    private static function patterns(array &$dark, array &$function, int $version): void
    {
        $size = 17 + 4 * $version;
        for ($i = 0; $i < $size; $i++) {
            self::put($dark, $function, 6, $i, $i % 2 === 0);
            self::put($dark, $function, $i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                        $ring = max(abs($dx), abs($dy));
                        self::put($dark, $function, $x, $y, $ring !== 2 && $ring !== 4);
                    }
                }
            }
        }
        $centres = self::ALIGNMENT[$version];
        $last = count($centres) - 1;
        foreach ($centres as $i => $cy) {
            foreach ($centres as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        self::put($dark, $function, $cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        self::format($dark, $function, $size, 0);
        if ($version >= 7) {
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                self::put($dark, $function, $a, $b, $bit);
                self::put($dark, $function, $b, $a, $bit);
            }
        }
    }

    /**
     * @param list<list<bool>> $dark
     * @param list<list<bool>> $function
     */
    private static function format(array &$dark, array &$function, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i < 6; $i++) {
            self::put($dark, $function, 8, $i, $bit($i));
        }
        self::put($dark, $function, 8, 7, $bit(6));
        self::put($dark, $function, 8, 8, $bit(7));
        self::put($dark, $function, 7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            self::put($dark, $function, 14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            self::put($dark, $function, $size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            self::put($dark, $function, 8, $size - 15 + $i, $bit($i));
        }
        self::put($dark, $function, 8, $size - 8, true);
    }

    /**
     * @param list<list<bool>> $dark
     * @param list<list<bool>> $function
     * @param list<int> $words
     */
    private static function place(array &$dark, array $function, int $size, array $words): void
    {
        $total = count($words) * 8;
        $i = 0;
        $right = $size - 1;
        while ($right >= 1) {
            if ($right === 6) {
                $right = 5;
            }
            $upward = (($right + 1) & 2) === 0;
            for ($vert = 0; $vert < $size; $vert++) {
                $y = $upward ? $size - 1 - $vert : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$function[$y][$x] && $i < $total) {
                        $dark[$y][$x] = (($words[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
            $right -= 2;
        }
    }

    private static function inverts(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /**
     * @param list<list<bool>> $dark
     * @param list<list<bool>> $function
     * @return list<list<bool>>
     */
    private static function masked(array $dark, array $function, int $size, int $mask): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$function[$y][$x] && self::inverts($mask, $x, $y)) {
                    $dark[$y][$x] = !$dark[$y][$x];
                }
            }
        }

        return $dark;
    }

    /** @param list<bool> $line rules 1 and 3 for one row or column */
    private static function linePenalty(array $line): int
    {
        $score = 0;
        $size = count($line);
        $run = 1;
        for ($i = 1; $i <= $size; $i++) {
            if ($i < $size && $line[$i] === $line[$i - 1]) {
                $run++;
                continue;
            }
            if ($run >= 5) {
                $score += self::N1 + $run - 5;
            }
            $run = 1;
        }
        for ($i = 0; $i < $size - 6; $i++) {
            if (array_slice($line, $i, 7) === self::FINDER_LIKE) {
                $before = true;
                for ($k = max(0, $i - 4); $k < $i; $k++) {
                    $before = $before && !$line[$k];
                }
                $after = true;
                for ($k = $i + 7, $end = min($size, $i + 11); $k < $end; $k++) {
                    $after = $after && !$line[$k];
                }
                if ($before || $after) {
                    $score += self::N3;
                }
            }
        }

        return $score;
    }

    /** @param list<list<bool>> $dark */
    public static function penalty(array $dark): int
    {
        $size = count($dark);
        $score = 0;
        for ($y = 0; $y < $size; $y++) {
            $score += self::linePenalty($dark[$y]);
        }
        for ($x = 0; $x < $size; $x++) {
            $score += self::linePenalty(array_column($dark, $x));
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $here = $dark[$y][$x];
                if ($here === $dark[$y][$x + 1] && $here === $dark[$y + 1][$x] && $here === $dark[$y + 1][$x + 1]) {
                    $score += self::N2;
                }
            }
        }
        $count = 0;
        foreach ($dark as $row) {
            foreach ($row as $module) {
                $count += $module ? 1 : 0;
            }
        }
        $total = $size * $size;

        return $score + intdiv(abs($count * 2 - $total) * 10, $total) * self::N4;
    }

    /** The QR code for these bytes, in the smallest version unless one is given, with the best mask unless one is given. */
    public static function encode(string $data, ?int $version = null, ?int $mask = null): self
    {
        if ($version === null) {
            $version = self::smallestVersion(strlen($data));
        } elseif ($version < 1 || $version > self::MAX_VERSION || !self::fits(strlen($data), $version)) {
            throw new TooLong(strlen($data) . ' bytes do not fit a version ' . $version . ' code at level M.');
        }
        $size = 17 + 4 * $version;
        $dark = array_fill(0, $size, array_fill(0, $size, false));
        $function = $dark;
        self::patterns($dark, $function, $version);
        self::place($dark, $function, $size, self::codewords($data, $version));
        if ($mask === null) {
            $best = null;
            for ($candidate = 0; $candidate < 8; $candidate++) {
                self::format($dark, $function, $size, $candidate);
                $score = self::penalty(self::masked($dark, $function, $size, $candidate));
                if ($best === null || $score < $best[0]) {
                    $best = [$score, $candidate];
                }
            }
            $mask = $best[1];
        }
        self::format($dark, $function, $size, $mask);

        return new self($version, $mask, self::masked($dark, $function, $size, $mask));
    }

    /** @return list<list<bool>> the modules with a light border of $border modules around */
    public function rows(int $border = self::QUIET): array
    {
        $width = $this->size + 2 * $border;
        $light = array_fill(0, $width, false);
        $out = array_fill(0, $border, $light);
        $edge = array_fill(0, $border, false);
        foreach ($this->modules as $row) {
            $out[] = array_merge($edge, $row, $edge);
        }

        return array_merge($out, array_fill(0, $border, $light));
    }

    // ------------------------------------------------------------------ drawing

    private static function chunk(string $kind, string $data): string
    {
        return pack('N', strlen($data)) . $kind . $data . pack('N', crc32($kind . $data));
    }

    /** The PNG rows before compression: a filter byte of 0, then one bit a pixel, 0 for dark. */
    public static function scanlines(self $symbol, int $scale, int $border = self::QUIET): string
    {
        $raw = '';
        foreach ($symbol->rows($border) as $row) {
            $line = '';
            $acc = 0;
            $filled = 0;
            foreach ($row as $dark) {
                for ($k = 0; $k < $scale; $k++) {
                    $acc = ($acc << 1) | ($dark ? 0 : 1);
                    $filled++;
                    if ($filled === 8) {
                        $line .= chr($acc);
                        $acc = 0;
                        $filled = 0;
                    }
                }
            }
            if ($filled > 0) {
                $line .= chr(($acc << (8 - $filled)) & 0xFF);
            }
            $raw .= str_repeat("\x00" . $line, $scale);
        }

        return $raw;
    }

    public static function png(self $symbol, int $scale = 10, int $border = self::QUIET): string
    {
        $side = ($symbol->size + 2 * $border) * $scale;
        $header = pack('NNCCCCC', $side, $side, 1, 0, 0, 0, 0);
        $compressed = gzcompress(self::scanlines($symbol, $scale, $border), 9);

        return "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $header) . self::chunk('IDAT', (string) $compressed) . self::chunk('IEND', '');
    }

    public static function svg(self $symbol, int $border = self::QUIET): string
    {
        $rows = $symbol->rows($border);
        $side = count($rows);
        $path = '';
        foreach ($rows as $y => $row) {
            $x = 0;
            while ($x < $side) {
                if ($row[$x]) {
                    $start = $x;
                    while ($x < $side && $row[$x]) {
                        $x++;
                    }
                    $path .= sprintf('M%d %dh%dv1h-%dz', $start, $y, $x - $start, $x - $start);
                } else {
                    $x++;
                }
            }
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" height="100%%" shape-rendering="crispEdges">'
            . '<rect width="%d" height="%d" fill="#fff"/><path fill="#000" d="%s"/></svg>' . "\n", $side, $side, $side, $side, $path);
    }

    public static function text(self $symbol, int $border = self::QUIET): string
    {
        $rows = $symbol->rows($border);
        $side = count($rows);
        $lines = [];
        for ($y = 0; $y < $side; $y += 2) {
            $top = $rows[$y];
            $bottom = $rows[$y + 1] ?? null;
            $line = '';
            for ($x = 0; $x < $side; $x++) {
                $upper = !$top[$x];
                $lower = $bottom !== null && !$bottom[$x];
                $line .= $upper && $lower ? self::TEXT_FULL : ($upper ? self::TEXT_UPPER : ($lower ? self::TEXT_LOWER : self::TEXT_EMPTY));
            }
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n";
    }

    /** The modules as one line of 0 and 1, row by row, after the version and the mask: the same in both runtimes. */
    public static function fingerprint(self $symbol): string
    {
        $bits = '';
        foreach ($symbol->modules as $row) {
            foreach ($row as $module) {
                $bits .= $module ? '1' : '0';
            }
        }

        return $symbol->version . ':' . $symbol->mask . ':' . $bits;
    }
}
