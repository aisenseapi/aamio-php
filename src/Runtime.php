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
    public const ANSWER_MARGIN = 600;
    public const PRESENCE_TTL = 120;
    public const PRESENCE_REFRESH = 60;
    public const RENEW_BEFORE = 180;
    public const SEND_DETERMINISTIC = [400, 403, 410, 413, 415, 422, 428, 501];
    public const SEND_TRY_LATER = [429, 502, 503, 504];
    /**
     * Answers that prove the message was never stored: the deterministic ones, and a
     * rate window, which aamio decides before it reads the body. 502, 503 and 504 are
     * deliberately not here -- they can be a front door that saw nothing or the
     * service failing after it had written, and this side cannot tell which.
     */
    public const SEND_NOT_STORED = [400, 403, 410, 413, 415, 422, 428, 501, 429];
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
    /** @var array<string, Channel> */
    public array $channels = [];
    /** @var array<string, array> */
    public array $outbox;
    /** @var array<string, array> */
    public array $effects;
    public bool $archiveEnabled;
    /** What this home does with its archive: keep, off, or so many days. */
    public array $archivePolicy;
    /**
     * After close() the home may be another process's, so nothing more is
     * written from here: a save then would be a save over somebody else's.
     */
    public bool $homeReleased = false;
    private float $prunedAt = 0.0;
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
    /** @var array<string, true> */
    private static array $lockedHomes = [];

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
            ($this->log)('made private: ' . $path . ' (an older version wrote it with the default mode)');
        }
        if (!Storage::isWindows()) {
            // Mode bits cost nothing to read. On Windows it is the access list
            // that decides and reading it starts a shell, so that is left to
            // `aamio doctor` and `aamio init`.
            $found = Storage::check($this->home);
            foreach (array_slice($found['findings'], 0, 5) as $finding) {
                ($this->log)('WARNING: ' . ($finding['path'] ?? $this->home) . ': ' . $finding['problem'] . '. ' . ($found['fix'] ?? ''));
            }
        }
        $this->prune();
    }

    private function prune(): void
    {
        if (!$this->archiveEnabled || !(($this->archivePolicy['days'] ?? null) || ($this->archivePolicy['max_mb'] ?? null))) {
            return;
        }
        $this->prunedAt = microtime(true);
        try {
            $gone = Storage::prune($this->home, $this->archivePolicy);
        } catch (\Throwable $error) {
            ($this->log)('archive not pruned: ' . $error->getMessage());

            return;
        }
        if ($gone['removed'] > 0) {
            ($this->log)('archive: ' . $gone['removed'] . ' record(s) past their lifetime removed');
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
        // Anything still pending was in flight when the last process stopped.
        // Whether it reached aamio is unknown, and it stays unknown until
        // somebody looks. Retrying is a decision, not a default.
        foreach ($this->outbox as &$entry) {
            if (($entry['status'] ?? null) === 'sending') {
                $entry['status'] = 'unknown';
                $entry['note'] = 'the process stopped while this was in flight';
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
        if ($text === false || trim($text) === '') {
            if ($text === false && $shape !== null) {
                throw new \RuntimeException($this->unreadable($name, 'it would not open'));
            }

            return $default;
        }
        $raw = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($shape === null) {
                return $default;
            }
            throw new \RuntimeException($this->unreadable($name, 'not UTF-8 JSON'));
        }
        if ($shape !== null && ($shape === 'list' ? !is_array($raw) : !$raw instanceof \stdClass)) {
            throw new \RuntimeException($this->unreadable($name, 'not a JSON ' . $shape));
        }

        return $assoc ? json_decode($text, true) : $raw;
    }

    private function unreadable(string $name, string $why): string
    {
        return sprintf('%s could not be read (%s), so this runtime stops here rather than save over it. Repair the file, or move it away to start without what was in it.', $this->path($name), $why);
    }

    private function saveJson(string $name, mixed $value): void
    {
        if ($this->homeReleased) {
            // After close the home may be another process's. Writing here
            // would be writing over what that one holds.
            ($this->log)($name . ' not written: this runtime is closed');

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
            throw new \RuntimeException('could not write ' . $path);
        }
    }

    private function takeLock(): void
    {
        $real = realpath($this->home) ?: $this->home;
        if (isset(self::$lockedHomes[$real])) {
            throw new \RuntimeException('another aamio in this process is already using ' . $this->home);
        }
        $held = $this->loadJson('lock', null);
        if (is_array($held) && is_int($held['pid'] ?? null) && $held['pid'] !== getmypid() && self::pidAlive($held['pid'])) {
            // The lock is written after its owner started. A process that
            // started later got the pid after the owner was gone.
            $started = is_int($held['at'] ?? null) || is_float($held['at'] ?? null) ? self::startedAt($held['pid']) : null;
            if ($started === null || $started <= $held['at'] + 2) {
                throw new \RuntimeException(sprintf('another aamio (pid %d) is using %s. Stop it, or use a different AAMIO_HOME. If no aamio is running, the one that took the lock stopped without letting go of it: delete %s and start again.', $held['pid'], $this->home, $this->path('lock')));
            }
        }
        $this->saveJson('lock', ['pid' => getmypid(), 'at' => time(), 'host' => $this->host]);
        self::$lockedHomes[$real] = true;
    }

    private static function pidAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            // Refused is not gone: EPERM is a process that belongs to someone else.
            return @posix_kill($pid, 0) || posix_get_last_error() === 1;
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');

            return is_string($out) && str_contains($out, (string) $pid);
        }

        return true;
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
                throw new \RuntimeException($this->unreadable('key', 'it would not open'));
            }
            if (trim($text) !== '') {
                // A new key over it would be a new identity, and the old one
                // gone for good. Partners know this runtime by that key.
                if (preg_match('/^[0-9a-f]{64}$/D', trim($text)) !== 1) {
                    throw new \RuntimeException($this->unreadable('key', 'not a 64 character hex seed'));
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
            throw new \RuntimeException('could not write ' . $path);
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
    public function archive(string $label, array $record): bool
    {
        if (!$this->archiveEnabled || $this->homeReleased) {
            return false;
        }
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
        Storage::appendPrivate($this->path('archive' . DIRECTORY_SEPARATOR . $label . '.jsonl'), $line);
        if (microtime(true) - $this->prunedAt > 3600) {
            $this->prune();
        }

        return true;
    }

    /** @return string[] labels this runtime has an archive for, the ones a board inbox uses */
    private function archiveLabels(string $prefix = ''): array
    {
        if (!$this->archiveEnabled) {
            return [];
        }
        $labels = [];
        foreach ((array) @glob($this->path('archive' . DIRECTORY_SEPARATOR . $prefix . '*.jsonl')) as $file) {
            $labels[] = basename((string) $file, '.jsonl');
        }

        return $labels;
    }

    /** Entries this client wrote down for a channel, oldest first; empty when archiving is off or the file is not there. */
    public function archived(string $label, string $kind): array
    {
        if (!$this->archiveEnabled) {
            return [];
        }
        $path = $this->path('archive' . DIRECTORY_SEPARATOR . $label . '.jsonl');
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $this->noteTrouble('archive', 'unread', 'the archive ' . basename($path) . ' could not be read, so answers written down earlier are not in this result');

            return [];
        }
        $found = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ($record['kind'] ?? null) === $kind) {
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
        if (!Codec::isKey($key)) {
            throw new \InvalidArgumentException('not a base64url Ed25519 public key of 32 bytes');
        }
        $this->partners = array_values(array_filter($this->partners, static fn (array $p): bool => $p['name'] !== $name && $p['key'] !== $key));
        $this->partners[] = ['name' => $name, 'key' => $key];
        $this->saveJson('partners.json', $this->partners);

        return $this->inboxAfterChange('partner ' . $name . ' was added', ['partner' => $name, 'key' => $key]);
    }

    /**
     * A partner out of the address book, and out of the inbox at once.
     *
     * Forgetting the name is not a revocation: the service takes the removed
     * key's writes to the old address until that thread expires. So the old
     * inbox is muted, read no more, and a new one is opened without the key.
     * Once the last partner is gone the new inbox takes signed writes from any
     * key, each shown as an unknown one, rather than unsigned writes from
     * anyone at an address the partners were given.
     */
    public function partnerRemove(string $name): array
    {
        $known = $this->partnerByName($name);
        $this->partners = array_values(array_filter($this->partners, static fn (array $p): bool => $p['name'] !== $name));
        $this->saveJson('partners.json', $this->partners);
        if ($known === null) {
            $inbox = $this->channels['inbox'] ?? null;

            return ['partner' => $name, 'known' => false, 'inbox' => $inbox?->w];
        }

        return $this->inboxAfterChange('partner ' . $name . ' was removed', ['partner' => $name, 'known' => true]);
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

        return $outcome + $this->rotateInbox($reason);
    }

    public function partnerList(): array
    {
        return array_map(static fn (array $p): array => ['name' => $p['name'], 'key' => $p['key'], 'hash_prefix' => Keys::hashPrefixOf($p['key'])], $this->partners);
    }

    public function partnerByName(string $name): ?array
    {
        foreach ($this->partners as $p) {
            if (strtolower($p['name']) === strtolower($name)) {
                return $p;
            }
        }

        return null;
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
                && !isset($names[strtolower($scope['name'])])
                && is_string($scope['address'] ?? null) && Address::isW($scope['address'])
                && ($key === null || (is_string($key) && Address::isScopeKey($key) && Address::scope($key) === $scope['address']))) {
                $usable[] = $scope;
                $names[strtolower($scope['name'])] = true;
            } else {
                $aside[] = $entry;
            }
        }
        if ($aside !== []) {
            ($this->log)(sprintf('scopes.json: %d entries are not scopes this runtime can use, and stay in the file as they are', count($aside)));
        }

        return [$usable, $aside];
    }

    /** Writes the scopes, and only then holds them: a save that fails changes nothing. */
    private function saveScopes(array $scopes): void
    {
        $this->saveJson('scopes.json', [...$scopes, ...$this->scopesAside]);
        $this->scopes = $scopes;
    }

    private function scopeIndex(string $name): ?int
    {
        foreach ($this->scopes as $i => $scope) {
            if (strtolower($scope['name']) === strtolower($name)) {
                return $i;
            }
        }

        return null;
    }

    private function scopeNamed(string $name): array
    {
        $i = $this->scopeIndex($name);
        if ($i === null) {
            throw new \RuntimeException('no scope called ' . $name . ' here. The scope list shows the ones this runtime holds');
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
            throw new \InvalidArgumentException('a scope name is 1 to 64 letters, digits, dots, dashes and underscores, starting with a letter or a digit');
        }

        return $name;
    }

    /** A new scope, its key from the CSPRNG. The key stays here. */
    public function scopeNew(mixed $name): array
    {
        $name = self::scopeNameOk($name);
        if ($this->scopeIndex($name) !== null) {
            throw new \InvalidArgumentException('there is a scope called ' . $name . ' already');
        }
        $key = Address::newScopeKey();
        $scope = ['name' => $name, 'key' => $key, 'address' => Address::scope($key)];
        $this->saveScopes([...$this->scopes, $scope]);

        return self::scopeView($scope);
    }

    /** A scope made elsewhere: the key, to read and post, or the address, to post only. */
    public function scopeAdd(mixed $name, mixed $key = null, mixed $address = null): array
    {
        $name = self::scopeNameOk($name);
        if ($key === null && $address === null) {
            throw new \InvalidArgumentException('give the key, to read and post, or the address, to post only');
        }
        if ($key !== null && (!is_string($key) || !Address::isScopeKey($key))) {
            throw new \InvalidArgumentException('a scope key is 26 to 64 characters of a-z and 0-9. The 20 character address goes in address');
        }
        if ($address !== null && (!is_string($address) || !Address::isW($address))) {
            throw new \InvalidArgumentException('a scope address is the 20 characters of a-z and 2-7 that go on a post');
        }
        $derived = $key !== null ? Address::scope($key) : $address;
        if ($address !== null && $derived !== $address) {
            throw new \InvalidArgumentException('that key does not give that address, so one of the two is wrong');
        }
        $index = $this->scopeIndex($name);
        if ($index !== null && $this->scopes[$index]['address'] !== $derived) {
            throw new \InvalidArgumentException('there is a scope called ' . $name . ' already, with another address. Remove it or choose another name');
        }
        foreach ($index === null ? $this->scopes : [] as $i => $scope) {
            if ($scope['address'] === $derived) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $held = ['name' => $name, 'key' => $key, 'address' => $derived];
            $this->saveScopes([...$this->scopes, $held]);
        } else {
            $held = $this->scopes[$index];
            if ($key !== null && empty($held['key'])) {
                // Held to post only until now. The key adds reading.
                $held['key'] = $key;
                $scopes = $this->scopes;
                $scopes[$index] = $held;
                $this->saveScopes($scopes);
            }
        }

        return self::scopeView($held);
    }

    public function scopeList(): array
    {
        return array_map(static fn (array $scope): array => self::scopeView($scope), $this->scopes);
    }

    public function scopeRemove(string $name): array
    {
        $scope = $this->scopeNamed($name);
        $scopes = $this->scopes;
        unset($scopes[$this->scopeIndex($name)]);
        $this->saveScopes(array_values($scopes));

        return ['removed' => $scope['name']];
    }

    /** The key itself, for a person to pass on by hand. The MCP server never calls this. */
    public function scopeKey(string $name): array
    {
        $scope = $this->scopeNamed($name);
        if (empty($scope['key'])) {
            throw new \InvalidArgumentException('scope ' . $scope['name'] . ' is held to post only, so there is no key here');
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
        if ($access !== 'read' && $access !== 'write') {
            throw new \InvalidArgumentException('access is read, which gives the key to read and post, or write, which gives the address to post only');
        }
        if ($access === 'read' && empty($scope['key'])) {
            throw new \InvalidArgumentException('scope ' . $scope['name'] . ' is held to post only, so it can only be shared with access write');
        }
        $partner = Codec::isKey($to) ? $this->partnerByKey($to) : $this->partnerByName($to);
        if ($partner === null) {
            throw new \InvalidArgumentException('a scope is shared only with a partner in your address book, by name. Never with an address, which can be anyone\'s');
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
    private function takeScopeShare(array &$entry): void
    {
        if (!is_array($entry['body'] ?? null)) {
            return;
        }
        if (array_key_exists('aamio_scope', $entry['body'])) {
            $entry['body']['aamio_scope'] = ['kept' => false, 'note' => 'not kept: a scope is shared in data.aamio_scope'];
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
        if (!(($entry['verified'] ?? false) && ($entry['encrypted'] ?? false) && ($entry['known_contact'] ?? false) && $partner !== null)) {
            $view['note'] = 'not kept: a scope is only taken when it comes sealed from a partner in your address book';
        } elseif ($entry['replay'] ?? false) {
            $view['note'] = 'not kept again: this message arrived before, and a scope removed since stays removed';
        } else {
            try {
                $local = $this->sharedScopeName($partner, $share['name'] ?? null);
                $view = array_merge($view, $this->scopeAdd($local, $key, $key === null ? ($share['address'] ?? null) : null), ['kept' => true]);
            } catch (\Throwable $error) {
                // This share alone, and nothing held that was not saved. The
                // rest of the messages are delivered either way.
                $view['note'] = 'not kept: ' . $error->getMessage();
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
     * removed: the old one is muted, kept for its records and its receipt but
     * read no more, so nothing from the removed key arrives through the
     * address it was given. That is this runtime's delivery stopping, not a
     * revocation: the service takes the write until the thread expires.
     *
     * A rotation that fails leaves the old inbox in use, and says so.
     */
    private function rotateInbox(string $reason): array
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
            if (!$old->muted && $old->allow === []) {
                $this->note($old, 'open', sprintf('inbox %s was open to anyone and is still read until %d; after %s the new inbox %s names %d key(s)', $old->w, $old->expireAt, $reason, $fresh->w, count($fresh->allow)));
            }
            $outcome['old_inbox'] = ['w' => $old->w, 'muted' => $old->muted, 'until' => $old->expireAt];
        }
        if ($muted !== []) {
            $outcome['muted'] = $muted;
        }
        $this->saveState();
        ($this->log)(sprintf('inbox %s until %d, %s%s', $fresh->w, $fresh->expireAt, $reason, $fresh->allow !== [] ? ' (allowlist ' . count($fresh->allow) . ' keys)' : ''));
        $outcome['presence'] = $this->publishPresence(true);

        return $outcome;
    }

    private function allowNames(array $allow): array
    {
        return array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, array_values($allow));
    }

    /**
     * Every inbox generation that names a key no longer in the address book is
     * muted. Muted means read no more: nothing handed over, the record and the
     * receipt kept until the address expires. It is this runtime's delivery
     * stopping, not a revocation; the service takes the removed key's writes
     * until the thread expires. An open generation, or one that takes any
     * signed key, names nobody and is left as it is.
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
            $this->note($channel, 'muted', sprintf('inbox %s is read no more after %s: %d removed key(s) can still write there until %d, and nothing from there is delivered', $channel->w, $reason, count($gone), $channel->expireAt));
        }

        return $muted;
    }

    public function ensureInbox(): Channel
    {
        // Partners may have changed while this process was not running. The
        // older generations are judged once, here, so a key removed while the
        // runtime was down is read no more from any address it was given.
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
            throw new \RuntimeException('could not open inbox: ' . $outcome['error']);
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

    public function openChannel(string $label, int $ttl, ?array $allowNames = null, mixed $gate = null): array
    {
        if (isset($this->channels[$label]) || str_starts_with($label, 'inbox')) {
            throw new \InvalidArgumentException('channel exists or reserved: ' . $label);
        }
        $keys = [];
        foreach ($allowNames ?? [] as $name) {
            $partner = $this->partnerByName((string) $name);
            if ($partner === null) {
                throw new \InvalidArgumentException('unknown partner: ' . $name);
            }
            $keys[] = $partner['key'];
        }
        if ($gate !== null) {
            $gate = self::checkGate($gate);
        }

        $opened = $this->client->open($ttl, $keys !== [] ? $keys : null, $gate);
        if ($opened['status'] !== 201) {
            throw new \RuntimeException('could not open channel: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel($label, $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], $keys);
        $this->channels[$label] = $channel;
        $this->saveState();

        // The gate is in the answer whether or not there is one, because a channel
        // with no conditions answered exactly like one whose gate went nowhere. It
        // is not kept here: the inbox holds it and GET /{w}/gate serves it, and a
        // second copy on this machine could only disagree with the first.
        return ['label' => $label, 'w' => $channel->w, 'expire_at' => $channel->expireAt, 'allow' => array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, $keys), 'gate' => $gate];
    }

    /**
     * The shape of a gate, refused before the call rather than after it.
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
    public const GATE_SHAPE = 'A gate is an object with "require", "advise" or both, as in {"require": {"pow": {"bits": 20}, "per_key": 5}}. require refuses a write that does not meet it; advise lets the write in and reports on it.';

    public static function checkGate(mixed $gate): array
    {
        if (!is_array($gate)) {
            throw new \InvalidArgumentException('a gate must be an object, not ' . get_debug_type($gate) . '. ' . self::GATE_SHAPE);
        }

        if ($gate !== [] && array_is_list($gate)) {
            throw new \InvalidArgumentException('a gate must be an object, not a list. ' . self::GATE_SHAPE);
        }

        $unknown = array_values(array_diff(array_keys($gate), ['require', 'advise']));

        if ($unknown !== []) {
            sort($unknown);

            throw new \InvalidArgumentException('a gate has no field ' . implode(', ', $unknown) . '. ' . self::GATE_SHAPE);
        }

        if ($gate === []) {
            throw new \InvalidArgumentException('an empty gate asks for nothing; leave the gate out instead. ' . self::GATE_SHAPE);
        }

        foreach (['require', 'advise'] as $part) {
            if (isset($gate[$part]) && (!is_array($gate[$part]) || ($gate[$part] !== [] && array_is_list($gate[$part])))) {
                throw new \InvalidArgumentException('gate.' . $part . ' must be an object. ' . self::GATE_SHAPE);
            }
        }

        return $gate;
    }

    public function closeChannel(string $label): array
    {
        $channel = $this->channels[$label] ?? null;
        if ($channel === null) {
            throw new \InvalidArgumentException('no such channel: ' . $label);
        }
        $closed = $this->client->close($channel->w, $channel->readKey);
        $channel->closed = true;
        unset($this->channels[$label]);
        $this->saveState();

        return ['label' => $label, 'status' => $closed['status'], 'deleted' => $closed['status'] === 200];
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
            throw new \RuntimeException('could not open the board inbox: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel('board', $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], ['*']);
        if ($held !== null) {
            $this->retireChannel($held);
        }
        $this->channels['board'] = $channel;
        $this->saveState();
        ($this->log)(sprintf('board inbox %s until %d (any key, signed only)', $channel->w, $channel->expireAt));

        return $channel;
    }

    /** With $scope, the name of a scope held here, the post is unlisted: only a find with that scope's key returns it. */
    public function boardPost(string $kind, string $title, string $text, ?array $tags = null, int $ttl = self::BOARD_TTL, ?string $lang = null, ?string $deadline = null, ?string $scope = null): array
    {
        // Before the inbox is opened, so a name that is not here costs nothing.
        $held = $scope !== null ? $this->scopeNamed($scope) : null;
        $inbox = $this->ensureBoardInbox($ttl + 60);
        $post = ['kind' => $kind, 'title' => $title, 'text' => $text, 'tags' => array_values($tags ?? []), 'w' => $inbox->w, 'ttl' => $ttl];
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
        $this->archive('board', ['kind' => 'posted', 'at' => time(), 'status' => $status, 'post' => is_array($answer) ? ($answer['id'] ?? null) : null, 'title' => $title, 'w' => $inbox->w]);
        if ($status !== 201) {
            throw new \RuntimeException('post failed: ' . $status . ' ' . json_encode($answer));
        }

        // Where the answers go, and how to read them, in the answer itself. An
        // agent took board replies for the whole inbox, got nothing back, and
        // spent an hour decrypting by hand what read would have shown at once.
        $posted = ['id' => $answer['id'], 'kind' => $kind, 'title' => $title, 'expire_at' => $answer['expire_at'] ?? null, 'work_bits' => $answer['work_bits'] ?? $bits, 'reply_inbox' => $inbox->w, 'inbox_expires_at' => $inbox->expireAt, 'replies_arrive_on' => 'board', 'read_them_with' => 'Read them with read, aamio read on the command line and aamio_read over MCP, which shows every message on your inboxes. board replies lists only the answers, the messages that name a post of yours or arrived on a board inbox, and says how many it left out.'];
        if ($held !== null) {
            $posted['scope'] = $held['name'];
        }

        return $posted;
    }

    /** With $scope, the name of a scope held here with its key, the find reads that scope instead of the public board. */
    public function boardFind(?string $kind = null, ?array $tags = null, ?string $lang = null, ?string $key = null, int $after = 0, int $wait = 0, int $minWorkBits = 0, ?string $scope = null): array
    {
        $held = $scope !== null ? $this->scopeNamed($scope) : null;
        if ($held !== null && empty($held['key'])) {
            throw new \InvalidArgumentException('scope ' . $held['name'] . ' is held to post only. Reading it takes the key, which a partner can share with access read');
        }
        try {
            // In the body and nowhere else. A board older than scopes answers
            // 400 to the field, so it never reads the public board instead.
            $found = $this->board->find($kind, $tags ?? [], $lang, $key, $after, $wait, $minWorkBits, $held['key'] ?? null);
        } catch (\UnexpectedValueException) {
            throw new \RuntimeException('the board did not say it read scope ' . $held['name'] . ', so these posts are not shown');
        }
        if ($found['status'] !== 200) {
            throw new \RuntimeException('board find failed: ' . $found['status'] . ' ' . json_encode($found['body']));
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

        throw new \RuntimeException('the board answered ' . $got['status'] . ' for post ' . $postId . ', so whether that post is live is unknown. Ask again rather than treating it as gone');
    }

    public function boardTags(): array
    {
        $tags = $this->board->tags();
        if ($tags['status'] !== 200) {
            throw new \RuntimeException('board tags failed: ' . $tags['status']);
        }

        return $tags['body'];
    }

    public function boardWithdraw(string $postId): array
    {
        $done = $this->board->withdraw($postId);
        $this->archive('board', ['kind' => 'withdrawn', 'at' => time(), 'post' => $postId, 'status' => $done['status']]);

        return ['post' => $postId, 'status' => $done['status'], 'withdrawn' => $done['status'] === 200];
    }

    /**
     * Answer a post, sealed to the poster's key and signed by ours, carrying the post id and our reply address.
     * A post in a scope is never served by id alone, so with $scope it is looked up in that scope.
     */
    public function boardAnswer(array|string $post, ?string $text = null, ?array $data = null, ?string $scope = null): array
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
                throw new \RuntimeException('no live post with that id' . ($scope !== null ? ' in scope ' . $scope : ". A post in a scope is found with the scope's name"));
            }
            $post = $fetched;
        }
        // The address on this answer has to outlive the post it answers.
        $remaining = max(0, (int) ($post['expire_at'] ?? 0) - time());
        $channel = $this->ensureBoardInbox($remaining + self::ANSWER_MARGIN);
        $ownPost = ($post['key'] ?? null) === $this->keys->public;
        if ($channel->expireAt < (int) ($post['expire_at'] ?? 0)) {
            ($this->log)(sprintf('your reply address expires %d s before the post does', (int) $post['expire_at'] - $channel->expireAt));
        }
        $this->peers[$post['w']] = $post['key'];
        $body = ['post' => $post['id'], 'reply_to' => $channel->w, 'from' => $this->keys->hashPrefix];
        if ($text !== null) {
            $body['text'] = $text;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }
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
        $this->archive('board', ['kind' => 'answered', 'at' => time(), 'post' => $post['id'], 'w' => $post['w'], 'status' => $status, 'message_id' => $entry['id'], 'outcome' => $entry['status'], 'body' => $body]);
        if ($status !== 201) {
            throw new SendFailed($entry['status'], $entry['id'], $status, $result);
        }
        $answer = ['post' => $post['id'], 'w' => $post['w'], 'message_id' => $entry['id'], 'seq' => $result['seq'], 'at' => $result['at'], 'replies_arrive_on' => 'board', 'reply_to' => $channel->w];
        if (isset($result['met'])) {
            $answer['met'] = $result['met'];
            $answer['proof_id'] = $result['proof_id'] ?? null;
        }
        if (($entry['gate_notes'] ?? []) !== []) {
            $answer['notes'] = $entry['gate_notes'];
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
        $seen = [];
        $skipped = [];
        foreach ($this->channels as $channel) {
            foreach ($channel->received as $entry) {
                $seen[$entry['sha256']] = true;
                if ($wanted($entry)) {
                    $out[] = $entry;
                } else {
                    $skipped[] = $entry;
                }
            }
        }
        // A board inbox is renewed while the old one still holds answers, and
        // the old one keeps its own label and its own archive. Once that
        // channel expires it leaves $this->channels, and reading only the
        // channels this process holds made those answers vanish from here
        // although they had arrived, been decrypted and been written down.
        $labels = array_unique(array_merge(array_keys($this->channels), ['board'], $this->archiveLabels()));
        sort($labels);
        foreach ($labels as $label) {
            foreach ($this->archived($label, 'received') as $entry) {
                if (isset($seen[$entry['sha256'] ?? ''])) {
                    continue;
                }
                $seen[$entry['sha256'] ?? ''] = true;
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
        if (!Codec::isKey($key)) {
            $partner = $this->partnerByName($key);
            if ($partner === null) {
                throw new \InvalidArgumentException('not a key and not a known partner: ' . $key);
            }
            $key = $partner['key'];
        }
        $label = $label ?: 'with-' . Keys::hashPrefixOf($key);
        if (isset($this->channels[$label])) {
            $label .= '-' . time();
        }
        $opened = $this->client->open($ttl, [$key]);
        if ($opened['status'] !== 201) {
            throw new \RuntimeException('could not open channel: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel($label, $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], [$key]);
        $this->channels[$label] = $channel;
        $this->saveState();
        if ($replyTo !== null) {
            $body = ['channel' => $channel->w, 'expire_at' => $channel->expireAt];
            if ($note !== null) {
                $body['text'] = $note;
            }
            // The message that carries the address is a send, and goes through
            // the outbox like one. It was posted directly, and a refusal was a
            // bare error: on the command line a traceback, with the channel
            // open and its address delivered to nobody.
            $entry = $this->outboxAdd($replyTo, $key, $this->keys->seal($key, Codec::json($body)), $body);
            [$status, $handed] = $this->deliver($entry);
            if ($status !== 201) {
                $entry = $this->outbox[$entry['id']];
                throw new SendFailed($entry['status'], $entry['id'], $status, $handed, ['label' => $label, 'w' => $channel->w, 'expire_at' => $channel->expireAt]);
            }
        }

        return ['label' => $label, 'w' => $channel->w, 'expire_at' => $channel->expireAt, 'with' => $this->nameForKey($key) ?? $key, 'address_sent_to' => $replyTo];
    }

    // ------------------------------------------------------------- lookup --

    public function lookup(?array $names = null, int $wait = 0): array
    {
        $wanted = $names ? array_map('strtolower', array_map('strval', $names)) : null;
        $partners = $wanted === null ? $this->partners : array_values(array_filter($this->partners, static fn (array $p): bool => in_array(strtolower($p['name']), $wanted, true)));
        if ($partners === []) {
            return ['online' => [], 'offline' => [], 'error' => 'no partners to look up'];
        }
        $prefixes = array_map(static fn (array $p): string => Keys::hashPrefixOf($p['key']), $partners);
        $found = $this->client->presenceLookup($prefixes, $wait);
        $names = array_map(static fn (array $p): string => $p['name'], $partners);
        if ($found['status'] !== 200) {
            return ['online' => [], 'offline' => $names, 'error' => 'lookup failed: ' . $found['status'] . ' ' . json_encode($found['body'])];
        }
        $online = [];
        $seen = [];
        foreach ((array) ($found['body']['matches'] ?? []) as $match) {
            $partner = $this->partnerByKey($match['key'] ?? null);
            if ($partner === null) {
                continue;
            }
            $seen[$partner['name']] = true;
            $this->peers[$match['w']] = $partner['key'];
            $online[] = ['name' => $partner['name'], 'w' => $match['w'], 'tags' => $match['tags'] ?? [], 'expires_at' => $match['expire_at'] ?? null];
        }
        $this->saveState();

        return ['online' => $online, 'offline' => array_values(array_filter($names, static fn (string $n): bool => !isset($seen[$n])))];
    }

    /** The write address a partner currently answers on, from presence, with the key. */
    public function addressFor(string $name): array
    {
        $partner = $this->partnerByName($name);
        if ($partner === null) {
            throw new \InvalidArgumentException('unknown partner: ' . $name);
        }
        $result = $this->lookup([$name]);
        foreach ($result['online'] as $entry) {
            return [$entry['w'], $partner['key']];
        }
        throw new \RuntimeException($partner['name'] . ' is not online right now');
    }

    // --------------------------------------------------------------- send --

    /**
     * Sends to a partner by name, or to a write address learned from presence
     * or from a message. Sealed to the partner's key, signed by ours, stored
     * in the outbox before the first attempt. Throws SendFailed when the
     * message did not land, with the outcome, and GateStop when the inbox
     * asks for something this client cannot do.
     */
    public function send(string $to, ?string $text = null, ?array $data = null, ?string $replyTo = null): array
    {
        if (Codec::isKey($to)) {
            $partner = $this->partnerByKey($to);
            if ($partner === null) {
                throw new \InvalidArgumentException('key is not in partners');
            }
            $to = $partner['name'];
        }
        if (Address::isW($to)) {
            $key = $this->peers[$to] ?? null;
            if ($key === null) {
                throw new \RuntimeException('no key known for address ' . $to . '; look the partner up or reply to a message');
            }

            return $this->sendSealed($to, $key, null, $text, $data, $replyTo);
        }
        [$w, $key] = $this->addressFor($to);

        return $this->sendSealed($w, $key, $to, $text, $data, $replyTo);
    }

    /**
     * One message sealed to $key and written to $w. $partner is the name
     * presence is asked again with when that address has gone, and null for
     * an address given as it is. $archivedData stands in for $data in the
     * archive, for a message carrying what no file should.
     */
    private function sendSealed(string $w, string $key, ?string $partner, ?string $text = null, ?array $data = null, ?string $replyTo = null, ?array $archivedData = null): array
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
            ($this->log)('archive sent ' . $entry['id'] . ': ' . $archiveError);
        }
        if ($status !== 201) {
            throw new SendFailed($entry['status'], $entry['id'], $status, $result);
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

        return $sent;
    }

    /** The gate kept for w, here and in the client, belongs to an inbox that may not be there now. */
    private function forgetGate(string $w): void
    {
        unset($this->gates[$w]);
        $this->client->forgetGate($w);
    }

    private function outboxAdd(string $w, string $key, string $envelope, array $body, ?string $replaces = null): array
    {
        $entry = [
            'id' => 'm-' . substr(Codec::sha256hex($this->keys->public . '|' . $w . '|' . $envelope), 0, 16),
            'w' => $w, 'to_key' => $key, 'envelope' => $envelope,
            'summary' => array_intersect_key($body, array_flip(['post', 'reply_to', 'channel'])),
            'created_at' => time(), 'attempts' => 0, 'status' => 'sending', 'last_status' => null, 'replaces' => $replaces,
        ];
        $this->outbox[$entry['id']] = $entry;
        $this->saveOutbox();

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
        $sent = $this->client->send($w, $envelope, true, null);
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
        $this->saveOutbox();
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
            $out[] = ['id' => $id, 'w' => $entry['w'], 'status' => $this->outbox[$id]['status'], 'http' => $status];
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

    /** What was said, and separately, what can be trusted about it: [body, meta]. */
    public function open(array $message): array
    {
        $raw = is_string($message['body'] ?? null) ? $message['body'] : '';
        if (empty($message['verified']) || empty($message['from'])) {
            return [['text' => $raw], ['signed' => false, 'encrypted' => false, 'format' => 'unsigned']];
        }
        if (!Keys::isEnvelope($raw)) {
            $parsed = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [['text' => $raw], ['signed' => true, 'encrypted' => false, 'format' => 'text']];
            }
            if (is_array($parsed) && !array_is_list($parsed)) {
                [$content, $extra] = self::canonical($parsed);

                return [$content, ['signed' => true, 'encrypted' => false, 'format' => 'json'] + $extra];
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

        $parsed = json_decode($plaintext, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [['text' => $plaintext], ['signed' => true, 'encrypted' => true, 'format' => 'text']];
        }
        if (is_array($parsed) && !array_is_list($parsed)) {
            [$content, $extra] = self::canonical($parsed);

            return [$content, ['signed' => true, 'encrypted' => true, 'format' => 'json'] + $extra];
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
            return ['channel' => $channel->label, 'w' => $channel->w, 'seq' => $message['seq'], 'at' => $message['at'], 'verified' => $message['verified'], 'from_key' => $from, 'known_contact' => $known !== null, 'sender' => $known ?? ($from ? 'unknown key' : 'unsigned'), 'sha256' => $digest, 'replay' => is_string($digest) && isset($channel->seen[$digest]), 'body' => $body, 'unverified_because' => $message['unverified_because'] ?? null] + $meta;
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
     */
    public function poll(Channel $channel, int $wait = 0, ?int $limit = null, ?int $maxBytes = null): array
    {
        $channel->leftWaiting = 0;
        $channel->moreAtService = false;
        // A muted channel is read no more, whoever calls: read() skips it, and
        // a library caller that asks straight out gets the same answer instead
        // of the removed partner's messages.
        if ($channel->muted) {
            return ['muted', []];
        }
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
        $reset = $answer['reset'] ?? null;
        if ($channel->createdAt !== null && $created !== null && $created !== $channel->createdAt && !$reset) {
            $this->note($channel, 'restarted', 'the thread at this address is a new one, opened at ' . $created . ' where this channel knew one opened at ' . $channel->createdAt . ', so it is read again from the start');
            $channel->forgetThread();

            return $this->poll($channel, 0, $limit, $maxBytes);
        }
        if ($reset) {
            $this->note($channel, 'restarted', (is_array($reset) && is_string($reset['what'] ?? null)) ? $reset['what'] : 'the service read this thread from the start');
            $channel->observed = [];
        }
        $channel->createdAt = $created;

        // What the budget kept out. The service answers a byte budget honestly:
        // whole messages only, because a signed message cut in half does not
        // verify. So a thread holding one message larger than the budget answers
        // with no messages and too_large naming it -- and that read as an empty
        // inbox for as long as nothing here looked at the field.
        $tooLarge = $answer['too_large'] ?? null;

        if (is_array($tooLarge)) {
            $this->note(
                $channel,
                'too_large',
                'message ' . ($tooLarge['seq'] ?? '?') . ' on this channel is ' . ($tooLarge['bytes'] ?? '?') . ' bytes and does not fit the byte budget this read asked for, so it was not sent. It is still there and every read at this budget will leave it. ' . (is_string($tooLarge['fix'] ?? null) ? $tooLarge['fix'] : 'Read again with a larger max_bytes, or without one.'),
                isset($tooLarge['seq']) ? [(int) $tooLarge['seq']] : null
            );
        }

        // And what it held back that does fit: more messages after the ones sent.
        if (($answer['more'] ?? null) === true) {
            $channel->moreAtService = true;
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
        foreach ($read['observed'] ?? [] as $observed) {
            $channel->observed[$observed['seq']] = $observed;
            if ($observed['unverified_because'] !== null && $observed['service_verified']) {
                $disposition = $observed['kept_out'] ? 'kept out by this channel\'s list' : 'handed over as unverified';
                $this->note($channel, 'unverified', 'message ' . $observed['seq'] . ' was called verified by the service and does not check out here: ' . $observed['unverified_because'] . '. It is ' . $disposition . '. This can indicate a faulty service or an operator that lies.', [$observed['seq']]);
            }
        }
        $entries = [];
        foreach ($handed as $message) {
            $entry = $this->readEntry($channel, $message);
            $from = $entry['from_key'];
            $body = $entry['body'];
            if (is_string($entry['sha256'])) { $channel->seen[$entry['sha256']] = true; }
            // Before the message is kept, archived or shown: a scope key in it
            // goes to scopes.json or nowhere, never to the reader.
            $this->takeScopeShare($entry);
            // A verified message that carries an address binds the sender's key
            // to it: reply_to, as always, and channel, which a handoff carries.
            // A handoff used to leave the new address without a key, so the
            // first send to it failed with no key known for the address.
            if (is_array($body) && $from) {
                foreach (['reply_to', 'channel'] as $field) {
                    if (is_string($body[$field] ?? null) && $body[$field] !== '') {
                        $this->peers[$body[$field]] = $from;
                    }
                }
            }
            $channel->received[] = $entry;
            // Received, readable, archived and handled are four different things.
            try {
                $wrote = $this->archive($channel->label, $entry + ['kind' => 'received']);
                $entry['archived'] = $wrote;

                if (!$wrote) {
                    // Nothing went wrong and nothing was written: this folder keeps
                    // nothing decrypted. Said, so the caller does not go looking.
                    $entry['archive_off'] = true;
                }
            } catch (\Throwable $error) {
                $entry['archived'] = false;
                $entry['archive_error'] = $error->getMessage();
            }
            $entries[] = $entry;
        }
        if ($entries !== []) {
            // The cursor moves and the hashes are stored in the same save, before the caller sees a message.
            $channel->after = max($channel->after, (int) end($entries)['seq']);
            $this->saveState();
        }
        if ($keptOut !== []) {
            // Past them as well, or the same messages are read and kept out on
            // every call. And said, since a message that does not arrive has to
            // be told from one that was never sent.
            $seqs = array_map('intval', array_column($keptOut, 'seq'));
            $channel->after = max($channel->after, max($seqs));
            $this->saveState();
            $openedFor = in_array('*', $channel->allow, true) ? 'any key, signed only' : count($channel->allow) . ' named key(s)';
            $this->note($channel, 'kept_out', 'This channel was opened for ' . $openedFor . ', and these messages did not satisfy its list as checked here. They are not handed over and not archived.', $seqs);
        }
        // After a reset the service's next is the cursor, and it is lower than
        // the one this channel held. Keeping the higher of the two would ask
        // past the new thread on every call and hand the same messages over
        // each time.
        //
        // A poll that stopped at its limit is the exception: the service's next
        // covers messages this call never looked at, so the cursor is the last
        // one it did look at.
        if ($stopAt !== null) {
            if ($stopAt > 0) {
                $channel->after = $stopAt;
                $this->saveState();
            }
        } elseif (is_int($answer['next'] ?? null)) {
            $channel->after = $answer['next'];
            $this->saveState();
        }

        return ['ok', $entries];
    }

    /** Something a caller has to hear about, even though the read returned no messages. */
    private function note(Channel $channel, string $state, string $what, ?array $seqs = null): void
    {
        $this->noteTrouble($channel->label, $state, $what, $channel->w, $seqs);
    }

    private function noteTrouble(string $where, string $state, string $what, ?string $w = null, ?array $seqs = null): void
    {
        $key = $where . '|' . $state;
        $note = ['channel' => $where, 'w' => $w, 'state' => $state, 'what' => $what, 'at' => time()];
        if (in_array($state, ['kept_out', 'unverified'], true) && $seqs !== null) {
            $previous = $this->attention[$key] ?? [];
            $note['seqs'] = array_merge($previous['seqs'] ?? [], $seqs);
            $note['count'] = count($note['seqs']);
            $note['details'] = array_merge($previous['details'] ?? [], [$what]);
            $note['what'] = $note['count'] . ' message(s) (seq ' . implode(', ', $note['seqs']) . '): ' . implode(' ', $note['details']);
        }
        $this->attention[$key] = $note;
        ($this->log)($where . ': ' . $what);
    }

    /** What the reads since the last call could not do, once, and then cleared. */
    public function attentionTaken(): array
    {
        $taken = array_values($this->attention);
        usort($taken, static fn (array $a, array $b): int => [$a['at'], $a['channel']] <=> [$b['at'], $b['channel']]);
        $this->attention = [];

        return $taken;
    }

    /** New messages on the inbox and every open channel; the first channel waits, the rest are read at once. */
    public function read(int $wait = 0, int $limit = 50, ?int $maxBytes = null): array
    {
        $this->ensureInbox();
        $this->publishPresence();
        $collected = [];
        $waited = false;
        $leftWaiting = 0;
        $heldBack = [];
        $notAsked = [];
        foreach (array_values($this->channels) as $channel) {
            if ($channel->muted) {
                continue;
            }
            // A channel is only asked for what this call still has room for.
            // poll() moves the cursor and saves it before the caller sees a
            // message, so whatever was fetched beyond the limit and cut off
            // here afterwards was past the cursor and never came back: 60
            // waiting, 50 handed over, the last ten gone.
            $room = $limit - count($collected);
            if ($room <= 0) {
                $notAsked[] = $channel->label;
                continue;
            }
            try {
                // The whole budget goes to each channel in turn, as the count does:
                // a budget divided between channels would refuse a message that fits,
                // and the caller cannot know beforehand which channel holds the bytes.
                [$state, $entries] = $this->poll($channel, $waited ? 0 : $wait, $room, $maxBytes);
            } catch (\Throwable $error) {
                $this->note($channel, 'unread', 'this channel could not be read: ' . (new \ReflectionClass($error))->getShortName() . '. Messages from other channels are still returned.');
                continue;
            }
            // A channel that answered 410 or nothing at all used to eat the
            // whole wait, so a read with wait 25 came back at once and the
            // inbox was only ever asked with wait 0.
            // A gone channel did wait: the service holds a read of a missing thread for a write.
            $waited = $waited || $state === 'ok' || $state === 'gone';
            foreach ($entries as $entry) {
                $collected[] = $entry;
            }
            $leftWaiting += $channel->leftWaiting;

            if ($channel->moreAtService) {
                $heldBack[] = $channel->label;
            }
        }
        if ($heldBack !== []) {
            // The service said it had more than the budget allowed. It was written
            // down on the channel and never said out loud, so a read that stopped
            // early looked exactly like one that had finished.
            $this->noteTrouble('read', 'more', 'the service had more waiting on '
                . implode(', ', $heldBack)
                . ' than the byte budget this read asked for, so it sent what fits and kept the rest. Read again for it: the cursor stands at the last message handed over.');
        }
        if ($leftWaiting > 0 || $notAsked !== []) {
            // Nothing is lost, and the caller still has to hear it: a read that
            // stopped at its limit is not a read of everything.
            $this->noteTrouble('read', 'more', 'this read stopped at its limit of ' . $limit . ' message(s). '
                . ($leftWaiting > 0 ? $leftWaiting . ' more that the service had already returned were left where they are. ' : '')
                . ($notAsked !== [] ? count($notAsked) . ' channel(s) were not asked this time: ' . implode(', ', $notAsked) . '. ' : '')
                . 'Nothing was passed over: every cursor stands at the last message this read dealt with, so read again for the rest.');
        }

        return $collected;
    }

    // ------------------------------------------------------------ receipt --

    public function receipt(string $label = 'inbox', bool $anchor = false): array
    {
        $channel = $this->channels[$label] ?? null;
        if ($channel === null) {
            throw new \InvalidArgumentException('no such channel: ' . $label);
        }
        $taken = $this->client->receipt($channel->w, $channel->readKey);
        if ($taken['status'] !== 200) {
            throw new \RuntimeException('receipt failed: ' . $taken['status'] . ' ' . json_encode($taken['body']));
        }
        $data = $taken['body'];
        $entries = array_values($channel->observed);
        usort($entries, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        $held = count($entries);
        $comparable = $held === (int) $data['count'];
        $ours = '';
        foreach ($entries as $e) {
            $ours .= sprintf("%d\t%d\t%s\t%s\n", $e['seq'], $e['at'], $e['sha256'], is_string($e['from']) ? ($e['from'] ?: '-') : '-');
        }
        $verifiedKeys = array_values(array_unique(array_column(array_filter($entries, static fn (array $entry): bool => $entry['verified'] && is_string($entry['from_key'])), 'from_key')));
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
            $result['local_check'] = sprintf('Not compared: this process holds %d of the %d messages the receipt counts. root_adds_up checks arithmetic only; our signature records what was fetched, not that its claims are true. Take the receipt in the process that read the messages for the independent comparison.', $held, $data['count']);
        }
        if ($anchor) {
            $idem = substr(Codec::sha256hex('aamio-listen:' . $this->keys->hash . ':' . $data['root']), 0, 32);
            $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'verifyum_anchor_commitment', 'arguments' => ['commitment' => 'sha256:' . $data['root'], 'idempotency_key' => $idem]]];
            [$status, $reply] = Http::call('POST', $this->verifyum, Codec::json($message), ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-11-25']);
            $proof = is_array($reply) ? json_decode((string) ($reply['result']['content'][0]['text'] ?? ''), true) : null;
            $result['anchor'] = $status === 200 && is_array($proof) ? $proof : ['error' => $status, 'detail' => $reply];
        }
        $this->archive($label, ['kind' => 'receipt', 'at' => time(), 'result' => $result]);

        return $result;
    }

    public function close(): void
    {
        // PHP has no background threads here, so there is nothing to wait for:
        // when this returns, nothing else in this process is reading.
        $this->homeReleased = true;
        $held = $this->loadJson('lock', null);
        if (is_array($held) && ($held['pid'] ?? null) === getmypid()) {
            @unlink($this->path('lock'));
        }
        unset(self::$lockedHomes[realpath($this->home) ?: $this->home]);
    }
}
