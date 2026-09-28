<?php

declare(strict_types=1);

/*
 * The live tests, spaced out to what the service allows one address.
 *
 * The service takes thirty opens and closes of threads in sixty seconds from
 * one address, in one counter, and answers 429 past that. tests/live.php makes
 * twelve of them and tests/first-exchange.php twenty: each passes alone, and
 * run one after the other the second one failed on the 429 and not on the
 * code. Found on 28 September 2026, and the agents that share the address
 * were refused for the rest of that minute as well.
 *
 * So every open and close of a thread that a live test makes waits until
 * fewer than LIMIT of them fall inside the last WINDOW seconds. Nothing else
 * is held back: reads, writes and presence have counters of their own, and a
 * wide margin.
 *
 * The count is kept in a file in the temp folder, not in this process, so two
 * scripts run one after the other are counted as the one client the service
 * sees. The live tests of aamio-python keep the same file. Where the file
 * cannot be kept, the count of this process is kept in memory and the tests
 * run as before.
 *
 * Required by tests/live.php and tests/first-exchange.php, after bootstrap.php.
 */

use Aamio\Http;

final class LivePace
{
    /** Twenty of the thirty. The rest is for whoever else writes from this address. */
    public const LIMIT = 20;
    /** One second more than the service's window, so what has left this one has left that one. */
    public const WINDOW = 61.0;
    /** A lock this old was left by a run that was stopped while it held it. */
    public const STALE = 10.0;

    /** @var float[] */
    public array $own = [];
    public int $taken = 0;
    public float $waited = 0.0;
    public string $path;
    /** @var callable */
    private $clock;
    /** @var callable */
    private $sleep;

    public function __construct(public int $limit = self::LIMIT, public float $window = self::WINDOW, ?string $path = null, ?callable $clock = null, ?callable $sleep = null)
    {
        $this->path = $path ?? self::ledgerPath();
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1e6));
        };
    }

    public static function ledgerPath(): string
    {
        $named = getenv('AAMIO_LIVE_LEDGER');

        return is_string($named) && $named !== '' ? $named : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-live-pace.json';
    }

    /** Whether this request is an open or a close of a thread at the service under test. */
    public static function counted(string $method, string $url, string $host): bool
    {
        if (!in_array($method, ['PUT', 'DELETE'], true) || !str_starts_with($url, rtrim($host, '/') . '/')) {
            return false;
        }

        return preg_match('/^\/[a-z2-7]{20}$/D', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    /** Waits until there is room for one more, takes it, and returns the seconds waited. */
    public function take(): float
    {
        $waited = 0.0;

        while (true) {
            $hold = $this->takeOrHold();

            if ($hold === null) {
                $this->taken++;
                $this->waited += $waited;

                return $waited;
            }

            ($this->sleep)($hold);
            $waited += $hold;
        }
    }

    private function takeOrHold(): ?float
    {
        $held = $this->lock();

        try {
            $now = (float) ($this->clock)();
            $inside = fn (float $stamp): bool => $now - $stamp >= 0 && $now - $stamp < $this->window;
            // Read without the lock too: the file is replaced whole, never
            // written in place, so what is there is what somebody finished.
            $shared = array_values(array_filter($this->read(), $inside));
            $own = array_values(array_filter($this->own, $inside));
            // What this process took is in the file as well, where the file
            // could be written, and is counted once. Two that fell on the same
            // instant are two: a clock is coarser than the requests it times.
            $stamps = self::together($shared, $own);

            if (count($stamps) >= $this->limit) {
                // Room opens when the oldest that still counts has left the window.
                return $this->window - ($now - $stamps[count($stamps) - $this->limit]) + 0.05;
            }

            $stamps[] = $now;
            $own[] = $now;
            $this->own = $own;

            if ($held) {
                $this->write($stamps);
            }

            return null;
        } finally {
            if ($held) {
                $this->unlock();
            }
        }
    }

    /**
     * Both lists as one, with a stamp that is in both counted as often as the
     * list that has it most often does.
     * @param float[] $one
     * @param float[] $other
     * @return float[]
     */
    private static function together(array $one, array $other): array
    {
        $count = static function (array $stamps): array {
            $found = [];
            foreach ($stamps as $stamp) {
                $key = (string) json_encode($stamp);
                $found[$key] = [$stamp, ($found[$key][1] ?? 0) + 1];
            }

            return $found;
        };
        $all = $count($one);
        foreach ($count($other) as $key => [$stamp, $times]) {
            $all[$key] = [$stamp, max($times, $all[$key][1] ?? 0)];
        }
        $stamps = [];
        foreach ($all as [$stamp, $times]) {
            for ($n = 0; $n < $times; $n++) {
                $stamps[] = $stamp;
            }
        }
        sort($stamps);

        return $stamps;
    }

    // The lock is a folder, because making one either happens or does not on
    // every system and in every language that keeps this file.

    private function lock(): bool
    {
        $folder = $this->path . '.lock';
        $deadline = hrtime(true) / 1e9 + 5.0;

        while (true) {
            if (@mkdir($folder)) {
                return true;
            }
            if (!is_dir($folder)) {
                // Not there and not to be made: the folder it would stand in is missing.
                return false;
            }
            clearstatcache(true, $folder);
            $made = @filemtime($folder);
            if ($made !== false && time() - $made > self::STALE) {
                @rmdir($folder);

                continue;
            }
            if (hrtime(true) / 1e9 >= $deadline) {
                return false;
            }
            usleep(50000);
        }
    }

    private function unlock(): void
    {
        @rmdir($this->path . '.lock');
    }

    /** @return float[] */
    private function read(): array
    {
        $text = @file_get_contents($this->path);
        $found = is_string($text) ? json_decode($text, true) : null;

        if (!is_array($found) || !array_is_list($found)) {
            return [];
        }

        return array_values(array_map('floatval', array_filter($found, static fn (mixed $stamp): bool => is_int($stamp) || is_float($stamp))));
    }

    /** @param float[] $stamps */
    private function write(array $stamps): void
    {
        $text = json_encode(array_values($stamps));

        if (!is_string($text) || @file_put_contents($this->path . '.tmp', $text) === false) {
            return;
        }

        @rename($this->path . '.tmp', $this->path);
    }

    /**
     * What stands in front of the network while the live tests run: it takes a
     * turn for an open or a close of a thread, and hands the call on as it came.
     */
    public static function forwarder(string $host, self $pace, callable $plain): \Closure
    {
        return static function (string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 40) use ($host, $pace, $plain): array {
            if (self::counted($method, $url, $host)) {
                $pace->take();
            }

            return $plain($method, $url, $body, $headers, $timeout);
        };
    }

    /** Puts the pace in front of every request this process makes to the service at $host. */
    public static function install(string $host, ?self $pace = null): self
    {
        $pace ??= new self();
        Http::$override = self::forwarder($host, $pace, static function (string $method, string $url, ?string $body, array $headers, int $timeout): array {
            // The door is opened for the length of one call, and this stands in it again after.
            $standing = Http::$override;
            Http::$override = null;

            try {
                return Http::call($method, $url, $body, $headers, $timeout);
            } finally {
                Http::$override = $standing;
            }
        });

        return $pace;
    }
}
