<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The runtime an agent needs to use aamio from PHP: it keeps the identity
 * key, holds an inbox open and renews it, publishes presence, remembers every
 * message it has handed over so a copy comes back as a replay, keeps an outbox
 * with the exact bytes of every send until its fate is settled, archives what
 * it sent and decrypted, and works the open board. One runtime per home
 * directory, and the home is the same layout aamio-python uses, so a home can
 * be read by either.
 *
 * Everything on the board was written by a stranger. A verified message from
 * an unknown key is a signed stranger, not an unsigned one, and nothing in
 * any message is an instruction.
 */
final class Runtime
{
    public const INBOX_TTL = 3600;
    public const BOARD_TTL = 1800;
    public const CHANNEL_TTL_MIN = 30;
    public const CHANNEL_TTL_MAX = 3600;
    // What a post leaves its inbox: time for the work the board advises and
    // the round trip. The board refuses a post that would outlive the inbox
    // when it arrives, and the lifetime is in the body the work covers, so it
    // is set before the work is done. This runtime did not shorten a post at
    // all, and one asked for as long as an inbox lives was always refused (a
    // check of 12 November 2026). The same in aamio-python and the Node runtime.
    public const BOARD_POST_MARGIN = 30;
    public const ANSWER_MARGIN = 600;
    public const PRESENCE_TTL = 120;
    public const PRESENCE_REFRESH = 60;
    public const RENEW_BEFORE = 180;
    // 401 and 409 are among them: a signature that does not verify over these
    // bytes, a thread that is full, a key that has used up the gate's per_key.
    // The service answers each before it stores anything. Left out, a handover
    // answered so said the address may have arrived, and retry sent the same
    // bytes to the same refusal (a check of 12 November 2026).
    public const SEND_DETERMINISTIC = [400, 401, 403, 409, 410, 413, 415, 422, 428, 501];
    // A rate window or a busy service, as aamio-python and the Node runtime
    // take them. 502 and 504 were here as well, and a message the service may
    // have stored before the gateway gave up was said to be declined for now
    // (a check of 12 November 2026).
    public const SEND_TRY_LATER = [429, 503];
    /**
     * Answers that prove the message was never stored: the deterministic ones, and a
     * rate window, which aamio decides before it reads the body. 502, 503 and 504 are
     * deliberately not here -- they can be a front door that saw nothing or the
     * service failing after it had written, and this side cannot tell which.
     */
    public const SEND_NOT_STORED = [400, 401, 403, 409, 410, 413, 415, 422, 428, 501, 429];
    public const VERIFYUM_MCP = Hosts::VERIFYUM_MCP;

    /** Only the spellings seen in the wild, and only for an answer to a post. */
    private const ANSWER_ALIASES = ['post' => ['post_id', 'postId'], 'reply_to' => ['replyTo', 'w', 'reply_address'], 'text' => ['reply', 'message']];

    /**
     * Files a runtime writes back, and the JSON each holds. One that is there
     * and cannot be read stops the runtime before anything is saved over it: a
     * save is how a broken file becomes a lost one, with the channels,
     * partners or scope keys in it.
     */
    private const KEPT_FILES = ['partners.json' => 'list', 'scopes.json' => 'list', 'state.json' => 'object', 'outbox.json' => 'object', 'effects.json' => 'object'];

    private const SCOPE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    // What a scope share says when scopes.json could not be written, and attention
    // says why. Shorter than the form a kept share takes, which a share is
    // weighed at when that is the longer.
    private const SCOPE_NOT_SAVED = 'not kept: could not be saved';

    public readonly string $home;
    public readonly string $host;
    public readonly Keys $keys;
    public readonly Client $client;
    public readonly Board $board;
    /** Where a receipt is anchored: AAMIO_VERIFYUM, or Hosts::VERIFYUM_MCP without it. */
    public readonly string $verifyum;
    /** @var string[] */
    public array $tags;
    /** @var array<int, array{name:string,key:string}> */
    public array $partners;
    /** @var array<int, array{name:string,key:?string,address:string}> scopes by name; the keys stay in scopes.json */
    public array $scopes;
    /** @var array<int, mixed> entries of scopes.json this runtime cannot use, written back as they were */
    private array $scopesAside = [];
    /** @var array<string,string> write address => partner key */
    public array $peers;
    /** Deliveries this process wrote to the archive at a read that has not written its cursor past them, by Codec::json([w, instance, seq]): the archive file each went to. */
    private array $writtenDown = [];
    /** What earlier processes wrote to each channel's archive, from the end of the file: [rows by Codec::json([w, instance, seq]) => sha256, the file's device and inode, its size]. */
    private array $archiveBefore = [];
    /** How far back from the end of a channel's archive a read looks for a delivery an earlier process wrote down and did not get past with its cursor. */
    public int $archiveTailBytes = 16777216;
    /** How often a delivery was handed over here with a row an earlier process had written, by Codec::json([w, instance, seq]): copies held here that wrote none. */
    private array $rowsReused = [];
    /** @var array<string, Channel> */
    public array $channels = [];
    /** @var array<string, array> */
    public array $outbox;
    /** @var array<string, array> */
    public array $effects;
    /**
     * @var array<string, array> per key, what was sent and what came back, as
     * hashes and shapes and never content; trace.json
     */
    public array $traces = [];
    public bool $archiveEnabled;
    /** What this home does with its archive: keep, off, or so many days. */
    public array $archivePolicy;
    /**
     * After close() the home may be another process's, so nothing more is
     * written from here: a save then would be a save over somebody else's.
     */
    public bool $homeReleased = false;
    private float $prunedAt = 0.0;
    /** How deep this process is in reads: one read for the archive is the outermost. */
    private int $readDepth = 0;
    /** @var callable */
    public $log;
    /**
     * What a read could not do, kept by channel and state until it is handed
     * to a caller. An empty read means nothing arrived. An empty read on a
     * thread that has expired, or that the service would not answer for,
     * means something else entirely, and both used to look the same.
     *
     * @var array<string, array>
     */
    public array $attention = [];
    private float $presenceAt = 0.0;
    /**
     * The last attempt and the last success are two different times: a failed
     * publish used to count as a fresh one, and the new address went
     * unpublished for a minute while the old one was still announced.
     */
    private float $presenceOkAt = 0.0;
    private bool $presenceFailed = false;
    private float $presenceRetryAt = 0.0;
    private float $presenceBackoff = 0.0;
    /** Whether the older inbox generations have been judged against the address book in this process. */
    private bool $generationsJudged = false;
    /** @var array<string, array> */
    private array $gates = [];
    /**
     * How many times this process has handed a message to the client to post,
     * counted just before it does. A handover that fails tells from it whether
     * anything could have left this machine.
     */
    private int $posts = 0;
    /**
     * Homes locked by this process, for a plainer message. The lock on
     * OWNER_LOCK keeps out a second runtime in this process as well.
     * @var array<string, true>
     */
    private static array $lockedHomes = [];
    /** The file in a home that the operating system holds locked for its owner. aamio-python locks the same file. */
    public const OWNER_LOCK = 'owner.lock';
    /** @var resource|null OWNER_LOCK, open and locked for as long as this runtime runs. */
    private $ownerLock = null;
    /** Whether this runtime took the home, so its close() gives back only what it took. */
    private bool $ownsHome = false;
    /**
     * A stand-in for flock() on OWNER_LOCK, for tests: a callable taking
     * (resource, int, &int) and returning bool, as flock() does. Null in
     * production. A lock that fails without another runtime holding it cannot
     * be made to happen on purpose on every system, and the refusal it leads
     * to has to hold all the same.
     * @var callable|null
     */
    public static $lockStandIn = null;

    /**
     * $archive null is the home's own choice, kept in config.json; false turns
     * the archive off for this process, which is what --no-archive does, and
     * true turns it on whatever the home says. It used to be on unless every
     * command said otherwise.
     */
    public function __construct(?string $home = null, ?string $host = null, ?array $tags = null, ?bool $archive = null, ?callable $log = null)
    {
        $this->home = $home ?: self::homeDir();
        $this->host = rtrim($host ?: (getenv('AAMIO_HOST') ?: Hosts::DEFAULT_HOST), '/');
        $this->log = $log ?: static function (string $line): void {
        };
        Storage::makePrivateDir($this->home);
        Storage::makePrivateDir($this->home . DIRECTORY_SEPARATOR . 'archive');
        $this->archivePolicy = Storage::readPolicy($this->home);
        $this->archiveEnabled = $archive ?? ($this->archivePolicy['mode'] !== 'off');
        $this->takeLock();
        try {
            $this->load($tags);
            $this->tidy();
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** What the home needs before the first read: no leftovers, private files, no archive past its lifetime. */
    private function tidy(): void
    {
        foreach (Storage::leftovers($this->home) as $path) {
            // A write that was interrupted. The file it was to replace is
            // whole, since the rename never happened.
            @unlink($path);
        }
        foreach (Storage::tighten($this->home) as $path) {
            $this->logged('made private: ' . $path . ' (an older version wrote it with the default mode)');
        }
        if (!Storage::isWindows()) {
            // Mode bits cost nothing to read. On Windows it is the access list
            // that decides and reading it starts a shell, so that is left to
            // `aamio doctor` and `aamio init`.
            $found = Storage::check($this->home);
            foreach (array_slice($found['findings'], 0, 5) as $finding) {
                // Who can read the decrypted archive is not a detail to mention once
                // into a logger a caller may never have set. It goes where every
                // other thing a caller has to hear about goes.
                $this->noteTrouble(
                    'home',
                    'unsafe',
                    ($finding['path'] ?? $this->home) . ': ' . $finding['problem'] . '. ' . ($found['fix'] ?? '')
                );
            }
        }
        $this->prune();
    }

    /**
     * Tidies the archive when an hour has passed since it was tidied last,
     * and never within a read: one read() over two channels tidied between
     * them, took the first channel's row and still said archived of it (the
     * fifth release check of 14 November 2026).
     */
    private function pruneWhenDue(): void
    {
        if ($this->readDepth === 0 && microtime(true) - $this->prunedAt > 3600) {
            $this->prune();
        }
    }

    /**
     * A read as its caller asked for it: it tidies first, when that is due,
     * and nothing within it tidies. The channels it reads one by one, the
     * reads within it and the rows it writes are one read for the archive, so
     * what it says of each message is so when it returns.
     *
     * @param callable(): array $read
     */
    private function outerRead(callable $read): array
    {
        if ($this->readDepth === 0 && $this->archiveEnabled && !$this->homeReleased) {
            $this->pruneWhenDue();
        }
        $this->readDepth++;
        try {
            return $read();
        } finally {
            $this->readDepth--;
        }
    }

    private function prune(): void
    {
        if (!$this->archiveEnabled || !(($this->archivePolicy['days'] ?? null) || ($this->archivePolicy['max_mb'] ?? null))) {
            return;
        }
        $this->prunedAt = microtime(true);
        try {
            $gone = Storage::prune($this->home, $this->archivePolicy);
            // What the end of each file held may be gone with it, and with it a
            // row this process wrote at a read whose cursor was not written:
            // the next read looks in the file again.
            $this->archiveBefore = [];
            $this->writtenDown = [];
        } catch (\Throwable $error) {
            // The archive keeps growing, in plain text, and the policy meant to
            // bound it did not run.
            $this->noteTrouble(
                'archive',
                'unpruned',
                'the archive was not pruned: ' . $error->getMessage()
                . '. It keeps growing, and what is in it stays readable to whoever can read this folder.'
            );

            return;
        }
        if ($gone['removed'] > 0) {
            $this->logged('archive: ' . $gone['removed'] . ' record(s) past their lifetime removed');
        }
    }

    /** The home's own choice, written where the next process finds it. */
    public function setArchive(array $policy): void
    {
        $this->saveJson('config.json', ['archive' => $policy]);
        $this->archivePolicy = $policy;
        $this->archiveEnabled = $policy['mode'] !== 'off';
        $this->prune();
    }

    private function load(?array $tags): void
    {
        $this->keys = $this->loadOrCreateKeys();
        $this->client = new Client($this->host, $this->keys);
        $this->board = new Board($this->client, getenv('AAMIO_BOARD') ?: Hosts::DEFAULT_BOARD);
        $this->verifyum = rtrim(getenv('AAMIO_VERIFYUM') ?: Hosts::VERIFYUM_MCP, '/');
        $this->partners = array_values(array_filter((array) $this->loadJson('partners.json', []), static fn ($p) => is_array($p) && isset($p['name'], $p['key'])));
        [$this->scopes, $this->scopesAside] = $this->usableScopes((array) $this->loadJson('scopes.json', [], false));
        $state = (array) $this->loadJson('state.json', []);
        $envTags = array_values(array_filter(explode(',', (string) (getenv('AAMIO_TAGS') ?: ''))));
        $this->tags = $tags ?? ((array) ($state['tags'] ?? []) ?: $envTags);
        $this->peers = (array) ($state['peers'] ?? []);
        foreach ((array) ($state['channels'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['expire_at'] ?? 0) > time()) {
                $channel = Channel::fromState($item);
                $held = $this->channels[$channel->label] ?? null;
                if ($held !== null) {
                    if ($held->expireAt > $channel->expireAt) {
                        $this->retireChannel($channel);
                        continue;
                    }
                    $this->retireChannel($held);
                }
                $this->channels[$channel->label] = $channel;
            }
        }
        $this->outbox = (array) $this->loadJson('outbox.json', []);
        $this->effects = (array) $this->loadJson('effects.json', []);
        // Not a kept file: a trace that cannot be read starts again, and is no
        // reason to stop. And read field by field, as if anyone could have
        // written it, with objects kept apart from lists (R3).
        $this->traces = self::normalizeTraces($this->loadJson('trace.json', [], false), self::TRACE_KEEP, self::TRACE_KEYS);
        // Anything still pending was in flight when the last process stopped.
        // Whether it reached aamio is unknown, and it stays unknown until
        // somebody looks. Retrying is a decision, not a default.
        foreach ($this->outbox as &$entry) {
            if (($entry['status'] ?? null) === 'sending') {
                $entry['status'] = 'unknown';
                $entry['note'] = 'the process stopped while this was in flight';
            }
            // An attempt left open before this process, by this version or an
            // older one, stays open: a stop or a refusal from now on settles a
            // later attempt, not that one. Only a send in flight was marked on
            // load, so an unknown or attempted entry saved by an older version,
            // which had no such mark, was called refused after a 428 on its
            // retry and left the pending list though it may have landed (a check
            // of 30 September 2026). The Python runtime does the same on load.
            if (is_array($entry) && self::outboxOpen($entry)) {
                $entry['ever_open'] = true;
            }
        }
        unset($entry);
        if ($tags !== null && $tags !== array_values((array) ($state['tags'] ?? []))) {
            // Tags given on the command line stick, so the next command sees them.
            $this->saveState();
        }
    }

    public static function homeDir(): string
    {
        $env = getenv('AAMIO_HOME');
        if ($env) {
            return rtrim($env, '/\\');
        }
        $base = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . '.aamio';
    }

    // -------------------------------------------------------------- files --

    private function path(string $name): string
    {
        return $this->home . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * What a file holds, or $default when it is not there or empty. A file in
     * KEPT_FILES that is there and cannot be read, or holds something other
     * than the list or object it should, throws instead. $assoc false keeps
     * objects as objects, so they are written back exactly as they were.
     */
    private function loadJson(string $name, mixed $default, bool $assoc = true): mixed
    {
        $path = $this->path($name);
        $shape = self::KEPT_FILES[$name] ?? null;
        if (!file_exists($path)) {
            return $default;
        }
        $text = @file_get_contents($path);
        // Past the mark first: a file that holds a mark and nothing else is
        // an empty file, as the other two runtimes read it.
        if ($text !== false) {
            $text = Storage::pastMark($text);
        }
        if ($text === false || trim($text) === '') {
            if ($text === false && $shape !== null) {
                throw new CodedRuntime('state_unreadable', $this->unreadable($name, 'it would not open'));
            }

            return $default;
        }
        $raw = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($shape === null) {
                return $default;
            }
            throw new CodedRuntime('state_unreadable', $this->unreadable($name, 'not UTF-8 JSON'));
        }
        if ($shape !== null && ($shape === 'list' ? !is_array($raw) : !$raw instanceof \stdClass)) {
            throw new CodedRuntime('state_unreadable', $this->unreadable($name, 'not a JSON ' . $shape));
        }

        return $assoc ? json_decode($text, true) : $raw;
    }

    private function unreadable(string $name, string $why): string
    {
        return sprintf('%s could not be read (%s), so this runtime stops here rather than save over it. Repair the file, or move it away to start without what was in it.', $this->path($name), $why);
    }

    public function saveJson(string $name, mixed $value): void
    {
        if ($this->homeReleased) {
            // After close the home may be another process's. Writing here
            // would be writing over what that one holds.
            $this->logged($name . ' not written: this runtime is closed');

            return;
        }
        $path = $this->path($name);
        $tmp = $path . '.tmp';
        // Private from the first byte, not made so once it has been written.
        $mask = umask(0077);
        try {
            $written = @file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        } finally {
            umask($mask);
        }
        @chmod($tmp, 0600);
        // A write that failed says so. Quietly carrying on, a scope was reported
        // kept that was never on disk.
        if ($written === false || !@rename($tmp, $path)) {
            throw new CodedRuntime('not_saved', 'could not write ' . $path);
        }
    }

    /**
     * One live runtime per home. Two would overwrite each other's state.
     *
     * The operating system holds a lock on OWNER_LOCK for as long as this
     * runtime runs, and lets go of it when the process ends, however it ends.
     * Taking it is one step. With the pid file alone, taking the home was
     * three steps, a read, a check and a write, and two runtimes started at
     * once could both read that nobody had it and both go on (a review, 29
     * September 2026). Where OWNER_LOCK cannot be opened or locked, as on a
     * network filesystem without locks, nothing proves the home free, and the
     * runtime stops with the reason instead of going on without it.
     *
     * The pid file is still written: it names the owner in the message a
     * second runtime gives, and it is all that versions from before
     * OWNER_LOCK look at. os_lock in it says that its writer held the lock, so
     * a runtime that holds the lock now knows that writer is gone.
     */
    private function takeLock(?callable $probe = null): void
    {
        $real = realpath($this->home) ?: $this->home;
        if (isset(self::$lockedHomes[$real])) {
            throw new CodedRuntime('home_in_use', 'another aamio in this process is already using ' . $this->home);
        }
        if ($this->holdOwnerLock() === false) {
            // The owner writes its pid just after it takes the lock, so a
            // moment may pass with none, or an old one, there to name.
            $holder = $this->lockHolder();
            $named = is_int($holder['pid'] ?? null) ? ' (pid ' . $holder['pid'] . ')' : '';
            throw new CodedRuntime('home_in_use', sprintf('another aamio%s is using %s. Stop it, or use a different AAMIO_HOME.', $named, $this->home));
        }
        try {
            $this->checkOlderOwner($probe);
            $this->saveJson('lock', ['pid' => getmypid(), 'at' => time(), 'host' => $this->host, 'os_lock' => true]);
        } catch (\Throwable $error) {
            $this->letGoOfOwnerLock();
            throw $error;
        }
        self::$lockedHomes[$real] = true;
        $this->ownsHome = true;
    }

    /**
     * True once OWNER_LOCK is held, and false when another open file holds it,
     * in this process or in another. Anything else throws: held by nobody and
     * not by this one either, nothing proves the home free. 0.3.8 went on with
     * the pid file alone here, which two runtimes started at once could both
     * take, and said so only to a logger that does nothing unless the caller
     * sets one (a review, 30 September 2026).
     *
     * flock() on Windows locks the whole file, which covers the one byte
     * aamio-python locks there, so each keeps the other out. The file is never
     * removed: a lock on a file that was removed and made again is a lock on
     * another file, and each of two runtimes would hold one.
     */
    private function holdOwnerLock(): bool
    {
        $path = $this->path(self::OWNER_LOCK);
        $mask = umask(0077);
        try {
            error_clear_last();
            $handle = @fopen($path, 'c');
            $why = error_get_last()['message'] ?? 'the file would not open';
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new CodedRuntime('lock_unavailable', sprintf('cannot establish exclusive ownership of %s: %s could not be opened (%s). Restore file access, or use a different AAMIO_HOME on a local disk.', $this->home, $path, $why));
        }
        $wouldBlock = 0;
        error_clear_last();
        $locked = self::$lockStandIn !== null ? (self::$lockStandIn)($handle, LOCK_EX | LOCK_NB, $wouldBlock) : @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
        if ($locked) {
            $this->ownerLock = $handle;

            return true;
        }
        $why = error_get_last()['message'] ?? 'flock() refused without saying why';
        fclose($handle);
        if ($wouldBlock) {
            return false;
        }

        throw new CodedRuntime('lock_unavailable', sprintf('cannot establish exclusive ownership of %s: %s could not be locked (%s). Restore locking support, or use a different AAMIO_HOME on a local disk.', $this->home, $path, $why));
    }

    /** What the pid file says, for a message. Nothing when it cannot be read. */
    private function lockHolder(): ?array
    {
        try {
            $held = $this->loadJson('lock', null);
        } catch (\Throwable) {
            return null;
        }

        return is_array($held) ? $held : null;
    }

    /** An owner the operating system's lock cannot see: a version from before OWNER_LOCK, which writes the pid file alone. */
    private function checkOlderOwner(?callable $probe): void
    {
        $held = $this->loadJson('lock', null);
        if (!is_array($held) || !is_int($held['pid'] ?? null) || $held['pid'] === getmypid()) {
            return;
        }
        if (($held['os_lock'] ?? null) === true) {
            // Written by a runtime that held OWNER_LOCK. This one holds it now,
            // so that one has let go of it or ended: no pid needs asking about.
            return;
        }
        $alive = ($probe ?? self::pidAlive(...))($held['pid']);
        if ($alive === null) {
            throw new CodedRuntime('home_in_use', sprintf('could not determine whether aamio (pid %d) is still using %s; the lock is left untouched. Process inspection was unavailable or inconclusive. Stop the other runtime or restore process inspection, or use a different AAMIO_HOME. Do not remove the lock unless you have independently confirmed its owner has stopped.', $held['pid'], $this->home));
        }
        // The lock is written after its owner started. A process that
        // started later got the pid after the owner was gone.
        $started = $alive && (is_int($held['at'] ?? null) || is_float($held['at'] ?? null)) ? self::startedAt($held['pid']) : null;
        if ($alive && ($started === null || $started <= $held['at'] + 2)) {
            throw new CodedRuntime('home_in_use', sprintf('another aamio (pid %d) is using %s. Stop it, or use a different AAMIO_HOME. If no aamio is running, the one that took the lock stopped without letting go of it: delete %s and start again.', $held['pid'], $this->home, $this->path('lock')));
        }
    }

    private function letGoOfOwnerLock(): void
    {
        if (is_resource($this->ownerLock)) {
            @flock($this->ownerLock, LOCK_UN);
            fclose($this->ownerLock);
        }
        $this->ownerLock = null;
    }

    /** True is alive, false is proven gone, and null must never release a lock. */
    private static function pidAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return null;
        }
        if (function_exists('posix_kill')) {
            // Refused is not gone: EPERM is a process that belongs to someone else.
            if (@posix_kill($pid, 0)) {
                return true;
            }

            return match (posix_get_last_error()) {
                1 => true, // EPERM
                3 => false, // ESRCH
                default => null,
            };
        }
        if (is_dir('/proc')) {
            if (is_dir('/proc/' . $pid)) {
                return true;
            }
            // A restricted /proc can hide another owner's processes even
            // while showing ours. Absence here cannot prove that one is gone.
            return null;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // A filtered tasklist uses a localized sentence for no matches.
            // Read a complete CSV list instead, requiring our own PID as a
            // positive control before absence can mean that the owner is gone.
            // By its place in System32: by the bare name it was looked for in the
            // working directory first (a security review of 13 November 2026).
            return self::windowsPidAlive($pid, self::processStatusCommand([Storage::systemTool('tasklist.exe'), '/FO', 'CSV', '/NH']));
        }

        return null;
    }

    /** @param array{0:int,1:string,2:string}|null $result */
    private static function windowsPidAlive(int $pid, ?array $result): ?bool
    {
        if ($result === null || $result[0] !== 0 || trim($result[2]) !== '' || trim($result[1]) === '') {
            return null;
        }
        $pids = [];
        foreach (preg_split('/\r?\n/', trim($result[1])) ?: [] as $line) {
            // Five quoted CSV fields, with no ignored error text or broken row.
            if (preg_match('/^"(?:[^"\r\n]|"")*"(?:,"(?:[^"\r\n]|"")*"){4}$/D', $line) !== 1) {
                return null;
            }
            $row = str_getcsv($line, ',', '"', '');
            if ($row[0] === '' || preg_match('/^(?:0|[1-9][0-9]*)$/D', $row[1]) !== 1 || isset($pids[$row[1]])) {
                return null;
            }
            $pids[$row[1]] = true;
        }

        return isset($pids[(string) getmypid()]) ? isset($pids[(string) $pid]) : null;
    }

    /**
     * A short, bounded local inspection. Files rather than pipes keep Windows
     * pipe reads from blocking past the deadline. Each call owns its handles.
     * @param string[] $command
     * @return array{0:int,1:string,2:string}|null
     */
    private static function processStatusCommand(array $command, float $timeout = 3.0): ?array
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $mask = umask(0077);
        try {
            $out = @tmpfile();
            $err = @tmpfile();
        } finally {
            umask($mask);
        }
        $process = null;
        try {
            if (!is_resource($out) || !is_resource($err)) {
                return null;
            }
            $process = @proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
            if (!is_resource($process)) {
                return null;
            }
            $until = hrtime(true) / 1e9 + $timeout;
            do {
                $status = proc_get_status($process);
                if (!is_array($status) || (fstat($out)['size'] ?? 1048577) > 1048576 || (fstat($err)['size'] ?? 1048577) > 1048576) {
                    return null;
                }
                if (!$status['running']) {
                    rewind($out);
                    rewind($err);
                    $stdout = stream_get_contents($out, 1048577);
                    $stderr = stream_get_contents($err, 1048577);
                    if (!is_string($stdout) || !is_string($stderr) || strlen($stdout) > 1048576 || strlen($stderr) > 1048576) {
                        return null;
                    }

                    return [(int) $status['exitcode'], $stdout, $stderr];
                }
                if (hrtime(true) / 1e9 >= $until) {
                    return null;
                }
                usleep(10000);
            } while (true);
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (is_array($status) && $status['running']) {
                    @proc_terminate($process);
                }
                @proc_close($process);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_resource($err)) {
                fclose($err);
            }
        }
    }

    /** When the process with this pid started, in Unix seconds, where /proc says so, and null elsewhere. */
    private static function startedAt(int $pid): ?float
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        $boot = @file_get_contents('/proc/stat');
        $close = is_string($stat) ? strrpos($stat, ')') : false;
        if ($close === false || !is_string($boot) || preg_match('/^btime (\d+)$/m', $boot, $found) !== 1) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        if (!isset($fields[19]) || !ctype_digit($fields[19])) {
            return null;
        }
        $ticks = function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK') ? (int) posix_sysconf(POSIX_SC_CLK_TCK) : 100;

        return (int) $found[1] + (int) $fields[19] / max(1, $ticks);
    }

    private function loadOrCreateKeys(): Keys
    {
        $path = $this->path('key');
        if (file_exists($path)) {
            $text = @file_get_contents($path);
            if ($text === false) {
                throw new CodedRuntime('key_unreadable', $this->unreadable('key', 'it would not open'));
            }
            // Not past a mark, as the home's other files are: the key holds
            // the seed and nothing else, in all three runtimes.
            if (trim($text) !== '') {
                // A new key over it would be a new identity, and the old one
                // gone for good. Partners know this runtime by that key.
                if (preg_match('/^[0-9a-f]{64}$/D', trim($text)) !== 1) {
                    throw new CodedRuntime('key_unreadable', $this->unreadable('key', 'not a 64 character hex seed'));
                }

                return Keys::fromSeedHex(trim($text));
            }
        }
        $keys = Keys::generate();
        $mask = umask(0077);
        try {
            $written = @file_put_contents($path, bin2hex($keys->seed()) . "\n");
        } finally {
            umask($mask);
        }
        @chmod($path, 0600);
        if ($written === false) {
            throw new CodedRuntime('not_saved', 'could not write ' . $path);
        }

        return $keys;
    }

    public function saveState(): void
    {
        $this->saveJson('state.json', ['tags' => $this->tags, 'peers' => $this->peers, 'channels' => array_map(static fn (Channel $c): array => $c->toState(), array_values($this->channels))]);
    }

    private function saveOutbox(): void
    {
        $this->saveJson('outbox.json', $this->outbox === [] ? new \stdClass() : $this->outbox);
    }

    private function saveEffects(): void
    {
        $this->saveJson('effects.json', $this->effects === [] ? new \stdClass() : $this->effects);
    }

    /** Writes one record, and says whether it wrote one: a folder that keeps nothing is not a failure, and not a write either. */
    /**
     * What earlier processes wrote to this channel's archive, from the end of
     * the file. The end is enough for what an earlier process wrote and did
     * not get past with its cursor, which was written last: a read takes at
     * most a thread's megabyte of messages. A row further back is not found,
     * and that delivery is written down again. The rows are the ones the
     * archive's own reader counts, by its rules: past a byte order mark at the
     * start of the file, JSON. The first line of the end counts when it starts
     * where a line starts, and is passed by when it is the tail of a line cut
     * by where the end begins.
     *
     * Read again when the file is not the one that was read: replaced, as a
     * prune replaces it, or shorter. A row a prune had removed was still taken
     * as there, and the message said archived: true with no row behind it (the
     * release check of 14 November 2026).
     *
     * @return array<string, ?string>
     */
    private function archivedBefore(string $label): array
    {
        $path = $this->path('archive' . DIRECTORY_SEPARATOR . Storage::archiveName($label) . '.jsonl');
        clearstatcache(true, $path);
        $stat = @stat($path);
        $version = $stat === false ? null : [$stat['dev'], $stat['ino']];
        $held = $this->archiveBefore[$label] ?? null;
        if ($held !== null && $held[1] === $version && ($stat === false || $stat['size'] >= $held[2])) {
            return $held[0];
        }
        if ($held !== null) {
            // Another file now, or a shorter one: what this process wrote to it
            // at a read that did not get past its cursor is looked for in it again.
            $name = Storage::archiveName($label);
            foreach ($this->writtenDown as $delivery => $at) {
                if ($at === $name) {
                    unset($this->writtenDown[$delivery]);
                }
            }
        }
        $found = [];
        $size = 0;
        $handle = $stat === false ? false : @fopen($path, 'rb');
        if ($handle !== false) {
            try {
                $size = (int) (fstat($handle)['size'] ?? 0);
                $start = max(0, $size - $this->archiveTailBytes);
                // The byte before the end says whether its first line is whole.
                fseek($handle, max(0, $start - 1));
                $data = (string) stream_get_contents($handle);
            } finally {
                fclose($handle);
            }
            if ($start > 0) {
                $whole = substr($data, 0, 1) === "\n";
                $lines = explode("\n", substr($data, 1));
                if (!$whole) {
                    array_shift($lines);
                }
            } else {
                $lines = explode("\n", str_starts_with($data, "\xEF\xBB\xBF") ? substr($data, 3) : $data);
            }
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && ($row['kind'] ?? null) === 'received' && is_string($row['w'] ?? null) && is_string($row['instance'] ?? null) && is_int($row['seq'] ?? null)) {
                    $found[Codec::json([$row['w'], $row['instance'], $row['seq']])] = is_string($row['sha256'] ?? null) ? $row['sha256'] : null;
                }
            }
        }
        $this->archiveBefore[$label] = [$found, $version, $size];

        return $found;
    }

    public function archive(string $label, array $record): bool
    {
        if (!$this->archiveEnabled || $this->homeReleased) {
            return false;
        }
        // Before the row, never after it: a ceiling under one row took the row
        // just written, and the read said archived (the fourth release check of
        // 14 November 2026). Within a read, never: the outer read tidies before
        // it begins.
        $this->pruneWhenDue();
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        // A write that failed says so. file_put_contents warns and returns
        // false, so the caller used to record archived: true for a message
        // that never reached the disk, and the command line, one process per
        // command, could never read it again.
        // Private from its first byte, locked while it is written, and with a
        // newline first when the file does not end in one: a crash used to
        // leave a last line with no end, and the next record became part of it.
        // Under a name that is a file's name and nothing else: the label when
        // it can be one, and a name made of its hash when it cannot.
        Storage::appendPrivate($this->path('archive' . DIRECTORY_SEPARATOR . Storage::archiveName($label) . '.jsonl'), $line);

        return true;
    }

    /** @return string[] labels this runtime has an archive for, the ones a board inbox uses, whether the archive is on or off */
    private function archiveLabels(string $prefix = ''): array
    {
        // By the names the folder lists, and not by a pattern over its path: a
        // home in a folder with a bracket in its name is a pattern of its own
        // to glob, which then found no file there, and the answers written
        // down for a board inbox that had expired were left out of board
        // replies with nothing said (a check of 12 November 2026).
        $labels = [];
        foreach ((array) @scandir($this->path('archive')) as $file) {
            if (is_string($file) && str_ends_with($file, '.jsonl') && ($prefix === '' || str_starts_with($file, $prefix))) {
                $labels[] = substr($file, 0, -6);
            }
        }

        return $labels;
    }

    /**
     * Entries this client wrote down for a channel, oldest first; empty when
     * the file is not there. With the archive off nothing is written, and what
     * an earlier run wrote is still read: it stays the record of what arrived
     * until it is pruned. A process with the archive off did not look, and
     * listed an answer written down before it with the mark of its own later
     * reading (a check of 11 November 2026).
     */
    public function archived(string $label, string $kind): array
    {
        // The file the folder lists under this name, when it lists one, and
        // else the file archive() writes for this label. Board replies read
        // by the names the folder lists, so each file is read once: the one
        // an earlier version wrote under a label as it stood, a name with a
        // space in it, as well as the one written now. A name is the folder's
        // own or made here, never a path a caller gave.
        $name = in_array($label, $this->archiveLabels(), true) ? $label : Storage::archiveName($label);
        $path = $this->path('archive' . DIRECTORY_SEPARATOR . $name . '.jsonl');
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $this->noteTrouble('archive', 'unread', 'the archive ' . basename($path) . ' could not be read, so answers written down earlier are not in this result');

            return [];
        }
        $found = [];
        foreach ($lines as $number => $line) {
            $text = $number === 0 ? Storage::pastMark($line) : $line;
            $record = json_decode($text, true);
            if (is_array($record) && ($record['kind'] ?? null) === $kind) {
                if (is_array($record['body'] ?? null) && array_key_exists('data', $record['body'])) {
                    $written = json_decode($text, false);
                    if (($written->body ?? null) instanceof \stdClass && property_exists($written->body, 'data')) {
                        $record['body']['data'] = Codec::jsonValue($written->body->data);
                    }
                }
                $found[] = $record;
            }
        }

        return $found;
    }

    // ----------------------------------------------------------- partners --

    public function whoami(): array
    {
        $inbox = $this->channels['inbox'] ?? null;

        return ['key' => $this->keys->public, 'hash' => $this->keys->hash, 'hash_prefix' => $this->keys->hashPrefix, 'inbox' => $inbox?->w, 'inbox_expires_at' => $inbox?->expireAt, 'host' => $this->host, 'tags' => $this->tags];
    }

    /**
     * A partner into the address book, and into the inbox at once.
     *
     * The address book and the inbox used to drift apart: this wrote
     * partners.json and nothing else, the inbox kept the list it was opened
     * with for up to 57 minutes, and the partner just added was refused with
     * 403 at the address presence pointed to, which the owner never saw. Now
     * an inbox that does not name the key is replaced by one that does, and
     * the answer says which address the partner can write to.
     */
    public function partnerAdd(string $name, string $key): array
    {
        self::checkPartner($name, $key);
        foreach ($this->partners as $p) {
            if ($p['name'] !== $name && CaseFold::lower($p['name']) === CaseFold::lower($name) && $p['key'] !== $key) {
                throw new AmbiguousPartner(sprintf('%s is in the address book with another key, and %s differs from it only in upper and lower case, which would make a send by name a guess. Give %s exactly to replace its key, or choose another name.', $p['name'], $name, $p['name']));
            }
        }
        // The new book counts only once partners.json holds it. Put in place
        // first, a write that failed left this process sealing to the new key
        // and opening its inbox to it, and the next start back on the old one
        // (the release check of 9 November 2026, found in the Node runtime).
        $partners = array_values(array_filter($this->partners, static fn (array $p): bool => $p['name'] !== $name && $p['key'] !== $key));
        $partners[] = ['name' => $name, 'key' => $key];
        $this->saveJson('partners.json', $partners);
        $this->partners = $partners;

        return $this->inboxAfterChange('partner ' . $name . ' was added', ['partner' => $name, 'key' => $key]);
    }

    /**
     * A partner out of the address book, and out of the inbox at once.
     *
     * Forgetting the name is not a revocation: the service takes the removed
     * key's writes to the old address until that thread expires. So a new
     * inbox is opened without the key, and the old one is read until it
     * expires for the partners still in the book, who may not have the new
     * address yet, while what the removed key writes there is not handed
     * over. Once the last partner is gone the new inbox takes signed writes from any
     * key, each shown as an unknown one, rather than unsigned writes from
     * anyone at an address the partners were given.
     */
    public function partnerRemove(string $name): array
    {
        // A key or a name, as a send takes them: the refusal of an ambiguous
        // name says to give the key, and partner remove did not take one.
        $known = Codec::isKey($name) ? $this->partnerByKey($name) : $this->partnerByName($name);
        if ($known === null) {
            $inbox = $this->channels['inbox'] ?? null;

            return ['partner' => $name, 'known' => false, 'inbox' => $inbox?->w];
        }
        // The partner the name finds, as a send finds it: apart from upper and
        // lower case when only one is so named. Filtered by the name as given,
        // a name in another case was said to be known and removed nothing (a
        // check of 12 November 2026).
        $partners = array_values(array_filter($this->partners, static fn (array $p): bool => $p['name'] !== $known['name']));
        $this->saveJson('partners.json', $partners);
        $this->partners = $partners;

        return $this->inboxAfterChange('partner ' . $known['name'] . ' was removed', ['partner' => $known['name'], 'known' => true]);
    }

    /** Whether the inbox still matches the address book, and a new one if not. */
    private function inboxAfterChange(string $reason, array $outcome): array
    {
        $inbox = $this->channels['inbox'] ?? null;
        if ($inbox === null) {
            // Registration before the first read: the inbox opens with this
            // list when it opens, and there is no address to hand out yet.
            return $outcome + ['inbox' => null, 'rotated' => false];
        }
        if ($this->inboxMatches($inbox, $this->wantedAllow())) {
            return $outcome + ['inbox' => $inbox->w, 'rotated' => false, 'expire_at' => $inbox->expireAt];
        }

        return $outcome + $this->rotateInbox($reason, true);
    }

    // ------------------------------------------------ what an add-on may use --
    //
    // An add-on keeps what it keeps in this home and asks this runtime which key
    // it means. These are the ways in, so that an add-on never reaches into
    // channels and files by their private names. saveJson is the third.

    /** The path of a file of that name in this home. */
    public function homeFile(string $name): string
    {
        return $this->path($name);
    }

    /**
     * The keys that signed a message this process read and verified, by its
     * hash. The same bytes may carry more than one. What this process checked
     * lives in its memory and not in state.json, so a message read by an
     * earlier process is not here. An observation the channel's allowlist kept
     * out is: a signature that checked is not a permission.
     *
     * @return list<string>
     */
    public function verifiedSigners(string $sha256): array
    {
        $keys = [];
        foreach ($this->channels as $channel) {
            foreach ($channel->observed as $row) {
                if (($row['sha256'] ?? null) === $sha256 && ($row['verified'] ?? null) === true && !in_array($row['from_key'] ?? null, $keys, true)) {
                    $keys[] = $row['from_key'] ?? null;
                }
            }
        }

        return $keys;
    }

    public function partnerList(): array
    {
        return array_map(static fn (array $p): array => ['name' => $p['name'], 'key' => $p['key'], 'hash_prefix' => Keys::hashPrefixOf($p['key'])], $this->partners);
    }

    /**
     * The partner with this name: the one so named exactly, or the only one so
     * named apart from upper and lower case. Two of those and no exact one
     * throws AmbiguousPartner: a guess seals the message to the wrong key.
     */
    public function partnerByName(string $name): ?array
    {
        foreach ($this->partners as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }
        // Lowered as aamio-python and the Node runtime lower it, outside ASCII too.
        $folded = array_values(array_filter($this->partners, static fn (array $p): bool => CaseFold::lower($p['name']) === CaseFold::lower($name)));
        if (count($folded) > 1) {
            throw new AmbiguousPartner(sprintf('%d partners are called %s apart from upper and lower case (%s). Give the name exactly as it is in the address book, or the key.', count($folded), $name, implode(', ', array_column($folded, 'name'))));
        }

        return $folded[0] ?? null;
    }

    public function partnerByKey(?string $key): ?array
    {
        foreach ($this->partners as $p) {
            if ($p['key'] === $key) {
                return $p;
            }
        }

        return null;
    }

    public function nameForKey(?string $key): ?string
    {
        return $this->partnerByKey($key)['name'] ?? null;
    }

    // ------------------------------------------------------------- scopes --
    //
    // A scope keeps board posts unlisted for a group. Here each one has a name,
    // and the name is all a caller passes or sees. The key is the read
    // capability: it stays in scopes.json, and scopeShare hands it to a partner
    // sealed, so no model has to hold it. The address is the write capability
    // and is no secret. Unlisted is not private.

    /**
     * The entries of scopes.json this runtime can use, and the rest. The rest
     * are never dropped. They go back into the file on every save as they
     * were, so a hand edit with a typo is there to be corrected rather than
     * gone with the key in it.
     *
     * @param array<int, mixed> $entries as decoded with objects kept as objects
     */
    private function usableScopes(array $entries): array
    {
        $usable = [];
        $aside = [];
        $names = [];
        foreach ($entries as $entry) {
            $scope = $entry instanceof \stdClass ? json_decode((string) json_encode($entry), true) : null;
            $key = is_array($scope) ? ($scope['key'] ?? null) : null;
            if (is_array($scope)
                && is_string($scope['name'] ?? null) && preg_match(self::SCOPE_NAME, $scope['name']) === 1
                && !isset($names[CaseFold::lower($scope['name'])])
                && is_string($scope['address'] ?? null) && Address::isW($scope['address'])
                && ($key === null || (is_string($key) && Address::isScopeKey($key) && Address::scope($key) === $scope['address']))) {
                $usable[] = $scope;
                $names[CaseFold::lower($scope['name'])] = true;
            } else {
                $aside[] = $entry;
            }
        }
        // An invitation whose scope was removed is remembered among the rest,
        // where an earlier version keeps it too, and is no typo to report.
        $unusable = array_filter($aside, static fn (mixed $entry): bool => !self::isRemovedShare($entry));
        if ($unusable !== []) {
            $this->logged(sprintf('scopes.json: %d entries are not scopes this runtime can use, and stay in the file as they are', count($unusable)));
        }

        return [$usable, $aside];
    }

    /** How many invitations whose scope was removed scopes.json remembers. One is only read again while its thread lives, and the oldest go first. */
    private const SCOPE_REMOVED_KEEP = 256;

    /** An entry of scopes.json that remembers an invitation whose scope was removed, as aamio-python writes it too. */
    private static function isRemovedShare(mixed $entry): bool
    {
        $entry = $entry instanceof \stdClass ? (array) $entry : $entry;

        return is_array($entry) && array_keys($entry) === ['removed_share'] && is_string($entry['removed_share'])
            && preg_match('/^[0-9a-f]{64}:(?:[0-9a-f]{16}|-|\?)$/D', $entry['removed_share']) === 1;
    }

    /** @return array<string, true> */
    private function removedShares(): array
    {
        $removed = [];
        foreach ($this->scopesAside as $entry) {
            if (self::isRemovedShare($entry)) {
                $removed[((array) $entry)['removed_share']] = true;
            }
        }

        return $removed;
    }

    /** What an invitation is known by: the hash of its bytes and its signer, as seen_from records it, or null. */
    private static function shareRecord(array $entry): ?string
    {
        $digest = $entry['sha256'] ?? null;

        return is_string($digest) && preg_match('/^[0-9a-f]{64}$/D', $digest) === 1
            ? Channel::seenRecord($digest, is_string($entry['from_key'] ?? null) ? $entry['from_key'] : null)
            : null;
    }

    /** Writes the scopes, and only then holds them: a save that fails changes nothing. */
    private function saveScopes(array $scopes, ?array $aside = null): void
    {
        $aside ??= $this->scopesAside;
        $this->saveJson('scopes.json', [...$scopes, ...$aside]);
        $this->scopes = $scopes;
        $this->scopesAside = $aside;
    }

    private function scopeIndex(string $name): ?int
    {
        foreach ($this->scopes as $i => $scope) {
            if (CaseFold::lower($scope['name']) === CaseFold::lower($name)) {
                return $i;
            }
        }

        return null;
    }

    private function scopeNamed(string $name): array
    {
        $i = $this->scopeIndex($name);
        if ($i === null) {
            throw new CodedRuntime('unknown_scope', 'no scope called ' . $name . ' here. The scope list shows the ones this runtime holds');
        }

        return $this->scopes[$i];
    }

    private static function scopeView(array $scope): array
    {
        return ['name' => $scope['name'], 'address' => $scope['address'], 'can_read' => !empty($scope['key'])];
    }

    private static function scopeNameOk(mixed $name): string
    {
        if (!is_string($name) || preg_match(self::SCOPE_NAME, $name) !== 1) {
            throw new CodedInvalidArgument('invalid_scope', 'a scope name is 1 to 64 letters, digits, dots, dashes and underscores, starting with a letter or a digit');
        }

        return $name;
    }

    /** A new scope, its key from the CSPRNG. The key stays here. */
    public function scopeNew(mixed $name): array
    {
        $name = self::scopeNameOk($name);
        if ($this->scopeIndex($name) !== null) {
            throw new CodedInvalidArgument('scope_exists', 'there is a scope called ' . $name . ' already');
        }
        $key = Address::newScopeKey();
        $scope = ['name' => $name, 'key' => $key, 'address' => Address::scope($key)];
        $this->saveScopes([...$this->scopes, $scope]);

        return self::scopeView($scope);
    }

    /** A scope made elsewhere: the key, to read and post, or the address, to post only. */
    public function scopeAdd(mixed $name, mixed $key = null, mixed $address = null): array
    {
        [$held, $keep] = $this->scopeAddPlan($name, $key, $address);
        if ($keep !== null) {
            $this->saveScopes($keep);
        }

        return self::scopeView($held);
    }

    /** What scopeAdd would hold, and the list it would keep or null when nothing changes. Nothing is kept here. */
    private function scopeAddPlan(mixed $name, mixed $key = null, mixed $address = null): array
    {
        $name = self::scopeNameOk($name);
        if ($key === null && $address === null) {
            throw new CodedInvalidArgument('invalid_scope', 'give the key, to read and post, or the address, to post only');
        }
        if ($key !== null && (!is_string($key) || !Address::isScopeKey($key))) {
            throw new CodedInvalidArgument('invalid_scope', 'a scope key is 26 to 64 characters of a-z and 0-9. The 20 character address goes in address');
        }
        if ($address !== null && (!is_string($address) || !Address::isW($address))) {
            throw new CodedInvalidArgument('invalid_scope', 'a scope address is the 20 characters of a-z and 2-7 that go on a post');
        }
        $derived = $key !== null ? Address::scope($key) : $address;
        if ($address !== null && $derived !== $address) {
            throw new CodedInvalidArgument('invalid_scope', 'that key does not give that address, so one of the two is wrong');
        }
        $index = $this->scopeIndex($name);
        if ($index !== null && $this->scopes[$index]['address'] !== $derived) {
            throw new CodedInvalidArgument('scope_exists', 'there is a scope called ' . $name . ' already, with another address. Remove it or choose another name');
        }
        foreach ($index === null ? $this->scopes : [] as $i => $scope) {
            if ($scope['address'] === $derived) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $held = ['name' => $name, 'key' => $key, 'address' => $derived];

            return [$held, [...$this->scopes, $held]];
        }
        $held = $this->scopes[$index];
        if ($key !== null && empty($held['key'])) {
            // Held to post only until now. The key adds reading.
            $held['key'] = $key;
            $scopes = $this->scopes;
            $scopes[$index] = $held;

            return [$held, $scopes];
        }

        return [$held, null];
    }

    public function scopeList(): array
    {
        return array_map(static fn (array $scope): array => self::scopeView($scope), $this->scopes);
    }

    /**
     * Removes a scope, and remembers the invitations it came from in the same
     * write. The cursor and the hashes of what was read live in state.json, and
     * the scope in scopes.json. When state.json could not be written, the
     * invitation came again after a restart as a message never seen, and a
     * scope removed since was taken back (a check of 11 November 2026). Now the
     * invitation is remembered here, and the same one read again keeps nothing.
     * A new invitation is another message, and is taken.
     */
    public function scopeRemove(string $name): array
    {
        $scope = $this->scopeNamed($name);
        $scopes = $this->scopes;
        unset($scopes[$this->scopeIndex($name)]);
        $aside = $this->scopesAside;
        $removed = $this->removedShares();
        foreach ((array) ($scope['taken_from'] ?? []) as $share) {
            if (is_string($share) && !isset($removed[$share]) && self::isRemovedShare(['removed_share' => $share])) {
                $aside[] = ['removed_share' => $share];
                $removed[$share] = true;
            }
        }
        $kept = array_keys(array_filter($aside, static fn (mixed $entry): bool => self::isRemovedShare($entry)));
        foreach (array_slice($kept, 0, max(0, count($kept) - self::SCOPE_REMOVED_KEEP)) as $index) {
            unset($aside[$index]);
        }
        $this->saveScopes(array_values($scopes), array_values($aside));

        return ['removed' => $scope['name']];
    }

    /** The key itself, for a person to pass on by hand. The MCP server never calls this. */
    public function scopeKey(string $name): array
    {
        $scope = $this->scopeNamed($name);
        if (empty($scope['key'])) {
            throw new CodedInvalidArgument('scope_write_only', 'scope ' . $scope['name'] . ' is held to post only, so there is no key here');
        }

        return ['name' => $scope['name'], 'key' => $scope['key'], 'address' => $scope['address']];
    }

    /**
     * Hand a scope to a partner in a sealed message. read gives the key, write
     * only the address. Only to a partner in the address book, by name or key.
     * An address is learned from the board and from messages, so it can be
     * anyone's, and the key would be sealed to whoever it was learned from: a
     * post asking for a scope would get it.
     */
    public function scopeShare(string $name, string $to, mixed $access): array
    {
        $scope = $this->scopeNamed($name);
        self::checkAccess($access);
        if ($access === 'read' && empty($scope['key'])) {
            throw new CodedInvalidArgument('scope_write_only', 'scope ' . $scope['name'] . ' is held to post only, so it can only be shared with access write');
        }
        $partner = Codec::isKey($to) ? $this->partnerByKey($to) : $this->partnerByName($to);
        if ($partner === null) {
            throw new CodedInvalidArgument('unknown_partner', 'a scope is shared only with a partner in your address book, by name. Never with an address, which can be anyone\'s');
        }
        $share = $access === 'read' ? ['name' => $scope['name'], 'key' => $scope['key']] : ['name' => $scope['name'], 'address' => $scope['address']];
        [$w, $key] = $this->addressFor($partner['name']);
        // The archive keeps what was shared and with whom, never the key.
        $sent = $this->sendSealed($w, $key, $partner['name'], sprintf('Scope %s, shared to %s.', $scope['name'], $access === 'read' ? 'read and post' : 'post only'), ['aamio_scope' => $share], null, ['aamio_scope' => ['name' => $scope['name'], 'access' => $access]]);

        return $sent + ['scope' => $scope['name'], 'access' => $access];
    }

    /**
     * The name a scope from a partner is kept under: the partner's name, a dot
     * and the scope's. A partner names its own scopes, and a name is where
     * posts go. Kept under the bare name, a partner could take review before
     * review was made here, and the posts meant for it would go where that
     * partner reads.
     */
    private function sharedScopeName(array $partner, mixed $name): string
    {
        $name = self::scopeNameOk($name);
        $prefix = substr(trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $partner['name']), '._-'), 0, 24);
        if ($prefix === '') {
            $prefix = Keys::hashPrefixOf((string) $partner['key']);
        }

        return substr($prefix . '.' . $name, 0, 64);
    }

    /**
     * A scope in an incoming message. Kept only when it came sealed and
     * verified from a partner in the address book, and not seen before. The
     * key is taken out of the message either way, and aamio_scope is replaced
     * whatever it holds and wherever it sits, so whoever reads the message
     * never sees a key in it.
     */
    private function takeScopeShare(array &$entry, bool $apply = true, bool $weigh = false): void
    {
        if (!is_array($entry['body'] ?? null)) {
            return;
        }
        if (array_key_exists('aamio_scope', $entry['body'])) {
            $entry['body']['aamio_scope'] = ['kept' => false, 'note' => 'not kept: a scope is shared in data.aamio_scope'];
        }
        if (($entry['body']['data'] ?? null) instanceof \stdClass && property_exists($entry['body']['data'], 'aamio_scope')) {
            $entry['body']['data'] = json_decode((string) json_encode($entry['body']['data']), true);
        }
        if (!is_array($entry['body']['data'] ?? null) || !array_key_exists('aamio_scope', $entry['body']['data'])) {
            return;
        }
        $share = $entry['body']['data']['aamio_scope'];
        if (!is_array($share) || ($share !== [] && array_is_list($share))) {
            $entry['body']['data']['aamio_scope'] = ['kept' => false, 'note' => 'not kept: data.aamio_scope is an object with name, and key or address'];

            return;
        }
        $key = $share['key'] ?? null;
        $view = ['shared_as' => is_string($share['name'] ?? null) ? $share['name'] : null, 'can_read' => $key !== null, 'kept' => false];
        $partner = is_string($entry['from_key'] ?? null) && $entry['from_key'] !== '' ? $this->partnerByKey($entry['from_key']) : null;
        if (!$apply) {
            // Weighed, not taken: shown as it is when nothing is kept.
            $view['note'] = 'not kept: the key that signed it left the address book while the message was being read, so nothing from it is taken';
        } elseif (!(($entry['verified'] ?? false) && ($entry['encrypted'] ?? false) && ($entry['known_contact'] ?? false) && $partner !== null)) {
            $view['note'] = 'not kept: a scope is only taken when it comes sealed from a partner in your address book';
        } elseif ($entry['replay'] ?? false) {
            $view['note'] = 'not kept again: this message arrived before, and a scope removed since stays removed';
        } elseif ($entry['replay_unknown'] ?? false) {
            $view['note'] = 'not kept: these bytes arrived here before, recorded by an earlier version without who sent them, so this may be that message again, and a scope removed since stays removed';
        } elseif (isset($this->removedShares()[self::shareRecord($entry) ?? ''])) {
            $view['note'] = 'not kept again: this invitation was taken here before, and its scope was removed since. A scope removed stays removed: ask for it to be shared again';
        } else {
            try {
                $local = $this->sharedScopeName($partner, $share['name'] ?? null);
                $address = $key === null ? ($share['address'] ?? null) : null;
                [$held, $keep] = $this->scopeAddPlan($local, $key, $address);
                // Held already, nothing but the record of this invitation to write.
                $plain = $keep === null;
                // The invitation goes with the scope, in the same write, so that
                // removing the scope can remember it.
                $invitation = self::shareRecord($entry);
                if ($invitation !== null && !in_array($invitation, (array) ($held['taken_from'] ?? []), true)) {
                    $scopes = $keep ?? $this->scopes;
                    foreach ($scopes as $i => $scope) {
                        if ($scope['address'] === $held['address']) {
                            $scopes[$i]['taken_from'] = [...(array) ($scope['taken_from'] ?? []), $invitation];
                            $held = $scopes[$i];
                        }
                    }
                    $keep = $scopes;
                }
            } catch (\Throwable $error) {
                // This share alone, and nothing held that was not saved. The
                // rest of the messages are delivered either way.
                $view['note'] = 'not kept: ' . $error->getMessage();
                $entry['body']['data']['aamio_scope'] = $view;

                return;
            }
            $shown = array_merge($view, self::scopeView($held), ['kept' => true]);
            $unsaved = $view + ['note' => self::SCOPE_NOT_SAVED];
            if ($weigh) {
                // Weighed, nothing is kept, against the scopes the messages
                // before it left: two of one name were both weighed as kept, and
                // the second came with the longer note of a conflict, 4313 bytes
                // through a budget of 4268 (a review of 10 November 2026). It is
                // shown in the longer of the two forms it can take: kept, or not
                // saved.
                $view = strlen(Codec::json($shown)) >= strlen(Codec::json($unsaved)) ? $shown : $unsaved;
            } else {
                try {
                    if ($keep !== null) {
                        $this->saveScopes($keep);
                    }
                    $view = $shown;
                } catch (\Throwable $error) {
                    if ($plain) {
                        // Held from before, and shown as it is held: only the
                        // record of this invitation was not written. Said in
                        // attention, where the reader looks: it stood in the
                        // log alone, and the reader was told kept with nothing
                        // more (a check of 11 November 2026).
                        $seq = $entry['seq'] ?? null;
                        $this->noteTrouble(is_string($entry['channel'] ?? null) ? $entry['channel'] : 'scopes', 'scope_share_not_recorded', 'message ' . (is_int($seq) ? $seq : 'without a seq') . ' shared scope ' . (is_string($share['name'] ?? null) ? $share['name'] : '(unnamed)') . ', which is held here already as ' . $held['name'] . ', and the invitation could not be recorded with it in scopes.json: ' . (new \ReflectionClass($error))->getShortName() . ': ' . $error->getMessage() . '. The scope stays as it is. Removing it will not remember this invitation, so the same message read again after that would bring the scope back.', is_string($entry['w'] ?? null) ? $entry['w'] : null, is_int($seq) ? [$seq] : null);
                        $entry['body']['data']['aamio_scope'] = $shown;

                        return;
                    }
                    // Said in attention, with what went wrong, and on the
                    // message in the form it was weighed at.
                    $view = $unsaved;
                    $seq = $entry['seq'] ?? null;
                    $this->noteTrouble(is_string($entry['channel'] ?? null) ? $entry['channel'] : 'scopes', 'scope_not_saved', 'message ' . (is_int($seq) ? $seq : 'without a seq') . ' shared scope ' . (is_string($share['name'] ?? null) ? $share['name'] : '(unnamed)') . ', and scopes.json could not be written: ' . (new \ReflectionClass($error))->getShortName() . ': ' . $error->getMessage() . '. The scope is not kept.', is_string($entry['w'] ?? null) ? $entry['w'] : null, is_int($seq) ? [$seq] : null);
                }
            }
        }
        $entry['body']['data']['aamio_scope'] = $view;
    }

    // -------------------------------------------------------------- inbox --

    private function retireChannel(Channel $channel): void
    {
        $base = $channel->label . '-' . $channel->expireAt;
        $label = $base;
        $suffix = 1;
        while (isset($this->channels[$label]) && $this->channels[$label] !== $channel) {
            $label = $base . '-' . $suffix++;
        }
        $channel->label = $label;
        $this->channels[$label] = $channel;
    }

    /**
     * The list the inbox should carry now: the partners' keys, or none while
     * there are no partners, since a first contact has nobody to name.
     */
    private function wantedAllow(): ?array
    {
        return $this->partners !== [] ? array_map(static fn (array $p): string => $p['key'], $this->partners) : null;
    }

    private function inboxMatches(Channel $inbox, ?array $wanted): bool
    {
        $have = array_values($inbox->allow);
        // No partners: an inbox open to anyone matches, and so does one that
        // takes any signed key, which is what the last removal leaves behind.
        if ($wanted === null) {
            return $have === [] || $have === ['*'];
        }
        $wanted = array_values($wanted);
        sort($have);
        sort($wanted);

        return $have === $wanted;
    }

    /**
     * A new inbox with the list as it is now; the old one is dealt with by
     * what changed.
     *
     * Keys only added, or an old inbox open to anyone: the old one is still
     * read until it expires, since whoever was told the address may still
     * write there, and an open one is said to be open until then. A key
     * removed: the old one is marked muted and read until it expires for the
     * partners still in the book, and nothing from the removed key arrives
     * through the address it was given. That is this runtime's delivery
     * stopping, not a revocation: the service takes the write until the
     * thread expires.
     *
     * A rotation that fails leaves the old inbox in use, and says so.
     */
    private function rotateInbox(string $reason, bool $caller = false): array
    {
        $old = $this->channels['inbox'] ?? null;
        $wanted = $this->wantedAllow();
        if ($wanted === null && $old !== null && $old->allow !== []) {
            // The last partner is gone. An inbox open to anyone would take
            // unsigned writes at an address the partners had: signed only, and
            // every message shown as an unknown key.
            $wanted = ['*'];
        }
        $opened = $this->client->open(self::INBOX_TTL, $wanted);
        if ($opened['status'] !== 201) {
            $what = 'the inbox could not be opened again after ' . $reason . ': ' . $opened['status'] . ' ' . json_encode($opened['body']) . '. The old inbox stays in use with its old list.';
            $this->noteTrouble('inbox', 'rotation_failed', $what, $old?->w);

            return ['rotated' => false, 'inbox' => $old?->w, 'allow' => $this->allowNames($old?->allow ?? []), 'error' => $what];
        }
        $fresh = new Channel('inbox', $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], $wanted ?? []);
        $outcome = ['rotated' => true, 'inbox' => $fresh->w, 'allow' => $this->allowNames($fresh->allow), 'expire_at' => $fresh->expireAt];
        // The new inbox, and what it changes in the older ones, count only once
        // state.json holds them. Kept when the save failed, the next read
        // published an address whose read key this home did not have after a
        // restart, and a partner wrote where nobody could read (the release
        // check of 9 November 2026, found in the Node runtime).
        $before = $this->channels;
        $flags = array_map(static fn (Channel $c): array => [$c, $c->label, $c->muted], array_values($before));
        $noted = $this->attention;
        if ($old !== null) {
            $this->retireChannel($old);
        }
        $this->channels['inbox'] = $fresh;
        // Every generation is judged, not only the one just retired: an inbox
        // from two rotations ago that still named the removed key was read on
        // until it expired, and delivered that key's messages as an unknown
        // contact.
        $muted = $this->muteGenerations($reason);
        if ($old !== null) {
            $outcome['old_inbox'] = ['w' => $old->w, 'muted' => $old->muted, 'until' => $old->expireAt];
            if (!$old->muted && $old->allow === []) {
                // In the answer of the call that changed the book, since partner
                // add on the command line is a process of its own and its notes
                // were gone before the next read; in the notes of a read otherwise.
                if ($caller && $fresh->allow !== []) {
                    $who = in_array('*', $fresh->allow, true) ? 'any signed key' : 'the ' . count($fresh->allow) . ' key(s) in the address book';
                    $outcome['old_inbox'] += ['open' => true, 'note' => sprintf('inbox %s was open to anyone, signed or not, and is still read until %d: whoever has that address can write there until then. After %s the new inbox %s takes writes from %s only.', $old->w, $old->expireAt, $reason, $fresh->w, $who)];
                } else {
                    $this->note($old, 'open', sprintf('inbox %s was open to anyone and is still read until %d; after %s the new inbox %s names %d key(s)', $old->w, $old->expireAt, $reason, $fresh->w, count($fresh->allow)));
                }
            }
        }
        if ($muted !== []) {
            $outcome['muted'] = $muted;
        }
        try {
            $this->saveState();
        } catch (\RuntimeException $error) {
            $this->channels = $before;
            foreach ($flags as [$channel, $label, $wasMuted]) {
                $channel->label = $label;
                $channel->muted = $wasMuted;
            }
            $this->attention = $noted;
            $what = 'the inbox opened again after ' . $reason . ' could not be written down (' . $error->getMessage() . '), so it is not used: a partner would write where this home cannot read after a restart. '
                . ($old !== null ? 'The old inbox stays in use with its old list.' : 'There is no inbox until state.json can be written.');
            $this->noteTrouble('inbox', 'rotation_failed', $what, $old?->w);

            return ['rotated' => false, 'inbox' => $old?->w, 'allow' => $this->allowNames($old?->allow ?? []), 'error' => $what];
        }
        $this->logged(sprintf('inbox %s until %d, %s%s', $fresh->w, $fresh->expireAt, $reason, $fresh->allow !== [] ? ' (allowlist ' . count($fresh->allow) . ' keys)' : ''));
        $outcome['presence'] = $this->publishPresence(true);

        return $outcome;
    }

    private function allowNames(array $allow): array
    {
        return array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, array_values($allow));
    }

    /**
     * Every inbox generation that names a key no longer in the address book is
     * marked muted. It is still read until it expires: what the partners still
     * in the book write there is handed over, and what a removed key writes
     * there is not. Read no more, as it was, a partner that had not yet learned
     * the new address wrote where nobody read (a check of 12 November 2026).
     * It is this runtime's delivery stopping for that key, not a revocation;
     * the service takes the removed key's writes until the thread expires. An
     * open generation, or one that takes any signed key, names nobody and is
     * left as it is.
     */
    private function muteGenerations(string $reason): array
    {
        $partners = array_map(static fn (array $p): string => $p['key'], $this->partners);
        $current = $this->channels['inbox'] ?? null;
        $muted = [];
        foreach ($this->channels as $channel) {
            if ($channel === $current || $channel->muted || !str_starts_with($channel->label, 'inbox')) {
                continue;
            }
            $gone = array_values(array_filter($channel->allow, static fn (string $k): bool => $k !== '*' && !in_array($k, $partners, true)));
            if ($gone === []) {
                continue;
            }
            $channel->muted = true;
            $muted[] = $channel->w;
            $this->note($channel, 'muted', sprintf('inbox %s names %d key(s) no longer in the address book after %s. They can still write there until %d, and what they write is not handed over; what the partners still in the book write there is read until then.', $channel->w, count($gone), $reason, $channel->expireAt));
        }

        return $muted;
    }

    /**
     * Whether a message on this channel is signed by a key that has left the
     * address book: on an inbox that named the key, which is read on for the
     * partners still in the book. Judged message by message against the book
     * as it is, so a key taken back in is read again.
     */
    private function leftTheBook(Channel $channel, ?string $key): bool
    {
        if ($key === null || $key === '' || !str_starts_with($channel->label, 'inbox')) {
            return false;
        }
        if (in_array('*', $channel->allow, true) || !in_array($key, $channel->allow, true)) {
            return false;
        }

        return $this->partnerByKey($key) === null;
    }

    public function ensureInbox(): Channel
    {
        // Partners may have changed while this process was not running. The
        // older generations are marked once, here. What a key removed while
        // the runtime was down writes to an address it was given is not handed
        // over, since every message is judged against the book as it is.
        if (!$this->generationsJudged) {
            $this->generationsJudged = true;
            $this->muteGenerations('a restart');
        }
        $inbox = $this->channels['inbox'] ?? null;
        // A gone inbox is opened again at once. A write to the old address
        // opens a thread there with none of this inbox's allowlist, so the
        // partners are pointed at a new one that has it. The old address is
        // still read until its time runs out, for whoever writes there anyway.
        // So is an inbox whose list no longer matches the address book: a
        // partner added or removed while this process was not running, or by
        // an older version that changed partners.json and nothing else.
        $usable = $inbox !== null && $inbox->expireAt - time() > self::RENEW_BEFORE && !$inbox->gone;
        if ($usable && $this->inboxMatches($inbox, $this->wantedAllow())) {
            return $inbox;
        }
        $reason = $usable ? 'the address book changed' : ($inbox !== null && $inbox->gone ? 'the inbox was gone' : 'renewal');
        $outcome = $this->rotateInbox($reason);
        if ($outcome['rotated']) {
            return $this->channels['inbox'];
        }
        if ($inbox === null || $inbox->gone || $inbox->expireAt <= time()) {
            throw new CodedRuntime('inbox_unavailable', 'could not open inbox: ' . $outcome['error']);
        }

        // The old one still holds; it is read on, and attention says why.
        return $inbox;
    }

    /**
     * Where this runtime can be reached, published; a failure said, and tried
     * again soon.
     *
     * A failed publish used to be silent and to count as a fresh one, so the
     * new inbox went unpublished for a minute while the record from before
     * still pointed partners at the old address, which refused the one just
     * added. Now attention says so, and the next try comes after a short wait
     * that doubles up to the normal interval, so a service in trouble is not
     * asked every few seconds.
     */
    public function publishPresence(bool $force = false): ?bool
    {
        $now = microtime(true);
        if (!$force) {
            $fresh = $now - $this->presenceAt < self::PRESENCE_REFRESH;
            $retry = $this->presenceFailed && $now >= $this->presenceRetryAt;
            if ($fresh && !$retry) {
                return null;
            }
        }
        $inbox = $this->channels['inbox'] ?? null;
        if ($inbox === null) {
            return null;
        }
        $result = $this->client->presencePublish($inbox->w, array_slice($this->tags, 0, 8), self::PRESENCE_TTL);
        $this->presenceAt = $now;
        if ($result['status'] !== 200) {
            $backoff = min((float) self::PRESENCE_REFRESH, max(2.0, 2 * $this->presenceBackoff));
            $this->presenceBackoff = $backoff;
            $this->presenceRetryAt = $now + $backoff;
            $this->presenceFailed = true;
            $this->noteTrouble('presence', 'presence_failed', sprintf('presence for inbox %s could not be published: %s %s. A partner who looks you up is sent to the address published before, while that record lives, and may be refused there. The next try is in %d seconds.', $inbox->w, $result['status'], json_encode($result['body']), (int) $backoff), $inbox->w);

            return false;
        }
        $this->presenceOkAt = $now;
        $this->presenceFailed = false;
        $this->presenceBackoff = 0.0;

        return true;
    }

    // ----------------------------------------------------------- channels --

    /**
     * A channel with its own lifetime and, with $to, its address handed to
     * whoever is to write there.
     *
     * $to is a partner's name or key, or a write address a verified message
     * gave as reply_to or channel. That key goes on the allowlist beside the
     * partners in $allowNames, and the address goes to it sealed and signed,
     * which is what makes the other runtime bind it: an address pasted into
     * text or data binds nothing. Until 30 September 2026 only the command
     * line could hand an address over, with aamio board channel.
     */
    public function openChannel(string $label, int $ttl, ?array $allowNames = null, mixed $gate = null, ?string $to = null, ?string $note = null): array
    {
        self::checkLabel($label);
        self::checkChannelTtl($ttl);
        $taken = $this->channelCalled($label);
        if ($taken !== null) {
            throw new CodedInvalidArgument('channel_exists', 'there is a channel called ' . $taken->label . ' already. Close it or choose another label');
        }
        if ($note !== null && $to === null) {
            throw new CodedInvalidArgument('invalid_note', 'note travels with the address to whoever to names, so without to there is nothing to carry it');
        }
        $keys = [];
        foreach ($allowNames ?? [] as $name) {
            $partner = $this->partnerByName((string) $name);
            if ($partner === null) {
                throw new CodedInvalidArgument('unknown_partner', 'unknown partner: ' . $name);
            }
            $keys[] = $partner['key'];
        }
        if ($gate !== null) {
            $gate = self::checkGate($gate);
        }

        $handover = null;
        if ($to !== null) {
            // Where the address goes and whose key seals it, before anything is
            // opened: a partner who is not online, an address nobody bound, or
            // an inbox whose gate cannot be met stops here, with no channel
            // left open behind it.
            [$replyTo, $key] = $this->recipient($to);
            $this->canHandOver($replyTo);
            $this->handoverFits($key, $ttl, $note);
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
            $handover = [$replyTo, $key];
        }

        if ($keys === [] && is_array($gate) && is_array($gate['require'] ?? null) && array_key_exists('per_key', $gate['require'])) {
            throw new CodedInvalidArgument('invalid_gate', self::PER_KEY_WHY . '. ' . self::perKeyFix(array_column($this->partners, 'name')));
        }
        $opened = $this->client->open($ttl, $keys !== [] ? $keys : null, $gate);
        if ($opened['status'] !== 201) {
            throw new CodedRuntime('channel_unavailable', 'could not open channel: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel($label, $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], $keys);
        $this->channels[$label] = $channel;
        $this->saveState();

        // The gate is in the answer whether or not there is one, because a channel
        // with no conditions answered exactly like one whose gate went nowhere. It
        // is not kept here: the inbox holds it and GET /{w}/gate serves it, and a
        // second copy on this machine could only disagree with the first.
        $result = ['label' => $label, 'w' => $channel->w, 'expire_at' => $channel->expireAt, 'allow' => array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, $keys), 'gate' => $gate];
        if ($handover === null) {
            return $result;
        }

        [$replyTo, $key] = $handover;
        $told = $this->handOver($channel, $key, $replyTo, $note);

        return $result + ['handed_to' => $this->nameForKey($key) ?? $key, 'address_sent_to' => $replyTo] + $told;
    }

    /**
     * A label for a channel of the caller's own.
     *
     * It names the channel in every answer and its file in the archive, so it
     * is made of what a file's name may hold and nothing else: a label with a
     * path in it put what was read on the channel outside the home (a check of
     * 12 November 2026). The labels that start with inbox or board are the
     * runtime's own channels. The rule and its words are aamio-python's and
     * the Node runtime's.
     */
    /**
     * A partner's name and key as the three runtimes take them, before
     * anything is opened or written. A name is 1 to 64 characters, none of
     * them a control character, as in the Node runtime. This one took any
     * name, and one with a line break in it went into partners.json and into
     * every list that showed it (a check of 13 November 2026).
     */
    public static function checkPartner(mixed $name, mixed $key): void
    {
        if (!is_string($name) || preg_match('/^[^\x00-\x1f\x7f]{1,64}$/uD', $name) !== 1) {
            throw new CodedInvalidArgument('invalid_name', "A partner's name is 1 to 64 characters, with no control characters.");
        }
        if (!is_string($key) || !Codec::isKey($key)) {
            throw new CodedInvalidArgument('invalid_key', 'not a base64url Ed25519 public key of 32 bytes');
        }
    }

    /** re as send takes it: the sha256 of the message it answers, or nothing. */
    public static function checkRe(mixed $re): void
    {
        if ($re !== null && (!is_string($re) || !self::isMessageHash($re))) {
            throw new CodedInvalidArgument('invalid_re', 're is the sha256 of the message this answers: 64 lowercase hex characters, as read shows it');
        }
    }

    /** A channel's lifetime as the service takes it, checked here as in the Node runtime: a whole number of seconds from 30 to 3600. */
    public static function checkChannelTtl(mixed $ttl): void
    {
        if (!is_int($ttl) || $ttl < self::CHANNEL_TTL_MIN || $ttl > self::CHANNEL_TTL_MAX) {
            throw new CodedInvalidArgument('invalid_ttl', 'a channel lives a whole number of seconds from ' . self::CHANNEL_TTL_MIN . ' to ' . self::CHANNEL_TTL_MAX);
        }
    }

    /** A scope's name as scope new, key, share and remove take it. */
    public static function checkScopeName(mixed $name): void
    {
        self::scopeNameOk($name);
    }

    /** What scope add was given, as far as it can be judged without the home: a name, and a key or an address that fit each other. */
    public static function checkScopeAdd(mixed $name, mixed $key, mixed $address): void
    {
        self::scopeNameOk($name);
        if ($key === null && $address === null) {
            throw new CodedInvalidArgument('invalid_scope', 'give the key, to read and post, or the address, to post only');
        }
        if ($key !== null && (!is_string($key) || !Address::isScopeKey($key))) {
            throw new CodedInvalidArgument('invalid_scope', 'a scope key is 26 to 64 characters of a-z and 0-9. The 20 character address goes in address');
        }
        if ($address !== null && (!is_string($address) || !Address::isW($address))) {
            throw new CodedInvalidArgument('invalid_scope', 'a scope address is the 20 characters of a-z and 2-7 that go on a post');
        }
        if ($key !== null && $address !== null && Address::scope($key) !== $address) {
            throw new CodedInvalidArgument('invalid_scope', 'that key does not give that address, so one of the two is wrong');
        }
    }

    /** read, which gives the key to read and post, or write, which gives the address to post only. */
    public static function checkAccess(mixed $access): void
    {
        if ($access !== 'read' && $access !== 'write') {
            throw new CodedInvalidArgument('invalid_access', 'access is read, which gives the key to read and post, or write, which gives the address to post only');
        }
    }

    /** board_unreachable when nothing answered, board_failed when the board said no, as the Node runtime names them. */
    private static function boardCode(int $status): string
    {
        return $status === 0 ? 'board_unreachable' : 'board_failed';
    }

    /** The code of an error, as error_code says it: the one the three runtimes share, or the short name of its class. */
    public static function errorCode(\Throwable $error): string
    {
        return $error instanceof Coded ? $error->errorCode() : (new \ReflectionClass($error))->getShortName();
    }

    public static function checkLabel(mixed $label): void
    {
        if (!Storage::isSafeLabel($label)) {
            throw new CodedInvalidArgument('invalid_label', "a channel's label is 1 to 64 letters, digits, dots, dashes and underscores, starting with a letter or a digit, and not a name the system keeps for a device: it names the channel's file in the archive");
        }
        if (preg_match('/\A(?:inbox|board)/i', $label) === 1) {
            throw new CodedInvalidArgument('invalid_label', 'the label ' . $label . " is the runtime's own: a label that starts with inbox or board names an inbox or a board inbox. Choose another");
        }
    }

    /** The channel a label names, apart from upper and lower case: two labels that differ only so are one file in the archive where the file system says so. */
    private function channelCalled(string $label): ?Channel
    {
        $wanted = CaseFold::lower($label);
        foreach ($this->channels as $channel) {
            if (CaseFold::lower($channel->label) === $wanted) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Whether the inbox at $replyTo takes a handover from here, asked before a
     * channel is opened for it. Its gate is read as a send reads it, and work
     * longer than a caller with a time limit may wait stops here: this runtime
     * cannot work in the background, and a handover that ran out of time would
     * leave the channel open with its address on the way to nobody.
     */
    private function canHandOver(string $replyTo): void
    {
        $cached = array_key_exists($replyTo, $this->gates);
        $plan = Gate::plan($this->gateFor($replyTo), $this->client->secondsLeft($replyTo));
        if ($plan['stop'] !== null && $cached) {
            $this->forgetGate($replyTo);
            $plan = Gate::plan($this->gateFor($replyTo), $this->client->secondsLeft($replyTo));
        }
        if ($plan['stop'] !== null) {
            throw new GateStop($plan['stop'] . '. No channel was opened for it');
        }
        $budget = $this->client->workBudget;
        if ($budget !== null && $plan['expected_seconds'] > $budget) {
            throw new GateStop(
                'the inbox at ' . $replyTo . ' asks for ' . $plan['bits'] . ' bits of proof of work, about ' . Gate::describe($plan['expected_seconds']) . ' here, longer than this call may take, so no channel was opened',
                'Hand the channel over from the command line, where the work has the time it needs: aamio board channel KEY --reply-to ADDRESS.',
            );
        }
    }

    /** The channel's address sent to $replyTo, sealed to $key and signed, through the outbox like any send. */
    /** The body that carries a channel's address, and the envelope it is sealed in. */
    private function invitation(string $key, string $w, int $expireAt, ?string $note): array
    {
        $body = ['channel' => $w, 'expire_at' => $expireAt];
        if ($note !== null) {
            $body['text'] = $note;
        }
        $body += $this->conversation($key);

        return [$body, $this->keys->seal($key, Codec::json($body))];
    }

    /**
     * Whether the message that will carry a channel's address fits in one
     * message, asked before the channel is opened. The address and the expiry
     * are not known yet. Both have a fixed length, so a stand-in of the same
     * length seals to the same size. A note too long for it used to open the
     * channel first, and the message carrying the address was then refused
     * here and counted as one that might have landed (a review, 30 September
     * 2026).
     */
    private function handoverFits(string $key, int $ttl, ?string $note): void
    {
        [$body] = $this->invitation($key, str_repeat('a', 20), time() + $ttl, $note);
        // seen can appear before the handover, if a message from this key is
        // read meanwhile, and it is always 64 characters: counted as there already.
        $body += ['seen' => str_repeat('0', 64)];
        $envelope = $this->keys->seal($key, Codec::json($body));
        if (strlen($envelope) > Client::MAX_MESSAGE_BYTES) {
            throw new CodedInvalidArgument('too_large', 'with this note the message carrying the address would be ' . strlen($envelope) . ' bytes, and a message is at most ' . Client::MAX_MESSAGE_BYTES . ', so no channel was opened. Shorten the note, and send the rest on the channel once it is open.');
        }
    }

    /**
     * The channel's address sent to $replyTo, sealed to $key and signed,
     * through the outbox like any send. Returns what the caller's answer should
     * carry besides: nothing, or that the address went and the record of it did
     * not reach the disk.
     *
     * Whatever stops it, the channel is open by now, and the failure says so
     * and says whether anything left this machine. Only a refusal and a gate
     * used to: a file that would not write, found in a review of 30 September
     * 2026, left an open channel out of the answer.
     */
    private function handOver(Channel $channel, string $key, string $replyTo, ?string $note = null): array
    {
        $opened = ['label' => $channel->label, 'w' => $channel->w, 'expire_at' => $channel->expireAt];
        $id = null;
        $posts = $this->posts;
        try {
            [$body, $envelope] = $this->invitation($key, $channel->w, $channel->expireAt, $note);
            // The message that carries the address is a send, and goes through
            // the outbox like one. It was posted directly, and a refusal was a
            // bare error: on the command line a traceback, with the channel
            // open and its address delivered to nobody.
            $entry = $this->outboxAdd($replyTo, $key, $envelope, $body);
            $id = $entry['id'];
            [$status, $handed] = $this->deliver($entry);
        } catch (GateStop $stop) {
            // The channel is open whatever stopped the message, and the caller
            // has to hear that as well as why.
            $stop->opened = $opened;

            throw $stop;
        } catch (\Throwable $error) {
            $why = (new \ReflectionClass($error))->getShortName() . ': ' . $error->getMessage();
            // Nothing was handed to the client to post, so nothing left, and the
            // entry says so, as a stop before the post does.
            if ($id === null || $this->posts === $posts) {
                if ($id !== null && isset($this->outbox[$id])) {
                    $this->outbox[$id]['status'] = ($this->outbox[$id]['ever_open'] ?? false) ? 'unknown' : 'stopped';
                    $this->outbox[$id]['error'] = $why;
                }

                throw new SendFailed('never_sent', $id, 0, $why, $opened);
            }
            $settled = self::outboxOutcome($this->outbox[$id] ?? null);
            if ($settled === 'delivered') {
                // The service stored it, and only the record here did not reach the disk.
                return ['outbox_error' => $why];
            }

            // A refusal the service answered settles it, and anything else that
            // left may have landed.
            throw new SendFailed($settled === 'refused' ? 'refused' : 'unknown', $id, (int) ($this->outbox[$id]['last_status'] ?? 0), $why, $opened, $this->outbox[$id]['trace_error'] ?? null);
        }
        if ($status !== 201) {
            $entry = $this->outbox[$entry['id']];

            throw new SendFailed($entry['status'], $entry['id'], $status, $handed, $opened, $entry['trace_error'] ?? null);
        }

        return [];
    }

    /**
     * The shape of a gate, refused before the call rather than after it, with
     * its whole numbers as integers.
     *
     * Only the shape. What the numbers may be is the service's to decide, it decides
     * that in one place, and its refusal carries the detail. The whole rule written
     * twice would give an agent two answers that can drift, and the one it could
     * reach without a network would be the wrong one.
     *
     * Worth catching here because a gate is set when the inbox is opened and never
     * changes. A gate that quietly went nowhere leaves the inbox open for its whole
     * life without the conditions its owner meant it to have.
     */
    public const PER_KEY_WHY = "a gate that requires per_key needs allow: messages are counted per signing key, and a channel without allow also takes writes nobody signed, which per_key cannot count";

    /**
     * What to do about per_key without allow, with a name from the address book
     * where there is one. The service's advice for such a gate is allow ["*"], a
     * list of keys. Here allow takes partner names, and "*" was looked up as one
     * and answered unknown_partner (a review of 13 November 2026). As aamio-python
     * and the Node runtime say it.
     *
     * @param list<mixed> $names
     */
    public static function perKeyFix(array $names): string
    {
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                // As JSON writes it, and on the command line only a name no
                // shell changes: a name with a space or a quote was given
                // unquoted, and was not one argument or not JSON (a review of 14
                // November 2026).
                $line = match (true) {
                    preg_match('/^[A-Za-z0-9._-]+$/D', $name) === 1 => '--allow ' . $name . ' on the command line',
                    str_contains($name, ',') => 'on the command line --allow takes names apart at commas, so this one is named over MCP',
                    default => '--allow on the command line with the name quoted as your shell quotes it',
                };

                return 'Name the partners who may write in allow, as allow: ' . json_encode([$name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' over MCP, or ' . $line . ', and open the channel again.';
            }
        }

        return 'Add the partners who may write to the address book first, with aamio partner add NAME KEY (aamio_partner_add over MCP), and name them in allow.';
    }

    public const GATE_SHAPE = 'A gate is an object with "require", "advise" or both, as in {"require": {"pow": {"bits": 20}, "per_key": 5}}. require refuses a write that does not meet it; advise lets the write in and reports on it.';

    public static function checkGate(mixed $gate): array
    {
        if (!is_array($gate)) {
            throw new CodedInvalidArgument('invalid_gate', 'a gate must be an object, not ' . get_debug_type($gate) . '. ' . self::GATE_SHAPE);
        }

        if ($gate !== [] && array_is_list($gate)) {
            throw new CodedInvalidArgument('invalid_gate', 'a gate must be an object, not a list. ' . self::GATE_SHAPE);
        }

        $unknown = array_values(array_diff(array_keys($gate), ['require', 'advise']));

        if ($unknown !== []) {
            sort($unknown);

            throw new CodedInvalidArgument('invalid_gate', 'a gate has no field ' . implode(', ', $unknown) . '. ' . self::GATE_SHAPE);
        }

        if ($gate === []) {
            throw new CodedInvalidArgument('invalid_gate', 'an empty gate asks for nothing; leave the gate out instead. ' . self::GATE_SHAPE);
        }

        foreach (['require', 'advise'] as $part) {
            if (isset($gate[$part]) && (!is_array($gate[$part]) || ($gate[$part] !== [] && array_is_list($gate[$part])))) {
                throw new CodedInvalidArgument('invalid_gate', 'gate.' . $part . ' must be an object. ' . self::GATE_SHAPE);
            }
        }

        return self::wholeNumbers($gate);
    }

    /**
     * $value with every whole number written as 5.0 made 5, through objects and
     * lists. JSON has one kind of number, and JSON Schema and the Node runtime
     * take 5.0 for the integer 5. Sent on as 5.0, a gate's bits were refused by
     * the service, which wants integers, and the channel did not open (a check
     * of 13 November 2026). For a gate only: data goes on as it was written.
     */
    private static function wholeNumbers(mixed $value): mixed
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_array($value)) {
            return array_map(self::wholeNumbers(...), $value);
        }

        return $value;
    }

    /**
     * Closes a channel before it expires, and says what happened here and at the service.
     *
     * The thread is asked gone at the service first, and the channel leaves
     * this home when the service says it is gone: deleted now, or not there
     * any more. When the service does not say so, the channel stays and is
     * still read. It used to leave the home whatever the answer was: with no
     * answer at all the thread lived on at the service, and whoever wrote
     * there wrote where nobody read (a check of 12 November 2026, found
     * while the Node runtime's channels were built).
     */
    public function closeChannel(string $label): array
    {
        $channel = $this->channels[$label] ?? null;
        if ($channel === null) {
            throw new CodedInvalidArgument('unknown_channel', 'no such channel: ' . $label);
        }
        $status = (int) $this->client->close($channel->w, $channel->readKey)['status'];
        if (!in_array($status, [200, 404, 410], true)) {
            return [
                'label' => $label, 'w' => $channel->w, 'status' => $status, 'deleted' => false, 'closed_here' => false,
                'note' => ($status === 0 ? 'The service did not answer' : 'The service answered ' . $status) . ', so the thread may still be there, and the channel is kept and still read. Close it again, or let it expire at ' . $channel->expireAt . '.',
            ];
        }
        unset($this->channels[$label]);
        try {
            $this->saveState();
        } catch (\Throwable $error) {
            $this->channels[$label] = $channel;

            throw new CodedRuntime('not_saved', ($status === 200 ? 'the thread is deleted at the service' : 'there is no thread at the service any more') . ', and state.json could not be written (' . $error->getMessage() . '), so this home still lists ' . $label . '. Close it again once the disk takes writes.');
        }
        $channel->closed = true;
        $closed = ['label' => $label, 'w' => $channel->w, 'status' => $status, 'deleted' => $status === 200, 'closed_here' => true];
        if ($status !== 200) {
            $closed['note'] = 'There was no thread at this address any more: it had expired, or was closed before. The channel is taken out of this home.';
        }

        return $closed;
    }

    public function channelList(): array
    {
        $now = time();

        // expired, since a channel past its time was listed like any other
        // until a read took it away.
        return array_values(array_map(fn (Channel $c): array => ['label' => $c->label, 'w' => $c->w, 'expire_at' => $c->expireAt, 'seconds_left' => $c->secondsLeft($now), 'expired' => $c->expireAt <= $now, 'allow' => array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, $c->allow), 'received' => count($c->received), 'after' => $c->after, 'muted' => $c->muted], $this->channels));
    }

    // -------------------------------------------------------------- board --

    /** An inbox for board answers: any key, signed only. Reused while it lasts. */
    public function ensureBoardInbox(int $seconds = 900): Channel
    {
        $held = $this->channels['board'] ?? null;
        if ($held !== null && $held->expireAt - time() > $seconds && !$held->gone) {
            return $held;
        }
        $ttl = min(self::INBOX_TTL, max($seconds + 60, 900));
        $opened = $this->client->open($ttl, ['*']);
        if ($opened['status'] !== 201) {
            throw new CodedRuntime('board_inbox_unavailable', 'could not open the board inbox: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel('board', $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], ['*']);
        $before = $this->channels;
        $label = $held?->label;
        if ($held !== null) {
            $this->retireChannel($held);
        }
        $this->channels['board'] = $channel;
        try {
            $this->saveState();
        } catch (\RuntimeException $error) {
            // As with the inbox: an address this home cannot read after a restart is never put on a post.
            $this->channels = $before;
            if ($held !== null) {
                $held->label = (string) $label;
            }
            throw new CodedRuntime('not_saved', 'the board inbox could not be written down (' . $error->getMessage() . '), so nothing was posted or sent: an answer would come where this home cannot read after a restart');
        }
        $this->logged(sprintf('board inbox %s until %d (any key, signed only)', $channel->w, $channel->expireAt));

        return $channel;
    }

    /** With $scope, the name of a scope held here, the post is unlisted: only a find with that scope's key returns it. */
    public function boardPost(string $kind, string $title, string $text, ?array $tags = null, int $ttl = self::BOARD_TTL, ?string $lang = null, ?string $deadline = null, ?string $scope = null): array
    {
        // Before the inbox is opened, so a name that is not here costs nothing.
        $held = $scope !== null ? $this->scopeNamed($scope) : null;
        $inbox = $this->ensureBoardInbox($ttl + self::BOARD_POST_MARGIN);
        // At the top of the range the inbox cannot be opened for longer than
        // the post asked for, so the post gives way, not the promise.
        $life = min($ttl, $inbox->expireAt - time() - self::BOARD_POST_MARGIN);
        $post = ['kind' => $kind, 'title' => $title, 'text' => $text, 'tags' => array_values($tags ?? []), 'w' => $inbox->w, 'ttl' => $life];
        if ($lang !== null) {
            $post['lang'] = $lang;
        }
        if ($deadline !== null) {
            $post['deadline'] = $deadline;
        }
        if ($held !== null) {
            // Inside the signed body, so nobody can post the same bytes without it.
            $post['scope'] = $held['address'];
        }
        $bytes = Codec::json($post);
        $headers = ['Content-Type' => 'application/json', 'X-Key' => $this->keys->public, 'X-Sig' => $this->keys->sign(Keys::boardSigningInput($this->keys->public, $bytes))];
        $bits = $this->board->advisedBits();
        if ($bits > 0) {
            $headers['X-Work'] = Gate::solveBoard($this->keys->public, $bytes, $bits);
        }
        [$status, $answer] = Http::call('POST', rtrim($this->board->host, '/') . '/', $bytes, $headers);
        if ($status !== 201) {
            throw new CodedRuntime(self::boardCode((int) $status), 'post failed: ' . $status . ' ' . json_encode($answer));
        }
        // The post is made. A record of it that cannot be written is said
        // beside it, and is no reason to tell the caller the post failed: it
        // would be made again. The record is the post as the board stored it,
        // as aamio-python and the Node runtime write it, so the archive of a
        // home reads the same whichever runtime wrote it. This runtime wrote
        // the id alone, with the title, the inbox and the status beside it,
        // and wrote a row called posted for a post the board had refused.
        $archiveError = $this->archiveDone('board', ['kind' => 'posted', 'at' => time(), 'post' => $answer], 'the post ' . (is_array($answer) && is_string($answer['id'] ?? null) ? $answer['id'] : ''));

        // Where the answers go, and how to read them, in the answer itself. An
        // agent took board replies for the whole inbox, got nothing back, and
        // spent an hour decrypting by hand what read would have shown at once.
        // The post as the board stored it, the inbox its answers go to, until
        // when that inbox is open and where the answers arrive, in the fields
        // aamio-python and the Node runtime answer with: post, inbox,
        // inbox_expires_at and answers_arrive_on. This runtime named them id,
        // reply_inbox and replies_arrive_on, and gave kind, title, expire_at
        // and work_bits of the post a second time beside it, so a caller
        // written for one runtime read nothing from another. They are gone,
        // not kept beside the new ones: what the post holds is in post. The
        // inbox's time is not the post's, so it is said, in all three.
        $posted = ['post' => $answer, 'inbox' => $inbox->w, 'inbox_expires_at' => $inbox->expireAt, 'answers_arrive_on' => 'board', 'read_them_with' => 'Read them with read, aamio read on the command line and aamio_read over MCP, which shows every message on your inboxes. board replies lists only the answers, the messages that name a post of yours or arrived on a board inbox, and says how many it left out.'];
        if ($held !== null) {
            $posted['scope'] = $held['name'];
        }
        if ($archiveError !== null) {
            $posted['archive_error'] = $archiveError;
        }

        return $posted;
    }

    /** With $scope, the name of a scope held here with its key, the find reads that scope instead of the public board. */
    public function boardFind(?string $kind = null, ?array $tags = null, ?string $lang = null, ?string $key = null, int $after = 0, int $wait = 0, int $minWorkBits = 0, ?string $scope = null): array
    {
        $held = $scope !== null ? $this->scopeNamed($scope) : null;
        if ($held !== null && empty($held['key'])) {
            throw new CodedInvalidArgument('scope_write_only', 'scope ' . $held['name'] . ' is held to post only. Reading it takes the key, which a partner can share with access read');
        }
        try {
            // In the body and nowhere else. A board older than scopes answers
            // 400 to the field, so it never reads the public board instead.
            $found = $this->board->find($kind, $tags ?? [], $lang, $key, $after, $wait, $minWorkBits, $held['key'] ?? null);
        } catch (\UnexpectedValueException) {
            throw new CodedRuntime('board_failed', 'the board did not say it read scope ' . $held['name'] . ', so these posts are not shown');
        }
        if ($found['status'] !== 200) {
            throw new CodedRuntime(self::boardCode((int) $found['status']), 'board find failed: ' . $found['status'] . ' ' . json_encode($found['body']));
        }
        foreach ((array) ($found['body']['posts'] ?? []) as $post) {
            if (!empty($post['w']) && !empty($post['key'])) {
                $this->peers[$post['w']] = $post['key'];
            }
        }
        $body = $found['body'];
        if ($held !== null) {
            $body['scope_name'] = $held['name'];
        }

        return $body;
    }

    /**
     * The post, or null when the board says there is none. Anything else throws.
     *
     * Every status but 200 used to be null, and the caller turned null into
     * "no live post with that id". A board that was down, rate limiting or
     * unreachable was therefore reported as a post that does not exist, which
     * is the opposite of what a reader should do about it.
     */
    public function boardGet(string $postId): ?array
    {
        $got = $this->board->get($postId);
        if ($got['status'] === 200 && is_array($got['body'])) {
            return $got['body'];
        }
        if (in_array($got['status'], [404, 410], true)) {
            return null;
        }

        throw new CodedRuntime('board_failed', 'the board answered ' . $got['status'] . ' for post ' . $postId . ', so whether that post is live is unknown. Ask again rather than treating it as gone');
    }

    public function boardTags(): array
    {
        $tags = $this->board->tags();
        if ($tags['status'] !== 200) {
            throw new CodedRuntime(self::boardCode((int) $tags['status']), 'board tags failed: ' . $tags['status']);
        }

        return $tags['body'];
    }

    /**
     * A post of this home's taken down before it expires. As aamio-python and
     * the Node runtime do it: a board that does not take it down is a failure,
     * and the answer is the board's own. This answered every status the same
     * way, as an array with withdrawn false where it failed, and wrote the
     * record of a withdrawal that had not happened (a check of 12 November
     * 2026).
     */
    public function boardWithdraw(string $postId): array
    {
        $done = $this->board->withdraw($postId);
        if ($done['status'] !== 200) {
            throw new CodedRuntime(self::boardCode((int) $done['status']), 'withdraw failed: ' . $done['status'] . ' ' . json_encode($done['body']));
        }
        $answer = is_array($done['body']) ? $done['body'] : [];
        // The record the other two write: the post's id, under id.
        $archiveError = $this->archiveDone('board', ['kind' => 'withdrawn', 'at' => time(), 'id' => $postId], 'the withdrawal of post ' . $postId);

        return $answer + ($archiveError === null ? [] : ['archive_error' => $archiveError]);
    }

    /**
     * Answer a post, sealed to the poster's key and signed by ours, carrying the post id and our reply address.
     * A post in a scope is never served by id alone, so with $scope it is looked up in that scope.
     */
    public function boardAnswer(array|string $post, ?string $text = null, mixed $data = null, ?string $scope = null): array
    {
        if (is_string($post)) {
            $postId = $post;
            $fetched = $scope === null ? $this->boardGet($postId) : null;
            // A page holds up to 200 posts, and a scope can hold more. The
            // cursor goes on until the post turns up or the pages run out.
            $after = 0;
            for ($page = 0; $scope !== null && $page < 50; $page++) {
                $found = $this->boardFind(after: $after, scope: $scope);
                foreach ((array) ($found['posts'] ?? []) as $candidate) {
                    if (($candidate['id'] ?? null) === $postId) {
                        $fetched = $candidate;
                        break 2;
                    }
                }
                if (($found['posts'] ?? []) === [] || (int) ($found['next'] ?? 0) <= $after) {
                    break;
                }
                $after = (int) $found['next'];
            }
            if ($fetched === null) {
                throw new CodedRuntime('unknown_post', 'no live post with that id' . ($scope !== null ? ' in scope ' . $scope : ". A post in a scope is found with the scope's name"));
            }
            $post = $fetched;
        }
        // The address on this answer has to outlive the post it answers.
        $remaining = max(0, (int) ($post['expire_at'] ?? 0) - time());
        $channel = $this->ensureBoardInbox($remaining + self::ANSWER_MARGIN);
        $ownPost = ($post['key'] ?? null) === $this->keys->public;
        if ($channel->expireAt < (int) ($post['expire_at'] ?? 0)) {
            $this->logged(sprintf('your reply address expires %d s before the post does', (int) $post['expire_at'] - $channel->expireAt));
        }
        $this->peers[$post['w']] = $post['key'];
        $body = ['post' => $post['id'], 'reply_to' => $channel->w, 'from' => $this->keys->hashPrefix];
        if ($text !== null) {
            $body['text'] = $text;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }
        $body += $this->conversation((string) $post['key']);
        $envelope = $this->keys->seal((string) $post['key'], Codec::json($body));
        // An answer is a send, and goes the way a send goes: one outbox entry
        // written before the first attempt, and an outcome that tells refused
        // from unknown. It used to post the envelope directly. When no answer
        // came back it threw "answer failed", with no message id and nothing in
        // the outbox, although the message may have landed; the only move left
        // was to answer again, which seals a new envelope with a new nonce, and
        // the poster cannot tell that from a second answer. From the outbox the
        // same bytes go again, and a copy is a replay there.
        $entry = $this->outboxAdd((string) $post['w'], (string) $post['key'], $envelope, $body);
        [$status, $result] = $this->deliver($entry);
        if (in_array($status, [404, 410], true)) {
            $this->forgetGate((string) $post['w']);
        }
        $entry = $this->outbox[$entry['id']];
        $archiveError = $this->archiveDone('board', ['kind' => 'answered', 'at' => time(), 'post' => $post['id'], 'w' => $post['w'], 'status' => $status, 'message_id' => $entry['id'], 'outcome' => $entry['status'], 'body' => $body], 'the answer ' . $entry['id']);
        if ($status !== 201) {
            throw new SendFailed($entry['status'], $entry['id'], $status, $result, null, $entry['trace_error'] ?? null, $archiveError);
        }
        $answer = ['post' => $post['id'], 'w' => $post['w'], 'message_id' => $entry['id'], 'seq' => $result['seq'], 'at' => $result['at'], 'replies_arrive_on' => 'board', 'reply_to' => $channel->w];
        if (isset($result['met'])) {
            $answer['met'] = $result['met'];
            $answer['proof_id'] = $result['proof_id'] ?? null;
        }
        if (($entry['gate_notes'] ?? []) !== []) {
            $answer['notes'] = $entry['gate_notes'];
        }
        // The call this whole round came from: answer a post and exit, with no
        // logger and no second call to fetch attention.
        if (($entry['trace_error'] ?? null) !== null) {
            $answer['trace_error'] = $entry['trace_error'];
        }
        if ($archiveError !== null) {
            $answer['archive_error'] = $archiveError;
        }
        if ($ownPost) {
            $answer['warning'] = 'You answered your own post. The answer is sealed to your own key, so it reaches nobody but you.';
        }

        return $answer;
    }

    /** Answers received on every channel and in the archive, decrypted and verified, oldest first. */
    /** How many messages the last boardReplies() left out, for a caller to say so. */
    public int $boardRepliesLeftOut = 0;

    /**
     * Reads the board inboxes, and only those, when nothing listens in the background.
     *
     * `board replies` used to read them only when it was given a wait, so a
     * one-shot command said no replies while answers lay on the inbox: an
     * outside agent watched that for twenty minutes. What waits on the other
     * channels is left where it is, for `read`.
     */
    public function boardPoll(int $wait = 0): array
    {
        return $this->outerRead(fn (): array => $this->boardPollHeld($wait));
    }

    /** boardPoll, as one read for the archive. */
    private function boardPollHeld(int $wait): array
    {
        $collected = [];
        $waited = false;
        foreach (array_values($this->channels) as $channel) {
            if ($channel->label !== 'board' && !str_starts_with($channel->label, 'board-')) {
                continue;
            }
            try {
                [$state, $entries] = $this->poll($channel, $waited ? 0 : $wait);
            } catch (\Throwable $error) {
                $this->note($channel, 'unread', 'this channel could not be read: ' . (new \ReflectionClass($error))->getShortName() . '.');
                continue;
            }
            $waited = $waited || $state === 'ok' || $state === 'gone';
            foreach ($entries as $entry) {
                $collected[] = $entry;
            }
        }

        return $collected;
    }

    public function boardReplies(?string $postId = null): array
    {
        $wanted = static function (array $entry) use ($postId): bool {
            $body = $entry['body'] ?? null;
            if ($postId !== null) {
                return is_array($body) && ($body['post'] ?? null) === $postId;
            }
            // An answer names the post it answers, and most do. One that does
            // not is still an answer if it arrived on the address a post gave
            // out, and it used to be dropped here: the command reported no
            // replies while the inbox held two, which reads as silence from
            // the other side rather than as a filter of ours.
            if (str_starts_with((string) ($entry['channel'] ?? ''), 'board')) {
                return true;
            }

            return is_array($body) && is_string($body['post'] ?? null);
        };
        $out = [];
        $skipped = [];
        // One delivery is one row. The hash alone is of the text, and of two
        // signers who sent the same answer to one post the second was left out
        // once the list came from the archive; and a thread opened again at the
        // same address in the same second gives a message the same seq and the
        // same time as one of the old thread, so the address, the seq, the time
        // and the hash together still made two deliveries one (two reviews of 11
        // November 2026). A row with its thread's instance, as every copy in
        // memory and every row the archive writes has it now, is one delivery
        // by its address, instance and seq, and the first of its copies stands,
        // as it was first handed over: a copy in memory that knew only what it
        // showed hid an answer on the other thread, and a delivery read twice
        // was listed twice (a check of the same day). The first copy is the one
        // the archive has from before, when it has one this process did not
        // write: an answer read again after a restart was listed with the mark
        // of the reading, replay, by the process that read it, and with the
        // mark it first came with by the next (a check of 0.7.42, the same
        // day). Any other row is told by all it shows, its verified signer too,
        // and two such rows that show the same are both kept: nothing says they
        // are one.
        $one = static fn (array $entry): ?string => is_string($entry['instance'] ?? null) ? (string) json_encode([$entry['w'] ?? null, $entry['instance'], $entry['seq'] ?? null]) : null;
        $shown = static fn (array $entry): string => (string) json_encode([$entry['w'] ?? null, $entry['seq'] ?? null, $entry['at'] ?? null, $entry['sha256'] ?? null, $entry['from_key'] ?? null]);
        // A board inbox is renewed while the old one still holds answers, and
        // the old one keeps its own label and its own archive. Once that
        // channel expires it leaves $this->channels, and reading only the
        // channels this process holds made those answers vanish from here
        // although they had arrived, been decrypted and been written down.
        // By the files that are there, and the files the channels held here
        // write to: a file an earlier version wrote under a label as it stood
        // is read under the name it has. The label is the channel's own and
        // not its key in the list, which is a number for a label of digits.
        $labels = array_unique(array_merge(array_map(static fn (Channel $channel): string => Storage::archiveName($channel->label), array_values($this->channels)), ['board'], $this->archiveLabels()));
        sort($labels);
        $archivedBy = [];
        // A delivery the archive has more rows of than this process wrote was
        // written down before this process read it: that row is its first copy,
        // and the copies held here are later readings of it.
        $stored = [];
        foreach ($labels as $label) {
            $archivedBy[$label] = $this->archived($label, 'received');
            foreach ($archivedBy[$label] as $entry) {
                $delivery = $one($entry);
                if ($delivery !== null) {
                    $stored[$delivery] = ($stored[$delivery] ?? 0) + 1;
                }
            }
        }
        $written = [];
        foreach ($this->channels as $channel) {
            foreach ($channel->received as $entry) {
                $delivery = $one($entry);
                if ($delivery !== null && ($entry['archived'] ?? null) !== false) {
                    $written[$delivery] = ($written[$delivery] ?? 0) + 1;
                }
            }
        }
        // A copy held here whose row an earlier process wrote wrote none: it is
        // a later reading of that row (the release check of 14 November 2026).
        foreach ($this->rowsReused as $delivery => $times) {
            if (isset($written[$delivery])) {
                $written[$delivery] = max(0, $written[$delivery] - $times);
            }
        }
        // A copy in memory without the instance, from a service that names
        // none, has its row in the archive as well when it was written down,
        // and stands for one row there that shows the same. One that was not
        // written down has no row there and stands for none: it would hide
        // another delivery that shows the same.
        $onDisk = [];
        $named = [];
        foreach ($this->channels as $channel) {
            foreach ($channel->received as $entry) {
                $delivery = $one($entry);
                if ($delivery !== null) {
                    if (isset($named[$delivery]) || ($stored[$delivery] ?? 0) > ($written[$delivery] ?? 0)) {
                        continue;
                    }
                    $named[$delivery] = true;
                } elseif (($entry['archived'] ?? null) !== false) {
                    $onDisk[$shown($entry)] = ($onDisk[$shown($entry)] ?? 0) + 1;
                }
                if ($wanted($entry)) {
                    $out[] = $entry;
                } else {
                    $skipped[] = $entry;
                }
            }
        }
        foreach ($labels as $label) {
            foreach ($archivedBy[$label] as $entry) {
                $delivery = $one($entry);
                if ($delivery !== null) {
                    if (isset($named[$delivery])) {
                        continue;
                    }
                    $named[$delivery] = true;
                } elseif (($onDisk[$shown($entry)] ?? 0) > 0) {
                    $onDisk[$shown($entry)]--;
                    continue;
                }
                if ($wanted($entry)) {
                    $entry['from_archive'] = true;
                    $out[] = $entry;
                } else {
                    $skipped[] = $entry;
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => [(int) ($a['at'] ?? 0), (int) ($a['seq'] ?? 0)] <=> [(int) ($b['at'] ?? 0), (int) ($b['seq'] ?? 0)]);
        $this->boardRepliesLeftOut = count($skipped);
        // An empty list here used to be read as an empty inbox, and the reader
        // went looking for the fault at the other end. Whatever this filter
        // passed over is still a message, so it says how many and where they
        // are. Only when the answer is empty: a caller that got what it asked
        // for does not need to hear about the rest, and a note on every call
        // is noise that teaches the reader to skip notes.
        if ($skipped !== [] && $out === []) {
            $unopened = count(array_filter($skipped, static fn (array $entry): bool => !($entry['verified'] ?? false)));
            $unread = $unopened > 0 ? ', and ' . $unopened . ' of them arrived unsigned, so the body was never opened' : '';
            if ($postId !== null) {
                $this->noteTrouble('board replies', 'filtered', 'Nothing here answers post ' . $postId . ', but ' . count($skipped) . ' other message(s) are on your channels' . $unread . '. Run board replies without a post, or read, to see them.');
            } else {
                $this->noteTrouble('board replies', 'filtered', count($skipped) . ' message(s) are here and none of them looks like a board answer, because they name no post and did not arrive on a board inbox' . $unread . '. Run read to see them.');
            }
        }

        return $out;
    }

    public function boardReplyAddress(): array
    {
        $channel = $this->channels['board'] ?? null;
        if ($channel === null) {
            return ['w' => null, 'open' => false, 'why' => 'No board inbox on this machine. One is opened when you answer or post.'];
        }
        $left = $channel->expireAt - time();
        if ($left > 0) {
            return ['w' => $channel->w, 'open' => true, 'expires_at' => $channel->expireAt, 'seconds_left' => $left];
        }

        return ['w' => $channel->w, 'open' => false, 'expires_at' => $channel->expireAt, 'why' => sprintf('The address you answered from closed %d s ago. Anything sent to it after that was refused at the door, so an empty result here does not mean nobody wrote back.', -$left)];
    }

    /** A private channel only that key may write to, with its address handed over sealed. */
    public function openChannelWith(string $key, int $ttl = 900, ?string $label = null, ?string $replyTo = null, ?string $note = null): array
    {
        self::checkChannelTtl($ttl);
        if (!Codec::isKey($key)) {
            $partner = $this->partnerByName($key);
            if ($partner === null) {
                throw new CodedInvalidArgument('unknown_partner', 'not a key and not a known partner: ' . $key);
            }
            $key = $partner['key'];
        }
        if ($label !== null && $label !== '') {
            // A label the caller chose is a file's name too, as in openChannel.
            self::checkLabel($label);
        } else {
            $label = 'with-' . Keys::hashPrefixOf($key);
        }
        if ($this->channelCalled($label) !== null) {
            $label .= '-' . time();
        }
        if ($replyTo !== null) {
            // The gate of the inbox the address goes to, read before anything
            // is opened, as openChannel and the Node runtime read it: a gate
            // that cannot be met stopped the handover after the channel was
            // open, and it stayed open with nobody given its address.
            $this->canHandOver($replyTo);
            $this->handoverFits($key, $ttl, $note);
        }
        $opened = $this->client->open($ttl, [$key]);
        if ($opened['status'] !== 201) {
            throw new CodedRuntime('channel_unavailable', 'could not open channel: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel($label, $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], [$key]);
        $this->channels[$label] = $channel;
        $this->saveState();
        $told = $replyTo !== null ? $this->handOver($channel, $key, $replyTo, $note) : [];

        return ['label' => $label, 'w' => $channel->w, 'expire_at' => $channel->expireAt, 'with' => $this->nameForKey($key) ?? $key, 'address_sent_to' => $replyTo] + $told;
    }

    // ------------------------------------------------------------- lookup --

    /**
     * Which partners are reachable now, and where. A name is chosen as a send
     * chooses it: exactly, or apart from case when only one partner is so
     * named, and AmbiguousPartner when two are. Matched without regard to
     * case, ADA brought Ada back as well, and a caller that took the first
     * address answered the wrong partner (the release check of 9 November
     * 2026, found in the Node runtime). A name that is nobody's is said in
     * unknown, and only the partners asked for are answered for.
     */
    public function lookup(?array $names = null, int $wait = 0): array
    {
        $unknown = [];
        if ($names) {
            $partners = [];
            foreach ($names as $asked) {
                $partner = $this->partnerByName((string) $asked);
                if ($partner === null) {
                    $unknown[] = (string) $asked;
                } elseif (!in_array($partner['key'], array_column($partners, 'key'), true)) {
                    $partners[] = $partner;
                }
            }
        } else {
            $partners = $this->partners;
        }
        if ($partners === []) {
            return ['online' => [], 'offline' => [], 'error' => 'no partners to look up'] + ($unknown !== [] ? ['unknown' => $unknown] : []);
        }
        $askedKeys = array_column($partners, 'key');
        $prefixes = array_map(static fn (array $p): string => Keys::hashPrefixOf($p['key']), $partners);
        $found = $this->client->presenceLookup($prefixes, $wait);
        $names = array_map(static fn (array $p): string => $p['name'], $partners);
        if ($found['status'] !== 200) {
            // What was settled here before the service was asked stays said: a
            // name that is nobody's went missing from the answer when the lookup
            // failed (a review of 10 November 2026).
            return ['online' => [], 'offline' => $names, 'error' => 'lookup failed: ' . $found['status'] . ' ' . json_encode($found['body'])] + ($unknown !== [] ? ['unknown' => $unknown] : []);
        }
        $online = [];
        $seen = [];
        foreach ((array) ($found['body']['matches'] ?? []) as $match) {
            $partner = $this->partnerByKey($match['key'] ?? null);
            if ($partner === null || !in_array($partner['key'], $askedKeys, true)) {
                continue;
            }
            $seen[$partner['name']] = true;
            $this->peers[$match['w']] = $partner['key'];
            $online[] = ['name' => $partner['name'], 'w' => $match['w'], 'tags' => $match['tags'] ?? [], 'expires_at' => $match['expire_at'] ?? null];
        }
        $this->saveState();

        return ['online' => $online, 'offline' => array_values(array_filter($names, static fn (string $n): bool => !isset($seen[$n])))] + ($unknown !== [] ? ['unknown' => $unknown] : []);
    }

    /**
     * The write address a partner currently answers on, from presence, with the
     * key: that partner's own record, never another's. The lookup is by name
     * without regard to case, so with Ada and ADA in the book both came back,
     * and the first one online was taken whichever was asked for: a message
     * sealed to one key went to the other's inbox.
     *
     * @param string|array{name: string, key: string} $name a name, or the partner itself
     */
    public function addressFor(string|array $name): array
    {
        $partner = is_array($name) ? $name : $this->partnerByName($name);
        if ($partner === null) {
            throw new CodedInvalidArgument('unknown_partner', 'unknown partner: ' . $name);
        }
        $result = $this->lookup([$partner['name']]);
        foreach ($result['online'] as $entry) {
            if ($entry['name'] === $partner['name']) {
                return [$entry['w'], $partner['key']];
            }
        }
        throw new CodedRuntime('partner_offline', $partner['name'] . ' is not online right now');
    }

    // --------------------------------------------------------------- send --

    /**
     * Sends to a partner by name, or to a write address learned from presence
     * or from a message. Sealed to the partner's key, signed by ours, stored
     * in the outbox before the first attempt. Throws SendFailed when the
     * message did not land, with the outcome, and GateStop when the inbox
     * asks for something this client cannot do.
     */
    /**
     * $answers is the sha256 of the message this one answers, as read shows it.
     * It goes in the sealed body as re, so the other side can tell which of its
     * messages this is about.
     */
    public function send(string $to, ?string $text = null, mixed $data = null, ?string $replyTo = null, ?string $answers = null): array
    {
        self::checkRe($answers);
        [$w, $key, $partner] = $this->recipient($to);

        return $this->sendSealed($w, $key, $partner, $text, $data, $replyTo, null, $answers);
    }

    /**
     * Where a message for $to goes, the key it is sealed to, and the partner's
     * name when it is one. $to is a partner's name or key, reached at the
     * address presence gives, or a write address whose key this runtime knows:
     * from presence, or from reply_to or channel in a verified message. A send
     * and a handover find their recipient the same way.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function recipient(string $to): array
    {
        if (Codec::isKey($to)) {
            // The partner the key names, kept as it is: turned into its name and
            // looked up again, it could become another partner of that name.
            $partner = $this->partnerByKey($to);
            if ($partner === null) {
                throw new CodedInvalidArgument('unknown_partner', 'key is not in partners');
            }
            [$w, $key] = $this->addressFor($partner);

            return [$w, $key, $partner['name']];
        }
        if (Address::isW($to)) {
            $key = $this->peers[$to] ?? null;
            if ($key === null) {
                // What to do instead, said where it stops. An address pasted into
                // text or data is the dead end an agent on MCP walks into: the
                // tool that opens a channel returns an address, and nothing here
                // learns whose it is from reading it (item 5 of the round-2 list).
                throw new CodedRuntime('unknown_address', 'no key known for address ' . $to . '. A runtime learns whose an address is from presence, or from reply_to or channel in a verified message; one pasted into text or data binds nothing. Send to the partner by name, answer a message at its reply_to, or ask the owner to hand the address over: aamio_open_channel with to, or aamio board channel KEY --reply-to ADDRESS on the command line');
            }

            return [$to, $key, null];
        }
        $partner = $this->partnerByName($to);
        if ($partner === null) {
            throw new CodedInvalidArgument('unknown_partner', 'unknown partner: ' . $to);
        }
        [$w, $key] = $this->addressFor($partner);

        // The book's own spelling, so asking presence again after a 404 or a
        // 410 asks for this partner and no other.
        return [$w, $key, $partner['name']];
    }

    /**
     * One message sealed to $key and written to $w. $partner is the name
     * presence is asked again with when that address has gone, and null for
     * an address given as it is. $archivedData stands in for $data in the
     * archive, for a message carrying what no file should.
     */
    private function sendSealed(string $w, string $key, ?string $partner, ?string $text = null, mixed $data = null, ?string $replyTo = null, ?array $archivedData = null, ?string $answers = null): array
    {
        // What the inbox asks of writers is read before anything is stored,
        // with how long it still takes writes, so work that would not be done
        // in time, or not within a tool call, stops here with its reason. A no
        // that rests on a gate read earlier is checked against a fresh read
        // once, since the address may have a new inbox by now.
        $cached = array_key_exists($w, $this->gates);
        $plan = Gate::plan($this->gateFor($w), $this->client->secondsLeft($w), $this->client->workBudget);
        if ($plan['stop'] !== null && $cached) {
            $this->forgetGate($w);
            $plan = Gate::plan($this->gateFor($w), $this->client->secondsLeft($w), $this->client->workBudget);
        }
        if ($plan['stop'] !== null) {
            throw new GateStop($plan['stop']);
        }
        $inbox = $this->ensureInbox();
        $body = ['from' => $this->keys->hashPrefix, 'reply_to' => $replyTo ?? $inbox->w];
        if ($text !== null) {
            $body['text'] = $text;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }
        $body += $this->conversation($key, $answers);
        $plaintext = Codec::json($body);
        $envelope = $this->keys->seal($key, $plaintext);
        $entry = $this->outboxAdd($w, $key, $envelope, $body);
        [$status, $result] = $this->deliver($entry);
        if (in_array($status, [404, 410], true)) {
            $this->forgetGate($w);
        }
        if (in_array($status, [404, 410], true) && $partner !== null) {
            // The partner may have renewed its inbox. Ask presence again, once.
            [$w, $key] = $this->addressFor($partner);
            $envelope = $this->keys->seal($key, $plaintext);
            $entry = $this->outboxAdd($w, $key, $envelope, $body, $entry['id']);
            [$status, $result] = $this->deliver($entry);
        }
        $entry = $this->outbox[$entry['id']];
        $archived = $archivedData === null ? $body : ['data' => $archivedData] + $body;
        $record = ['kind' => 'sent', 'at' => time(), 'to' => $this->nameForKey($key) ?? $key, 'w' => $entry['w'], 'status' => $status, 'message_id' => $entry['id'], 'outcome' => $entry['status'], 'body' => $archived];
        // The archive is a record of the send, not the send. A failed write
        // after a 201 threw here, and the caller heard an error for a message
        // that was delivered, and might send it again as new bytes: a real
        // duplicate. It is said beside the outcome instead.
        $archiveError = null;
        try {
            $this->archive('sent', $record);
        } catch (\Throwable $error) {
            $archiveError = $error->getMessage();
            $this->logged('archive sent ' . $entry['id'] . ': ' . $archiveError);
        }
        if ($status !== 201) {
            throw new SendFailed($entry['status'], $entry['id'], $status, $result, null, $entry['trace_error'] ?? null, $archiveError);
        }
        $sent = ['to' => $record['to'], 'w' => $entry['w'], 'message_id' => $entry['id'], 'seq' => $result['seq'], 'at' => $result['at'], 'sha256' => $result['sha256'] ?? null, 'expire_at' => $result['expire_at'] ?? null, 'replies_arrive_on' => 'inbox', 'reply_to' => $body['reply_to']];
        if (isset($result['met'])) {
            $sent['met'] = $result['met'];
            $sent['proof_id'] = $result['proof_id'] ?? null;
        }
        if (!empty($entry['gate_notes'])) {
            $sent['notes'] = $entry['gate_notes'];
        }
        if ($archiveError !== null) {
            $sent['archive_error'] = $archiveError;
        }
        if (($entry['trace_error'] ?? null) !== null) {
            $sent['trace_error'] = $entry['trace_error'];
        }

        return $sent;
    }

    /** What could not be written to the archive since attention was last taken, each in its own words. */
    private array $archiveMissing = [];

    /**
     * Writes the record of something that is done already, and says what went
     * wrong instead of throwing it.
     *
     * The post is made, the answer delivered, the receipt taken. A record of it
     * that cannot be written is said beside it and in attention. Thrown, it
     * told the caller that the thing itself had failed: an answer on the board
     * was stored at the service and settled as delivered in the outbox while
     * the call threw, and over MCP the advice that went with it was to call
     * again, which is a second answer (a check of 12 November 2026, found
     * while the Node runtime's archive was built). A send has said it so since
     * September.
     */
    private function archiveDone(string $label, array $record, string $what): ?string
    {
        try {
            $this->archive($label, $record);
        } catch (\Throwable $error) {
            $how = $error->getMessage();
            // Attention keeps one note for each place and state, so a second
            // record that could not be written would take the place of the
            // first. Each is said, in the one note, until the note is taken.
            $this->archiveMissing[] = $what . ' could not be written to the archive: ' . $how . '.';
            $this->noteTrouble('archive', 'archive_failed', implode(' ', $this->archiveMissing) . ' The archive does not have ' . (count($this->archiveMissing) === 1 ? 'it' : 'them') . '.');

            return $how;
        }

        return null;
    }

    /** The gate kept for w, here and in the client, belongs to an inbox that may not be there now. */
    private function forgetGate(string $w): void
    {
        unset($this->gates[$w]);
        $this->client->forgetGate($w);
    }

    private function outboxAdd(string $w, string $key, string $envelope, array $body, ?string $replaces = null): array
    {
        // Every send passes here, so a message too large for any inbox stops
        // here, before an entry exists, a byte leaves or a gate is worked for.
        // It was stored first and refused by the client afterwards, which left a
        // message that could never go looking as if it might have (a health
        // check of 30 September 2026).
        if (strlen($envelope) > Client::MAX_MESSAGE_BYTES) {
            throw new CodedInvalidArgument('too_large', 'this message is ' . strlen($envelope) . ' bytes sealed, and a message is at most ' . Client::MAX_MESSAGE_BYTES . ', so nothing was stored or sent. Send a URL and a hash instead.');
        }
        $entry = [
            'id' => 'm-' . substr(Codec::sha256hex($this->keys->public . '|' . $w . '|' . $envelope), 0, 16),
            'w' => $w, 'to_key' => $key, 'envelope' => $envelope,
            'summary' => array_intersect_key($body, array_flip(['post', 'reply_to', 'channel'])),
            'created_at' => time(), 'attempts' => 0, 'status' => 'sending', 'last_status' => null, 'replaces' => $replaces,
            // For the trace: what the message was made of, without what it said.
            'shape' => self::messageShape($body, $envelope),
        ];
        $earlier = $this->outbox[$entry['id']] ?? null;
        $this->outbox[$entry['id']] = $entry;
        try {
            $this->saveOutbox();
        } catch (\Throwable $error) {
            // An entry that could not be written is taken out again: nothing was
            // sent for it, and left in memory the next save that worked wrote it
            // as a send in flight, which a restart made unknown and offered to
            // send again. A review of 30 September 2026.
            if ($earlier === null) {
                unset($this->outbox[$entry['id']]);
            } else {
                $this->outbox[$entry['id']] = $earlier;
            }

            throw $error;
        }

        return $entry;
    }

    /** What an inbox asks of writers, read once per address; {} when there is none or it cannot be read now. */
    private function gateFor(string $w): array
    {
        if (array_key_exists($w, $this->gates)) {
            return $this->gates[$w];
        }
        $gate = $this->client->gate($w, true);
        if ($gate !== null) {
            $this->gates[$w] = $gate;

            return $gate;
        }

        return [];
    }

    /** POST an envelope to an inbox with the work its gate asks for, answering a 428 once. */
    private function post(string $w, string $envelope, array &$notes): array
    {
        $this->posts++;
        try {
            $sent = $this->client->send($w, $envelope, true, null);
        } catch (\InvalidArgumentException | \LogicException $refused) {
            // Refused here before any byte left: an address that is not one, no
            // keys, or a message over the size. Not a post, so a failure of this
            // kind is never taken for one that may have landed (the second look
            // of 30 September 2026).
            $this->posts--;

            throw $refused;
        }
        $notes = array_values(array_unique(array_merge($notes, $sent['notes'])));
        if (!empty($sent['stopped'])) {
            throw new GateStop((string) ($sent['body']['error'] ?? 'the gate stopped the send'));
        }

        return [$sent['status'], $sent['body']];
    }

    /** Send the stored bytes once, and record what the answer allows us to claim. */
    private function deliver(array $entry): array
    {
        $id = $entry['id'];
        $this->outbox[$id]['attempts']++;
        $this->outbox[$id]['status'] = 'sending';
        try {
            $this->saveOutbox();
        } catch (\Throwable $error) {
            // Nothing has left this machine, and nothing will: the send stops here
            // with its reason, and the entry says so, as a stop at a gate does. As
            // sending, the next save that worked wrote it as a send in flight, and
            // a restart offered to send it again. Unless an earlier attempt is
            // still open: a stop now settles this decision, not that one.
            $this->outbox[$id]['status'] = ($this->outbox[$id]['ever_open'] ?? false) ? 'unknown' : 'stopped';
            $this->outbox[$id]['error'] = $error->getMessage();
            $this->outbox[$id]['last_at'] = time();

            throw $error;
        }
        $notes = [];
        try {
            [$status, $result] = $this->post($entry['w'], $entry['envelope'], $notes);
        } catch (GateStop $stop) {
            // Nothing left this machine and nothing will. That is not the same as the
            // service turning bytes away: refused with no answer behind it read as an
            // attempt that left, so forget said already_sending about a message the
            // transport had never been asked to send. Unless an earlier attempt is
            // still open -- a stop now settles this decision, not that one.
            $this->outbox[$id]['status'] = ($this->outbox[$id]['ever_open'] ?? false) ? 'unknown' : 'stopped';
            $this->outbox[$id]['error'] = $stop->getMessage();
            $this->outbox[$id]['last_at'] = time();
            $this->saveOutbox();
            throw $stop;
        } catch (\InvalidArgumentException | \LogicException $refused) {
            // The client refused it before any byte left: a message stored by an
            // older version, over the size an inbox takes. It is settled the way a
            // stop is, or it waited as a send that might have landed and every
            // retry met the same refusal (a health check of 30 September 2026).
            // An earlier attempt left open stays open.
            $this->outbox[$id]['status'] = ($this->outbox[$id]['ever_open'] ?? false) ? 'unknown' : 'stopped';
            $this->outbox[$id]['error'] = $refused->getMessage();
            $this->outbox[$id]['last_at'] = time();
            $this->saveOutbox();
            throw $refused;
        }
        if ($notes !== []) {
            $this->outbox[$id]['gate_notes'] = $notes;
        }
        $this->outbox[$id]['last_status'] = $status;
        $this->outbox[$id]['last_at'] = time();
        if ($status !== 201) {
            $this->outbox[$id]['error'] = is_array($result) ? ($result['error'] ?? null) : substr((string) $result, 0, 200);
        }

        if ($status === 201) {
            $this->outbox[$id]['status'] = 'delivered';
            $this->outbox[$id]['seq'] = is_array($result) ? ($result['seq'] ?? null) : null;
        } elseif ($status === 0) {
            // No reply at all. The bytes may be on the other side.
            $this->outbox[$id]['ever_open'] = true;
            $this->outbox[$id]['status'] = 'unknown';
        } elseif (in_array($status, self::SEND_NOT_STORED, true)) {
            // Turned away without being stored. It settles the message unless
            // something earlier was left open: a 410 an hour later says the thread is
            // gone now, not that an attempt which got no answer never landed.
            $this->outbox[$id]['status'] = ($this->outbox[$id]['ever_open'] ?? false) ? 'attempted' : 'refused';
        } else {
            // It answered, and its answer says nothing about whether it stored the
            // message first: a 500 is the service failing, not the service saying no.
            // This wore the status refused, and refused is not one of the statuses
            // outboxPending shows, so the one kind of message that most needs a
            // decision was the one kind that did not appear on the list.
            $this->outbox[$id]['ever_open'] = true;
            $this->outbox[$id]['status'] = 'attempted';
        }
        $this->saveOutbox();
        // After the outcome is saved, and unable to change it.
        // A script that sends once and exits never fetches attention, so the
        // failure rides on the record this send is about. It is this attempt's:
        // a retry whose trace was written does not go on saying that the first
        // attempt's could not be.
        $traceError = $this->traceSafely('sent', fn (): ?string => $this->traceSent($this->outbox[$id], $status, $result));
        unset($this->outbox[$id]['trace_error']);

        if ($traceError !== null) {
            $this->outbox[$id]['trace_error'] = $traceError;
        }

        return [$status, $result];
    }

    // ------------------------------------------------------------- outbox --

    public function outboxPending(): array
    {
        return array_values(array_filter($this->outbox, static fn (array $e): bool => in_array($e['status'], ['working', 'sending'], true) || self::outboxOpen($e)));
    }

    public function outboxRetry(?string $messageId = null): array
    {
        $out = [];
        foreach (array_keys($this->outbox) as $id) {
            $entry = $this->outbox[$id];
            if ($messageId !== null && $id !== $messageId) {
                continue;
            }
            // Worth sending again, which is not the same as unsettled: a rate window
            // is settled and is exactly what a second attempt is for. One the service
            // will refuse again, or has already stored, is not.
            if (!self::outboxRetryable($entry)) {
                continue;
            }
            try {
                [$status] = $this->deliver($entry);
            } catch (GateStop) {
                $status = 0;
            }
            $row = ['id' => $id, 'w' => $entry['w'], 'status' => $this->outbox[$id]['status'], 'http' => $status];
            // A retry run once from a script never fetches attention either.
            if (($this->outbox[$id]['trace_error'] ?? null) !== null) {
                $row['trace_error'] = $this->outbox[$id]['trace_error'];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Drops an entry once its fate no longer matters, and says whether it stopped anything.
     *
     * A send that is already away cannot be recalled. This runtime sends on the calling
     * thread, so the status is the signal: unknown means a post went out and no answer
     * came back. The tool description promised already_sending before this returned it,
     * which told a model to read a field that was not there.
     */
    public function outboxForget(string $messageId): array
    {
        $entry = $this->outbox[$messageId] ?? null;
        $outcome = self::outboxOutcome($entry);
        unset($this->outbox[$messageId]);
        $this->saveOutbox();

        return [
            'id' => $messageId,
            'forgotten' => $entry !== null,
            'already_sending' => !in_array($outcome, ['never_sent', 'not_found'], true),
            'outcome' => $outcome,
            'note' => self::OUTBOX_NOTES[$outcome],
        ];
    }

    public const OUTBOX_NOTES = [
        'never_sent' => 'nothing had left this machine for it, and nothing will now',
        'attempted' => 'a send was attempted and its answer does not prove the message is absent, so its outcome stays unknown and nothing here can recall it',
        'delivered' => 'the service had already stored it when this was dropped; dropping the entry does not unsend it',
        'refused' => 'the service answered that it refused this one, so it was not stored, but the attempt did leave this machine',
        'unknown' => 'a send was already away when this was dropped, so its outcome stays unknown and nothing here can recall it',
        'not_found' => 'this outbox has no message with that id, so nothing was dropped and nothing was sent',
    ];

    /**
     * Which of five things happened to a send, for a caller deciding whether to replace it.
     *
     * Only a stop before the first POST may be called safely unsent. An attempt answered
     * 500 does not prove the message is absent, and a delivered one is certainly not
     * unsent: both were reported as never sent until 20 September.
     */
    /**
     * Whether this message still has something to decide. One place, because two
     * places disagreed: pending listed a message and retry called the same message
     * settled, in the same runtime, in the same second.
     */
    public static function outboxOpen(?array $entry): bool
    {
        return in_array(self::outboxOutcome($entry), ['attempted', 'unknown'], true);
    }

    /**
     * Whether a message that carried a channel's address may be with them, for
     * the words in front of the fix. A refusal says the message is not there,
     * and one that never left is not there either. Anything else that left may
     * have arrived. "Only the message carrying its address did not go" was
     * said of all of them, and of a message that was with them it read as
     * leave to open another channel and invite again (a check of 12 November
     * 2026).
     */
    public static function handoverUnsettled(string $outcome): bool
    {
        return !in_array($outcome, ['refused', 'never_sent'], true);
    }

    /**
     * What comes first in the fix when a channel was opened and the message
     * carrying its address was stopped. $listed is where the caller sees its
     * channels: aamio_channels over the local server, `aamio channel list` on
     * the command line. $outcome is the send's, or null for a stop at the
     * gate, where nothing went.
     */
    public static function handoverStill(?array $opened, ?string $outcome, string $listed): string
    {
        if ($opened === null) {
            return '';
        }
        if ($outcome !== null && self::handoverUnsettled($outcome)) {
            return 'The channel ' . $opened['label'] . ' is open and listed by ' . $listed . ', and the message carrying its address may have arrived: its outcome is not known. '
                . 'Do not open another channel for this: a new attempt is the same message, under the same message_id. ';
        }

        return 'The channel ' . $opened['label'] . ' is open and listed by ' . $listed . ': only the message carrying its address did not go. ';
    }

    /**
     * What the command line prints for a send that failed or a stop at the
     * gate. With a channel that was opened for a message that was then
     * stopped, the channel is in opened and the fix says first that it is
     * open. Only the local server said that: on the command line it was a
     * thread the caller was never told about (a check of 12 November 2026).
     */
    public static function commandLineStop(SendFailed|GateStop $error): array
    {
        $opened = $error->opened === null ? [] : ['opened' => $error->opened];
        if ($error instanceof GateStop) {
            // Nothing was sent, so nobody has the address of a channel opened for it.
            $close = $error->opened === null ? '' : 'Nothing was sent, so nobody has been given its address: close it with `aamio channel close ' . $error->opened['label'] . '`, and open a new one when the inbox\'s conditions can be met. ';

            return $opened + ['error' => $error->getMessage(), 'error_code' => 'gate', 'fix' => self::handoverStill($error->opened, null, '`aamio channel list`') . $close . $error->fix];
        }
        [$retryable, $fix] = self::sendAdvice($error->outcome, $error->status);
        // A channel whose address never left, or was turned away: there is
        // nothing to send again, and an address goes only with a channel.
        if ($error->opened !== null && $error->outcome === 'never_sent') {
            $fix = 'Nothing left this machine, so nobody has been given its address. Close it with `aamio channel close ' . $error->opened['label'] . '`, put right what the error says, and open a new one.';
        } elseif ($error->opened !== null && $error->outcome === 'refused' && !in_array($error->status, self::SEND_TRY_LATER, true)) {
            $fix = 'aamio refused it, so nobody has been given its address. Close it with `aamio channel close ' . $error->opened['label'] . '`, put right what the error says, and open a new one.';
        }

        return $opened
            + ['error' => $error->getMessage(), 'error_code' => 'send_' . $error->outcome, 'outcome' => $error->outcome, 'message_id' => $error->messageId, 'status' => $error->status, 'retryable' => $retryable, 'fix' => self::handoverStill($error->opened, $error->outcome, '`aamio channel list`') . $fix]
            + $error->traceSaid() + $error->archiveSaid();
    }

    /**
     * Whether sending these same bytes again is the right next move. Not the same
     * question as outboxOpen, and retry was asking that one: a rate window is
     * settled -- the service turned the request away without reading the body -- and
     * it is also the one refusal whose advice is do not change the content, wait,
     * send it again. Retry refused to do the thing its own advice asked for.
     */
    public static function outboxRetryable(?array $entry): bool
    {
        if ($entry === null) {
            return false;
        }

        if (self::outboxOpen($entry)) {
            return true;
        }

        return self::outboxOutcome($entry) === 'refused'
            && in_array($entry['last_status'] ?? 0, self::SEND_TRY_LATER, true);
    }

    public static function outboxOutcome(?array $entry): string
    {
        if ($entry === null) {
            return 'not_found';
        }
        $status = $entry['status'] ?? '';
        if (in_array($status, ['delivered', 'unknown'], true)) {
            return $status;
        }
        if ($status === 'attempted') {
            // It left this machine and what came back settles nothing.
            return 'attempted';
        }
        if ($status === 'refused') {
            // Only what the service turned away without storing, and only when nothing
            // earlier was left open. An attempt that got no answer is not undone by any
            // later refusal, and ever_open keeps that; an entry written by an older
            // version has no such field and reads the same as it did.
            if ($entry['ever_open'] ?? false) {
                return 'attempted';
            }

            return in_array($entry['last_status'] ?? 0, self::SEND_NOT_STORED, true) ? 'refused' : 'attempted';
        }

        // This runtime sends on the calling thread and has no post flag, but the same
        // rule holds: a message still working on proof of work has an attempt counted
        // and has sent nothing.
        // A post that happened is history. This runtime sends on the calling thread,
        // so it cannot be caught mid-work, but an entry carried over from a Python
        // home, or a later change here, must not lose it to a rewritten status.
        if (($entry['posting'] ?? false) === true) {
            return 'attempted';
        }
        // working, or stopped: the proof of work never finished, or this machine
        // decided not to send at all. Both mean nothing left. Only stopped fell
        // through to the attempts count below, and attempts counts the call the work
        // runs inside.
        if (in_array($status, ['working', 'stopped'], true)) {
            return 'never_sent';
        }

        return ($entry['attempts'] ?? 0) > 0 ? 'attempted' : 'never_sent';
    }

    /** (retryable, fix) for one send outcome. retryable is about the same stored bytes, never a permission to repeat automatically. */
    public static function sendAdvice(string $outcome, int $status): array
    {
        if ($outcome === 'never_sent') {
            return [false, 'Nothing left this machine for this message, so it was not delivered and will not be: the error says what stopped it. Put that right, then send it as a new message. There is nothing to retry.'];
        }
        if ($outcome === 'unknown') {
            return [null, 'No answer came back, so this message may already have been delivered. Keep its message_id. aamio_pending lists what has no settled outcome on this machine; it does not confirm delivery, and nothing here can, because the address it went to is not yours to read. Do not pass the message_id to aamio_send and do not compose a replacement. An approved retry sends the stored bytes again: aamio_outbox_retry with this id, or aamio outbox retry --id on the command line.'];
        }
        if (in_array($status, self::SEND_TRY_LATER, true)) {
            return [true, sprintf('aamio declined this for now, not because of the message: %d is a rate window or a busy service. Do not change the content. Wait, then send the stored message again through the runtime\'s outbox retry rather than composing a new one.', $status)];
        }
        if (in_array($status, self::SEND_DETERMINISTIC, true)) {
            return [false, 'aamio refused this and will refuse the same bytes again. Read the error, correct the request, and send the corrected one as a new message.'];
        }

        return [null, sprintf('aamio answered %d, which this client does not classify. It may or may not have stored the message before failing, so treat delivery as unsettled: keep the message_id, read the error, and do not resend blindly.', $status)];
    }

    // ------------------------------------------------------------ effects --

    public function effect(string $key, ?string $fingerprint = null): array
    {
        $record = $this->effects[$key] ?? null;
        if ($record === null) {
            return ['state' => 'new', 'key' => $key];
        }
        if ($fingerprint !== null && !in_array($record['fingerprint'] ?? null, [null, $fingerprint], true)) {
            return ['state' => 'conflict', 'key' => $key, 'stored_fingerprint' => $record['fingerprint'], 'result' => $record['result'] ?? null];
        }

        return ['state' => 'done', 'key' => $key, 'result' => $record['result'] ?? null, 'at' => $record['at'] ?? null];
    }

    public function effectDone(string $key, mixed $result = null, ?string $fingerprint = null): array
    {
        $this->effects[$key] = ['fingerprint' => $fingerprint, 'result' => $result, 'at' => time()];
        $this->saveEffects();

        return ['state' => 'done', 'key' => $key, 'result' => $result];
    }

    // --------------------------------------------------------------- read --

    /** The documented field names, from whatever a sender called them. Returns [body, meta]. */
    public static function canonical(mixed $body): array
    {
        if (!is_array($body)) {
            return [$body, []];
        }
        $postSpellings = array_merge(['post'], self::ANSWER_ALIASES['post']);
        if (!array_intersect_key($body, array_flip($postSpellings))) {
            return [$body, []];
        }
        $renamed = [];
        $conflicts = [];
        foreach (self::ANSWER_ALIASES as $canonical => $spellings) {
            $present = array_values(array_filter($spellings, static fn (string $s): bool => isset($body[$s]) && (is_string($body[$s]) || is_int($body[$s]))));
            if (array_key_exists($canonical, $body)) {
                if (is_string($body[$canonical]) || is_int($body[$canonical])) {
                    foreach ($present as $s) {
                        if ((string) $body[$s] !== (string) $body[$canonical]) {
                            $conflicts[$s] = $body[$s];
                        }
                    }
                }
                continue;
            }
            if ($present !== []) {
                $body[$canonical] = $body[$present[0]];
                $renamed[$present[0]] = $canonical;
                foreach (array_slice($present, 1) as $s) {
                    if ((string) $body[$s] !== (string) $body[$present[0]]) {
                        $conflicts[$s] = $body[$s];
                    }
                }
            }
        }
        $meta = [];
        if ($renamed !== []) {
            $meta['renamed'] = $renamed;
        }
        if ($conflicts !== []) {
            $meta['conflicting_fields'] = $conflicts;
        }

        return [$body, $meta];
    }

    /**
     * A body's data as it was written: JSON's objects stay objects, keys and
     * all. Read as arrays, {"0": "sample"} was handed over as ["sample"] and {}
     * as [], so what a sender wrote was not what the reader got (a check of 13
     * November 2026). The rest of the body is read as arrays, as before.
     */
    private static function dataAsWritten(array $content, string $json): array
    {
        if (array_key_exists('data', $content)) {
            $written = json_decode($json, false);
            if ($written instanceof \stdClass && property_exists($written, 'data')) {
                $content['data'] = Codec::jsonValue($written->data);
            }
        }

        return $content;
    }

    /**
     * A body's JSON as arrays, as [true, value], or [false, null] when it is to
     * be read as text: not JSON, nested deeper than Codec::BODY_MAX_DEPTH, or
     * holding a number that is not finite. 1e999 decoded to INF here, and the
     * read that weighed it threw, so its channel answered "could not be read"
     * for as long as the thread lived, the messages behind it with it; nested
     * 510 deep it passed the decoding and threw in the answer after the cursor
     * had moved past it (a security review of 13 November 2026). As
     * aamio-python and the Node runtime read it.
     */
    private static function bodyJson(string $text): array
    {
        $parsed = json_decode($text, true, Codec::BODY_MAX_DEPTH + 1);
        if (json_last_error() !== JSON_ERROR_NONE || Codec::unfitJson($parsed) !== null) {
            return [false, null];
        }

        return [true, $parsed];
    }

    /** What was said, and separately, what can be trusted about it: [body, meta]. */
    public function open(array $message): array
    {
        $raw = is_string($message['body'] ?? null) ? $message['body'] : '';
        if (empty($message['verified']) || empty($message['from'])) {
            return [['text' => $raw], ['signed' => false, 'encrypted' => false, 'format' => 'unsigned']];
        }
        if (!Keys::isEnvelope($raw)) {
            [$isJson, $parsed] = self::bodyJson($raw);
            if (!$isJson) {
                return [['text' => $raw], ['signed' => true, 'encrypted' => false, 'format' => 'text']];
            }
            if (is_array($parsed) && !array_is_list($parsed)) {
                [$content, $extra] = self::canonical($parsed);

                return [self::dataAsWritten($content, $raw), ['signed' => true, 'encrypted' => false, 'format' => 'json'] + $extra];
            }

            return [['text' => $raw], ['signed' => true, 'encrypted' => false, 'format' => 'json']];
        }
        // Three things, and they used to share one catch: opening the envelope,
        // reading the bytes as text, and the text happening to be JSON. Only the first
        // two say anything about whether the message can be read. `for your eyes`,
        // encrypted and signed, opened correctly and came back as text: null, format:
        // unreadable, with the cursor moved past it -- a message that had arrived
        // intact, reported as one nobody could read, and then dropped.
        //
        // The plain branch above has always handed over text that is not JSON. This is
        // the same message with a lid on it.
        try {
            $plaintext = $this->keys->open((string) $message['from'], $raw);
        } catch (\Throwable $error) {
            return [['text' => null], ['signed' => true, 'encrypted' => true, 'format' => 'unreadable', 'error' => (new \ReflectionClass($error))->getShortName()]];
        }

        // PCRE rather than mbstring: this package has never needed an extension
        // beyond sodium, and the portable PHP the gate runs on has no mbstring.
        // preg_match with the u modifier is false on bytes that are not UTF-8.
        if (preg_match('//u', $plaintext) !== 1) {
            return [['text' => null], ['signed' => true, 'encrypted' => true, 'format' => 'unreadable', 'error' => 'NotUtf8']];
        }

        [$isJson, $parsed] = self::bodyJson($plaintext);

        if (!$isJson) {
            return [['text' => $plaintext], ['signed' => true, 'encrypted' => true, 'format' => 'text']];
        }
        if (is_array($parsed) && !array_is_list($parsed)) {
            [$content, $extra] = self::canonical($parsed);

            return [self::dataAsWritten($content, $plaintext), ['signed' => true, 'encrypted' => true, 'format' => 'json'] + $extra];
        }

        return [['text' => $parsed], ['signed' => true, 'encrypted' => true, 'format' => 'json']];
    }

    /** Decode one checked message. Failure never grants it sender authority. */
    private function readEntry(Channel $channel, array $message): array
    {
        try {
            $from = $message['from'] ?? null;
            $known = $this->nameForKey($from);
            [$body, $meta] = $this->open($message);
            $digest = $message['sha256'] ?? null;
            $replay = $channel->replayState(is_string($digest) ? $digest : null, ($message['verified'] ?? false) === true && is_string($from) ? $from : null);
            return ['channel' => $channel->label, 'w' => $channel->w, 'seq' => $message['seq'], 'at' => $message['at'], 'verified' => $message['verified'], 'from_key' => $from, 'known_contact' => $known !== null, 'sender' => $known ?? ($from ? 'unknown key' : 'unsigned'), 'sha256' => $digest, 'replay' => $replay === true, 'body' => $body, 'unverified_because' => $message['unverified_because'] ?? null] + $meta + ($replay === null ? ['replay_unknown' => true] : []);
        } catch (\Throwable $error) {
            $why = 'the message could not be checked here: ' . (new \ReflectionClass($error))->getShortName();
            return ['channel' => $channel->label, 'w' => $channel->w, 'seq' => $message['seq'], 'at' => $message['at'], 'verified' => false, 'from_key' => null, 'known_contact' => false, 'sender' => 'unsigned', 'sha256' => null, 'replay' => false, 'body' => ['text' => $message['body'] ?? null], 'signed' => false, 'encrypted' => false, 'format' => 'unreadable', 'error' => $why, 'unverified_because' => $why];
        }
    }

    /** One read of a channel from its cursor: [state, entries], state ok | expired | error. */
    /**
     * Reads one channel. With a limit, at most that many messages are handed over.
     *
     * The cursor then stops at the last message this call dealt with, and what
     * the service returned beyond it is fetched again by the next poll. It used
     * to be the caller that cut the list, after the cursor had moved past
     * everything: the messages over the limit were gone for good.
     *
     * A read that throws leaves nothing of itself in memory: the cursor, the
     * thread it was on, the hashes, what board replies keep and the bindings
     * are as they were, and the messages wait at the service for the next
     * read, which hands them over. One that threw halfway through its messages
     * kept the hashes of the ones it had taken, the next channel's save wrote
     * them down, and the next read handed those messages over marked replay,
     * never having handed them over at all (the release check of 13 November
     * 2026).
     *
     * A read that holds this one, and the reads within it, are one read for
     * the archive: see outerRead().
     */
    public function poll(Channel $channel, int $wait = 0, ?int $limit = null, ?int $maxBytes = null): array
    {
        return $this->outerRead(fn (): array => $this->pollHeld($channel, $wait, $limit, $maxBytes));
    }

    /** One poll, inside the read it belongs to. */
    private function pollHeld(Channel $channel, int $wait, ?int $limit, ?int $maxBytes): array
    {
        $before = [$channel->after, $channel->createdAt, $channel->expireAt, $channel->instance, $channel->gone, $channel->seen, $channel->seenFrom, count($channel->received), $this->peers];
        try {
            return $this->pollChannel($channel, $wait, $limit, $maxBytes);
        } catch (\Throwable $error) {
            [$channel->after, $channel->createdAt, $channel->expireAt, $channel->instance, $channel->gone, $channel->seen, $channel->seenFrom] = $before;
            $channel->received = array_slice($channel->received, 0, $before[7]);
            $this->peers = $before[8];

            throw $error;
        }
    }

    /** One read of one channel, as poll describes it: [state, entries]. */
    private function pollChannel(Channel $channel, int $wait, ?int $limit, ?int $maxBytes): array
    {
        $channel->leftWaiting = 0;
        $channel->moreAtService = false;
        $channel->moreAtLimit = false;
        $channel->stoppedAtBudget = false;
        // A muted inbox is read like any other, until it expires: each message
        // is judged against the address book below, and what a removed key
        // writes there is not handed over, whoever calls.
        // The channel's own allowlist goes with the read: the client checks
        // every message itself and keeps out what the list does not allow.
        // The limit goes with it too, so the service sends what was asked for
        // rather than everything, to be trimmed here after it arrived.
        $read = $this->client->read($channel->w, $channel->readKey, $channel->after, $wait, $channel->allow, $limit, $maxBytes);
        if ($read['status'] === 410) {
            $this->note($channel, 'expired', 'the thread at this address has expired, so anything written to it before now is gone and nothing more will arrive here');

            return ['expired', []];
        }
        if ($read['status'] !== 200) {
            $this->note($channel, 'unread', 'the service answered ' . $read['status'] . ', so this channel was not read and there may be messages waiting');

            return ['error', []];
        }
        // Three answers that used to read as a quiet inbox. No thread at the
        // address: never written to, swept after expiry, or taken by a
        // restart. A reset: the cursor was past everything the thread holds, so
        // the service read from the start. And a created_at that is not the one
        // this channel knew: a new thread at the same address whose count has
        // already passed the old cursor, which the service cannot flag, since
        // it does not know what this channel has seen. In all three the old
        // cursor and the old hashes belong to another thread.
        // Not $body: the loop below decodes each message into $body, and a
        // read of next after it got the last message's body instead.
        if (!is_array($read['body'] ?? null) || !is_array($read['body']['messages'] ?? null)) {
            $this->note($channel, 'unread', 'the service returned a malformed read answer; this channel was not read');
            return ['error', []];
        }
        $answer = $read['body'];
        if (($answer['exists'] ?? null) === false) {
            if (!$channel->gone) {
                $this->note($channel, 'gone', 'there is no thread at this address any more. It expired and was swept, or the service restarted and it went with it. A write opens a new one here with the default lifetime and without the allowlist or gate this channel was opened with' . ($channel->label === 'inbox' ? ', so a new inbox is opened for the partners' : ''));
            }
            $channel->gone = true;
            $channel->forgetThread();
            $this->saveState();

            return ['gone', []];
        }
        $channel->gone = false;
        $created = isset($answer['created_at']) ? (int) $answer['created_at'] : null;
        $expires = is_int($answer['expire_at'] ?? null) ? $answer['expire_at'] : null;
        $reset = $answer['reset'] ?? null;
        // A thread is known by when it was opened and when it expires. One that a
        // write opened after the service restarted has the service's own
        // lifetime, so it is told apart even when it was opened in the same second.
        $instance = is_string($answer['instance'] ?? null) ? $answer['instance'] : null;
        // The instance the service names the thread by decides where there is
        // one; when and how long it was opened for, where there is none.
        $known = $channel->instance !== null && $instance !== null;
        $another = $known
            ? $instance !== $channel->instance
            : $channel->createdAt !== null && (($created !== null && $created !== $channel->createdAt) || ($expires !== null && $expires !== $channel->expireAt));
        if ($another && !$reset) {
            $this->note($channel, 'restarted', $known
                ? 'the thread at this address is a new one, instance ' . $instance . ' where this channel knew instance ' . $channel->instance . ', so it is read again from the start'
                : 'the thread at this address is a new one, opened at ' . $created . ' and expiring at ' . $expires . ', where this channel knew one opened at ' . $channel->createdAt . ' and expiring at ' . $channel->expireAt . ', so it is read again from the start');
            $channel->forgetThread();

            return $this->poll($channel, 0, $limit, $maxBytes);
        }
        // A cursor kept by a client that did not keep the thread's instance,
        // this one before 10 November 2026 among them, cannot be checked against
        // it: a thread opened again at this address in the same second, with the
        // same lifetime and list, looks the same in every other field, and its
        // first messages were passed over without a word (a review of 10
        // November 2026). So the thread is read again from the start, once, and
        // all of it is handed over: what this channel handed over before comes
        // again marked replay, as after a restart. Nothing is passed over by its
        // hash, which is the hash of the text alone: another sender's message
        // with the same text has it too (the same review).
        if (!$another && !$reset && $channel->after > 0 && $channel->instance === null && $instance !== null) {
            $this->note($channel, 'reread', 'this channel\'s cursor, ' . $channel->after . ', was kept by a client that did not keep the thread\'s instance, so it cannot be told whether the thread here is the one that cursor was kept on. It is read again from the start this once: what this channel handed over before comes again, marked replay, or replay_unknown where an earlier version kept it without who sent it, and nothing is passed over.');
            $channel->after = 0;

            return $this->poll($channel, 0, $limit, $maxBytes);
        }
        if ($reset) {
            $this->note($channel, 'restarted', (is_array($reset) && is_string($reset['what'] ?? null)) ? $reset['what'] : 'the service read this thread from the start');
            $channel->observed = [];
        }
        // The channel follows the thread that is there, all of it at once: one
        // taken up after its cursor was forgotten, or read from the start by the
        // service after a restart, may expire at another time than the one
        // opened. A channel that took the new instance and kept the old expiry
        // chose an inbox that had expired, and one that expired later was given
        // up early (a review of 10 November 2026). The list this channel was
        // opened with is its own, and stays.
        if (($channel->createdAt === null || $another || $reset) && $expires !== null) {
            $channel->expireAt = $expires;
        }
        $channel->createdAt = $created;
        if ($instance !== null) {
            $channel->instance = $instance;
        }

        // What the budget kept out. The service answers a byte budget honestly:
        // whole messages only, because a signed message cut in half does not
        // verify. So a thread holding one message larger than the budget answers
        // with no messages and too_large naming it -- and that read as an empty
        // inbox for as long as nothing here looked at the field.
        $tooLarge = $answer['too_large'] ?? null;

        if (is_array($tooLarge)) {
            // The advice is this runtime's own. The service's speaks of the
            // header this read sent and of stepping past the message with after,
            // and whoever reads this note set max_bytes and holds no cursor.
            $this->note(
                $channel,
                'too_large',
                'message ' . ($tooLarge['seq'] ?? '?') . ' on this channel is ' . ($tooLarge['bytes'] ?? '?') . ' bytes and does not fit the byte budget this read asked for, so it was not sent. It is still there, what was written after it waits behind it, and every read at this budget will leave it. Read again with a larger budget to take it: max_bytes, or --max-bytes on the command line.',
                isset($tooLarge['seq']) ? [(int) $tooLarge['seq']] : null
            );
        }

        // And what it held back that does fit: more messages after the ones
        // sent. The service says more both when the count this read asked for
        // was reached and when the byte budget was, and the note says which: a
        // read for one message blamed a budget nobody had set (a review of 10
        // November 2026). Every row the service sent is observed or cut below.
        if (($answer['more'] ?? null) === true) {
            $channel->moreAtService = true;
            $channel->moreAtLimit = $limit !== null && count((array) ($read['observed'] ?? [])) >= $limit;
        }
        // Where this poll stops, when the limit is reached before the end of
        // what the service returned: the seq of the last message handed over.
        // Whatever lies beyond it, handed over or kept out, has not been dealt
        // with: it is not observed, not noted and not passed by the cursor,
        // and the next poll fetches it again.
        $handed = array_values((array) ($read['body']['messages'] ?? []));
        $keptOut = array_values((array) ($read['kept_out'] ?? []));
        $stopAt = null;
        if ($limit !== null && count($handed) > max(0, $limit)) {
            $stopAt = $limit > 0 ? (int) ($handed[$limit - 1]['seq'] ?? 0) : -1;
            $within = static fn (array $item): bool => (int) ($item['seq'] ?? 0) <= $stopAt;
            $channel->leftWaiting = count($handed) + count($keptOut);
            $handed = array_values(array_filter($handed, $within));
            $keptOut = array_values(array_filter($keptOut, $within));
            $channel->leftWaiting -= count($handed) + count($keptOut);
            $read['observed'] = array_values(array_filter((array) ($read['observed'] ?? []), $within));
        }
        // The budget is kept here as well, for what is handed over, measured as
        // it is handed over: the service counts its own form of each message,
        // and a read of 512 bytes handed over 712 (a review of 10 November 2026).
        // Each message is weighed when the ones before it have been taken, their
        // shares saved or not, their addresses bound and their hashes remembered,
        // so it is weighed against what this read has done rather than against a
        // plan. Planned ahead, a share was weighed as held under a name whose save
        // then failed, and came under a longer one: 2164 bytes through a budget of
        // 2124 (a review of the same evening). A message that does not fit is not
        // taken at all: nothing is shared, bound, remembered or observed for it,
        // and the cursor stays before it.
        $oversized = null;
        $used = 0;
        $budgetAt = null;
        $entries = [];
        // Messages signed by a key that has left the address book, on an inbox
        // that named it: passed by like the ones the list keeps out, and said.
        $withheld = [];
        // The last message this read dealt with, handed over or passed by.
        $dealtWith = null;
        // The outer read tidied before it began, and nothing tidies until it
        // returns: what it says of the archive is so as it returns. Tidied
        // here, channel by channel, a ceiling under one row took the first
        // channel's row when the hour passed before the second (the fifth
        // release check of 14 November 2026).
        foreach ($handed as $message) {
            $entry = $this->readEntry($channel, $message);
            if (($entry['verified'] ?? false) === true && $this->leftTheBook($channel, is_string($entry['from_key'] ?? null) ? $entry['from_key'] : null)) {
                $withheld[] = (int) $entry['seq'];
                $dealtWith = (int) $entry['seq'];
                continue;
            }
            if ($maxBytes !== null) {
                $weight = $this->weightAsHandedOver($channel, $message);
                if ($entries !== [] && $used + $weight > $maxBytes) {
                    $budgetAt = $dealtWith ?? (int) $entries[count($entries) - 1]['seq'];
                    break;
                }
                // Alone and still over the budget: it has come, so it is handed
                // over on its own rather than kept back for ever.
                if ($entries === [] && $weight > $maxBytes) {
                    $oversized = [(int) ($message['seq'] ?? 0), $weight];
                }
                $used += $weight;
            }
            $dealtWith = (int) $entry['seq'];
            $from = $entry['from_key'];
            $body = $entry['body'];
            if (is_string($entry['sha256'])) { $channel->remember($entry['sha256'], $entry['verified'] === true && is_string($entry['from_key']) ? $entry['from_key'] : null); }
            // Before the message is kept, archived or shown: a scope key in it
            // goes to scopes.json or nowhere, never to the reader.
            $this->takeScopeShare($entry);
            // A verified message that carries an address binds the sender's key
            // to it: reply_to, as always, and channel, which a handoff carries.
            // A handoff used to leave the new address without a key, so the
            // first send to it failed with no key known for the address. And
            // an address another key already holds is not rebound by a claim.
            if (is_array($body) && $entry['verified'] === true && $from) {
                foreach (['reply_to', 'channel'] as $field) {
                    $this->bindClaimedAddress($channel, $entry, $field, $body[$field] ?? null, $from);
                }
            }
            // And the trace: what came from this key, and what it says it read of
            // ours. Verified only, since an unsigned message can claim to be from
            // anyone and to have read anything. Whatever goes wrong in the trace
            // costs the trace, never this batch, and is said in attention, not on
            // the message: a message has to be the size it was weighed at, and an
            // error added after the weighing let 1036 bytes through a budget of
            // 768 (a review of 10 November 2026).
            if ($entry['verified'] === true && $from) {
                $this->traceSafely('received', fn (): ?string => $this->traceReceived($channel, $entry));
            }
            // Received, readable, archived and handled are four different things.
            try {
                // With the thread's instance: a thread opened again at the same
                // address in the same second gives a message the same seq and the
                // same time as one of the old thread, and board replies had
                // nothing else to tell the two by (a review of 11 November 2026).
                // One row a delivery: one this process wrote at a read that did
                // not get as far as its cursor, or an earlier process wrote and
                // stopped before it, is used again when it holds the same
                // message, and was written down a second time (the release check
                // of 14 November 2026). A row without the thread's instance,
                // from a version before it was kept, could be another delivery,
                // so both are kept.
                $delivery = is_string($channel->instance) ? Codec::json([$channel->w, $channel->instance, (int) $entry['seq']]) : null;
                // The file first: one that is another now, or shorter, makes
                // this process forget what it wrote to it, and that is looked
                // for in it again (the third release check of 14 November 2026).
                $before = $delivery !== null && $this->archiveEnabled && !$this->homeReleased ? $this->archivedBefore($channel->label) : [];
                $already = $delivery !== null && isset($this->writtenDown[$delivery]);
                if (!$already && $delivery !== null && $this->archiveEnabled && !$this->homeReleased) {
                    $earlier = $before[$delivery] ?? null;
                    $already = $earlier !== null && $earlier === ($entry['sha256'] ?? null);
                    if ($already) {
                        $this->writtenDown[$delivery] = Storage::archiveName($channel->label);
                        $this->rowsReused[$delivery] = ($this->rowsReused[$delivery] ?? 0) + 1;
                    }
                }
                if ($already) {
                    $entry['archived'] = true;
                } else {
                    $row = $entry + ['kind' => 'received'];
                    if (is_string($channel->instance)) {
                        $row['instance'] = $channel->instance;
                    }
                    $wrote = $this->archive($channel->label, $row);
                    $entry['archived'] = $wrote;

                    if (!$wrote) {
                        // Nothing went wrong and nothing was written: this folder keeps
                        // nothing decrypted. Said, so the caller does not go looking.
                        $entry['archive_off'] = true;
                    } elseif ($delivery !== null) {
                        $this->writtenDown[$delivery] = Storage::archiveName($channel->label);
                    }
                }
            } catch (\Throwable $error) {
                // Said in attention, not on the message: a message has to be the
                // size it was weighed at, and an error of any length added after
                // the weighing let 1070 bytes through a budget of 776 (a review of
                // 10 November 2026). false is the longer of the two marks, and the
                // one the budget counts.
                $entry['archived'] = false;
                $this->note($channel, 'archive_failed', 'message ' . $entry['seq'] . ' was handed over and could not be written to the archive: ' . $error->getMessage() . '. The archive does not have it.', [(int) $entry['seq']]);
            }
            // The copy board replies keep, with the archive's mark and the
            // thread's instance, which the message handed over does not carry:
            // one delivery is known by its address, instance and seq in memory
            // as it is in the archive (a check of 11 November 2026).
            $channel->received[] = $entry + (is_string($channel->instance) ? ['instance' => $channel->instance] : []);
            $entries[] = $entry;
        }
        // Messages whose bytes this channel handed over before, which an earlier
        // version recorded without who sent them: whether each is a copy cannot
        // be told, and it is said rather than guessed.
        $unknown = array_values(array_map(static fn (array $entry): int => (int) $entry['seq'], array_filter($entries, static fn (array $entry): bool => ($entry['replay_unknown'] ?? false) === true)));
        if ($unknown !== []) {
            $this->note($channel, 'replay_unknown', 'these carry the bytes of a message this channel handed over before, which an earlier version recorded without who sent it, so whether each is a copy cannot be told: each comes with replay false and replay_unknown true.', $unknown);
        }
        if ($budgetAt !== null) {
            $within = static fn (array $item): bool => (int) ($item['seq'] ?? 0) <= $budgetAt;
            $keptOut = array_values(array_filter($keptOut, $within));
            $read['observed'] = array_values(array_filter((array) ($read['observed'] ?? []), $within));
            $stopAt = $budgetAt;
            $channel->stoppedAtBudget = true;
        }
        if ($oversized !== null) {
            $this->note($channel, 'over_budget', 'message ' . $oversized[0] . ' on this channel is ' . $oversized[1] . ' bytes as handed over here, larger than the whole byte budget of ' . $maxBytes . ' this read asked for. It had already come, so it is handed over on its own rather than kept back for ever, and this read is over its budget on purpose.');
        }
        // The rows belong to the thread they were read on. After the service
        // restarted, a receipt of the new thread at the same address was
        // compared with rows of the old one (a review of 10 November 2026).
        if (($read['observed'] ?? []) !== [] && [$channel->observedCreatedAt, $channel->observedExpireAt, $channel->observedInstance] !== [$channel->createdAt, $channel->expireAt, $channel->instance]) {
            $channel->observed = [];
            $channel->observedCreatedAt = $channel->createdAt;
            $channel->observedExpireAt = $channel->expireAt;
            $channel->observedInstance = $channel->instance;
        }
        foreach ($read['observed'] ?? [] as $observed) {
            $channel->observed[$observed['seq']] = $observed;
            if ($observed['unverified_because'] !== null && $observed['service_verified']) {
                $disposition = $observed['kept_out'] ? 'kept out by this channel\'s list' : 'handed over as unverified';
                $this->note($channel, 'unverified', 'message ' . $observed['seq'] . ' was called verified by the service and does not check out here: ' . $observed['unverified_because'] . '. It is ' . $disposition . '. This can indicate a faulty service or an operator that lies.', [$observed['seq']]);
            }
        }
        // The cursor moves once, to where this read leaves it, and is written
        // down once, with the hashes, before the caller sees a message. Two
        // writes in one read let attention say the cursor stayed at 0 when the
        // second had written 1 (a check of 11 November 2026). It moves past
        // messages the list kept out as well, or the same messages are read and
        // kept out on every call.
        //
        // After a reset the service's next is the cursor, and it is lower than
        // the one this channel held. Keeping the higher of the two would ask
        // past the new thread on every call and hand the same messages over
        // each time. A poll that stopped at its limit is the exception: the
        // service's next covers messages this call never looked at, so the
        // cursor is the last one it did look at.
        $to = $channel->after;
        $moved = $entries !== [] || $keptOut !== [] || $withheld !== [];
        if ($entries !== []) {
            $to = max($to, (int) end($entries)['seq']);
        }
        $seqs = array_map('intval', array_column($keptOut, 'seq'));
        if ($seqs !== []) {
            $to = max($to, max($seqs));
        }
        // Past the messages of a key that has left the book as well, or they
        // are passed by again on every read.
        if ($withheld !== []) {
            $to = max($to, max($withheld));
        }
        if ($stopAt !== null) {
            if ($stopAt > 0) {
                $to = $stopAt;
                $moved = true;
            }
        } elseif (is_int($answer['next'] ?? null)) {
            $to = $answer['next'];
            $moved = true;
        }
        if ($moved) {
            $this->saveCursor($channel, $to);
        }
        if ($keptOut !== []) {
            // Said, since a message that does not arrive has to be told from
            // one that was never sent.
            $openedFor = in_array('*', $channel->allow, true) ? 'any key, signed only' : count($channel->allow) . ' named key(s)';
            $this->note($channel, 'kept_out', 'This channel was opened for ' . $openedFor . ', and these messages did not satisfy its list as checked here. They are not handed over and not archived.', $seqs);
        }
        if ($withheld !== []) {
            // Said as well: what the removed key writes is passed by, never dropped in silence.
            $this->note($channel, 'removed_key', 'These messages are signed by a key that is no longer in the address book, on an inbox that was given to it. They are not handed over and not archived. Add the key as a partner again to take what it writes from then on.', $withheld);
        }

        return ['ok', $entries];
    }

    /**
     * Moves the cursor and writes it down with the hashes, and says so when the
     * writing fails. The save used to throw out of poll after the messages had
     * been taken: read() called the channel unread and handed none of them
     * over, while the cursor in memory had moved past them, so a process that
     * went on reading never handed them over at all, and what had been said of
     * them, replay_unknown among it, was said of messages nobody got (found
     * while the review of 11 November 2026 was answered). Now what arrived is
     * handed over, the cursor stays where state.json has it, and the next read
     * hands the same messages over again, marked replay while this process
     * runs: a message twice is a nuisance, a message never is a loss. As in
     * aamio-python.
     */
    private function saveCursor(Channel $channel, int $to): void
    {
        $was = $channel->after;
        $channel->after = $to;
        try {
            $this->saveState();
        } catch (\Throwable $error) {
            $channel->after = $was;
            $this->note($channel, 'unsaved', 'this machine could not write down what it had read: ' . $error->getMessage() . '. The cursor stays at ' . $was . '. Nothing was lost: what arrived was handed over, and anything whose place was not written down arrives again, marked as a replay while this process runs. Free some room or fix the permissions on the home folder, and check aamio doctor.');

            return;
        }
        // What the cursor is now past is never read again here.
        foreach (array_keys($this->writtenDown) as $delivery) {
            $held = json_decode((string) $delivery, true);
            if (is_array($held) && ($held[0] ?? null) === $channel->w && (int) ($held[2] ?? 0) <= $to) {
                unset($this->writtenDown[$delivery]);
            }
        }
    }

    /**
     * The bytes of a message as this read would hand it over, worked out on a
     * copy and keeping nothing, when the messages before it in the answer have
     * been taken: the scope share in it at the longer of the forms it can take,
     * a replay, the binding conflicts it would carry, and the archive's mark.
     * Compact JSON in UTF-8, as the message goes out.
     */
    private function weightAsHandedOver(Channel $channel, array $message): int
    {
        $entry = $this->readEntry($channel, $message);
        $this->takeScopeShare($entry, true, true);
        $from = $entry['from_key'] ?? null;
        if (is_array($entry['body'] ?? null) && ($entry['verified'] ?? false) === true && is_string($from) && $from !== '') {
            foreach (['reply_to', 'channel'] as $field) {
                $address = $entry['body'][$field] ?? null;
                if (!is_string($address) || !Address::isW($address)) {
                    continue;
                }
                $bound = $this->peers[$address] ?? null;
                if ($bound !== null && $bound !== $from) {
                    $entry['binding_conflicts'][] = ['field' => $field, 'address' => $address, 'claimed_by' => $from, 'bound_to' => $bound];
                }
            }
        }
        // The archive's mark is weighed at its longer value, false: a write that
        // fails turns true into false after the weighing, and a budget must hold
        // for what is handed over. One written is a byte under.
        $entry['archived'] = false;
        if (!($this->archiveEnabled && !$this->homeReleased)) {
            $entry['archive_off'] = true;
        }

        return strlen(Codec::json($entry));
    }

    /** Something a caller has to hear about, even though the read returned no messages. */
    /**
     * A verified message that names an address binds its signer's key to it,
     * once. The signature proves who made the claim, not that the claimant
     * holds an address another key is already bound to: on 21 September 2026
     * a stranger's signed message naming a partner's address in channel
     * replaced that partner's key, and the next send there was sealed to the
     * stranger. A first claim is learned, the same key again changes nothing,
     * and a different key is a conflict: the binding stays, the message still
     * arrives, and the conflict is on the entry and in attention. Whoever
     * holds the address reaches this side through the partner list or a fresh
     * handoff, not by claiming.
     */
    private function bindClaimedAddress(Channel $channel, array &$entry, string $field, mixed $address, string $key): void
    {
        if (!is_string($address) || !Address::isW($address)) {
            return;
        }
        $bound = $this->peers[$address] ?? null;
        if ($bound === null) {
            $this->peers[$address] = $key;

            return;
        }
        if ($bound === $key) {
            return;
        }
        $entry['binding_conflicts'][] = ['field' => $field, 'address' => $address, 'claimed_by' => $key, 'bound_to' => $bound];
        $this->note($channel, 'binding_conflict', 'message ' . $entry['seq'] . ' names ' . $address . ' as ' . $field . ', signed by ' . ($this->nameForKey($key) ?? $key) . ', and that address is already bound to ' . ($this->nameForKey($bound) ?? $bound) . '. The binding is kept: a signature proves who made the claim, not who holds the address. Reach the claimant through the partner list or a fresh handoff.', [(int) $entry['seq']]);
    }

    // ------------------------------------------------------------- trace --
    //
    // A conversation that goes wrong is hard to take apart from one side. On 25
    // September 2026 a participant answered, more than once, that the text of our
    // messages was missing, while the send log said non-empty and delivered. Both
    // can be true: every message this runtime sends is sealed to the key it goes
    // to, and a reader without that key sees an envelope and no text. So each
    // message now says, inside the sealed body, which message it answers (re) and
    // the last one this side read and opened from the other (seen), and trace()
    // lays the two sides next to each other as hashes: the sha256 the service
    // stored, byte for byte, is the one the other side read, or it is not. The
    // same as aamio-python.
    //
    // Each field is a claim about the one message it names. The review of the
    // same evening found the first version saying more than it knew: one seen
    // marked every earlier send as read, a side channel nobody had opened
    // included (R1); a message that arrived and could not be opened moved seen
    // all the same, and so did an old one sent again (R2); and a trace file of
    // the wrong shape turned a delivered send into an error, which invites the
    // same message twice (R3).

    public const TRACE_KEEP = 50;
    public const TRACE_KEYS = 100;

    /** What a row of the trace may hold, and of what type. */
    private const TRACE_SENT_FIELDS = ['message_id' => 'string', 'at' => 'int', 'w' => 'string', 'status' => 'int', 'outcome' => 'string', 'seq' => 'int', 'sha256' => 'hash', 'sealed' => 'bool', 'bytes' => 'int', 'fields' => 'list', 'text_chars' => 'int', 're' => 'hash', 'seen' => 'hash'];
    private const TRACE_RECEIVED_FIELDS = ['at' => 'int', 'channel' => 'string', 'w' => 'string', 'seq' => 'int', 'sha256' => 'hash', 'encrypted' => 'bool', 'format' => 'string', 'error' => 'string', 'fields' => 'list', 'text_chars' => 'int', 're' => 'hash', 'seen' => 'hash'];
    private const TRACE_READ_FIELDS = ['sha256' => 'hash', 'seq' => 'int', 'w' => 'string', 'at' => 'int'];

    /** The sha256 of a message as the service gives it: 64 lowercase hex characters. */
    public static function isMessageHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /** What a message was made of, for the trace, without what it said. */
    private static function messageShape(array $body, string $envelope): array
    {
        $fields = array_keys($body);
        sort($fields);
        $shape = ['bytes' => strlen($envelope), 'fields' => $fields, 'text_chars' => is_string($body['text'] ?? null) ? (int) preg_match_all('/./us', $body['text']) : null];
        foreach (['re', 'seen'] as $field) {
            if (self::isMessageHash($body[$field] ?? null)) {
                $shape[$field] = $body[$field];
            }
        }

        return $shape;
    }

    /**
     * How many field names a row keeps, and how long a name may be, in
     * characters. The names of a message that arrived are its sender's to
     * choose: one message of the largest size can carry thousands of them,
     * fifty such from one key were megabytes in a file that is written again
     * at every read, and a board inbox takes any signed key (found while the
     * Node runtime's trace was built, 12 November 2026). The fields the three
     * runtimes write are a handful of short names, so what is cut is only what
     * was made to be long.
     */
    private const TRACE_FIELDS = 32;
    private const TRACE_FIELD_CHARS = 64;

    private static function traceValue(string $kind, mixed $value): mixed
    {
        if ($kind === 'list') {
            if (!is_array($value) || !array_is_list($value)) {
                return null;
            }
            $short = static function (mixed $item): bool {
                if (!is_string($item)) {
                    return false;
                }
                if (strlen($item) <= self::TRACE_FIELD_CHARS) {
                    return true;
                }
                // Longer in bytes: counted in characters, as the other two runtimes count.
                $chars = preg_match_all('/./us', $item);

                return $chars !== false && $chars <= self::TRACE_FIELD_CHARS;
            };
            $strings = array_values(array_filter($value, $short));
            sort($strings, SORT_STRING);

            return array_slice($strings, 0, self::TRACE_FIELDS);
        }

        return match ($kind) {
            'hash' => self::isMessageHash($value) ? $value : null,
            'int' => is_int($value) ? $value : null,
            'bool' => is_bool($value) ? $value : null,
            'string' => is_string($value) ? $value : null,
        };
    }

    /** One row of a trace as this runtime writes it: the fields it knows, each of the type it writes or null. */
    private static function traceRow(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $kind) {
            if (array_key_exists($name, $row)) {
                $out[$name] = self::traceValue($kind, $row[$name]);
            }
        }

        return $out;
    }

    /**
     * Rows as the file held them: a list of objects, anything else left out. A
     * row without what names it, the message id of a send or the hash of what
     * arrived, says nothing and is left out too.
     */
    private static function traceRows(mixed $found, array $fields, int $keep, string $required): array
    {
        $rows = [];
        foreach (is_array($found) && array_is_list($found) ? $found : [] as $item) {
            $row = $item instanceof \stdClass ? self::traceRow(get_object_vars($item), $fields) : [];
            if (($row[$required] ?? null) !== null) {
                $rows[] = $row;
            }
        }

        return array_slice($rows, -$keep);
    }

    /**
     * trace.json as this runtime writes it, whatever the file held: a book per
     * key, rows of a known shape, within the bounds. On 25 September 2026 a
     * nested field of the wrong type reached the code that records a send,
     * after the message had gone, and the caller heard an error about a message
     * that was delivered.
     */
    private static function normalizeTraces(mixed $traces, int $keep, int $keys): array
    {
        $books = [];
        foreach ($traces instanceof \stdClass ? get_object_vars($traces) : [] as $key => $book) {
            if (!is_string($key) || !Codec::isKey($key) || !$book instanceof \stdClass) {
                continue;
            }
            $last = ($book->last_read ?? null) instanceof \stdClass ? self::traceRow(get_object_vars($book->last_read), self::TRACE_READ_FIELDS) : null;
            $books[$key] = [
                'sent' => self::traceRows($book->sent ?? null, self::TRACE_SENT_FIELDS, $keep, 'message_id'),
                'received' => self::traceRows($book->received ?? null, self::TRACE_RECEIVED_FIELDS, $keep, 'sha256'),
                'last_read' => $last !== null && ($last['sha256'] ?? null) !== null ? $last : null,
                'active' => is_int($book->active ?? null) ? $book->active : 0,
            ];
        }
        if (count($books) > $keys) {
            $by = array_map(static fn (array $book): int => $book['active'], $books);
            asort($by);
            foreach (array_slice(array_keys($by), 0, count($books) - $keys) as $stale) {
                unset($books[$stale]);
            }
        }

        return $books;
    }

    /**
     * One counterpart's record, with each message sent next to what came back
     * about it. A claim is exact: a verified message from them whose seen names
     * a sha256 says their runtime read and opened that message, and nothing
     * more, not the ones sent before it and not another channel's. The first
     * version read one seen as covering every earlier send, so a side channel
     * nobody had read showed as read (R1). A message nothing names is unknown,
     * never unread.
     */
    private static function traceView(array $book, int $limit): array
    {
        $sentRows = array_values(array_filter($book['sent'] ?? [], 'is_array'));
        $receivedRows = array_values(array_filter($book['received'] ?? [], 'is_array'));
        $claimed = [];
        $answered = [];
        $mine = [];
        foreach ($receivedRows as $row) {
            if (self::isMessageHash($row['seen'] ?? null)) {
                $claimed[$row['seen']] = true;
            }
            if (self::isMessageHash($row['re'] ?? null)) {
                $answered[$row['re']] = true;
            }
        }
        foreach ($sentRows as $row) {
            if (self::isMessageHash($row['sha256'] ?? null)) {
                $mine[$row['sha256']] = $row;
            }
        }
        $delivered = array_values(array_filter($sentRows, static fn (array $row): bool => ($row['status'] ?? null) === 201 && self::isMessageHash($row['sha256'] ?? null)));
        $noClaim = [];
        foreach ($delivered as $row) {
            if (!isset($claimed[$row['sha256']])) {
                $noClaim[] = $row['message_id'] ?? null;
            }
        }
        $sent = [];
        foreach (array_slice($sentRows, -$limit) as $record) {
            $stored = ($record['status'] ?? null) === 201 && self::isMessageHash($record['sha256'] ?? null) ? $record['sha256'] : null;
            $record['seen_by_them'] = $stored !== null && isset($claimed[$stored]) ? true : null;
            $record['answered_by_them'] = $stored !== null && isset($answered[$stored]) ? true : null;
            $sent[] = $record;
        }
        $received = [];
        foreach (array_slice($receivedRows, -$limit) as $record) {
            foreach (['re' => 'answers', 'seen' => 'acknowledges'] as $field => $as) {
                if (self::isMessageHash($record[$field] ?? null)) {
                    $ours = $mine[$record[$field]] ?? null;
                    $record[$as] = $ours !== null ? ['seq' => $ours['seq'] ?? null, 'sha256' => $ours['sha256'], 'at' => $ours['at'] ?? null] : ['sha256' => $record[$field], 'note' => 'no confirmed send in this record has this sha256'];
                }
            }
            $received[] = $record;
        }

        return ['sent' => $sent, 'received' => $received, 'no_read_claim' => $noClaim, 'note' => self::traceNote($sentRows, $receivedRows, $delivered, $noClaim, count(array_diff_key($claimed, $mine)))];
    }

    /**
     * What the record says, in words, and no more than it says. A send the
     * service did not confirm is not a send it did not store: the answer can be
     * lost after the message was stored. The first version said the service
     * stored none of the messages in a trace whose one send had an unknown
     * outcome and had been stored all the same, and whoever trusts that sends
     * the text again as new bytes, which is a second message. A claim naming
     * such a send was put down to a message older than the record or sent from
     * elsewhere. R5 of the follow-up review of 25 September 2026.
     */
    private static function traceNote(array $sent, array $received, array $delivered, array $noClaim, int $elsewhere): string
    {
        if ($sent === []) {
            return 'Nothing sent to them is recorded here.';
        }
        $named = false;
        foreach ($received as $row) {
            $named = $named || self::isMessageHash($row['seen'] ?? null);
        }
        $turnedAway = count(array_filter($sent, static fn (array $row): bool => ($row['outcome'] ?? null) === 'refused' && ($row['status'] ?? null) !== 201));
        $unsettled = count($sent) - count($delivered) - $turnedAway;
        if ($delivered === []) {
            $note = 'No message here has an answer from the service that confirms it was stored.';
        } elseif (!$named) {
            $said = $received !== [] ? 'Nothing from them has named a message of yours as read' : 'Nothing has come back from them';
            $note = $said . ', so each is unknown, not unread. A client that does not send seen says nothing, and neither does one that cannot open what it gets: every message here is sealed to their key, so a reader without it sees an envelope and no text. The sha256 of each message is what the service stored, byte for byte; ask them for the sha256 they read.';
        } else {
            $note = count($delivered) . ' delivered, and their runtime says it read and opened ' . (count($delivered) - count($noClaim)) . ' of them.';
            if ($noClaim !== []) {
                $note .= ' For ' . count($noClaim) . ' there is no claim kept here: that is unknown, not unread, since each message from them names only the last of yours it had read when it was written, and this record keeps fifty each way.';
            }
        }
        if ($elsewhere > 0) {
            $note .= ' They named ' . $elsewhere . ' message(s) this record cannot match to a send the service confirmed: older than this record, sent from elsewhere, or sent from here without an answer that confirmed it.';
        }
        if ($unsettled > 0) {
            $note .= ' For ' . $unsettled . ' no answer settled whether the service stored it, so it may be stored already. Check each one\'s status and outcome first, and if it must go again, retry it from the outbox: the same bytes are marked a replay where the first arrived, and new bytes would be a second message.';
        }
        if ($turnedAway > 0) {
            $note .= ' The service turned away ' . $turnedAway . '; the status of each says why.';
        }
        if ($named) {
            $note .= ' A claim covers the one message it names. It says their runtime opened it, not that anyone understood it or acted on it.';
        }

        return $note;
    }

    /** The record for one key, made when first needed and kept to a bound. */
    private function &traceBook(string $key): array
    {
        if (!is_array($this->traces[$key] ?? null)) {
            $this->traces[$key] = ['sent' => [], 'received' => [], 'last_read' => null, 'active' => 0];
        }
        $this->traces[$key]['active'] = time();
        if (count($this->traces) > self::TRACE_KEYS) {
            $by = array_map(static fn ($book): int => (int) (is_array($book) ? ($book['active'] ?? 0) : 0), $this->traces);
            asort($by);
            foreach (array_slice(array_keys($by), 0, count($this->traces) - self::TRACE_KEYS) as $stale) {
                if ($stale !== $key) {
                    unset($this->traces[$stale]);
                }
            }
        }

        return $this->traces[$key];
    }

    /** Written when it changes. A trace that cannot be written costs the trace and nothing else. */
    private function saveTrace(): ?string
    {
        try {
            $this->saveJson('trace.json', $this->traces === [] ? new \stdClass() : $this->traces);
        } catch (\Throwable $error) {
            return $this->untraced('trace.json: ' . $error->getMessage());
        }

        return null;
    }

    /**
     * A trace that did not happen, said in the two places a caller may be looking.
     *
     * Costing the trace is the contract and it still holds. Costing it in
     * silence was not: the logger is a no-op unless the caller set one, and a
     * failure with no reader is a failure nobody can act on. That is one way a
     * trace goes missing without a word; it is not established that it was the
     * way it happened the time this was found, so this closes the silence
     * without claiming to have closed the case.
     *
     * attention has to be fetched, and a script that sends once and exits never
     * fetches it, so the line is also returned for the caller to put on the
     * answer that call is about to hand back.
     *
     * The line reaches the log through noteTrouble, which puts its channel in
     * front of it, as it does for every other note.
     */
    private function untraced(string $line): string
    {
        try {
            $this->noteTrouble(
                'trace',
                'untraced',
                $line . ' The sends and reads themselves are unaffected; what is behind is the record of them.'
            );
        } catch (\Throwable) {
            // Never at the cost of the thing being traced, even here.
            try {
                $this->logged($line);
            } catch (\Throwable) {
            }
        }

        return $line;
    }

    /**
     * The trace is diagnostics: whatever goes wrong in it costs the trace, never
     * the send or the read it records.
     *
     * The line comes back out rather than being written into something passed
     * in: the update is a closure with no arguments here, and a field on the
     * runtime would belong to whichever send finished last.
     */
    private function traceSafely(string $what, callable $update): ?string
    {
        try {
            return $update();
        } catch (\Throwable $error) {
            return $this->untraced('trace ' . $what . ': not recorded: ' . (new \ReflectionClass($error))->getShortName() . ': ' . $error->getMessage());
        }
    }

    /** The two fields a message carries about the conversation it is part of. */
    private function conversation(string $key, ?string $answers = null): array
    {
        $fields = [];
        if ($answers !== null) {
            $fields['re'] = $answers;
        }
        // seen is the last message from this key that was read here and could be
        // opened. A trace that cannot say leaves it out, and never stops a send.
        try {
            $last = $this->traces[$key]['last_read'] ?? null;
            if (is_array($last) && self::isMessageHash($last['sha256'] ?? null)) {
                $fields['seen'] = $last['sha256'];
            }
        } catch (\Throwable) {
        }

        return $fields;
    }

    /** One answer to one send: what went where, as the service stored it. */
    private function traceSent(array $entry, int $status, mixed $result): ?string
    {
        $key = (string) ($entry['to_key'] ?? '');
        if ($key === '') {
            return null;
        }
        $stored = is_array($result) && $status === 201;
        $record = self::traceRow([
            'message_id' => $entry['id'] ?? null,
            'at' => time(),
            'w' => $entry['w'] ?? null,
            'status' => $status,
            'outcome' => $entry['status'] ?? null,
            'seq' => $stored ? ($result['seq'] ?? null) : null,
            'sha256' => $stored ? ($result['sha256'] ?? null) : null,
            'sealed' => true,
        ] + (is_array($entry['shape'] ?? null) ? $entry['shape'] : []), self::TRACE_SENT_FIELDS);
        $book = &$this->traceBook($key);
        $kept = array_values(array_filter($book['sent'], static fn (array $r): bool => ($r['message_id'] ?? null) !== $record['message_id']));
        $kept[] = $record;
        $book['sent'] = array_slice($kept, -self::TRACE_KEEP);
        unset($book);
        return $this->saveTrace();
    }

    /**
     * One verified message from a key, as it arrived here. Recorded whether or
     * not it could be opened, since one that could not is what a trace is for.
     * Taken as read only when it could: last_read is what the next message to
     * this key names as seen, and it names a message this side opened, not one
     * that merely arrived. A replay is neither recorded nor read: its first
     * arrival was, and an old message sent again must not move seen back to it.
     */
    private function traceReceived(Channel $channel, array $entry): ?string
    {
        if (($entry['replay'] ?? false) === true) {
            return null;
        }
        $key = (string) $entry['from_key'];
        $opened = in_array($entry['format'] ?? null, ['text', 'json'], true) && empty($entry['error']);
        $body = $opened && is_array($entry['body'] ?? null) ? $entry['body'] : [];
        $fields = array_keys($body);
        sort($fields);
        $row = self::traceRow([
            'at' => $entry['at'] ?? null,
            'channel' => $channel->label,
            'w' => $channel->w,
            'seq' => $entry['seq'] ?? null,
            'sha256' => $entry['sha256'] ?? null,
            'encrypted' => $entry['encrypted'] ?? null,
            'format' => $entry['format'] ?? null,
            'error' => $entry['error'] ?? null,
            // What a message held is known only if it opened.
            'fields' => $opened ? $fields : null,
            'text_chars' => is_string($body['text'] ?? null) ? (int) preg_match_all('/./us', $body['text']) : null,
            're' => $body['re'] ?? null,
            'seen' => $body['seen'] ?? null,
        ], self::TRACE_RECEIVED_FIELDS);
        $book = &$this->traceBook($key);
        foreach ($book['received'] as $had) {
            if ($row['sha256'] !== null && ($had['sha256'] ?? null) === $row['sha256']) {
                unset($book);

                return null;
            }
        }
        $book['received'][] = $row;
        $book['received'] = array_slice($book['received'], -self::TRACE_KEEP);
        if ($opened && ($entry['verified'] ?? false) === true && self::isMessageHash($entry['sha256'] ?? null)) {
            $book['last_read'] = self::traceRow(['sha256' => $entry['sha256'], 'seq' => $entry['seq'] ?? null, 'w' => $channel->w, 'at' => $entry['at'] ?? null], self::TRACE_READ_FIELDS);
        }
        unset($book);
        return $this->saveTrace();
    }

    /**
     * What was sent to one counterpart and what came back, as hashes and shapes.
     * $who is a partner name, a key or a write address; null gives one line per
     * counterpart. Nothing here is content: the sha256 is what the service stored,
     * byte for byte, so the other side can hold its own trace against this one.
     */
    public function trace(?string $who = null, int $limit = 20): array
    {
        $limit = max(1, min($limit, self::TRACE_KEEP));
        if ($who === null) {
            $everyone = [];
            foreach ($this->traces as $key => $book) {
                if (!is_array($book)) {
                    continue;
                }
                $sent = $book['sent'] ?? [];
                $received = $book['received'] ?? [];
                $everyone[] = [
                    'with' => $this->nameForKey((string) $key) ?? (string) $key,
                    'key' => (string) $key,
                    'sent' => count($sent),
                    'received' => count($received),
                    'last_sent_at' => $sent === [] ? null : ($sent[count($sent) - 1]['at'] ?? null),
                    'last_received_at' => $received === [] ? null : ($received[count($received) - 1]['at'] ?? null),
                    'no_read_claim' => count(self::traceView($book, self::TRACE_KEEP)['no_read_claim']),
                ];
            }
            usort($everyone, static fn (array $x, array $y): int => max($y['last_sent_at'] ?? 0, $y['last_received_at'] ?? 0) <=> max($x['last_sent_at'] ?? 0, $x['last_received_at'] ?? 0));

            return ['counterparts' => $everyone, 'note' => 'Name one with who for the messages themselves, as hashes.'];
        }
        $key = $this->traceKey($who);
        $book = $key === null ? null : ($this->traces[$key] ?? null);
        if (!is_array($book)) {
            throw new CodedRuntime('no_trace', 'nothing sent to or received from ' . $who . ' is recorded here');
        }
        $view = self::traceView($book, $limit);

        return [
            'with' => $this->nameForKey($key) ?? $key,
            'key' => $key,
            'sent' => $view['sent'],
            'received' => $view['received'],
            // What this side last read and opened from them: what the next
            // message to them names as seen.
            'last_read' => $book['last_read'] ?? null,
            'no_read_claim' => $view['no_read_claim'],
            'note' => $view['note'],
        ];
    }

    /** A partner name, a key or a write address, as the key it stands for. */
    private function traceKey(string $who): ?string
    {
        $partner = $this->partnerByName($who);
        if ($partner !== null) {
            return (string) $partner['key'];
        }
        if (Codec::isKey($who)) {
            return $who;
        }

        return $this->peers[$who] ?? null;
    }

    /**
     * The caller's logger, whose failure costs the log line and nothing else.
     * A note made after the cursor was written down called a logger that
     * failed, and the failure threw out of the read, which then handed over
     * nothing of what it had passed (the release check of 14 November 2026).
     */
    public function logged(string $line): void
    {
        try {
            ($this->log)($line);
        } catch (\Throwable) {
        }
    }

    private function note(Channel $channel, string $state, string $what, ?array $seqs = null): void
    {
        $this->noteTrouble($channel->label, $state, $what, $channel->w, $seqs);
    }

    private function noteTrouble(string $where, string $state, string $what, ?string $w = null, ?array $seqs = null): void
    {
        $key = $where . '|' . $state;
        $note = ['channel' => $where, 'w' => $w, 'state' => $state, 'what' => $what, 'at' => time()];
        if (in_array($state, ['kept_out', 'removed_key', 'unverified', 'binding_conflict', 'archive_failed', 'scope_not_saved', 'scope_share_not_recorded', 'replay_unknown'], true) && $seqs !== null) {
            $previous = $this->attention[$key] ?? [];
            $note['seqs'] = array_merge($previous['seqs'] ?? [], $seqs);
            $note['count'] = count($note['seqs']);
            $note['details'] = array_merge($previous['details'] ?? [], [$what]);
            $note['what'] = $note['count'] . ' message(s) (seq ' . implode(', ', $note['seqs']) . '): ' . implode(' ', $note['details']);
        }
        $this->attention[$key] = $note;
        $this->logged($where . ': ' . $what);
    }

    /** What the reads since the last call could not do, once, and then cleared. */
    public function attentionTaken(): array
    {
        $taken = array_values($this->attention);
        usort($taken, static fn (array $a, array $b): int => [$a['at'], $a['channel']] <=> [$b['at'], $b['channel']]);
        $this->attention = [];
        $this->archiveMissing = [];

        return $taken;
    }

    /**
     * New messages on the inbox and every open channel. Every channel is asked
     * at once first, so mail already waiting on any of them comes now; only
     * when nothing is waiting does the read wait, on the first channel that
     * answered, usually the inbox, and the others are asked again when that
     * wait ends. There is no listener in this runtime, and the command line,
     * the MCP server and a controller all read this way; the README and
     * aamio_read say so.
     *
     * Until 30 September 2026 the first channel got the wait before the others
     * were asked, and a reply waiting on a private thread sat behind a quiet
     * inbox for the whole wait.
     *
     * The channels it reads one by one are one read for the archive: it is
     * tidied before the first, and not again until this returns.
     */
    public function read(int $wait = 0, int $limit = 50, ?int $maxBytes = null): array
    {
        return $this->outerRead(fn (): array => $this->readHeld($wait, $limit, $maxBytes));
    }

    /** read, as one read for the archive. */
    private function readHeld(int $wait, int $limit, ?int $maxBytes): array
    {
        $this->ensureInbox();
        $this->publishPresence();
        $collected = [];
        $leftWaiting = 0;
        $heldBack = [];
        $heldHere = [];
        $atLimit = [];
        $notAsked = [];
        $notAskedAfter = [];

        /** One channel asked, for what this call still has room for; how it answered, or null. */
        $ask = function (Channel $channel, int $seconds, array &$passedOver) use (&$collected, &$leftWaiting, &$heldBack, &$heldHere, &$atLimit, $limit, $maxBytes): ?string {
            // A channel is only asked for what this call still has room for.
            // poll() moves the cursor and saves it before the caller sees a
            // message, so whatever was fetched beyond the limit and cut off
            // here afterwards was past the cursor and never came back: 60
            // waiting, 50 handed over, the last ten gone.
            $room = $limit - count($collected);
            if ($room <= 0) {
                $passedOver[] = $channel->label;

                return null;
            }
            try {
                // The whole budget goes to each channel in turn, as the count does:
                // a budget divided between channels would refuse a message that fits,
                // and the caller cannot know beforehand which channel holds the bytes.
                [$state, $entries] = $this->poll($channel, $seconds, $room, $maxBytes);
            } catch (\Throwable $error) {
                $this->note($channel, 'unread', 'this channel could not be read: ' . (new \ReflectionClass($error))->getShortName() . '. Messages from other channels are still returned.');

                return null;
            }
            foreach ($entries as $entry) {
                $collected[] = $entry;
            }
            $leftWaiting += $channel->leftWaiting;

            if ($channel->stoppedAtBudget) {
                $heldHere[] = $channel->label;
            } elseif ($channel->moreAtService) {
                if ($channel->moreAtLimit) {
                    $atLimit[] = $channel->label;
                } else {
                    $heldBack[] = $channel->label;
                }
            }

            return $state;
        };

        $answered = [];
        // A muted inbox too: it is read until it expires, for the partners still in the book.
        foreach (array_values($this->channels) as $channel) {
            // A channel that answered 410 or nothing at all never gets the
            // wait: it used to eat it, so a read with wait 25 came back at once
            // and the inbox was only ever asked with wait 0. A gone one does:
            // the service holds a read of a missing thread for a write.
            $state = $ask($channel, 0, $notAsked);
            if ($state === 'ok' || $state === 'gone') {
                $answered[] = $channel;
            }
        }

        // Only when nothing was waiting anywhere does the read wait, and only
        // the first channel can end the wait early. What reached the others
        // meanwhile comes with this answer, at the end of the wait.
        if ($collected === [] && $wait > 0 && $answered !== []) {
            $ask($answered[0], $wait, $notAskedAfter);
            foreach (array_slice($answered, 1) as $channel) {
                $ask($channel, 0, $notAskedAfter);
            }
        }

        $budgetSaid = null;
        if ($heldBack !== []) {
            // The service said it had more than the budget allowed. It was written
            // down on the channel and never said out loud, so a read that stopped
            // early looked exactly like one that had finished.
            $held = implode(', ', array_values(array_unique($heldBack)));
            $budgetSaid = $maxBytes !== null
                ? 'the service had more waiting on ' . $held . ' than the byte budget this read asked for, so it sent what fits and kept the rest. Read again for it: the cursor stands at the last message handed over.'
                : 'the service says it has more waiting on ' . $held . ' than it sent. Nothing was passed over: the cursor stands at the last message handed over, so read again for the rest.';
        }
        $limitSaid = null;
        if ($leftWaiting > 0 || $notAsked !== [] || $notAskedAfter !== [] || $atLimit !== []) {
            // Nothing is lost, and the caller still has to hear it: a read that
            // stopped at its limit is not a read of everything.
            $limitSaid = 'this read stopped at its limit of ' . $limit . ' message(s). '
                . ($atLimit !== [] ? 'The service has more waiting on ' . implode(', ', array_values(array_unique($atLimit))) . '. ' : '')
                . ($leftWaiting > 0 ? $leftWaiting . ' more that the service had already returned were left where they are. ' : '')
                . ($notAsked !== [] ? count($notAsked) . ' channel(s) were not asked this time: ' . implode(', ', $notAsked) . '. ' : '')
                . ($notAskedAfter !== [] ? count($notAskedAfter) . ' channel(s) were asked before the wait and not after it: ' . implode(', ', $notAskedAfter) . '. ' : '')
                . 'Nothing was passed over: every cursor stands at the last message this read dealt with, so read again for the rest.';
        }
        $hereSaid = $heldHere === [] ? null
            : 'this read stopped at the byte budget it asked for on ' . implode(', ', array_values(array_unique($heldHere))) . ', ' . $maxBytes . ' bytes of messages as they are handed over here, and left the rest at the service. Nothing was passed over: the cursor stands at the last message handed over, so read again for the rest.';
        // One note for the read, under one key: a second note under the same key
        // used to replace the first, so a read that met both its limit and a
        // byte budget said only one of them.
        $said = array_values(array_filter([$limitSaid, $budgetSaid, $hereSaid], static fn (?string $part): bool => $part !== null));
        if ($said !== []) {
            $this->noteTrouble('read', 'more', implode(' ', array_merge([$said[0]], array_map('ucfirst', array_slice($said, 1)))));
        }

        return $collected;
    }

    // ------------------------------------------------------------ receipt --

    /**
     * Why a service's answer is not a receipt for the thread at $w, or null when
     * it is one: the v1 schema, the address that was asked for, its times, one
     * line in order for every message it counts, and a root its commitment
     * names. The same check as aamio-js's and aamio-python's, with the same words.
     */
    public static function receiptFault(mixed $data, string $w): ?string
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return 'it is not an object';
        }
        if (($data['schema'] ?? null) !== 'aamio-receipt-v1') {
            return 'its schema is ' . json_encode($data['schema'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ', not aamio-receipt-v1';
        }
        if (($data['w'] ?? null) !== $w) {
            return 'it is for ' . json_encode($data['w'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ', not for ' . $w . ', the address asked for';
        }
        foreach (['created_at', 'expire_at', 'issued_at'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                return 'its ' . $field . ' is not a time';
            }
        }
        $messages = $data['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages)) {
            return 'it has no list of messages';
        }
        if (!is_int($data['count'] ?? null) || $data['count'] !== count($messages)) {
            return 'it counts ' . json_encode($data['count'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' messages and has ' . count($messages) . ' lines';
        }
        $previous = 0;
        foreach ($messages as $index => $m) {
            $fine = is_array($m) && is_int($m['seq'] ?? null) && $m['seq'] > $previous && is_int($m['at'] ?? null) && $m['at'] >= 0
                && is_string($m['sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/', $m['sha256']) === 1
                && (($m['from'] ?? null) === null || is_string($m['from']));
            if (!$fine) {
                return 'line ' . ($index + 1) . ' is not a message line in order';
            }
            $previous = $m['seq'];
        }
        if (!is_string($data['root'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $data['root']) !== 1) {
            return 'its root is not a sha256 in hex';
        }
        if (($data['commitment'] ?? null) !== 'sha256:' . $data['root']) {
            return 'its commitment is not sha256: and its root';
        }
        // The signers are the lines' own: the service lists each key that signed
        // a line, once. One that left a signer out was signed as if nobody had
        // signed (a review of 10 November 2026).
        $keys = $data['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || array_filter($keys, static fn ($key): bool => !is_string($key)) !== [] || count(array_unique($keys)) !== count($keys)) {
            return 'its keys are not a list of keys';
        }
        $signers = array_values(array_unique(array_filter(array_map(static fn (array $m) => $m['from'] ?? null, $messages), 'is_string')));
        sort($signers);
        $listed = $keys;
        sort($listed);
        if ($listed !== $signers) {
            return 'its keys are not the signers of its lines';
        }

        return null;
    }

    public function receipt(string $label = 'inbox', bool $anchor = false): array
    {
        $channel = $this->channels[$label] ?? null;
        if ($channel === null) {
            throw new CodedInvalidArgument('unknown_channel', 'no such channel: ' . $label);
        }
        $taken = $this->client->receipt($channel->w, $channel->readKey);
        if ($taken['status'] !== 200) {
            throw new CodedRuntime('receipt_failed', 'receipt failed: ' . $taken['status'] . ' ' . json_encode($taken['body']));
        }
        $data = $taken['body'];
        // Checked as a receipt for this channel before anything is compared,
        // signed or anchored: one for another address, and one that counted
        // seven messages and listed one, were signed with root_adds_up true (a
        // review of 10 November 2026).
        $fault = self::receiptFault($data, $channel->w);
        if ($fault !== null) {
            throw new CodedRuntime('receipt_failed', 'receipt failed: the service\'s answer is not a receipt for this channel: ' . $fault . '. Nothing was compared, signed or anchored.');
        }
        // What this process read counts only for the thread it was read on. After
        // the service restarted, a new thread at the same address was compared
        // with what was read of the old one, and its signer named as checked here.
        $sameThread = is_string($data['instance'] ?? null) && $channel->observedInstance !== null
            ? $channel->observedInstance === $data['instance']
            : $channel->observedCreatedAt !== null && $channel->observedCreatedAt === $data['created_at'] && ($channel->observedExpireAt === null || $channel->observedExpireAt === $data['expire_at']);
        $earlier = $channel->observed !== [] && !$sameThread ? ['instance' => $channel->observedInstance, 'created_at' => $channel->observedCreatedAt, 'expire_at' => $channel->observedExpireAt] : null;
        $which = static fn (array $thread): string => (is_string($thread['instance'] ?? null) ? 'instance ' . $thread['instance'] . ', ' : 'the one ') . 'opened at ' . ($thread['created_at'] ?? '?') . ' and expiring at ' . ($thread['expire_at'] ?? '?');
        $entries = $sameThread ? array_values($channel->observed) : [];
        usort($entries, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        $held = count($entries);
        $comparable = $held === (int) $data['count'];
        $ours = '';
        foreach ($entries as $e) {
            $ours .= sprintf("%d\t%d\t%s\t%s\n", $e['seq'], $e['at'], $e['sha256'], is_string($e['from']) ? ($e['from'] ?: '-') : '-');
        }
        // A signer is named only for a row this process checked and the receipt
        // lists as it was read: the same seq, time, hash and signer. A row read
        // on a thread the fields cannot tell from this one is not taken for it
        // (a review of 10 November 2026).
        $listedBySeq = [];
        foreach ($data['messages'] as $line) {
            $listedBySeq[(int) $line['seq']] = $line;
        }
        $asListed = static function (array $entry) use ($listedBySeq): bool {
            $line = $listedBySeq[(int) $entry['seq']] ?? null;

            return $line !== null && $line['at'] === $entry['at'] && $line['sha256'] === $entry['sha256'] && (($line['from'] ?? null) ?: null) === (($entry['from'] ?? null) ?: null);
        };
        $verifiedKeys = array_values(array_unique(array_column(array_filter($entries, static fn (array $entry): bool => $entry['verified'] && is_string($entry['from_key']) && $asListed($entry)), 'from_key')));
        $claims = (array) ($data['keys'] ?? []);
        $unverifiedKeys = array_values(array_filter($claims, static fn ($key): bool => !in_array($key, $verifiedKeys, true)));
        $attestation = sprintf("aamio-receipt-v1\n%s\n%s\n%d\n%d", $channel->w, $data['root'], $data['count'], $data['issued_at'] ?? 0);
        $result = [
            'label' => $label, 'w' => $channel->w, 'root' => $data['root'], 'commitment' => $data['commitment'] ?? null, 'count' => $data['count'],
            'keys' => array_map(fn ($key) => in_array($key, $verifiedKeys, true) ? ($this->nameForKey($key) ?? $key) : $key, $claims),
            'keys_unverified_count' => count($unverifiedKeys), 'keys_service_claim_only' => $unverifiedKeys, 'held_locally' => $held,
            'root_adds_up' => $taken['check']['root_adds_up'], 'local_root_matches' => $comparable ? hash('sha256', $ours) === $data['root'] : ($held > (int) $data['count'] ? false : null),
            'issued_at' => $data['issued_at'] ?? null, 'expire_at' => $data['expire_at'] ?? null, 'gate_hash' => $data['gate_hash'] ?? null,
            'attestation' => ['key' => $this->keys->public, 'over' => $attestation, 'sig' => $this->keys->sign($attestation)],
        ];
        if ($comparable) {
            $result['local_differences'] = [];
            foreach ($data['messages'] ?? [] as $message) {
                $observed = $channel->observed[$message['seq']] ?? [];
                $fields = array_values(array_filter(['at', 'sha256', 'from'], static fn (string $field): bool => ($observed[$field] ?? null) !== ($message[$field] ?? null)));
                if ($fields !== []) { $result['local_differences'][] = ['seq' => $message['seq'], 'fields' => $fields]; }
            }
            $result['signers_not_verified_locally'] = array_values(array_column(array_filter($entries, static fn (array $entry): bool => !empty($entry['from']) && !$entry['verified']), 'seq'));
        }
        if ($held > (int) $data['count']) {
            $result['local_check'] = 'Mismatch: the receipt counts fewer messages than this process read. The thread was replaced or the service lost messages.';
        } elseif (!$comparable) {
            $result['local_check'] = sprintf('Not compared: this process holds %d of the %d messages the receipt counts.', $held, $data['count'])
                . ($earlier !== null ? ' What this process read at this address was on another thread, ' . $which($earlier) . '; this receipt is for ' . $which($data) . ', so none of that counts here.' : '')
                . ' root_adds_up checks arithmetic only; our signature records what was fetched, not that its claims are true. Take the receipt in the process that read the messages for the independent comparison.';
        }
        if ($anchor) {
            $idem = substr(Codec::sha256hex('aamio-listen:' . $this->keys->hash . ':' . $data['root']), 0, 32);
            $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'verifyum_anchor_commitment', 'arguments' => ['commitment' => 'sha256:' . $data['root'], 'idempotency_key' => $idem]]];
            [$status, $reply] = Http::call('POST', $this->verifyum, Codec::json($message), ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-11-25'], 40, true);
            $tool = is_array($reply) && is_array($reply['result'] ?? null) ? $reply['result'] : null;
            $text = $tool !== null ? ($tool['content'][0]['text'] ?? null) : null;
            $proof = is_string($text) ? json_decode($text, true) : null;
            // A proof is a 200 with a proof_id from a tool that did not answer
            // with an error: one that named a proof_id in its error was shown as
            // a proof (a review of 10 November 2026).
            $toolError = $tool !== null && ($tool['isError'] ?? false) === true;
            // A proof_id of blanks names no proof (a review of 10 November 2026).
            $made = $status === 200 && !$toolError && is_array($proof) && is_string($proof['proof_id'] ?? null) && trim($proof['proof_id']) !== '';
            $result['anchor'] = $made ? $proof : ['error' => $status, 'detail' => $toolError && is_string($text) ? $text : $reply];
        }
        // Taken, and anchored when that was asked: a record of it that cannot
        // be written is said beside it.
        $archiveError = $this->archiveDone($label, ['kind' => 'receipt', 'at' => time(), 'result' => $result], 'the receipt for ' . $label);
        if ($archiveError !== null) {
            $result['archive_error'] = $archiveError;
        }

        return $result;
    }

    public function close(): void
    {
        // Only what this runtime took. Closed a second time, after another
        // runtime in this process had taken the home, it deleted that one's
        // pid file: the two share a pid, so the pid could not tell them apart
        // (a review, 30 September 2026).
        if (!$this->ownsHome) {
            return;
        }
        // PHP has no background threads here, so there is nothing to wait for:
        // when this returns, nothing else in this process is reading.
        $this->homeReleased = true;
        try {
            $held = $this->loadJson('lock', null);
            if (is_array($held) && ($held['pid'] ?? null) === getmypid()) {
                @unlink($this->path('lock'));
            }
        } finally {
            // After the pid file, so a runtime that takes the lock the moment
            // it is free finds no pid file of this one's to wonder about. And
            // whatever the pid file said, the lock and the home go back.
            $this->letGoOfOwnerLock();
            unset(self::$lockedHomes[realpath($this->home) ?: $this->home]);
            $this->ownsHome = false;
        }
    }
}
