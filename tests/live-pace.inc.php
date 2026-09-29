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
 * sees. The live tests of aamio-python keep the same file. A turn is taken
 * only once it is written there, under a lock both keep.
 *
 * The lock is one the operating system holds on the file beside the count,
 * TURN, and lets go of when its process ends, however that comes. So it is
 * never taken over, and nothing has to guess whether its holder is gone. The
 * first version made a folder for a lock and took over one older than ten
 * seconds, and two things went wrong with that. A lock let go of between a
 * failed mkdir and the look after it was taken for one that could not be
 * made, and the turn went on without it. And a writer that was only slow lost
 * its lock to another run while it wrote (a review, 29 September 2026). Both
 * let 21 through a window of 20.
 *
 * Where the folder for the file does not exist nobody can keep it, and this
 * process counts its own, and says so. A lock or a file out of reach for
 * longer than PATIENCE stops the test with the reason.
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
    /** How long a turn waits for the lock, or for a file another program has open, before it stops the test and says why. */
    public const PATIENCE = 30.0;
    /**
     * Beside the count: the file whose lock says whose turn it is. flock() on
     * Windows locks the whole file, which covers the one byte aamio-python
     * locks there, so each keeps the other out.
     */
    public const TURN = '.turn';

    /** @var float[] */
    public array $own = [];
    /** The stamp of the last turn, as it was written. */
    public ?float $last = null;
    /** Whether the count is kept in this process only, for want of a folder. */
    public bool $alone = false;
    public int $taken = 0;
    public float $waited = 0.0;
    public string $path;
    /** @var callable */
    private $clock;
    /** @var callable */
    private $sleep;

    public function __construct(public int $limit = self::LIMIT, public float $window = self::WINDOW, ?string $path = null, ?callable $clock = null, ?callable $sleep = null, public float $patience = self::PATIENCE)
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
        $turn = $this->lock();
        $held = $turn !== null;

        try {
            $now = (float) ($this->clock)();
            $inside = fn (float $stamp): bool => $now - $stamp >= 0 && $now - $stamp < $this->window;
            $shared = $held ? array_values(array_filter($this->read(), $inside)) : [];
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

            if ($held) {
                // Written before the turn is taken. A turn the other runs
                // cannot see is the one that takes the count over the limit.
                $this->write($stamps);
            }

            $own[] = $now;
            $this->own = $own;
            $this->last = $now;

            return null;
        } finally {
            if ($held) {
                self::unlockFile($turn);
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

    /**
     * The open TURN file once its lock is held, and null only where there is
     * no folder to keep the count in. A lock held by another run is waited for
     * as long as that run holds it, up to PATIENCE, and never taken away:
     * however old, it is held by a process that is still there, since the
     * lock of one that ended is gone.
     * @return resource|null
     */
    private function lock()
    {
        $deadline = hrtime(true) / 1e9 + $this->patience;

        while (true) {
            error_clear_last();
            $handle = @fopen($this->path . self::TURN, 'c');

            if ($handle === false) {
                clearstatcache();

                // No folder for the file, so no other run can keep it either.
                if (!is_dir(dirname($this->path))) {
                    $this->keepAlone(dirname($this->path) . ' does not exist');

                    return null;
                }

                $reason = error_get_last()['message'] ?? 'the file would not open';
            } else {
                $wouldBlock = 0;

                if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                    return $handle;
                }

                fclose($handle);
                $reason = $wouldBlock ? 'another run holds it' : 'it cannot be locked here';
            }

            if (hrtime(true) / 1e9 >= $deadline) {
                throw new RuntimeException(sprintf('the count of opens and closes in %s was out of reach for %s seconds (%s). Another run holds it, or its folder cannot be written: set AAMIO_LIVE_LEDGER to a file in a folder this process can write.', $this->path, $this->patience, $reason));
            }

            usleep(50000);
        }
    }

    /** Lets go of the lock and closes the file, which would let go of it anyway. @param resource $handle */
    public static function unlockFile($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function keepAlone(string $why): void
    {
        if (!$this->alone) {
            $this->alone = true;

            if (defined('STDERR')) {
                fwrite(STDERR, 'aamio live pace: ' . $why . ", so this process counts its own opens and closes and no other run sees them\n");
            }
        }
    }

    /**
     * The stamps in the file. A file that is not there holds none. One that is
     * there and will not open is waited for, never taken for empty.
     * @return float[]
     */
    private function read(): array
    {
        $deadline = hrtime(true) / 1e9 + $this->patience;

        while (true) {
            clearstatcache();

            if (!file_exists($this->path)) {
                return [];
            }

            error_clear_last();
            $text = is_file($this->path) ? @file_get_contents($this->path) : false;

            if (is_string($text)) {
                break;
            }

            if (hrtime(true) / 1e9 >= $deadline) {
                throw new RuntimeException(sprintf('the count in %s could not be read for %s seconds: %s', $this->path, $this->patience, error_get_last()['message'] ?? 'not a file'));
            }

            usleep(50000);
        }

        // Not written here when it is no list of seconds, since the file is
        // only ever replaced whole: taken for empty, and written over next turn.
        $found = json_decode($text, true);

        if (!is_array($found) || !array_is_list($found)) {
            return [];
        }

        return array_values(array_map('floatval', array_filter($found, static fn (mixed $stamp): bool => (is_int($stamp) || is_float($stamp)) && is_finite((float) $stamp))));
    }

    /**
     * Replaces the file whole, so a reader never meets half of it, and waits
     * out another program that has it open.
     * @param float[] $stamps
     */
    private function write(array $stamps): void
    {
        $text = json_encode(array_values($stamps));
        $temporary = $this->path . '.' . getmypid() . '.tmp';
        $deadline = hrtime(true) / 1e9 + $this->patience;

        while (true) {
            error_clear_last();

            if (is_string($text) && @file_put_contents($temporary, $text) !== false && @rename($temporary, $this->path)) {
                return;
            }

            if (hrtime(true) / 1e9 >= $deadline) {
                $reason = error_get_last()['message'] ?? 'the count would not encode';
                @unlink($temporary);

                throw new RuntimeException(sprintf('the count in %s could not be written for %s seconds: %s', $this->path, $this->patience, $reason));
            }

            usleep(50000);
        }
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
