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
 * the time each session with a challenge was opened at, and the time this
 * party asked. Everything else is written to the home before anything is
 * answered, and a write that fails is carry_storage_failed with no receipt.
 * Nothing that guards against a replay is deleted before A6.9 allows it.
 *
 * The kept state has the same shape in every runtime, since another runtime
 * may take the home over: four maps (incoming, stored, outgoing, outbox),
 * written as JSON objects also when they are empty, one list (used) and three
 * numbers. A file of any other shape is refused whole as carry_state_unreadable,
 * rather than read in part and written back without what could not be read.
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
    private const STATE_VERSION = 1;
    /** The four maps of the kept state, and what each holds: a record, or a hash in hex. */
    private const MAPS = ['incoming' => 'record', 'outgoing' => 'record', 'stored' => 'hash', 'outbox' => 'hash'];

    public static int $maxRecords = 100000;
    public static int $maxRecordsPerPartner = 10000;

    public array $durable;
    private array $used;
    private array $waiting = [];
    private array $anchors = [];
    private array $askedAt = [];
    /** @var callable(int): string */
    private $randomBytes;

    public function __construct(public readonly Keys $keys, public readonly ?string $path = null, ?callable $randomBytes = null)
    {
        $this->randomBytes = $randomBytes ?? static fn (int $n): string => random_bytes($n);
        $this->durable = $this->load();
        $this->used = array_fill_keys($this->durable['used'], true);
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

    private static function mark(string $pk, string $session, int $counter): string
    {
        return bin2hex($pk) . ':' . bin2hex($session) . ':' . $counter;
    }

    private static function emptyState(): array
    {
        return ['version' => self::STATE_VERSION, 'floor' => null, 'challenges' => 0, 'incoming' => [], 'used' => [], 'stored' => [],
                'outgoing' => [], 'outbox' => []];
    }

    // ------------------------------------------------------------ keeping --

    private function load(): array
    {
        if ($this->path === null || !is_file($this->path)) {
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

    /**
     * The shape every runtime writes (the class comment). PHP reads {} and [] alike, so an empty map is either;
     * a map with entries is never a list, since its keys are hex with colons.
     */
    private static function readable(mixed $value): bool
    {
        $whole = static fn (mixed $number, bool $nullable = false): bool => ($nullable && $number === null) || (is_int($number) && $number >= 0);
        if (!is_array($value) || ($value['version'] ?? null) !== self::STATE_VERSION || !array_key_exists('floor', $value)
            || !$whole($value['floor'], true) || !$whole($value['challenges'] ?? null)) {
            return false;
        }
        $used = $value['used'] ?? null;
        if (!is_array($used) || !array_is_list($used) || count(array_filter($used, 'is_string')) !== count($used)) {
            return false;
        }
        foreach (self::MAPS as $name => $kind) {
            $group = $value[$name] ?? null;
            if (!is_array($group) || ($group !== [] && array_is_list($group))) {
                return false;
            }
            foreach ($group as $item) {
                if ($kind === 'record' ? !is_array($item) || $item === [] || array_is_list($item) : !is_string($item)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** The kept state as JSON writes it: the maps as objects also when empty, so the other runtimes read them as maps. */
    private function forJson(): array
    {
        $out = $this->durable;
        foreach (array_keys(self::MAPS) as $name) {
            $out[$name] = (object) $out[$name];
        }

        return $out;
    }

    private function save(): void
    {
        $this->durable['used'] = array_map('strval', array_keys($this->used));
        // As text, as the other runtimes sort it: a regular sort compares ids of only digits as numbers.
        sort($this->durable['used'], SORT_STRING);
        if ($this->path === null) {
            return;
        }
        $folder = dirname($this->path);
        $tmp = $this->path . '.tmp';
        $mask = umask(0077);
        try {
            if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
                throw new CarryRefused('carry_storage_failed', $this->path . ' could not be written');
            }
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
                throw new CarryRefused('carry_storage_failed', $this->path . ' could not be written');
            }
            $written = @fwrite($handle, json_encode($this->forJson(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            Storage::sync($handle);
            fclose($handle);
        } finally {
            umask($mask);
        }
        if ($written === false || !@rename($tmp, $this->path)) {
            throw new CarryRefused('carry_storage_failed', $this->path . ' could not be written');
        }
    }

    /** What a new process on the same home would have: the kept state, and nothing from the monotonic clock. */
    public function restart(): void
    {
        if ($this->path !== null) {
            $this->durable = $this->load();
        }
        $this->used = array_fill_keys($this->durable['used'], true);
        $this->waiting = [];
        $this->anchors = [];
        $this->askedAt = [];
    }

    private function tick(int $now): int
    {
        $this->durable['floor'] = $this->durable['floor'] === null ? $now : max($this->durable['floor'], $now);

        return $this->durable['floor'];
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
        $session ??= $this->newSession($partnerPk);
        $pair = self::pair($partnerPk, $session);
        $this->room($partnerPk);
        $this->durable['outgoing'][$pair] = ['nonce' => null, 'answered' => false, 'counter' => 0, 'closed' => false, 'deadline' => null];
        $this->save();
        $this->askedAt[$pair] = $mono;

        return [$session, $this->write($partnerPk, $profile, Carry::ASK, 0, $session, 0, 0, '', null, $rnd)];
    }

    public function send(string $partnerPk, string $session, string $content, array $profile, int $deadline = 0, ?string $rnd = null): string
    {
        $pair = self::pair($partnerPk, $session);
        $out = $this->durable['outgoing'][$pair] ?? null;
        if ($out === null || $out['closed'] || !($out['answered'] || $out['deadline'])) {
            throw new CarryRefused('carry_session_unknown', 'no open session of this party\'s with that partner');
        }
        if (strlen($content) > $profile['max_content']) {
            throw new CarryRefused('carry_too_large', sprintf('%d bytes, and the profile takes %d', strlen($content), $profile['max_content']));
        }
        $this->room($partnerPk);
        $out['counter']++;
        if ($out['counter'] >= 0xFFFFFFFF) {
            throw new CarryRefused('carry_session_limit', 'the counter is spent; ask for a new session');
        }
        [$flags, $binding] = $out['answered'] ? [Carry::BOUND, hex2bin($out['nonce'])] : [0, null];
        $deadline = $out['answered'] ? $deadline : $out['deadline'];
        $envelope = $this->write($partnerPk, $profile, Carry::MESSAGE, $flags, $session, $out['counter'], $deadline, $content, $binding, $rnd);
        $this->durable['outgoing'][$pair] = $out;
        $this->durable['outbox'][self::mark($partnerPk, $session, $out['counter'])] = hash('sha256', $envelope);
        // The counter and the bytes are kept before they leave, so a crash never sends a counter twice.
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
        $session = $this->newSession($partnerPk);
        $this->room($partnerPk);
        $this->durable['outgoing'][self::pair($partnerPk, $session)] = ['nonce' => null, 'answered' => false, 'counter' => 0, 'closed' => false, 'deadline' => $deadline];
        $this->save();

        return $session;
    }

    public function rememberSent(string $partnerPk, string $session, int $counter, string $envelope): void
    {
        $this->durable['outbox'][self::mark($partnerPk, $session, $counter)] = hash('sha256', $envelope);
        $this->save();
    }

    private function write(string $partnerPk, array $profile, int $kind, int $flags, string $session, int $counter, int $deadline, string $content, ?string $binding = null, ?string $rnd = null): string
    {
        return Carry::write($this->keys, $partnerPk, $profile['mode'] === 'sealed' ? Carry::SEALED : Carry::READABLE, $kind, $flags, $session, $counter, $deadline, $content, $binding, $rnd);
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
     * @return array{0: string, 1: ?string, 2: array}
     */
    public function deliver(string $envelope, array $known, array $profile, float $mono, int $now, ?string $rnd = null, ?string $sealRnd = null): array
    {
        $t = $this->tick($now);
        $got = Carry::read($envelope, $this->keys, $known, fn (string $pk, string $session): ?string => $this->bindingFor($pk, $session));
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
                $waitingForPartner = count(array_filter(array_keys($this->waiting), static fn ($key) => str_starts_with((string) $key, $prefix)));
                if ($waitingForPartner >= self::MAX_WAITING_PER_PARTNER || count($this->waiting) >= self::MAX_WAITING) {
                    throw new CarryRefused('carry_rate_limited', 'too many challenges wait');
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
                $this->room($pk);
                $this->durable['challenges']++;
                $nonce = Carry::challengeNonce($this->keys, $rnd ?? ($this->randomBytes)(32), $pk, $session, $this->durable['challenges']);
                $this->used[$pair] = true;
                $this->save();
                $this->waiting[$pair] = ['nonce' => $nonce, 't0' => $mono, 'used' => false];

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
                $this->waiting[$pair]['used'] = true;
                // The lifetime goes with the session, so deleting it later does not depend on a profile that may have changed.
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
                $mark = self::mark($pk, substr($body, 1, 8), unpack('N', substr($body, 9, 4))[1]);
                if (($this->durable['outbox'][$mark] ?? null) !== bin2hex(substr($body, 13, 32))) {
                    throw new CarryRefused('carry_receipt_unknown');
                }

                return ['accepted', null, $got];
        }

        return $this->message($envelope, $got, $pair, $profile, $mono, $t, $sealRnd);
    }

    /** A6.6, step by step. */
    private function message(string $envelope, array $got, string $pair, array $profile, float $mono, int $t, ?string $sealRnd): array
    {
        $pk = $got['sender'];
        $session = $got['session'];
        $counter = $got['counter'];
        $deadline = $got['deadline'];
        $digest = hash('sha256', $envelope);
        $trusted = $profile['clock_trusted'];
        $receipt = fn (): string => $this->write($pk, $profile, Carry::RECEIPT, 0, $session, 0, 0, Carry::receiptBody(Carry::STORED, $session, $counter, $envelope), null, $sealRnd);

        $mark = self::mark($pk, $session, $counter);
        $stored = $this->durable['stored'][$mark] ?? null;
        if ($stored !== null) {
            if ($stored !== $digest) {
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
                $record = ['challenged' => false, 'nonce' => null, 'highest' => 0, 'closed' => false, 'deadline' => $deadline, 'opened' => null, 'closed_at' => null];
                $new = true;
            }
        }
        if ($record['closed']) {
            throw new CarryRefused('carry_session_closed');
        }
        if ($record['challenged']) {
            $t0 = $this->anchors[$pair] ?? null;
            if ($t0 === null || $mono - $t0 > $profile['lifetime']) {
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
        $this->room($pk, $new ? 2 : 1);
        $record['highest'] = max($record['highest'], $counter);
        $this->durable['incoming'][$pair] = $record;
        if ($new) {
            $this->used[$pair] = true;
        }
        $this->durable['stored'][$mark] = $digest;
        $this->save();

        return ['accepted', $receipt(), $got];
    }

    // ----------------------------------------------------------- deleting --

    /** Delete what A6.9 allows at this time, by the time floor, and nothing more. */
    public function prune(int $now): void
    {
        $floor = $this->tick($now);
        foreach ($this->durable['incoming'] as $pair => $record) {
            $gone = $record['challenged']
                ? $floor > max($record['opened'] + $record['lifetime'], $record['closed_at'] ?? 0) + self::HORIZON
                : $floor > $record['deadline'] + self::SKEW;
            if ($gone) {
                unset($this->durable['incoming'][$pair], $this->used[$pair], $this->anchors[$pair]);
                foreach (array_keys($this->durable['stored']) as $mark) {
                    if (str_starts_with((string) $mark, $pair . ':')) {
                        unset($this->durable['stored'][$mark]);
                    }
                }
            }
        }
        $this->save();
    }
}
