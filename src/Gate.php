<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Gate from the writer's side: the canonical text and hash of a gate, the
 * proof of work, and the plan a client follows before writing to an inbox
 * that sets conditions. The ceilings are the service's own, so an inbox run by
 * a stranger can never make this client spend more CPU than aamio lets any
 * inbox ask for. 32 bits is for an inbox that means to meet only writers with
 * real compute, and takes this client hours, so the plan weighs the work
 * against the time the inbox has left and says no before it starts.
 */
final class Gate
{
    public const REQUIRE_MAX_BITS = 32;
    public const ADVISE_MAX_BITS = 18;
    /** Below this the work is a second or so anywhere, and not worth timing first. */
    public const ESTIMATE_FROM_BITS = 17;

    private static ?float $rate = null;
    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    // -------------------------------------------------------- canonical --

    /**
     * The canonical text a gate_hash is taken over: defaults written out by
     * the service before it is shown, so here only the ordering and the
     * escaping are ours: keys sorted by their UTF-8 bytes at every level, no
     * whitespace, integers as integers, an empty object as {}, and strings
     * escaped only where JSON requires it.
     */
    public static function canonical(array $gate): string
    {
        $gate = self::pruneEmptyBuckets($gate);

        return self::encode($gate);
    }

    public static function hash(array $gate): string
    {
        return hash('sha256', self::canonical($gate));
    }

    private static function pruneEmptyBuckets(array $gate): array
    {
        foreach (['require', 'advise'] as $bucket) {
            if (array_key_exists($bucket, $gate) && (is_array($gate[$bucket]) && $gate[$bucket] === [] || $gate[$bucket] instanceof \stdClass && (array) $gate[$bucket] === [])) {
                unset($gate[$bucket]);
            }
        }

        return $gate;
    }

    private static function encode(mixed $value): string
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            if ($value === []) {
                return '{}';
            }
            if (array_is_list($value)) {
                return '[' . implode(',', array_map([self::class, 'encode'], $value)) . ']';
            }
            $keys = array_map('strval', array_keys($value));
            usort($keys, 'strcmp');
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = self::encodeString($key) . ':' . self::encode($value[$key]);
            }

            return '{' . implode(',', $parts) . '}';
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }

        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function encodeString(string $text): string
    {
        return json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    // -------------------------------------------------------------- work --

    /** What thread work is computed over. key is the X-Key exactly as sent, or empty for an unsigned message. */
    public static function powInput(string $w, string $key, string $bodySha256, string $nonce): string
    {
        return "aamio-pow-v1\n" . $w . "\n" . $key . "\n" . $bodySha256 . "\n" . $nonce;
    }

    public static function powDigest(string $w, string $key, string $bodySha256, string $nonce): string
    {
        return hash('sha256', self::powInput($w, $key, $bodySha256, $nonce), true);
    }

    /** What board work is computed over: no address, the post is not to a thread. */
    public static function boardPowInput(string $key, string $bodySha256, string $nonce): string
    {
        return "aamio-board-pow-v1\n" . $key . "\n" . $bodySha256 . "\n" . $nonce;
    }

    public static function boardPowDigest(string $key, string $bodySha256, string $nonce): string
    {
        return hash('sha256', self::boardPowInput($key, $bodySha256, $nonce), true);
    }

    /** Leading zero bits, counted from the top bit of the first byte. */
    public static function zeroBits(string $digest): int
    {
        $bits = 0;
        foreach (str_split($digest) as $byte) {
            $value = ord($byte);
            if ($value === 0) {
                $bits += 8;
                continue;
            }
            for ($mask = 0x80; ($value & $mask) === 0; $mask >>= 1) {
                $bits++;
            }
            break;
        }

        return $bits;
    }

    public static function isNonce(string $nonce): bool
    {
        return preg_match(self::NONCE_PATTERN, $nonce) === 1;
    }

    /**
     * The first nonce, counting from 0, whose thread digest reaches bits, or
     * null when $deadline, a microtime(true) value, passes first. Without a
     * deadline a large bits runs for hours, and past the life of the inbox
     * that work buys nothing.
     */
    public static function solve(string $w, string $key, string $body, int $bits, ?float $deadline = null): ?string
    {
        if ($bits < 0 || $bits > self::REQUIRE_MAX_BITS) {
            throw new \InvalidArgumentException('work is 0 to ' . self::REQUIRE_MAX_BITS . ' bits');
        }
        $prefix = "aamio-pow-v1\n" . $w . "\n" . $key . "\n" . Codec::sha256hex($body) . "\n";
        for ($n = 0; ; $n++) {
            if (self::zeroBits(hash('sha256', $prefix . $n, true)) >= $bits) {
                return (string) $n;
            }
            // Every 65536 attempts, a tenth of a second or so here.
            if ($deadline !== null && ($n & 0xFFFF) === 0xFFFF && microtime(true) > $deadline) {
                return null;
            }
        }
    }

    /**
     * Attempts a second solve makes on this machine, timed once and kept. The
     * estimate before long work is only as good as this, so it is solve's own
     * loop that is timed, for a quarter of a second.
     */
    public static function hashRate(): float
    {
        if (self::$rate === null) {
            $prefix = "aamio-pow-v1\ncalibration\n\n" . str_repeat('0', 64) . "\n";
            $count = 0;
            $start = microtime(true);
            do {
                for ($i = 0; $i < 4096; $i++, $count++) {
                    self::zeroBits(hash('sha256', $prefix . $count, true));
                }
                $elapsed = microtime(true) - $start;
            } while ($elapsed < 0.25);
            self::$rate = $count / $elapsed;
        }

        return self::$rate;
    }

    /** How long bits of work takes here on average. A lottery: one in a hundred takes about 4.6 times as long. */
    public static function expectedSeconds(int $bits): float
    {
        return (2 ** $bits) / self::hashRate();
    }

    public static function describe(float $seconds): string
    {
        if ($seconds < 90) {
            return max(1, (int) round($seconds)) . ' seconds';
        }
        if ($seconds < 5400) {
            return (int) round($seconds / 60) . ' minutes';
        }

        return number_format($seconds / 3600, 1) . ' hours';
    }

    /** The first nonce whose board digest reaches bits, over the exact text posted. */
    public static function solveBoard(string $key, string $body, int $bits): string
    {
        if ($bits < 0 || $bits > self::REQUIRE_MAX_BITS) {
            throw new \InvalidArgumentException('work is 0 to ' . self::REQUIRE_MAX_BITS . ' bits');
        }
        $prefix = "aamio-board-pow-v1\n" . $key . "\n" . Codec::sha256hex($body) . "\n";
        for ($n = 0; ; $n++) {
            if (self::zeroBits(hash('sha256', $prefix . $n, true)) >= $bits) {
                return (string) $n;
            }
        }
    }

    // -------------------------------------------------------------- plan --

    /**
     * What to do about an inbox's gate before writing. Returns
     * ['bits' => int|null, 'stop' => string|null, 'notes' => string[],
     * 'expected_seconds' => float]: bits to work for (null for none), stop
     * with the reason when the send must not happen, notes for what was
     * passed over, and how long the required work takes here.
     *
     * $secondsLeft is how long the inbox still takes writes, from
     * X-Seconds-Left on its gate: work that would not be done by then is not
     * started, since finding that out from a 410 an hour later is the worst way
     * to learn it. $budget is how long a caller may be held, set by the MCP
     * server, whose host cuts a tool call after a minute or so.
     */
    public static function plan(?array $gate, ?float $secondsLeft = null, ?float $budget = null): array
    {
        $plan = ['bits' => null, 'stop' => null, 'notes' => [], 'expected_seconds' => 0.0];
        if ($gate === null || $gate === []) {
            return $plan;
        }
        $known = ['pow', 'per_key', 'write_until'];

        foreach ((array) ($gate['require'] ?? []) as $condition => $value) {
            if (!in_array($condition, $known, true)) {
                $plan['stop'] = 'the inbox requires "' . $condition . '", which this client does not know; nothing was sent';

                return $plan;
            }
            if ($condition === 'pow') {
                $bits = (int) (((array) $value)['bits'] ?? 0);
                if ($bits > self::REQUIRE_MAX_BITS) {
                    $plan['stop'] = 'the inbox requires ' . $bits . ' bits of work, above the ' . self::REQUIRE_MAX_BITS . ' aamio lets an inbox ask for; nothing was sent';

                    return $plan;
                }
                $expected = $bits >= self::ESTIMATE_FROM_BITS ? self::expectedSeconds($bits) : 0.0;
                if ($secondsLeft !== null && $expected > $secondsLeft) {
                    $plan['stop'] = 'the inbox requires ' . $bits . ' bits of work, which takes about ' . self::describe($expected) . ' on this machine, and it takes writes for ' . self::describe($secondsLeft) . ' more. The work would not be done before it closes, so it was not started and nothing was sent. Ask the owner for a longer inbox or less work, or send from a machine with more compute';

                    return $plan;
                }
                if ($budget !== null && $expected > $budget) {
                    $plan['stop'] = 'the inbox requires ' . $bits . ' bits of work, which takes about ' . self::describe($expected) . ' on this machine, longer than a tool call is given here, and this server cannot do it in the background. Nothing was sent. Send it from the command line with aamio send, where the work has the time it needs';

                    return $plan;
                }
                $plan['expected_seconds'] = $expected;
                $plan['bits'] = max($plan['bits'] ?? 0, $bits);
            }
        }

        foreach ((array) ($gate['advise'] ?? []) as $condition => $value) {
            if ($condition !== 'pow') {
                $plan['notes'][] = 'the inbox advises "' . $condition . '", which this client does not know, and it was passed over';
                continue;
            }
            $bits = (int) (((array) $value)['bits'] ?? 0);
            if ($bits > self::ADVISE_MAX_BITS) {
                $plan['notes'][] = 'the inbox advises ' . $bits . ' bits of work, above the ' . self::ADVISE_MAX_BITS . ' a client does without asking, and it was passed over';
                continue;
            }
            $plan['bits'] = max($plan['bits'] ?? 0, $bits);
        }

        return $plan;
    }
}
