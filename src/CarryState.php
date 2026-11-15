<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Sessions, counters and receipts for aamio-carry-v1 (contract 3, A6 and A7).
 *
 * Carry says whether an envelope is well formed and signed; this says whether
 * it is taken. One CarryState belongs to one home and holds every session in
 * both directions over every transport, so a copy that arrives over another
 * medium is known again.
 *
 * What hangs on the monotonic clock goes at a restart: challenges that wait,
 * the time each session with a challenge was opened at, the time this party
 * asked, and the counts behind the limits per minute. Everything else is
 * written to the home before anything is answered: the sessions, the session
 * ids that were used, each message taken with its mark and its content until
 * the program says it has it, each message sent with its bytes and its tries
 * until its receipt comes, the time floor and the count of challenges given.
 * A write that fails is carry_storage_failed with no receipt, and the state
 * then refuses everything until restart has read the file again. Nothing that
 * guards against a replay is deleted before A6.9 allows it.
 *
 * The kept state has one shape in every runtime, since another runtime may take
 * the home over: docs/aamio-carry-v1-tilstand-2026-11-15.md in the service says
 * it field by field. A file of any other shape is refused whole as
 * carry_state_unreadable, rather than read in part and written back without
 * what could not be read. PHP reads {} and [] alike, so an empty map may be
 * either here, and it is always written as {}.
 */
final class CarryState
{
    public const WINDOW = 64;
    public const SKEW = 120;
    public const HORIZON = 604800;
    public const LIFETIME_DEFAULT = 600;
    public const LIFETIME_MAX = 86400;
    public const CHALLENGE_WINDOW_DEFAULT = 60;
    public const CHALLENGE_WINDOW_MIN = 10;
    public const CHALLENGE_WINDOW_MAX = 600;
    public const MAX_WAITING_PER_PARTNER = 8;
    public const MAX_WAITING = 64;
    public const MAX_OPEN_PER_PARTNER = 16;
    public const MESSAGES_PER_MINUTE = 60;
    public const BYTES_PER_MINUTE = 262144;
    public const ASKS_PER_MINUTE = 10;
    public const SPENT_KEPT_PER_PARTNER = 64;
    public const TRIES_DEFAULT = 30;
    public const U32 = 4294967295;
    /** The largest whole number JSON keeps exact in every runtime. */
    public const SAFE = 9007199254740991;
    private const STATE_VERSION = 1;
    private const ENVELOPE_MOST = 43 + 24 + Carry::MAX_CONTENT + 64 + 16;
    private const RECEIPT_RND = "aamio-carry-v1 receipt\n";
    private const TOP = ['challenges', 'floor', 'incoming', 'outbox', 'outgoing', 'stored', 'used', 'version'];
    private const INCOMING = ['challenged', 'closed', 'closed_at', 'deadline', 'highest', 'lifetime', 'nonce', 'opened'];
    private const OUTGOING = ['answered', 'closed', 'closed_at', 'counter', 'deadline', 'nonce'];
    private const STORED = ['at', 'content', 'sealed', 'sha256'];
    private const OUTBOX = ['envelope', 'most', 'receipt', 'sha256', 'tries'];
    private const MAPS = ['incoming', 'stored', 'outgoing', 'outbox'];

    public static int $maxRecords = 100000;
    public static int $maxRecordsPerPartner = 10000;

    public array $durable;
    public bool $broken = false;
    private array $used;
    private ?int $keptFloor;
    private array $waiting = [];
    private array $anchors = [];
    private array $askedAt = [];
    /** @var array<string, list<array{0: float, 1: int}>> pk hex => what came in the last minute */
    private array $messagesSeen = [];
    /** @var array<string, list<array{0: float, 1: int}>> */
    private array $asksSeen = [];
    /** @var callable(int): string */
    private $randomBytes;

    public function __construct(public readonly Keys $keys, public readonly ?string $path = null, ?callable $randomBytes = null)
    {
        $this->randomBytes = $randomBytes ?? static fn (int $n): string => random_bytes($n);
        $this->durable = $this->load();
        $this->used = array_fill_keys($this->durable['used'], true);
        $this->keptFloor = $this->durable['floor'];
    }

    /** A security profile (B5), checked: what the user chose and the core holds to. */
    public static function profile(string $mode = 'sealed', bool $challenge = true, bool $acceptUnchallenged = false, int $lifetime = self::LIFETIME_DEFAULT,
        int $challengeWindow = self::CHALLENGE_WINDOW_DEFAULT, bool $clockTrusted = true, int $maxContent = Carry::MAX_CONTENT): array
    {
        if (!in_array($mode, ['sealed', 'readable'], true)) {
            throw new \InvalidArgumentException('mode is sealed or readable');
        }
        if ($challenge && $acceptUnchallenged) {
            throw new \InvalidArgumentException('a profile with challenges takes nothing without one');
        }
        if ($challengeWindow < self::CHALLENGE_WINDOW_MIN || $challengeWindow > self::CHALLENGE_WINDOW_MAX) {
            throw new \InvalidArgumentException('challenge_window is 10 to 600 seconds');
        }
        if ($lifetime <= $challengeWindow || $lifetime > self::LIFETIME_MAX) {
            throw new \InvalidArgumentException('lifetime is longer than challenge_window and at most 86400 seconds');
        }
        if ($maxContent < 0 || $maxContent > Carry::MAX_CONTENT) {
            throw new \InvalidArgumentException('max_content is at most ' . Carry::MAX_CONTENT);
        }

        return ['mode' => $mode, 'challenge' => $challenge, 'accept_unchallenged' => $acceptUnchallenged, 'lifetime' => $lifetime,
                'challenge_window' => $challengeWindow, 'clock_trusted' => $clockTrusted, 'max_content' => $maxContent];
    }

    private static function pair(string $pk, string $session): string
    {
        return bin2hex($pk) . ':' . bin2hex($session);
    }

    /** The id a message is known by in both homes and after a restart: partner, session and counter. */
    public static function mark(string $pk, string $session, int $counter): string
    {
        return bin2hex($pk) . ':' . bin2hex($session) . ':' . $counter;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private static function parts(string $key): array
    {
        [$pk, $session, $counter] = explode(':', $key);

        return [hex2bin($pk), hex2bin($session), (int) $counter];
    }

    private static function emptyState(): array
    {
        return ['version' => self::STATE_VERSION, 'floor' => null, 'challenges' => 0, 'incoming' => [], 'used' => [], 'stored' => [],
                'outgoing' => [], 'outbox' => []];
    }

    // ---------------------------------------------------------- the shape --

    private static function int(mixed $value, int $low, int $high): bool
    {
        return is_int($value) && $value >= $low && $value <= $high;
    }

    private static function time(mixed $value): bool
    {
        return $value === null || self::int($value, 0, self::SAFE);
    }

    private static function hex(mixed $value, ?int $size = null, ?int $most = null): bool
    {
        return is_string($value) && preg_match('/\A(?:[0-9a-f]{2})*\z/', $value) === 1 && ($size === null || strlen($value) === 2 * $size)
            && ($most === null || strlen($value) <= 2 * $most);
    }

    private static function fields(mixed $record, array $sorted): bool
    {
        if (!is_array($record)) {
            return false;
        }
        $keys = array_keys($record);
        sort($keys, SORT_STRING);

        return $keys === $sorted;
    }

    private static function pairKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/\A[0-9a-f]{64}:[0-9a-f]{16}\z/', $key) === 1;
    }

    private static function markKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/\A[0-9a-f]{64}:[0-9a-f]{16}:(0|[1-9][0-9]{0,9})\z/', $key, $found) === 1 && (int) $found[1] <= self::U32;
    }

    private static function incomingOk(mixed $record): bool
    {
        if (!self::fields($record, self::INCOMING) || !is_bool($record['challenged']) || !is_bool($record['closed']) || !self::int($record['highest'], 0, self::U32)) {
            return false;
        }
        if ($record['closed'] !== ($record['closed_at'] !== null) || !self::time($record['closed_at'])) {
            return false;
        }
        if ($record['challenged']) {
            return self::hex($record['nonce'], Carry::NONCE_BYTES) && $record['deadline'] === null && self::int($record['opened'], 0, self::SAFE)
                && self::int($record['lifetime'], self::CHALLENGE_WINDOW_MIN + 1, self::LIFETIME_MAX);
        }

        return $record['nonce'] === null && self::int($record['deadline'], 1, self::U32) && $record['opened'] === null && $record['lifetime'] === null;
    }

    private static function outgoingOk(mixed $record): bool
    {
        if (!self::fields($record, self::OUTGOING) || !is_bool($record['answered']) || !is_bool($record['closed']) || !self::int($record['counter'], 0, self::U32)) {
            return false;
        }
        if ($record['closed'] !== ($record['closed_at'] !== null) || !self::time($record['closed_at'])) {
            return false;
        }
        if ($record['answered']) {
            return self::hex($record['nonce'], Carry::NONCE_BYTES) && $record['deadline'] === null;
        }

        return $record['nonce'] === null && ($record['deadline'] === null || self::int($record['deadline'], 1, self::U32));
    }

    private static function storedOk(mixed $entry): bool
    {
        return self::fields($entry, self::STORED) && self::hex($entry['sha256'], 32)
            && ($entry['content'] === null || self::hex($entry['content'], null, Carry::MAX_CONTENT))
            && is_bool($entry['sealed']) && self::int($entry['at'], 0, self::SAFE);
    }

    private static function outboxOk(mixed $entry): bool
    {
        if (!self::fields($entry, self::OUTBOX) || !self::hex($entry['sha256'], 32) || !is_bool($entry['receipt'])) {
            return false;
        }
        if (!self::int($entry['most'], 1, self::U32) || !self::int($entry['tries'], 0, $entry['most'])) {
            return false;
        }

        return $entry['envelope'] === null || (self::hex($entry['envelope'], null, self::ENVELOPE_MOST) && !$entry['receipt']);
    }

    /** The shape every runtime writes (the tilstand document): anything else is not read at all. */
    public static function readable(mixed $value): bool
    {
        if (!self::fields($value, self::TOP) || $value['version'] !== self::STATE_VERSION || !self::time($value['floor'])
            || !self::int($value['challenges'], 0, self::SAFE)) {
            return false;
        }
        $used = $value['used'];
        if (!is_array($used) || !array_is_list($used) || count(array_filter($used, [self::class, 'pairKey'])) !== count($used)
            || count(array_unique($used)) !== count($used)) {
            return false;
        }
        foreach (self::MAPS as $name) {
            // An empty map may have come as [], since PHP reads {} and [] alike; one with entries is never a list.
            if (!is_array($value[$name]) || ($value[$name] !== [] && array_is_list($value[$name]))) {
                return false;
            }
        }
        foreach ($value['incoming'] as $key => $record) {
            if (!self::pairKey($key) || !self::incomingOk($record)) {
                return false;
            }
        }
        foreach ($value['outgoing'] as $key => $record) {
            if (!self::pairKey($key) || !self::outgoingOk($record)) {
                return false;
            }
        }
        foreach ($value['stored'] as $key => $entry) {
            if (!self::markKey($key) || !self::storedOk($entry)) {
                return false;
            }
        }
        foreach ($value['outbox'] as $key => $entry) {
            if (!self::markKey($key) || !self::outboxOk($entry)) {
                return false;
            }
        }
        // What belongs together: a session taken in is a session id used, and a message kept belongs to a session kept.
        $usedSet = array_fill_keys($used, true);
        foreach (array_keys($value['incoming']) as $key) {
            if (!isset($usedSet[$key])) {
                return false;
            }
        }
        foreach (array_keys($value['stored']) as $key) {
            if (!isset($value['incoming'][substr((string) $key, 0, strrpos((string) $key, ':'))])) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------ keeping --

    private function load(): array
    {
        if ($this->path === null || !file_exists($this->path)) {
            return self::emptyState();
        }
        $text = @file_get_contents($this->path);
        $value = is_string($text) ? json_decode($text, true) : null;
        if (!self::readable($value)) {
            // Starting from nothing would forget which messages were taken, and a copy of one could then be taken
            // again; reading only part of it would write the rest away at the next save.
            throw new CarryRefused('carry_state_unreadable', $this->path . ' could not be read, so nothing is taken until it is repaired or moved away');
        }

        return $value;
    }

    /** The kept state as JSON writes it: the maps as objects also when empty, so the other runtimes read them as maps. */
    private function forJson(): array
    {
        $out = $this->durable;
        foreach (self::MAPS as $name) {
            $out[$name] = (object) $out[$name];
        }

        return $out;
    }

    private function save(): void
    {
        $this->durable['used'] = array_map('strval', array_keys($this->used));
        // As text, as the other runtimes sort it: a regular sort compares ids of only digits as numbers.
        sort($this->durable['used'], SORT_STRING);
        if ($this->path !== null) {
            $folder = dirname($this->path);
            $tmp = $this->path . '.tmp';
            $mask = umask(0077);
            $written = false;
            try {
                if (is_dir($folder) || @mkdir($folder, 0700, true) || is_dir($folder)) {
                    $handle = @fopen($tmp, 'wb');
                    if ($handle !== false) {
                        $written = @fwrite($handle, json_encode($this->forJson(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                        Storage::sync($handle);
                        fclose($handle);
                    }
                }
            } finally {
                umask($mask);
            }
            if ($written === false || !@rename($tmp, $this->path)) {
                // What is in memory may now hold what the file does not: nothing more is answered from it.
                $this->broken = true;
                throw new CarryRefused('carry_storage_failed', $this->path . ' could not be written');
            }
        }
        $this->keptFloor = $this->durable['floor'];
    }

    private function check(): void
    {
        if ($this->broken) {
            throw new CarryRefused('carry_storage_failed', 'a write of ' . $this->path . ' failed, so nothing is taken or sent until restart has read it again');
        }
    }

    /** What a new process on the same home would have: the kept state, and nothing from the monotonic clock. */
    public function restart(): void
    {
        if ($this->path !== null) {
            $this->durable = $this->load();
            $this->broken = false;
        }
        $this->used = array_fill_keys($this->durable['used'], true);
        $this->keptFloor = $this->durable['floor'];
        $this->waiting = [];
        $this->anchors = [];
        $this->askedAt = [];
        $this->messagesSeen = [];
        $this->asksSeen = [];
    }

    private function tick(int $now): int
    {
        $this->durable['floor'] = $this->durable['floor'] === null ? $now : max($this->durable['floor'], $now);

        return $this->durable['floor'];
    }

    /** A higher floor is kept before anything is answered, also a refusal (A6.6.6): a passed deadline never comes back. */
    private function keepFloor(): void
    {
        if ($this->durable['floor'] !== $this->keptFloor) {
            $this->save();
        }
    }

    private function records(?string $pk = null): int
    {
        $prefix = $pk === null ? null : bin2hex($pk) . ':';
        $count = 0;
        foreach ([$this->durable['incoming'], $this->durable['stored'], $this->durable['outgoing'], $this->durable['outbox'], $this->used] as $group) {
            foreach (array_keys($group) as $key) {
                if ($prefix === null || str_starts_with((string) $key, $prefix)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function room(string $pk, int $more = 1): void
    {
        if ($this->records() + $more > self::$maxRecords || $this->records($pk) + $more > self::$maxRecordsPerPartner) {
            throw new CarryRefused('carry_storage_full', 'the carrier state holds as much as it may, and nothing that guards against a replay is deleted to make room');
        }
    }

    // ------------------------------------------------------------ sending --

    /** 8 random bytes in use in neither direction with this partner (A6.2, step 1). */
    public function newSession(string $partnerPk): string
    {
        while (true) {
            $session = ($this->randomBytes)(8);
            $pair = self::pair($partnerPk, $session);
            if (!isset($this->used[$pair]) && !isset($this->durable['outgoing'][$pair])) {
                return $session;
            }
        }
    }

    /** @return array{0: string, 1: string} the session and the ask */
    public function ask(string $partnerPk, array $profile, float $mono, ?string $session = null, ?string $rnd = null): array
    {
        $this->check();
        $session ??= $this->newSession($partnerPk);
        $pair = self::pair($partnerPk, $session);
        $this->room($partnerPk);
        $this->durable['outgoing'][$pair] = ['nonce' => null, 'answered' => false, 'counter' => 0, 'closed' => false, 'closed_at' => null, 'deadline' => null];
        $this->save();
        $this->askedAt[$pair] = $mono;

        return [$session, $this->write($partnerPk, $profile, Carry::ASK, 0, $session, 0, 0, '', null, $rnd)];
    }

    /**
     * A message in this party's own session: bound where it was opened with a challenge, with one deadline where it was not.
     * The envelope is kept in the outbox with its tries before it leaves, so a crash never sends a counter twice, and a
     * carrier on the same home after a restart sends the same bytes again.
     */
    public function send(string $partnerPk, string $session, string $content, array $profile, int $deadline = 0, ?string $rnd = null, int $most = self::TRIES_DEFAULT): string
    {
        $this->check();
        if ($deadline < 0 || $deadline > self::U32) {
            throw new \ValueError('a deadline is a whole number of seconds from 0 to 2^32 - 1');
        }
        if ($most < 1 || $most > self::U32) {
            throw new \ValueError('most is the number of tries, at least 1');
        }
        $pair = self::pair($partnerPk, $session);
        $out = $this->durable['outgoing'][$pair] ?? null;
        if ($out === null || $out['closed'] || !($out['answered'] || $out['deadline'])) {
            throw new CarryRefused('carry_session_unknown', 'no open session of this party\'s with that partner');
        }
        if (strlen($content) > $profile['max_content']) {
            throw new CarryRefused('carry_too_large', sprintf('%d bytes, and the profile takes %d', strlen($content), $profile['max_content']));
        }
        if ($out['counter'] + 1 >= self::U32) {
            throw new CarryRefused('carry_session_limit', 'the counter is spent; ask for a new session');
        }
        $this->room($partnerPk);
        $counter = $out['counter'] + 1;
        [$flags, $binding] = $out['answered'] ? [Carry::BOUND, hex2bin($out['nonce'])] : [0, null];
        $envelope = $this->write($partnerPk, $profile, Carry::MESSAGE, $flags, $session, $counter, $out['answered'] ? $deadline : $out['deadline'], $content, $binding, $rnd);
        $this->durable['outgoing'][$pair]['counter'] = $counter;
        $this->durable['outbox'][self::mark($partnerPk, $session, $counter)] = ['sha256' => hash('sha256', $envelope), 'envelope' => bin2hex($envelope),
            'receipt' => false, 'tries' => 1, 'most' => $most];
        $this->save();

        return $envelope;
    }

    public function counterOf(string $partnerPk, string $session): int
    {
        return $this->durable['outgoing'][self::pair($partnerPk, $session)]['counter'];
    }

    /** A session without a challenge, for a one-way medium: one deadline for all of it (A6.4). */
    public function oneWay(string $partnerPk, int $deadline): string
    {
        $this->check();
        if ($deadline < 1 || $deadline > self::U32) {
            throw new \ValueError('a session without a challenge has one deadline, from 1 to 2^32 - 1');
        }
        $session = $this->newSession($partnerPk);
        $this->room($partnerPk);
        $this->durable['outgoing'][self::pair($partnerPk, $session)] = ['nonce' => null, 'answered' => false, 'counter' => 0, 'closed' => false,
            'closed_at' => null, 'deadline' => $deadline];
        $this->save();

        return $session;
    }

    /** An envelope sent before, for a receipt to be matched against. */
    public function rememberSent(string $partnerPk, string $session, int $counter, string $envelope, int $most = self::TRIES_DEFAULT): void
    {
        $this->check();
        $this->room($partnerPk);
        $this->durable['outbox'][self::mark($partnerPk, $session, $counter)] = ['sha256' => hash('sha256', $envelope), 'envelope' => bin2hex($envelope),
            'receipt' => false, 'tries' => 1, 'most' => $most];
        $this->save();
    }

    /**
     * waiting while the bytes are kept for another try, confirmed when the receipt came, and unknown otherwise: the
     * tries ran out, so it may or may not have arrived, or nothing is known of it here.
     */
    public function outcome(string $key): string
    {
        $entry = $this->durable['outbox'][$key] ?? null;
        if ($entry === null) {
            return 'unknown';
        }
        if ($entry['receipt']) {
            return 'confirmed';
        }

        return $entry['envelope'] !== null ? 'waiting' : 'unknown';
    }

    /**
     * One more try of a message waiting for its receipt, counted before it goes: true while the budget allows it. When
     * the tries are used up the bytes go and the outcome is unknown for good, but the hash stays, so a valid receipt
     * that comes late still confirms it (A7).
     */
    public function tried(string $key): bool
    {
        $this->check();
        $entry = $this->durable['outbox'][$key] ?? null;
        if ($entry === null || $entry['receipt'] || $entry['envelope'] === null) {
            return false;
        }
        if ($entry['tries'] >= $entry['most']) {
            $this->durable['outbox'][$key]['envelope'] = null;
            $this->save();

            return false;
        }
        $this->durable['outbox'][$key]['tries']++;
        $this->save();

        return true;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: int, 4: string}> what waits for its receipt, oldest first */
    public function unconfirmed(): array
    {
        $out = [];
        foreach ($this->durable['outbox'] as $key => $entry) {
            if ($entry['envelope'] !== null && !$entry['receipt']) {
                $out[] = [(string) $key, ...self::parts((string) $key), hex2bin($entry['envelope'])];
            }
        }

        return $out;
    }

    private function write(string $partnerPk, array $profile, int $kind, int $flags, string $session, int $counter, int $deadline, string $content, ?string $binding = null, ?string $rnd = null): string
    {
        return Carry::write($this->keys, $partnerPk, $profile['mode'] === 'sealed' ? Carry::SEALED : Carry::READABLE, $kind, $flags, $session, $counter, $deadline, $content, $binding, $rnd);
    }

    // ------------------------------------------------- what the program takes --

    /**
     * Messages taken and kept that the program has not said it has, in the order they came: [mark, sender pk, session,
     * counter, content]. Reading them takes nothing: a crash before handedOver offers them again, so the program can use
     * the mark to do nothing twice.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: string}>
     */
    public function pending(): array
    {
        $out = [];
        foreach ($this->durable['stored'] as $key => $entry) {
            if ($entry['content'] !== null) {
                $out[] = [(string) $key, ...self::parts((string) $key), hex2bin($entry['content'])];
            }
        }

        return $out;
    }

    /**
     * What is kept of a message taken in: sha256, whether it came sealed, and when (the time floor's clock), or null.
     *
     * @return ?array{sha256: string, sealed: bool, at: int}
     */
    public function kept(string $key): ?array
    {
        $entry = $this->durable['stored'][$key] ?? null;

        return $entry === null ? null : ['sha256' => $entry['sha256'], 'sealed' => $entry['sealed'], 'at' => $entry['at']];
    }

    /** The program has kept the message itself or is done with it: the content goes, the mark stays until A6.9. */
    public function handedOver(string $key): void
    {
        $this->check();
        if (($this->durable['stored'][$key]['content'] ?? null) !== null) {
            $this->durable['stored'][$key]['content'] = null;
            $this->save();
        }
    }

    // ---------------------------------------------------------- receiving --

    private function bindingFor(string $pk, string $session): ?string
    {
        $record = $this->durable['incoming'][self::pair($pk, $session)] ?? null;

        return $record !== null && $record['challenged'] ? hex2bin($record['nonce']) : null;
    }

    /**
     * An envelope that came over a transport: [result, what goes back or null, what was read], or CarryRefused.
     *
     * $profile is a profile, or a function from the sender's key to its profile, asked once the sender is known.
     *
     * @return array{0: string, 1: ?string, 2: array}
     */
    public function deliver(string $envelope, array $known, array|\Closure $profile, float $mono, int $now, ?string $rnd = null, ?string $sealRnd = null): array
    {
        $this->check();
        try {
            $answer = $this->take($envelope, $known, $profile, $mono, $now, $rnd, $sealRnd);
        } catch (CarryRefused $refused) {
            if ($refused->carryCode !== 'carry_storage_failed') {
                $this->keepFloor();
            }
            throw $refused;
        }
        $this->keepFloor();

        return $answer;
    }

    /** @param list<array{0: float, 1: int}> $seen what came in the last minute on the monotonic clock, oldest first */
    private static function minute(array &$seen, float $mono): void
    {
        while ($seen !== [] && $mono - $seen[0][0] >= 60) {
            array_shift($seen);
        }
    }

    /**
     * Challenges used or past their window are remembered for their own code, nonce_used or nonce_expired, at most
     * SPENT_KEPT_PER_PARTNER per partner; an open for one forgotten is nonce_unknown. Either way it is refused, and
     * nothing kept against a replay goes with it.
     */
    private function forgetSpent(string $prefix, float $mono, int $window): void
    {
        $spent = [];
        foreach ($this->waiting as $key => $entry) {
            if (str_starts_with((string) $key, $prefix) && ($entry['used'] || $mono - $entry['t0'] > $window)) {
                $spent[(string) $key] = $entry['t0'];
            }
        }
        asort($spent);
        foreach (array_slice(array_keys($spent), 0, max(0, count($spent) - self::SPENT_KEPT_PER_PARTNER)) as $key) {
            unset($this->waiting[$key]);
        }
    }

    private function take(string $envelope, array $known, array|\Closure $profile, float $mono, int $now, ?string $rnd, ?string $sealRnd): array
    {
        $t = $this->tick($now);
        $got = Carry::read($envelope, $this->keys, $known, fn (string $pk, string $session): ?string => $this->bindingFor($pk, $session));
        if ($profile instanceof \Closure) {
            $profile = $profile($got['sender']);
        }
        if ($got['mode'] !== ($profile['mode'] === 'sealed' ? Carry::SEALED : Carry::READABLE)) {
            throw new CarryRefused('carry_mode_refused');
        }
        $pk = $got['sender'];
        $session = $got['session'];
        $pair = self::pair($pk, $session);
        $prefix = bin2hex($pk) . ':';

        switch ($got['kind']) {
            case Carry::ASK:
                if (isset($this->used[$pair]) || isset($this->durable['outgoing'][$pair])) {
                    throw new CarryRefused('carry_session_in_use');
                }
                $window = $profile['challenge_window'];
                $forPartner = 0;
                $inAll = 0;
                foreach ($this->waiting as $key => $entry) {
                    if (!$entry['used'] && $mono - $entry['t0'] <= $window) {
                        $inAll++;
                        $forPartner += str_starts_with((string) $key, $prefix) ? 1 : 0;
                    }
                }
                if ($forPartner >= self::MAX_WAITING_PER_PARTNER || $inAll >= self::MAX_WAITING) {
                    throw new CarryRefused('carry_rate_limited', 'too many challenges wait');
                }
                $asks = $this->asksSeen[bin2hex($pk)] ?? [];
                self::minute($asks, $mono);
                $this->asksSeen[bin2hex($pk)] = $asks;
                if (count($asks) >= self::ASKS_PER_MINUTE) {
                    throw new CarryRefused('carry_rate_limited', self::ASKS_PER_MINUTE . ' asks a minute from one partner');
                }
                $open = 0;
                foreach ($this->durable['incoming'] as $key => $record) {
                    if (str_starts_with((string) $key, $prefix) && $record['challenged'] && !$record['closed'] && isset($this->anchors[$key])) {
                        $open++;
                    }
                }
                if ($open >= self::MAX_OPEN_PER_PARTNER) {
                    throw new CarryRefused('carry_session_limit');
                }
                if ($this->durable['challenges'] >= self::SAFE) {
                    throw new CarryRefused('carry_storage_full', 'the count of challenges given is spent at 2^53 - 1, so no new one can be given');
                }
                $this->room($pk);
                $this->durable['challenges']++;
                $nonce = Carry::challengeNonce($this->keys, $rnd ?? ($this->randomBytes)(32), $pk, $session, $this->durable['challenges']);
                $this->used[$pair] = true;
                $this->save();
                $this->asksSeen[bin2hex($pk)][] = [$mono, 0];
                $this->waiting[$pair] = ['nonce' => $nonce, 't0' => $mono, 'used' => false];
                $this->forgetSpent($prefix, $mono, $window);

                return ['accepted', $this->write($pk, $profile, Carry::CHALLENGE, 0, $session, 0, 0, $nonce, null, $sealRnd), $got];

            case Carry::CHALLENGE:
                $out = $this->durable['outgoing'][$pair] ?? null;
                $asked = $this->askedAt[$pair] ?? null;
                if ($out === null || $out['answered'] || $asked === null || $mono - $asked > $profile['challenge_window']) {
                    throw new CarryRefused('carry_challenge_unexpected');
                }
                $this->durable['outgoing'][$pair]['answered'] = true;
                $this->durable['outgoing'][$pair]['nonce'] = bin2hex($got['content']);
                $this->save();

                return ['accepted', $this->write($pk, $profile, Carry::OPEN, 0, $session, 0, 0, $got['content'], null, $sealRnd), $got];

            case Carry::OPEN:
                $entry = $this->waiting[$pair] ?? null;
                if ($entry === null || $entry['nonce'] !== $got['content']) {
                    throw new CarryRefused('carry_nonce_unknown');
                }
                if ($entry['used']) {
                    throw new CarryRefused('carry_nonce_used');
                }
                if ($mono - $entry['t0'] > $profile['challenge_window']) {
                    throw new CarryRefused('carry_nonce_expired');
                }
                $this->room($pk);
                $this->waiting[$pair]['used'] = true;
                // The lifetime goes with the session: a profile changed later can shorten it, never lengthen it (A6.6.4).
                $this->durable['incoming'][$pair] = ['challenged' => true, 'nonce' => bin2hex($entry['nonce']), 'highest' => 0, 'closed' => false,
                    'deadline' => null, 'opened' => $t, 'closed_at' => null, 'lifetime' => $profile['lifetime']];
                $this->save();
                $this->anchors[$pair] = $entry['t0'];

                return ['accepted', null, $got];

            case Carry::CLOSE:
                $group = isset($this->durable['incoming'][$pair]) ? 'incoming' : (isset($this->durable['outgoing'][$pair]) ? 'outgoing' : null);
                if ($group === null) {
                    throw new CarryRefused('carry_session_unknown');
                }
                if ($this->durable[$group][$pair]['closed']) {
                    throw new CarryRefused('carry_session_closed');
                }
                $this->durable[$group][$pair]['closed'] = true;
                $this->durable[$group][$pair]['closed_at'] = $t;
                $this->save();

                return ['accepted', null, $got];

            case Carry::RECEIPT:
                $body = $got['content'];
                $key = self::mark($pk, substr($body, 1, 8), unpack('N', substr($body, 9, 4))[1]);
                $entry = $this->durable['outbox'][$key] ?? null;
                if ($entry === null || $entry['sha256'] !== bin2hex(substr($body, 13, 32))) {
                    throw new CarryRefused('carry_receipt_unknown');
                }
                if (!$entry['receipt']) {
                    $this->durable['outbox'][$key]['receipt'] = true;
                    $this->durable['outbox'][$key]['envelope'] = null;
                    $this->save();
                }

                return ['accepted', null, $got];
        }

        return $this->message($envelope, $got, $pair, $profile, $mono, $t, $sealRnd);
    }

    /** A6.6, step by step, within the limits per minute of A10. */
    private function message(string $envelope, array $got, string $pair, array $profile, float $mono, int $t, ?string $sealRnd): array
    {
        $pk = $got['sender'];
        $session = $got['session'];
        $counter = $got['counter'];
        $deadline = $got['deadline'];
        $digest = hash('sha256', $envelope);
        $trusted = $profile['clock_trusted'];
        // The same message gets the same receipt, byte for byte, so on a medium that loses frames the frames of several
        // copies add up. The ephemeral key still comes from the seed and the receipt's own bytes (A4).
        $receiptRnd = $sealRnd ?? hash('sha256', self::RECEIPT_RND . hex2bin($digest), true);
        $receipt = fn (): string => $this->write($pk, $profile, Carry::RECEIPT, 0, $session, 0, 0, Carry::receiptBody(Carry::STORED, $session, $counter, $envelope), null, $receiptRnd);

        // Every message from a known partner counts, a copy too: a copy is answered with a receipt, and A10 has no
        // limit apart from this one on what that costs.
        $seen = $this->messagesSeen[bin2hex($pk)] ?? [];
        self::minute($seen, $mono);
        if (count($seen) >= self::MESSAGES_PER_MINUTE || array_sum(array_column($seen, 1)) + strlen($envelope) > self::BYTES_PER_MINUTE) {
            $this->messagesSeen[bin2hex($pk)] = $seen;
            throw new CarryRefused('carry_rate_limited', self::MESSAGES_PER_MINUTE . ' messages and ' . self::BYTES_PER_MINUTE . ' bytes a minute from one partner');
        }
        $seen[] = [$mono, strlen($envelope)];
        $this->messagesSeen[bin2hex($pk)] = $seen;

        $key = self::mark($pk, $session, $counter);
        $stored = $this->durable['stored'][$key] ?? null;
        if ($stored !== null) {
            if ($stored['sha256'] !== $digest) {
                throw new CarryRefused('carry_counter_reused');
            }

            return ['duplicate', $receipt(), $got];
        }
        $record = $this->durable['incoming'][$pair] ?? null;
        $new = false;
        $bound = (bool) ($got['flags'] & Carry::BOUND);
        if (!$bound && isset($this->durable['outgoing'][$pair])) {
            throw new CarryRefused('carry_wrong_direction');
        }
        if (!$bound) {
            if ($record !== null && $record['challenged']) {
                throw new CarryRefused('carry_binding_mismatch');
            }
            if ($record === null) {
                if (!$profile['accept_unchallenged']) {
                    throw new CarryRefused('carry_session_unknown');
                }
                $record = ['challenged' => false, 'nonce' => null, 'highest' => 0, 'closed' => false, 'deadline' => $deadline, 'opened' => null,
                    'closed_at' => null, 'lifetime' => null];
                $new = true;
            }
        }
        if ($record['closed']) {
            throw new CarryRefused('carry_session_closed');
        }
        if ($record['challenged']) {
            $t0 = $this->anchors[$pair] ?? null;
            if ($t0 === null || $mono - $t0 > min($record['lifetime'], $profile['lifetime'])) {
                throw new CarryRefused('carry_session_expired');
            }
        }
        if ($counter <= $record['highest'] - self::WINDOW) {
            throw new CarryRefused('carry_counter_too_old');
        }
        if ($deadline !== 0 && $trusted) {
            if ($t > $deadline + self::SKEW) {
                throw new CarryRefused('carry_deadline_passed');
            }
            if ($deadline > $t + self::HORIZON) {
                throw new CarryRefused('carry_deadline_too_far');
            }
        }
        if (!$record['challenged']) {
            if ($deadline === 0 || !$trusted) {
                throw new CarryRefused('carry_freshness_unknown');
            }
            if ($deadline !== $record['deadline']) {
                throw new CarryRefused('carry_deadline_mismatch');
            }
        }
        if (strlen($got['content']) > $profile['max_content']) {
            throw new CarryRefused('carry_too_large', sprintf('%d bytes, and the profile takes %d', strlen($got['content']), $profile['max_content']));
        }
        // A new session without a challenge adds its record and its used id, and every message its mark.
        $this->room($pk, $new ? 3 : 1);
        $record['highest'] = max($record['highest'], $counter);
        $this->durable['incoming'][$pair] = $record;
        if ($new) {
            $this->used[$pair] = true;
        }
        // How it came and when, kept with it: what read says of it does not depend on a profile that may change meanwhile.
        $this->durable['stored'][$key] = ['sha256' => $digest, 'content' => bin2hex($got['content']), 'sealed' => $got['mode'] === Carry::SEALED, 'at' => $t];
        $this->save();

        return ['accepted', $receipt(), $got];
    }

    // ----------------------------------------------------------- deleting --

    /** Delete what A6.9 allows at this time, by the time floor and each session's own lifetime, and nothing more. */
    public function prune(int $now): void
    {
        $this->check();
        $floor = $this->tick($now);
        foreach ($this->durable['incoming'] as $pair => $record) {
            $gone = $record['challenged']
                ? $floor > max($record['opened'] + $record['lifetime'], $record['closed_at'] ?? 0) + self::HORIZON
                : $floor > $record['deadline'] + self::SKEW;
            if ($gone) {
                unset($this->durable['incoming'][$pair], $this->used[$pair], $this->anchors[$pair]);
                foreach (array_keys($this->durable['stored']) as $key) {
                    if (str_starts_with((string) $key, $pair . ':')) {
                        unset($this->durable['stored'][$key]);
                    }
                }
            }
        }
        $this->save();
    }
}
