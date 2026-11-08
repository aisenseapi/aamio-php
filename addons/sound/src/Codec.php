<?php

declare(strict_types=1);

namespace Aamio\Sound;

/**
 * aisense-audio-v2 on this machine: the sound aisenseapi.com makes and reads,
 * made and read here instead.
 *
 * aisense's own implementation, func_audio_v2.php and the WAV reading of
 * func_audio_codec.php (aisense-free-public-rest-apis, code/pro-api/libs), as a
 * class of this add-on, so a card can be played and heard with nothing leaving
 * the machine. It makes the same sound as aisense byte for byte, and each reads
 * the other's. The same as aamio-python's aamio_sound.codec.
 *
 * Each 20 ms symbol carries two bits as one of four tones, and every symbol
 * position uses its own set of four, cycling through three sets, so the echo of
 * the two symbols before is never a candidate for the current one. Twelve tones
 * per mode on a 200 Hz grid. The packet is a 32-symbol preamble, an 8-symbol
 * timing marker before every 16 bytes, and the bytes, four symbols each: header
 * "AS", version 3 and the payload length with 8 Reed-Solomon bytes, then the
 * payload and a CRC32 of header and payload with 16 + 2 * intdiv(n + 4, 16)
 * Reed-Solomon bytes. Reed-Solomon over GF(256), 0x11d, first root 0.
 *
 * The decoder brings each band to complex baseband with a third-order CIC
 * decimator, finds the preamble with a sliding DFT, learns each tone's level
 * from it, finds every timing marker again, reads each symbol in its own set
 * and keeps how sure it was, and tries Reed-Solomon on errors alone, then with
 * the least sure bytes erased. Nothing is returned unless the CRC holds.
 */
final class Codec
{
    public const SAMPLE_RATE = 48000;
    public const SYMBOL_SECONDS = 0.020;
    public const PREAMBLE = [0, 3, 0, 3, 1, 2, 1, 2, 3, 1, 0, 2, 0, 1, 3, 2, 1, 0, 2, 3, 2, 0, 3, 1, 3, 2, 0, 1, 2, 1, 3, 0];
    public const MARKER = [3, 0, 2, 0, 1, 3, 1, 2];
    public const MAX_BYTES = 128;
    public const MAX_WAV_BYTES = 8388608;
    public const MAX_SECONDS = 40;
    public const PROTOCOL = 'aisense-audio-v2';
    public const VERSION = 3;
    public const SETS = [
        'audible' => [[800, 1400, 2200, 3000], [1000, 1800, 2400, 2800], [1200, 1600, 2000, 2600]],
        'high' => [[14000, 14600, 15200, 15800], [14200, 14800, 15400, 16000], [14400, 15000, 15600, 16200]],
        'ultrasound' => [[18000, 18600, 19200, 19800], [18200, 18800, 19400, 20000], [18400, 19000, 19600, 20200]],
    ];
    public const MODES = ['audible', 'high', 'ultrasound'];
    public const BLOCK = 16;
    public const HEADER_PARITY = 8;
    public const LEVEL = 0.55;
    public const RAMP_SECONDS = 0.002;
    public const LEAD_SECONDS = 0.25;
    public const WINDOW_START = 0.25;
    public const WINDOW_SECONDS = 0.010;
    public const DECIMATION = 16;

    /** @var array{0: list<int>, 1: list<int>}|null */
    private static ?array $tables = null;

    // ------------------------------------------------------------------ Reed-Solomon over GF(256)

    /** @return array{0: list<int>, 1: list<int>} */
    private static function tables(): array
    {
        if (self::$tables === null) {
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
            self::$tables = [$exp, $log];
        }

        return self::$tables;
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        [$exp, $log] = self::tables();

        return $exp[$log[$a] + $log[$b]];
    }

    private static function div(int $a, int $b): int
    {
        if ($b === 0) {
            throw new \DivisionByZeroError('Reed-Solomon division by zero.');
        }
        if ($a === 0) {
            return 0;
        }
        [$exp, $log] = self::tables();

        return $exp[(($log[$a] - $log[$b]) % 255 + 255) % 255];
    }

    /** Polynomials with the highest power first. @param list<int> $p @param list<int> $q @return list<int> */
    private static function polyMul(array $p, array $q): array
    {
        $r = array_fill(0, count($p) + count($q) - 1, 0);
        foreach ($q as $j => $b) {
            foreach ($p as $i => $a) {
                $r[$i + $j] ^= self::mul($a, $b);
            }
        }

        return $r;
    }

    /** @param list<int> $p */
    private static function polyEval(array $p, int $x): int
    {
        $y = $p[0];
        for ($i = 1, $n = count($p); $i < $n; $i++) {
            $y = self::mul($y, $x) ^ $p[$i];
        }

        return $y;
    }

    /** Polynomials with the lowest power first, for the decoder. @param list<int> $p @param list<int> $q @return list<int> */
    private static function polyMulLow(array $p, array $q): array
    {
        $r = array_fill(0, count($p) + count($q) - 1, 0);
        foreach ($p as $i => $a) {
            if ($a) {
                foreach ($q as $j => $b) {
                    $r[$i + $j] ^= self::mul($a, $b);
                }
            }
        }

        return $r;
    }

    /** @param list<int> $p @param list<int> $q @return list<int> */
    private static function polyAddLow(array $p, array $q): array
    {
        $n = max(count($p), count($q));
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            $r[] = ($p[$i] ?? 0) ^ ($q[$i] ?? 0);
        }

        return $r;
    }

    /** @param list<int> $p */
    private static function polyEvalLow(array $p, int $x): int
    {
        $y = 0;
        for ($i = count($p) - 1; $i >= 0; $i--) {
            $y = self::mul($y, $x) ^ $p[$i];
        }

        return $y;
    }

    /** @return list<int> */
    private static function generator(int $nsym): array
    {
        [$exp] = self::tables();
        $g = [1];
        for ($i = 0; $i < $nsym; $i++) {
            $g = self::polyMul($g, [1, $exp[$i]]);
        }

        return $g;
    }

    /** The message followed by $nsym parity bytes. */
    public static function rsEncode(string $message, int $nsym): string
    {
        $g = self::generator($nsym);
        $msg = array_values(unpack('C*', $message) ?: []);
        $out = array_merge($msg, array_fill(0, $nsym, 0));
        $len = count($msg);
        for ($i = 0; $i < $len; $i++) {
            $c = $out[$i];
            if ($c) {
                for ($j = 1, $gn = count($g); $j < $gn; $j++) {
                    $out[$i + $j] ^= self::mul($g[$j], $c);
                }
            }
        }

        return $message . pack('C*', ...array_slice($out, $len));
    }

    /** @param list<int> $msg @return list<int> */
    private static function syndromes(array $msg, int $nsym): array
    {
        [$exp] = self::tables();
        $s = [];
        for ($i = 0; $i < $nsym; $i++) {
            $s[] = self::polyEval($msg, $exp[$i]);
        }

        return $s;
    }

    /**
     * The corrected message without parity, or null: up to $nsym / 2 byte
     * errors, more with known erasures (2 * errors + erasures <= $nsym).
     *
     * @param list<int> $erasures
     */
    public static function rsDecode(string $data, int $nsym, array $erasures = []): ?string
    {
        [$exp] = self::tables();
        $msg = array_values(unpack('C*', $data) ?: []);
        $n = count($msg);
        $erasures = array_values(array_unique($erasures));
        sort($erasures);
        $ne = count($erasures);
        if ($ne > $nsym || $n <= $nsym) {
            return null;
        }
        $s = self::syndromes($msg, $nsym);
        if (max($s) === 0) {
            return pack('C*', ...array_slice($msg, 0, $n - $nsym));
        }
        $loc = [1];
        foreach ($erasures as $pos) {
            $loc = self::polyMulLow($loc, [1, $exp[$n - 1 - $pos]]);
        }
        $lam = $loc;
        $b = $loc;
        $bigL = $ne;
        for ($r = $ne; $r < $nsym; $r++) {
            $delta = 0;
            foreach ($lam as $j => $coef) {
                if ($r - $j >= 0) {
                    $delta ^= self::mul($coef, $s[$r - $j]);
                }
            }
            array_unshift($b, 0);
            if ($delta) {
                $scaled = array_map(static fn (int $c): int => self::mul($delta, $c), $b);
                if (2 * $bigL <= $r + $ne) {
                    $new = self::polyAddLow($lam, $scaled);
                    $b = array_map(static fn (int $c): int => self::div($c, $delta), $lam);
                    $lam = $new;
                    $bigL = $r + 1 + $ne - $bigL;
                } else {
                    $lam = self::polyAddLow($lam, $scaled);
                }
            }
        }
        while (count($lam) > 1 && end($lam) === 0) {
            array_pop($lam);
        }
        $errs = count($lam) - 1;
        if (2 * ($errs - $ne) + $ne > $nsym) {
            return null;
        }
        $positions = [];
        for ($pos = 0; $pos < $n; $pos++) {
            if (self::polyEvalLow($lam, $exp[(255 - ($n - 1 - $pos)) % 255]) === 0) {
                $positions[] = $pos;
            }
        }
        if (count($positions) !== $errs) {
            return null;
        }
        $omega = array_slice(self::polyMulLow($s, $lam), 0, $nsym);
        $dlam = [];
        for ($i = 1, $ln = count($lam); $i < $ln; $i++) {
            $dlam[] = $i % 2 === 1 ? $lam[$i] : 0;
        }
        foreach ($positions as $pos) {
            $xk = $exp[$n - 1 - $pos];
            $xinv = $exp[(255 - ($n - 1 - $pos)) % 255];
            $den = self::polyEvalLow($dlam, $xinv);
            if ($den === 0) {
                return null;
            }
            $msg[$pos] ^= self::mul($xk, self::div(self::polyEvalLow($omega, $xinv), $den));
        }
        if (max(self::syndromes($msg, $nsym)) !== 0) {
            return null;
        }

        return pack('C*', ...array_slice($msg, 0, $n - $nsym));
    }

    // ------------------------------------------------------------------ the packet and the sound

    public static function bodyParity(int $length): int
    {
        return 16 + 2 * intdiv($length + 4, 16);
    }

    public static function packetBytes(string $payload): string
    {
        $length = strlen($payload);
        if ($length < 1 || $length > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Provide between 1 and 128 bytes.');
        }
        $header = 'AS' . chr(self::VERSION) . chr($length);
        $body = $payload . hash('crc32b', $header . $payload, true);

        return self::rsEncode($header, self::HEADER_PARITY) . self::rsEncode($body, self::bodyParity($length));
    }

    /** The wire symbols, values 0 to 3; symbol position k sounds in set k % 3. @return list<int> */
    public static function symbols(string $payload): array
    {
        $bytes = self::packetBytes($payload);
        $symbols = self::PREAMBLE;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            if ($i % self::BLOCK === 0) {
                array_push($symbols, ...self::MARKER);
            }
            $b = ord($bytes[$i]);
            array_push($symbols, ($b >> 6) & 3, ($b >> 4) & 3, ($b >> 2) & 3, $b & 3);
        }

        return $symbols;
    }

    public static function wav(string $pcm, int $sampleRate = self::SAMPLE_RATE, int $channels = 1): string
    {
        return 'RIFF' . pack('V', strlen($pcm) + 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, $channels, $sampleRate, $sampleRate * $channels * 2, $channels * 2, 16)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    /** The WAV for 1 to 128 bytes in one of the three modes: mono 16-bit PCM at 48 kHz, as aisense makes it. */
    public static function encode(string $payload, string $mode = 'audible'): string
    {
        if (!isset(self::SETS[$mode])) {
            throw new \InvalidArgumentException('Mode must be audible, high or ultrasound.');
        }
        $sets = self::SETS[$mode];
        $symbols = self::symbols($payload);
        $rate = self::SAMPLE_RATE;
        $n = (int) round(self::SYMBOL_SECONDS * $rate);
        $ramp = (int) (self::RAMP_SECONDS * $rate);
        $envelope = array_fill(0, $n, 1.0);
        for ($i = 0; $i < $ramp; $i++) {
            $envelope[$i] = $envelope[$n - 1 - $i] = 0.5 - 0.5 * cos(M_PI * $i / $ramp);
        }
        $tones = [];
        foreach ($sets as $set) {
            foreach ($set as $frequency) {
                $tone = '';
                for ($i = 0; $i < $n; $i++) {
                    $value = (int) round(self::LEVEL * $envelope[$i] * sin(2 * M_PI * $frequency * $i / $rate) * 32767);
                    $tone .= pack('v', $value & 65535);
                }
                $tones[$frequency] = $tone;
            }
        }
        $quiet = str_repeat("\0", 2 * (int) (self::LEAD_SECONDS * $rate));
        $pcm = $quiet;
        foreach ($symbols as $position => $value) {
            $pcm .= $tones[$sets[$position % 3][$value]];
        }

        return self::wav($pcm . $quiet);
    }

    // ------------------------------------------------------------------ reading a recording

    /**
     * Where the stronger channel's samples are, and the recording's shape, with
     * the same checks aisense makes. Samples stay in the packed string.
     *
     * @return array{offset: int, stride: int, sample_rate: int, samples: int, channel: int, duration_seconds: float}
     */
    public static function parseWav(string $wav): array
    {
        $size = strlen($wav);
        if ($size > self::MAX_WAV_BYTES) {
            throw new BadRecording('WAV is larger than 8 MiB.');
        }
        if ($size < 44 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new BadRecording('Provide an uncompressed PCM16 WAV recording.');
        }
        if (unpack('V', substr($wav, 4, 4))[1] + 8 !== $size) {
            throw new BadRecording('WAV length does not match its RIFF header.');
        }
        $format = null;
        $data = null;
        $chunks = 0;
        for ($offset = 12; $offset < $size;) {
            if (++$chunks > 64) {
                throw new BadRecording('WAV contains too many chunks.');
            }
            if ($offset + 8 > $size) {
                throw new BadRecording('Truncated WAV chunk header.');
            }
            $tag = substr($wav, $offset, 4);
            $length = unpack('V', substr($wav, $offset + 4, 4))[1];
            $start = $offset + 8;
            if ($length > $size - $start || $start + $length + ($length & 1) > $size) {
                throw new BadRecording('Truncated WAV chunk.');
            }
            if ($tag === 'fmt ') {
                if ($format !== null || $length < 16) {
                    throw new BadRecording('Invalid or duplicate WAV format chunk.');
                }
                $format = unpack('vformat/vchannels/Vrate/Vbytes_per_second/valign/vbits', substr($wav, $start, 16));
            } elseif ($tag === 'data') {
                if ($data !== null) {
                    throw new BadRecording('Duplicate WAV data chunk.');
                }
                $data = ['offset' => $start, 'bytes' => $length];
            }
            $offset = $start + $length + ($length & 1);
        }
        if ($format === null || $data === null || $format['format'] !== 1 || $format['bits'] !== 16
            || !in_array($format['channels'], [1, 2], true) || !in_array($format['rate'], [44100, 48000], true)) {
            throw new BadRecording('Use PCM16 WAV, mono or stereo, at 44100 or 48000 Hz.');
        }
        $stride = $format['channels'] * 2;
        if ($format['align'] !== $stride || $format['bytes_per_second'] !== $format['rate'] * $stride || $data['bytes'] % $stride !== 0) {
            throw new BadRecording('Invalid WAV sample alignment.');
        }
        $samples = intdiv($data['bytes'], $stride);
        if ($samples < 1 || $samples > self::MAX_SECONDS * $format['rate']) {
            throw new BadRecording('Recording must contain at most 40 seconds of audio.');
        }
        // A stereo microphone can put the useful signal in only one channel: use
        // the stronger one, without the phase cancellation of a naive mix.
        $channel = 0;
        if ($format['channels'] === 2) {
            $energy = [0.0, 0.0];
            for ($sample = 0; $sample < $samples; $sample += 211) {
                foreach ([0, 1] as $side) {
                    $pos = $data['offset'] + $sample * 4 + $side * 2;
                    $value = ord($wav[$pos]) | (ord($wav[$pos + 1]) << 8);
                    if ($value >= 32768) {
                        $value -= 65536;
                    }
                    $energy[$side] += $value * $value;
                }
            }
            $channel = $energy[1] > $energy[0] ? 1 : 0;
        }

        return ['offset' => $data['offset'] + $channel * 2, 'stride' => $stride, 'sample_rate' => $format['rate'], 'samples' => $samples,
            'channel' => $channel, 'duration_seconds' => $samples / $format['rate']];
    }

    /**
     * One band at complex baseband: mixed down by its centre and decimated by
     * 16 with a third-order CIC filter, whose nulls fall on the band's mirror.
     *
     * @param array{offset: int, stride: int, sample_rate: int, samples: int} $info
     * @return array{0: list<float>, 1: list<float>, 2: float}
     */
    private static function baseband(string $wav, array $info, float $centre): array
    {
        $rate = $info['sample_rate'];
        $d = self::DECIMATION;
        $period = intdiv($rate, 100);
        if ($period * 100 !== $rate || fmod($centre, 100.0) !== 0.0) {
            throw new \InvalidArgumentException('The baseband needs a rate and centre on a 100 Hz grid.');
        }
        $tr = $ti = [];
        for ($k = 0; $k < $period; $k++) {
            $a = -2 * M_PI * $centre * $k / $rate;
            $tr[] = cos($a);
            $ti[] = sin($a);
        }
        $idx = 0;
        $i1r = $i1i = $i2r = $i2i = $i3r = $i3i = 0.0;
        $c1r = $c1i = $c2r = $c2i = $c3r = $c3i = 0.0;
        $zr = [];
        $zi = [];
        $stride = $info['stride'];
        $step = intdiv($stride, 2);
        $phase = 0;
        for ($first = 0, $total = $info['samples']; $first < $total; $first += 65536) {
            $count = min(65536, $total - $first);
            // 's' is a signed 16-bit value in machine order, little-endian on the x86 and ARM hosts PHP runs on.
            $values = unpack('s*', substr($wav, $info['offset'] + $first * $stride, ($count - 1) * $stride + 2));
            for ($k = 1, $last = count($values); $k <= $last; $k += $step) {
                $s = $values[$k];
                $i1r += $s * $tr[$idx];
                $i1i += $s * $ti[$idx];
                if (++$idx === $period) {
                    $idx = 0;
                }
                $i2r += $i1r;
                $i2i += $i1i;
                $i3r += $i2r;
                $i3i += $i2i;
                if (++$phase === $d) {
                    $phase = 0;
                    $t1r = $i3r - $c1r;
                    $t1i = $i3i - $c1i;
                    $c1r = $i3r;
                    $c1i = $i3i;
                    $t2r = $t1r - $c2r;
                    $t2i = $t1i - $c2i;
                    $c2r = $t1r;
                    $c2i = $t1i;
                    $zr[] = $t2r - $c3r;
                    $zi[] = $t2i - $c3i;
                    $c3r = $t2r;
                    $c3i = $t2i;
                }
            }
        }

        return [$zr, $zi, $rate / $d];
    }

    /**
     * Tone powers of 10 ms windows at the given baseband start indexes.
     *
     * @param list<float> $zr
     * @param list<float> $zi
     * @param list<array{0: list<float>, 1: list<float>}> $twiddle
     * @param list<int> $starts
     * @return list<list<float>>
     */
    private static function powers(array $zr, array $zi, array $twiddle, array $starts): array
    {
        $out = [];
        $n = count($zr);
        foreach ($starts as $start) {
            $row = [];
            foreach ($twiddle as [$wr, $wi]) {
                $ar = $ai = 0.0;
                foreach ($wr as $m => $c) {
                    $k = $start + $m;
                    if ($k < 0 || $k >= $n) {
                        continue;
                    }
                    $s = $wi[$m];
                    $ar += $zr[$k] * $c - $zi[$k] * $s;
                    $ai += $zr[$k] * $s + $zi[$k] * $c;
                }
                $row[] = $ar * $ar + $ai * $ai;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** The band's tones, their index per set, and the DFT twiddles of a 10 ms window at the baseband rate. @return array<string, mixed> */
    private static function plan(string $mode, float $fs): array
    {
        $sets = self::SETS[$mode];
        $tones = [];
        foreach ($sets as $set) {
            foreach ($set as $f) {
                $tones[] = $f;
            }
        }
        sort($tones);
        $index = array_flip($tones);
        $centre = ($tones[0] + $tones[count($tones) - 1]) / 2;
        $length = (int) round(self::WINDOW_SECONDS * $fs);
        $twiddle = [];
        foreach ($tones as $f) {
            $wr = $wi = [];
            for ($m = 0; $m < $length; $m++) {
                $a = -2 * M_PI * ($f - $centre) * $m / $fs;
                $wr[] = cos($a);
                $wi[] = sin($a);
            }
            $twiddle[] = [$wr, $wi];
        }
        $setIndex = [];
        foreach ($sets as $j => $set) {
            $setIndex[$j] = array_map(static fn (int $f): int => $index[$f], $set);
        }

        return ['sets' => $sets, 'tones' => $tones, 'centre' => $centre, 'length' => $length, 'twiddle' => $twiddle, 'set_index' => $setIndex];
    }

    /**
     * Packet starts, best first: the preamble scored through a sliding DFT at every fourth baseband sample.
     *
     * @param list<float> $zr
     * @param list<float> $zi
     * @param array<string, mixed> $plan
     * @return list<array{0: float, 1: float}>
     */
    private static function candidates(array $zr, array $zi, float $fs, array $plan, int $keep = 6): array
    {
        $length = $plan['length'];
        $sym = self::SYMBOL_SECONDS * $fs;
        $hop = 4;
        $count = count($plan['tones']);
        $rc = $rs = [];
        $leave = [];
        $exact = false;
        foreach ($plan['tones'] as $j => $f) {
            $a = 2 * M_PI * ($f - $plan['centre']) / $fs;
            $rc[$j] = cos($a);
            $rs[$j] = sin($a);
            // The sample leaving the window has turned through a * length since it entered: at 48 kHz a whole
            // number of cycles, at 44.1 kHz it must be turned back.
            $leave[$j] = [cos($a * $length), sin($a * $length)];
            if (abs($leave[$j][0] - 1.0) > 1e-9 || abs($leave[$j][1]) > 1e-9) {
                $exact = true;
            }
        }
        $offset = [];
        $expected = [];
        foreach (self::PREAMBLE as $i => $value) {
            $offset[$i] = (int) round((($i + self::WINDOW_START) * $sym + $length - 1) / $hop);
            $expected[$i] = $plan['set_index'][$i % 3][$value];
        }
        $span = $offset[count($offset) - 1] + 1;
        $ring = 1;
        while ($ring < $span + 2) {
            $ring <<= 1;
        }
        $mask = $ring - 1;
        $share = array_fill(0, $ring, array_fill(0, $count, 0.0));
        $xr = array_fill(0, $count, 0.0);
        $xi = array_fill(0, $count, 0.0);
        $best = [];
        $floor = 0.5;
        $n = count($zr);
        $row = 0;
        for ($k = 0; $k < $n; $k++) {
            $nr = $zr[$k];
            $ni = $zi[$k];
            $or = $oi = 0.0;
            if ($k >= $length) {
                $or = $zr[$k - $length];
                $oi = $zi[$k - $length];
            }
            if ($exact) {
                for ($j = 0; $j < $count; $j++) {
                    [$lc, $ls] = $leave[$j];
                    $br = $xr[$j] + $nr - ($or * $lc - $oi * $ls);
                    $bi = $xi[$j] + $ni - ($or * $ls + $oi * $lc);
                    $xr[$j] = $br * $rc[$j] - $bi * $rs[$j];
                    $xi[$j] = $br * $rs[$j] + $bi * $rc[$j];
                }
            } else {
                $ar = $nr - $or;
                $ai = $ni - $oi;
                for ($j = 0; $j < $count; $j++) {
                    $br = $xr[$j] + $ar;
                    $bi = $xi[$j] + $ai;
                    $xr[$j] = $br * $rc[$j] - $bi * $rs[$j];
                    $xi[$j] = $br * $rs[$j] + $bi * $rc[$j];
                }
            }
            if ($k % $hop !== 0) {
                continue;
            }
            $p = [];
            for ($j = 0; $j < $count; $j++) {
                $p[$j] = $xr[$j] * $xr[$j] + $xi[$j] * $xi[$j];
            }
            $shares = array_fill(0, $count, 0.0);
            foreach ($plan['set_index'] as $members) {
                $sum = $p[$members[0]] + $p[$members[1]] + $p[$members[2]] + $p[$members[3]] + 1e-30;
                foreach ($members as $t) {
                    $shares[$t] = $p[$t] / $sum;
                }
            }
            $share[$row & $mask] = $shares;
            $first = $row - $span + 1;
            if ($first >= 0) {
                $score = 0.0;
                foreach ($offset as $i => $o) {
                    $score += $share[($first + $o) & $mask][$expected[$i]];
                }
                $score /= count($offset);
                if ($score > $floor) {
                    $start = (float) ($first * $hop);
                    $merged = false;
                    foreach ($best as $b => [$other, $otherScore]) {
                        if (abs($other - $start) < $sym / 2) {
                            if ($score > $otherScore) {
                                $best[$b] = [$start, $score];
                            }
                            $merged = true;
                            break;
                        }
                    }
                    if (!$merged) {
                        $best[] = [$start, $score];
                        if (count($best) > $keep) {
                            usort($best, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
                            array_pop($best);
                            $floor = max(0.5, $best[count($best) - 1][1]);
                        }
                    }
                }
            }
            $row++;
        }
        usort($best, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $best;
    }

    /**
     * Reed-Solomon on errors alone, then with the least sure bytes erased: the message and the bits it changed, or null.
     *
     * @param list<float> $margins
     * @return array{0: string, 1: int}|null
     */
    private static function correct(string $code, int $nsym, array $margins): ?array
    {
        $got = self::rsDecode($code, $nsym);
        if ($got === null) {
            $order = array_keys($margins);
            usort($order, static fn (int $a, int $b): int => $margins[$a] <=> $margins[$b]);
            foreach ([intdiv($nsym, 4), intdiv($nsym, 2), intdiv(3 * $nsym, 4), $nsym] as $k) {
                $got = self::rsDecode($code, $nsym, array_slice($order, 0, $k));
                if ($got !== null) {
                    break;
                }
            }
        }
        if ($got === null) {
            return null;
        }
        $fixed = self::rsEncode($got, $nsym);
        $bits = 0;
        for ($i = 0, $n = strlen($code); $i < $n; $i++) {
            $x = ord($code[$i]) ^ ord($fixed[$i]);
            while ($x) {
                $bits += $x & 1;
                $x >>= 1;
            }
        }

        return [$got, $bits];
    }

    /**
     * The packet at one candidate start, or null.
     *
     * @param list<float> $zr
     * @param list<float> $zi
     * @param array<string, mixed> $plan
     * @return array{data: string, corrected_bits: int}|null
     */
    private static function read(array $zr, array $zi, float $fs, array $plan, float $start): ?array
    {
        $sym = self::SYMBOL_SECONDS * $fs;
        $frac = self::WINDOW_START;
        $twiddle = $plan['twiddle'];
        $setIndex = $plan['set_index'];
        $read = static function (float $at, int $position, int $count, array $gain) use ($zr, $zi, $sym, $frac, $twiddle, $setIndex): array {
            $starts = [];
            for ($i = 0; $i < $count; $i++) {
                $starts[] = (int) round($at + ($i + $frac) * $sym);
            }
            $out = [];
            foreach (self::powers($zr, $zi, $twiddle, $starts) as $i => $row) {
                $v = [];
                foreach ($setIndex[($position + $i) % 3] as $t) {
                    $v[] = $row[$t] / $gain[$t];
                }
                $sum = array_sum($v) + 1e-30;
                $out[] = array_map(static fn (float $x): float => $x / $sum, $v);
            }

            return $out;
        };
        $score = static function (float $at, int $position, array $values, array $gain) use ($read): float {
            $rows = $read($at, $position, count($values), $gain);
            $s = 0.0;
            foreach ($values as $i => $v) {
                $s += $rows[$i][$v];
            }

            return $s / count($values);
        };
        $flat = array_fill(0, count($plan['tones']), 1.0);
        $bestStart = $start;
        $bestScore = $score($start, 0, self::PREAMBLE, $flat);
        for ($shift = -3; $shift <= 3; $shift++) {
            if ($shift === 0) {
                continue;
            }
            $s = $score($start + $shift, 0, self::PREAMBLE, $flat);
            if ($s > $bestScore) {
                [$bestStart, $bestScore] = [$start + $shift, $s];
            }
        }
        if ($bestScore < 0.5) {
            return null;
        }
        // Each tone's received level while it was the one sent in the preamble.
        $starts = [];
        for ($i = 0; $i < count(self::PREAMBLE); $i++) {
            $starts[] = (int) round($bestStart + ($i + $frac) * $sym);
        }
        $rows = self::powers($zr, $zi, $twiddle, $starts);
        $heard = array_fill(0, count($plan['tones']), []);
        foreach (self::PREAMBLE as $i => $v) {
            $t = $setIndex[$i % 3][$v];
            $heard[$t][] = $rows[$i][$t];
        }
        $gain = [];
        foreach ($heard as $t => $list) {
            sort($list);
            $gain[$t] = $list ? max($list[intdiv(count($list), 2)], 1e-30) : 1.0;
        }
        $sorted = $gain;
        sort($sorted);
        $median = $sorted[intdiv(count($sorted), 2)];
        $gain = array_map(static fn (float $g): float => $g / $median, $gain);

        $position = count(self::PREAMBLE);
        $at = $bestStart + $position * $sym;
        $index = 0;
        $readBytes = static function (int $want) use (&$at, &$position, &$index, $read, $score, $gain, $sym, $fs): array {
            $bytes = [];
            $margins = [];
            for ($b = 0; $b < $want; $b++, $index++) {
                if ($index % self::BLOCK === 0) {
                    $best = $at;
                    $bestScore = -1.0;
                    for ($ms = -16.0; $ms <= 16.0; $ms += 0.5) {
                        $cand = $at + $ms * 0.001 * $fs;
                        $s = $score($cand, $position, self::MARKER, $gain);
                        if ($s > $bestScore) {
                            [$best, $bestScore] = [$cand, $s];
                        }
                    }
                    $at = $best + count(self::MARKER) * $sym;
                    $position += count(self::MARKER);
                }
                $value = 0;
                $margin = 1.0;
                foreach ($read($at, $position, 4, $gain) as $r) {
                    $order = $r;
                    arsort($order);
                    $keys = array_keys($order);
                    $value = ($value << 2) | $keys[0];
                    $margin = min($margin, $order[$keys[0]] - $order[$keys[1]]);
                }
                $bytes[] = $value;
                $margins[] = $margin;
                $at += 4 * $sym;
                $position += 4;
            }

            return [pack('C*', ...$bytes), $margins];
        };
        [$head, $headMargins] = $readBytes(4 + self::HEADER_PARITY);
        $header = self::correct($head, self::HEADER_PARITY, $headMargins);
        if ($header === null || substr($header[0], 0, 3) !== 'AS' . chr(self::VERSION)) {
            return null;
        }
        $length = ord($header[0][3]);
        if ($length < 1 || $length > self::MAX_BYTES) {
            return null;
        }
        $parity = self::bodyParity($length);
        [$body, $bodyMargins] = $readBytes($length + 4 + $parity);
        $corrected = self::correct($body, $parity, $bodyMargins);
        if ($corrected === null) {
            return null;
        }
        $payload = substr($corrected[0], 0, $length);
        if (!hash_equals(hash('crc32b', $header[0] . $payload, true), substr($corrected[0], $length, 4))) {
            return null;
        }

        return ['data' => $payload, 'corrected_bits' => $header[1] + $corrected[1]];
    }

    /**
     * The first intact packet in one band of a parsed recording, or null.
     *
     * @param array{offset: int, stride: int, sample_rate: int, samples: int, channel: int, duration_seconds: float} $info
     * @return array<string, mixed>|null
     */
    public static function decodeBand(string $wav, array $info, string $mode): ?array
    {
        $plan = self::plan($mode, $info['sample_rate'] / self::DECIMATION);
        [$zr, $zi, $fs] = self::baseband($wav, $info, (float) $plan['centre']);
        foreach (self::candidates($zr, $zi, $fs, $plan) as [$start]) {
            $result = self::read($zr, $zi, $fs, $plan, $start);
            if ($result !== null) {
                return $result + ['mode' => $mode, 'protocol' => self::PROTOCOL, 'sample_rate' => $info['sample_rate'], 'duration_seconds' => $info['duration_seconds']];
            }
        }

        return null;
    }

    /**
     * What a WAV carries, as aisense answers it: data, corrected_bits, mode,
     * protocol, sample_rate, duration_seconds; or null when no intact packet is
     * in it. BadRecording for a file it does not read.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $wav, string $mode = 'auto'): ?array
    {
        if (!in_array($mode, ['auto', 'audible', 'high', 'ultrasound'], true)) {
            throw new \InvalidArgumentException('Mode must be auto, audible, high or ultrasound.');
        }
        $info = self::parseWav($wav);
        foreach ($mode === 'auto' ? self::MODES : [$mode] as $name) {
            $result = self::decodeBand($wav, $info, $name);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
