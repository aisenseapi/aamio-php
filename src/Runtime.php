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
    /** @var array<string, array> */
    private array $gates = [];
    /** @var array<string, true> */
    private static array $lockedHomes = [];

    public function __construct(?string $home = null, ?string $host = null, ?array $tags = null, bool $archive = true, ?callable $log = null)
    {
        $this->home = $home ?: self::homeDir();
        $this->host = rtrim($host ?: (getenv('AAMIO_HOST') ?: Hosts::DEFAULT_HOST), '/');
        $this->archiveEnabled = $archive;
        $this->log = $log ?: static function (string $line): void {
        };
        if (!is_dir($this->home . '/archive')) {
            mkdir($this->home . '/archive', 0700, true);
        }
        $this->takeLock();
        try {
            $this->load($tags);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
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

    public function archive(string $label, array $record): void
    {
        if (!$this->archiveEnabled) {
            return;
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        file_put_contents($this->path('archive' . DIRECTORY_SEPARATOR . $label . '.jsonl'), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return string[] labels this runtime has an archive for, the ones a board inbox uses */
    private function archiveLabels(string $prefix = 'board'): array
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
        $found = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
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

    public function partnerAdd(string $name, string $key): void
    {
        if (!Codec::isKey($key)) {
            throw new \InvalidArgumentException('not a base64url Ed25519 public key of 32 bytes');
        }
        $this->partners = array_values(array_filter($this->partners, static fn (array $p): bool => $p['name'] !== $name && $p['key'] !== $key));
        $this->partners[] = ['name' => $name, 'key' => $key];
        $this->saveJson('partners.json', $this->partners);
    }

    public function partnerRemove(string $name): void
    {
        $this->partners = array_values(array_filter($this->partners, static fn (array $p): bool => $p['name'] !== $name));
        $this->saveJson('partners.json', $this->partners);
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

    public function ensureInbox(): Channel
    {
        $inbox = $this->channels['inbox'] ?? null;
        if ($inbox !== null && $inbox->expireAt - time() > self::RENEW_BEFORE) {
            return $inbox;
        }
        $allow = $this->partners !== [] ? array_map(static fn (array $p): string => $p['key'], $this->partners) : null;
        $opened = $this->client->open(self::INBOX_TTL, $allow);
        if ($opened['status'] !== 201) {
            throw new \RuntimeException('could not open inbox: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $fresh = new Channel('inbox', $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], $allow ?? []);
        if ($inbox !== null) {
            // Keep reading the old one until it dies; partners may still write there.
            $this->channels['inbox-' . $inbox->expireAt] = $inbox;
        }
        $this->channels['inbox'] = $fresh;
        $this->saveState();
        ($this->log)(sprintf('inbox %s until %d%s', $fresh->w, $fresh->expireAt, $allow ? ' (allowlist ' . count($allow) . ' keys)' : ''));
        $this->publishPresence(true);

        return $fresh;
    }

    public function publishPresence(bool $force = false): ?bool
    {
        if (!$force && microtime(true) - $this->presenceAt < self::PRESENCE_REFRESH) {
            return null;
        }
        $inbox = $this->channels['inbox'] ?? null;
        if ($inbox === null) {
            return null;
        }
        $result = $this->client->presencePublish($inbox->w, array_slice($this->tags, 0, 8), self::PRESENCE_TTL);
        $this->presenceAt = microtime(true);
        if ($result['status'] !== 200) {
            ($this->log)('presence failed: ' . $result['status'] . ' ' . json_encode($result['body']));
        }

        return $result['status'] === 200;
    }

    // ----------------------------------------------------------- channels --

    public function openChannel(string $label, int $ttl, ?array $allowNames = null): array
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
        $opened = $this->client->open($ttl, $keys !== [] ? $keys : null);
        if ($opened['status'] !== 201) {
            throw new \RuntimeException('could not open channel: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel($label, $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], $keys);
        $this->channels[$label] = $channel;
        $this->saveState();

        return ['label' => $label, 'w' => $channel->w, 'expire_at' => $channel->expireAt, 'allow' => array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, $keys)];
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

        return array_values(array_map(fn (Channel $c): array => ['label' => $c->label, 'w' => $c->w, 'expire_at' => $c->expireAt, 'seconds_left' => $c->secondsLeft($now), 'allow' => array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, $c->allow), 'received' => count($c->received), 'after' => $c->after], $this->channels));
    }

    // -------------------------------------------------------------- board --

    /** An inbox for board answers: any key, signed only. Reused while it lasts. */
    public function ensureBoardInbox(int $seconds = 900): Channel
    {
        $held = $this->channels['board'] ?? null;
        if ($held !== null && $held->expireAt - time() > $seconds) {
            return $held;
        }
        $ttl = min(self::INBOX_TTL, max($seconds + 60, 900));
        $opened = $this->client->open($ttl, ['*']);
        if ($opened['status'] !== 201) {
            throw new \RuntimeException('could not open the board inbox: ' . $opened['status'] . ' ' . json_encode($opened['body']));
        }
        $channel = new Channel('board', $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], ['*']);
        if ($held !== null) {
            $this->channels['board-' . $held->expireAt] = $held;
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

        $posted = ['id' => $answer['id'], 'kind' => $kind, 'title' => $title, 'expire_at' => $answer['expire_at'] ?? null, 'work_bits' => $answer['work_bits'] ?? $bits, 'reply_inbox' => $inbox->w, 'inbox_expires_at' => $inbox->expireAt, 'replies_arrive_on' => 'board'];
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

    public function boardGet(string $postId): ?array
    {
        $got = $this->board->get($postId);

        return $got['status'] === 200 && is_array($got['body']) ? $got['body'] : null;
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
        $notes = [];
        [$status, $result] = $this->post((string) $post['w'], $envelope, $notes);
        $this->archive('board', ['kind' => 'answered', 'at' => time(), 'post' => $post['id'], 'w' => $post['w'], 'status' => $status, 'body' => $body]);
        if ($status !== 201) {
            throw new \RuntimeException('answer failed: ' . $status . ' ' . json_encode($result));
        }
        $answer = ['post' => $post['id'], 'w' => $post['w'], 'seq' => $result['seq'], 'at' => $result['at'], 'replies_arrive_on' => 'board', 'reply_to' => $channel->w];
        if (isset($result['met'])) {
            $answer['met'] = $result['met'];
            $answer['proof_id'] = $result['proof_id'] ?? null;
        }
        if ($notes !== []) {
            $answer['notes'] = $notes;
        }
        if ($ownPost) {
            $answer['warning'] = 'You answered your own post. The answer is sealed to your own key, so it reaches nobody but you.';
        }

        return $answer;
    }

    /** Answers received on every channel and in the archive, decrypted and verified, oldest first. */
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
        foreach ($this->channels as $channel) {
            foreach ($channel->received as $entry) {
                if ($wanted($entry)) {
                    $seen[$entry['sha256']] = true;
                    $out[] = $entry;
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
                if ($wanted($entry) && !isset($seen[$entry['sha256'] ?? ''])) {
                    $seen[$entry['sha256'] ?? ''] = true;
                    $entry['from_archive'] = true;
                    $out[] = $entry;
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => [(int) ($a['at'] ?? 0), (int) ($a['seq'] ?? 0)] <=> [(int) ($b['at'] ?? 0), (int) ($b['seq'] ?? 0)]);

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
            $notes = [];
            [$status, $handed] = $this->post($replyTo, $this->keys->seal($key, Codec::json($body)), $notes);
            if ($status !== 201) {
                throw new \RuntimeException('channel opened but the address could not be handed over: ' . $status . ' ' . json_encode($handed));
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
        // What the inbox asks of writers is read before anything is stored.
        $plan = Gate::plan($this->gateFor($w));
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
        $this->archive('sent', $record);
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

        return $sent;
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
            $this->outbox[$id]['status'] = 'refused';
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
        if ($status === 201) {
            $this->outbox[$id]['status'] = 'delivered';
            $this->outbox[$id]['seq'] = is_array($result) ? ($result['seq'] ?? null) : null;
        } elseif ($status === 0) {
            $this->outbox[$id]['status'] = 'unknown';
        } else {
            $this->outbox[$id]['status'] = 'refused';
            $this->outbox[$id]['error'] = is_array($result) ? ($result['error'] ?? null) : substr((string) $result, 0, 200);
        }
        $this->saveOutbox();

        return [$status, $result];
    }

    // ------------------------------------------------------------- outbox --

    public function outboxPending(): array
    {
        return array_values(array_filter($this->outbox, static fn (array $e): bool => in_array($e['status'], ['sending', 'unknown'], true)));
    }

    public function outboxRetry(?string $messageId = null): array
    {
        $out = [];
        foreach (array_keys($this->outbox) as $id) {
            $entry = $this->outbox[$id];
            if ($messageId !== null && $id !== $messageId) {
                continue;
            }
            if (!in_array($entry['status'], ['unknown', 'refused'], true)) {
                continue;
            }
            if ($entry['status'] === 'refused' && in_array($entry['last_status'], self::SEND_DETERMINISTIC, true)) {
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

    public function outboxForget(string $messageId): array
    {
        $had = isset($this->outbox[$messageId]);
        unset($this->outbox[$messageId]);
        $this->saveOutbox();

        return ['id' => $messageId, 'forgotten' => $had];
    }

    /** (retryable, fix) for one send outcome. retryable is about the same stored bytes, never a permission to repeat automatically. */
    public static function sendAdvice(string $outcome, int $status): array
    {
        if ($outcome === 'unknown') {
            return [null, 'No answer came back, so this message may already have been delivered. Keep its message_id. aamio_pending lists what has no settled outcome on this machine; it does not confirm delivery, and nothing here can, because the address it went to is not yours to read. This interface has no retry-by-id tool: do not pass the message_id to aamio_send and do not compose a replacement. An approved retry sends the stored bytes again through the runtime\'s own outbox retry.'];
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
        $raw = (string) ($message['body'] ?? '');
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
        try {
            $plaintext = $this->keys->open((string) $message['from'], $raw);
            $parsed = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            return [['text' => null], ['signed' => true, 'encrypted' => true, 'format' => 'unreadable', 'error' => (new \ReflectionClass($error))->getShortName()]];
        }
        if (is_array($parsed) && !array_is_list($parsed)) {
            [$content, $extra] = self::canonical($parsed);

            return [$content, ['signed' => true, 'encrypted' => true, 'format' => 'json'] + $extra];
        }

        return [['text' => $parsed], ['signed' => true, 'encrypted' => true, 'format' => 'json']];
    }

    /** One read of a channel from its cursor: [state, entries], state ok | expired | error. */
    public function poll(Channel $channel, int $wait = 0): array
    {
        $read = $this->client->read($channel->w, $channel->readKey, $channel->after, $wait);
        if ($read['status'] === 410) {
            $this->note($channel, 'expired', 'the thread at this address has expired, so anything written to it before now is gone and nothing more will arrive here');

            return ['expired', []];
        }
        if ($read['status'] !== 200) {
            $this->note($channel, 'unread', 'the service answered ' . $read['status'] . ', so this channel was not read and there may be messages waiting');

            return ['error', []];
        }
        $entries = [];
        foreach ((array) ($read['body']['messages'] ?? []) as $message) {
            $from = $message['from'] ?? null;
            $known = $this->nameForKey($from);
            $entry = [
                'channel' => $channel->label, 'seq' => $message['seq'], 'at' => $message['at'], 'verified' => (bool) ($message['verified'] ?? false),
                'from_key' => $from, 'known_contact' => $known !== null, 'sender' => $known ?? ($from ? 'unknown key' : 'unsigned'),
                'sha256' => $message['sha256'], 'replay' => isset($channel->seen[$message['sha256']]),
            ];
            $channel->seen[$message['sha256']] = true;
            try {
                [$body, $meta] = $this->open($message);
            } catch (\Throwable $error) {
                [$body, $meta] = [['text' => $message['body'] ?? null], ['signed' => (bool) $from, 'encrypted' => false, 'format' => 'undecodable', 'error' => (new \ReflectionClass($error))->getShortName()]];
            }
            $entry['body'] = $body;
            $entry += $meta;
            // Before the message is kept, archived or shown: a scope key in it
            // goes to scopes.json or nowhere, never to the reader.
            $this->takeScopeShare($entry);
            if (is_array($body) && is_string($body['reply_to'] ?? null) && $from) {
                $this->peers[$body['reply_to']] = $from;
            }
            $channel->received[] = $entry;
            // Received, readable, archived and handled are four different things.
            try {
                $this->archive($channel->label, $entry + ['kind' => 'received']);
                $entry['archived'] = true;
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

        return ['ok', $entries];
    }

    /** Something a caller has to hear about, even though the read returned no messages. */
    private function note(Channel $channel, string $state, string $what): void
    {
        $this->attention[$channel->label . '|' . $state] = ['channel' => $channel->label, 'w' => $channel->w, 'state' => $state, 'what' => $what, 'at' => time()];
        ($this->log)($channel->label . ': ' . $what);
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
    public function read(int $wait = 0, int $limit = 50): array
    {
        $this->ensureInbox();
        $this->publishPresence();
        $collected = [];
        $first = true;
        foreach (array_values($this->channels) as $channel) {
            [, $entries] = $this->poll($channel, $first ? $wait : 0);
            $first = false;
            foreach ($entries as $entry) {
                $collected[] = $entry;
            }
        }

        return array_slice($collected, 0, $limit);
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
        $entries = $channel->received;
        usort($entries, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        $held = count($entries);
        $comparable = $held === (int) $data['count'];
        $ours = '';
        foreach ($entries as $e) {
            $ours .= sprintf("%d\t%d\t%s\t%s\n", $e['seq'], $e['at'], $e['sha256'], $e['from_key'] ?: '-');
        }
        $attestation = sprintf("aamio-receipt-v1\n%s\n%s\n%d\n%d", $channel->w, $data['root'], $data['count'], $data['issued_at'] ?? 0);
        $result = [
            'label' => $label, 'w' => $channel->w, 'root' => $data['root'], 'commitment' => $data['commitment'] ?? null, 'count' => $data['count'],
            'keys' => array_map(fn (string $k): string => $this->nameForKey($k) ?? $k, (array) ($data['keys'] ?? [])),
            'root_adds_up' => $taken['check']['root_adds_up'], 'local_root_matches' => $comparable ? hash('sha256', $ours) === $data['root'] : null,
            'issued_at' => $data['issued_at'] ?? null, 'expire_at' => $data['expire_at'] ?? null, 'gate_hash' => $data['gate_hash'] ?? null,
            'attestation' => ['key' => $this->keys->public, 'over' => $attestation, 'sig' => $this->keys->sign($attestation)],
        ];
        if (!$comparable) {
            $result['local_check'] = sprintf('Not compared: this process holds %d of the %d messages the receipt counts, so a local root would differ for a reason that is not the receipt\'s. The receipt stands on root_adds_up and the signature. For the independent check, take the receipt in the process that read the messages.', $held, $data['count']);
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
        $held = $this->loadJson('lock', null);
        if (is_array($held) && ($held['pid'] ?? null) === getmypid()) {
            @unlink($this->path('lock'));
        }
        unset(self::$lockedHomes[realpath($this->home) ?: $this->home]);
    }
}
