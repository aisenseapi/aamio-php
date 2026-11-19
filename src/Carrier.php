<?php

declare(strict_types=1);

namespace Aamio;

/**
 * One transport at work: frames in and out, envelopes delivered, receipts awaited.
 *
 * A Carrier joins a running TransportProcess to the home's CarryState for the
 * partners bound to that transport, under one security profile or one per
 * partner (B5). What comes in
 * is put together from its frames and given to the state, which decides; what
 * the state answers goes back to the address it came from.
 *
 * A message is known by its mark (partner, session and counter), which outlives
 * the process. It is sent again with the same bytes until its receipt comes; it
 * is never sealed again or moved to another session, so a copy is known as one.
 * The bytes and the tries are kept in the state, so a carrier on the same home
 * after a restart goes on with the same bytes and the tries that are left. It is
 * confirmed only when its receipt comes: when the tries run out without one, its
 * outcome is unknown, since it may still have arrived, and it is never reported
 * as confirmed. A try is counted when it can be given to the transport, not
 * while an earlier one waits for room, and one that has not begun is not begun
 * once the message is over. Bytes go out only under the mode they were written
 * in: when the partner's profile is of another mode now, the message is given
 * up and the user is told (B5, A7).
 *
 * What came in is the state's until the program says it has it: inbox() is what
 * the state keeps that the program has not taken, read as often as needed, and
 * take(mark) says the program has it. A crash before take offers it again.
 *
 * The challenge and the open are sent again with the same bytes through the
 * challenge window, since on a medium that loses frames one try is not enough;
 * an ask no challenge answers is asked again with a new session.
 *
 * Where a partner is reached is learned only from what shows it is fresh: a
 * challenge to an ask of this party's, an open to a challenge of its own, or a
 * new message. An ask can be played again from anywhere, and a copy of a message
 * or a receipt can be sent again by anyone who heard it, so neither moves where
 * the partner is said to be: a replayed ask could otherwise lead every message
 * to a machine in range that never answers. An address known from before, as
 * the home keeps it, is where the first ask goes; when it has stopped answering,
 * every second ask goes to nobody in particular, as one did before anything was
 * known. The same as aamio-python's carrier.
 */
final class Carrier
{
    public const UNVERIFIED_PER_SECOND = 20;
    /** A receipt, a challenge or an open, sealed, is under 200 bytes: 192, 163 and 163. An ask is 147. */
    public const ANSWER_BYTES = 200;
    /** What a sealed message adds to its content, rounded up: 262 bytes carry a body of about 110. */
    public const MESSAGE_BYTES = 160;
    /**
     * On a slow medium a turn is longer by a random part of up to half of it: two that began together, as two that both
     * ask for a session at once, and lost each other's frames, would otherwise try again together for good.
     */
    public const JITTER = 0.5;
    /**
     * How much longer than the bare time on the medium a wait gives: a half-duplex medium takes turns, and what is heard
     * has to be read before it is answered.
     */
    public const SLACK = 2.0;
    /**
     * A session a message went this many tries in without a receipt, with no later message in it confirmed, takes no new
     * message: the partner may have started again and taken it as expired (A6.8), and what it refuses is never answered
     * over the medium (A7), so every message after would be lost in it too. The next one asks for a new session.
     */
    public const UNANSWERED_TRIES = 3;

    private CarryReassembly $reassembly;
    private int $frameLimit;
    /**
     * A full frame's time on the medium, as the transport's rate says, and what the waits are measured in where the
     * medium is slow. Over sound a frame is 16 seconds: a try every 2 seconds filled the air with copies of a message
     * whose receipt then had no turn, and a session could not be opened in the 30 seconds a send waited.
     */
    private float $frameSeconds;
    /** @var \Closure(): float a number from 0 up to 1, for the random part of a turn; a property, so a test can fix it */
    public \Closure $random;
    /** @var list<array{0: list<string>, 1: ?string, 2: ?string, 3: list<string>}> [frames, to, mark or null, ids given so far], in order */
    private array $queue = [];
    /** @var array<string, list<list<string>>> mark => the frame ids of each try that was given to the transport whole */
    private array $given = [];
    /** @var array<string, true> marks one try of which the transport said it handed over whole (B6 1) */
    private array $handed = [];
    /** @var \Closure(string): array the profile for a partner's key */
    private \Closure $profileOf;
    /** @var array<string, array{envelope: string, pk: string, session: string, next: float}> mark => sent, its receipt not come */
    public array $messages = [];
    /** @var array<string, array> challenges and opens sent again through the window */
    private array $control = [];
    private array $asks = [];
    /** @var array<string, array{0: string, 1: string, 2: float}> pair => [partner pk, session, when its challenge came], in that order */
    public array $answered = [];
    /** @var array<string, string> bin2hex of a partner's key => the transport address it was last heard from fresh, or the home kept from before */
    public array $addresses = [];
    /** @var list<string> */
    public array $refused = [];
    private float $budget = self::UNVERIFIED_PER_SECOND;
    private float $budgetAt;
    private int $counter = 0;
    /** @var callable(): float */
    private $clock;
    /** @var callable(): int */
    private $wall;
    /** @var callable(string): void what the user is to hear, as a message that is not sent again */
    private $note;
    /** @var callable(string, string): void told where a partner was heard from, fresh: its key and the address */
    private $heard;
    /** @var callable(list<string>): void told where the transport says it is reached */
    private $onHere;
    /** @var callable(string): string a partner as the user knows it, from its key, for the line that says a message came in */
    private $nameOf;
    /** @var callable(string): void where the owner keeps its log */
    private $log;

    /**
     * @param array|\Closure(string): array $profile one profile for every partner, or a function from a partner's key to its own
     * @param ?callable(string): void $note where the user hears what a carrier has to say: the runtime keeps it in attention
     * @param ?array<string, string> $addresses where partners are reached, as the home keeps it: bin2hex of a key => address
     */
    public function __construct(private CarryState $state, private TransportProcess $process, array|\Closure $profile, public array $known,
        ?callable $clock = null, ?callable $wall = null, private float $retrySeconds = 2.0, private float $askRetrySeconds = 5.0, private int $tries = CarryState::TRIES_DEFAULT,
        ?callable $note = null, ?array $addresses = null, ?callable $heard = null, ?callable $nameOf = null, ?callable $onHere = null, ?callable $log = null)
    {
        $this->profileOf = $profile instanceof \Closure ? $profile : static fn (string $pk): array => $profile;
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->wall = $wall ?? static fn (): int => time();
        $this->note = $note ?? static function (string $text): void {
        };
        // Told where a partner was heard from, fresh, so the home can keep it; told where the transport says it is reached.
        $this->heard = $heard ?? static function (string $pk, string $address): void {
        };
        $this->onHere = $onHere ?? static function (array $here): void {
        };
        // A partner as the user knows it, for the line that says a message came in.
        $this->nameOf = $nameOf ?? static fn (string $pk): string => substr(bin2hex($pk), 0, 8);
        $this->log = $log ?? static function (string $line): void {
        };
        $this->addresses = $addresses ?? [];
        $rateDown = $process->properties['rate_down'] ?? null;
        $frameDown = $process->properties['max_frame_down'] ?? null;
        $slow = (is_int($rateDown) || is_float($rateDown)) && $rateDown > 0 && is_int($frameDown);
        $this->reassembly = new CarryReassembly($this->clock, $slow ? $frameDown / $rateDown : 0.0);
        $this->frameLimit = $process->properties['max_frame_up'];
        $rate = $process->properties['rate_up'] ?? null;
        $this->frameSeconds = (is_int($rate) || is_float($rate)) && $rate > 0 ? $this->frameLimit / $rate : 0.0;
        $this->random = static fn (): float => mt_rand() / (mt_getrandmax() + 1);
        $this->budgetAt = ($this->clock)();
        $knownSet = array_fill_keys(array_map('bin2hex', $known), true);
        foreach ($state->unconfirmed() as [$key, $pk, $session, , $envelope]) {
            // Sent before this process started: taken up with the same bytes and the tries that are left, when they
            // were written as the partner's profile says now (B5).
            if (isset($knownSet[bin2hex($pk)]) && $this->fitsProfile($key, $pk, $envelope)) {
                $this->messages[$key] = ['envelope' => $envelope, 'pk' => $pk, 'session' => $session, 'next' => ($this->clock)()];
            }
        }
    }

    private static function pair(string $pk, string $session): string
    {
        return bin2hex($pk) . ':' . bin2hex($session);
    }

    /**
     * Whether bytes written earlier may go on under the partner's profile now, given up and said when they may not.
     *
     * Readable bytes taken up after the partner's profile became sealed went out readable (the follow-up check of 15
     * November 2026). A message is never sealed again under its mark, so it is given up: its mark and hash stay for a
     * late receipt, and its outcome is unknown, since an earlier try may have arrived.
     */
    private function fitsProfile(string $key, string $pk, string $envelope): bool
    {
        $mode = ($this->profileOf)($pk)['mode'];
        $written = ord($envelope[4]);
        if ($written === ($mode === 'sealed' ? Carry::SEALED : Carry::READABLE)) {
            return true;
        }
        $this->state->giveUp($key);
        ($this->note)($key . ' was written ' . ($written === Carry::READABLE ? 'readable' : 'sealed') . ', and the profile for that partner is ' . $mode
            . ' now, so it is not sent again: B5 sends no message under another mode. Its outcome is unknown, since an earlier try may have arrived; send it again as a new message to have it go '
            . $mode . '.');

        return false;
    }

    /** Whether a try at the message waits in the queue, not one frame of it given to the transport yet. */
    private function queued(string $key): bool
    {
        foreach ($this->queue as $item) {
            if ($item[2] === $key && $item[3] === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * An envelope in frames, waiting for room. $counted says whether the try it is has been counted in the state: the
     * first try of a message is, when the message is written, and a later one is counted as it begins.
     */
    private function queue(string $envelope, ?string $to, ?string $key = null, bool $counted = true): void
    {
        $this->queue[] = [Carry::split($envelope, $this->frameLimit), $to, $key, [], $counted];
    }

    /** How many frames $size bytes take on this medium. */
    private function frames(int $size): int
    {
        $room = max(1, $this->frameLimit - Carry::FRAME_HEADER);

        return intdiv($size + $room - 1, $room);
    }

    /** The bare time $size bytes take on the medium, as the transport's rate says; nothing on a fast medium. */
    private function airtime(int $size): float
    {
        return $this->frameSeconds * $this->frames($size);
    }

    /**
     * How long to wait for the answer to an envelope before it goes again: $base, or on a slow medium the time the
     * envelope and what answers it take there, with slack. A fast medium never reaches $base.
     */
    private function turn(string $envelope, float $base): float
    {
        $slow = self::SLACK * $this->frameSeconds * ($this->frames(strlen($envelope)) + $this->frames(self::ANSWER_BYTES));

        return $slow <= $base ? $base : $slow * (1 + self::JITTER * ($this->random)());
    }

    /** What a challenge and its open take on this medium, with slack: what a profile's challenge window has to give. */
    public function handshakeSeconds(): float
    {
        return self::SLACK * $this->frameSeconds * 2 * $this->frames(self::ANSWER_BYTES);
    }

    /** A challenge's and its open's time on this medium, at most: every frame counted as a full one. */
    public function airtimeOfHandshake(): float
    {
        return 2 * $this->airtime(self::ANSWER_BYTES);
    }

    /**
     * A challenge window in which an open has $tries chances to come through on this medium, the longest turns counted:
     * what a profile for a slow medium is to give. A lost open loses its session.
     */
    public function windowForOpens(int $tries = 3): float
    {
        $turn = self::SLACK * $this->frameSeconds * 2 * $this->frames(self::ANSWER_BYTES) * (1 + self::JITTER);

        return 2 * $this->airtime(self::ANSWER_BYTES) + ($tries - 1) * $turn;
    }

    /**
     * How long a send of $size bytes of content takes on this medium when nothing is lost: an ask, its challenge and the
     * open, the message and its receipt, with slack. Next to nothing on a fast medium.
     */
    public function patience(int $size): float
    {
        return self::SLACK * $this->frameSeconds * (4 * $this->frames(self::ANSWER_BYTES) + $this->frames($size + self::MESSAGE_BYTES));
    }

    /**
     * A challenge or an open, sent again with the same bytes one turn after it went, as long as it can still arrive in the
     * challenge window: a try that began later would come after the window, and be turned down, so it is left out.
     *
     * An open goes again on the same turn as a challenge, though the first receipt in its session is what shows it came:
     * waiting for the message behind it and that message's receipt as well, an open over sound had two tries within a
     * window of 60 seconds at eight times real time, and when both were lost, so was the session (16 November 2026).
     */
    private function control(string $envelope, ?string $to, string $kind, string $pk, string $session, int $most, float $window): void
    {
        $now = ($this->clock)();
        $this->counter++;
        $this->control['c-' . $this->counter] = ['envelope' => $envelope, 'to' => $to, 'kind' => $kind, 'pk' => $pk, 'session' => $session,
            'next' => $now + $this->turn($envelope, $this->retrySeconds), 'tries' => 1, 'most' => $most,
            'until' => $now + $window - $this->airtime(self::ANSWER_BYTES)];
        $this->queue($envelope, $to);
    }

    private function windowTries(string $pk): int
    {
        // A challenge goes again one turn after it went, a turn being as long as a challenge and its open take.
        return max(1, (int) (($this->profileOf)($pk)['challenge_window'] / max($this->retrySeconds, self::SLACK * $this->frameSeconds * 2 * $this->frames(self::ANSWER_BYTES))));
    }

    /** Ask the partner for a session of this party's: the challenge and the open follow in pump. */
    public function open(string $partnerPk): string
    {
        [$session, $envelope] = $this->state->ask($partnerPk, ($this->profileOf)($partnerPk), ($this->clock)());
        $this->asks[self::pair($partnerPk, $session)] = ['pk' => $partnerPk, 'session' => $session, 'next' => ($this->clock)() + $this->turn($envelope, $this->askRetrySeconds), 'tries' => 1];
        $this->queue($envelope, $this->addresses[bin2hex($partnerPk)] ?? null);

        return $session;
    }

    /** Whether an ask for a session with the partner is under way: sent, and gone again on its own until answered. */
    public function asking(string $partnerPk): bool
    {
        foreach ($this->asks as $entry) {
            if ($entry['pk'] === $partnerPk) {
                return true;
            }
        }

        return false;
    }

    /** The newest session of this party's with the partner that a challenge has answered, or null. */
    public function openSession(string $partnerPk): ?string
    {
        $found = null;
        foreach ($this->answered as [$pk, $session]) {
            if ($pk === $partnerPk) {
                $found = $session;
            }
        }

        return $found;
    }

    /**
     * The newest session of this party's with the partner whose challenge came less than $seconds ago, or null. Null too
     * when a message in it went UNANSWERED_TRIES tries without a receipt and nothing later in it was confirmed (A7): the
     * next message asks for a new session.
     */
    public function usableSession(string $partnerPk, float $seconds): ?string
    {
        $found = null;
        $now = ($this->clock)();
        foreach ($this->answered as [$pk, $session, $at]) {
            if ($pk === $partnerPk && $now - $at < $seconds) {
                $found = $session;
            }
        }
        if ($found !== null && $this->state->unanswered($partnerPk, $found, self::UNANSWERED_TRIES)) {
            return null;
        }

        return $found;
    }

    /** Seconds until the answer to the ask under way with the partner is due on this medium, or null without one. */
    public function askDue(string $partnerPk): ?float
    {
        $due = [];
        foreach ($this->asks as $entry) {
            if ($entry['pk'] === $partnerPk) {
                $due[] = $entry['next'];
            }
        }

        return $due === [] ? null : max(0.0, min($due) - ($this->clock)());
    }

    /** A message in an open session, sent again with the same bytes until its receipt comes: its mark. */
    public function send(string $partnerPk, string $session, string $content, int $deadline = 0): string
    {
        $envelope = $this->state->send($partnerPk, $session, $content, ($this->profileOf)($partnerPk), $deadline, null, $this->tries);
        $key = CarryState::mark($partnerPk, $session, $this->state->counterOf($partnerPk, $session));
        $this->messages[$key] = ['envelope' => $envelope, 'pk' => $partnerPk, 'session' => $session, 'next' => ($this->clock)() + $this->turn($envelope, $this->retrySeconds)];
        $this->queue($envelope, $this->addresses[bin2hex($partnerPk)] ?? null, $key);

        return $key;
    }

    /** B6 1: the transport said sent for every frame of one try at the message, in this process. Nothing about receipt. */
    public function handedOver(string $key): bool
    {
        if (isset($this->handed[$key])) {
            return true;
        }
        foreach ($this->given[$key] ?? [] as $ids) {
            if ($ids !== [] && array_filter($ids, fn (string $id): bool => $this->process->status($id) !== 'sent') === []) {
                $this->handed[$key] = true;
                unset($this->given[$key]);

                return true;
            }
        }

        return false;
    }

    /** Whether frames wait here for the transport, or wait at the transport for an answer. */
    public function busy(): bool
    {
        return $this->queue !== [] || $this->process->waiting() > 0;
    }

    /** True only when the receipt came; never for a message whose tries ran out without one. */
    public function confirmed(string $key): bool
    {
        return $this->state->outcome($key) === 'confirmed';
    }

    /** waiting while it is sent again, confirmed when its receipt came, and unknown when the tries ran out without one. */
    public function outcome(string $key): string
    {
        return $this->state->outcome($key);
    }

    /**
     * What came and is kept that the program has not taken: [sender pk, session, counter, content, mark], in order.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    public function inbox(): array
    {
        $out = [];
        foreach ($this->state->pending() as [$key, $pk, $session, $counter, $content]) {
            $out[] = [$pk, $session, $counter, $content, $key];
        }

        return $out;
    }

    /** The program has the message: the state keeps only its mark from now on. */
    public function take(string $key): void
    {
        $this->state->handedOver($key);
    }

    /** Read what the transport said, deliver what is whole, resend what waits, and give frames within the room. */
    public function pump(float $timeout = 0.05): void
    {
        foreach ($this->process->poll($timeout) as $event) {
            if ($event[0] === 'received') {
                $this->received($event[1], $event[2]);
            } elseif ($event[0] === 'here') {
                ($this->onHere)($event[1]);
            }
        }
        $this->resend();
        $this->flush();
        $this->process->checkTime();
    }

    private function received(?string $source, string $frame): void
    {
        try {
            $envelope = $this->reassembly->add($frame);
        } catch (CarryRefused $refused) {
            $this->refused[] = $refused->carryCode;

            return;
        }
        if ($envelope === null) {
            return;
        }
        $now = ($this->clock)();
        $this->budget = min(self::UNVERIFIED_PER_SECOND, $this->budget + ($now - $this->budgetAt) * self::UNVERIFIED_PER_SECOND);
        $this->budgetAt = $now;
        if ($this->budget < 1) {
            $this->refused[] = 'unverified_over_budget';

            return;
        }
        $this->budget--;
        try {
            [$result, $reply, $got] = $this->state->deliver($envelope, $this->known, $this->profileOf, $now, ($this->wall)());
        } catch (CarryRefused $refused) {
            // Refusals stay here (A7): logged and counted, never answered over the medium.
            $this->refused[] = $refused->carryCode;

            return;
        }
        if ($source !== null && $result === 'accepted' && in_array($got['kind'], [Carry::CHALLENGE, Carry::OPEN, Carry::MESSAGE], true)) {
            // Fresh: a challenge answers an ask of ours, an open a challenge of ours, and a new message has a counter
            // never taken before. The address it came from is where the partner is now.
            if (($this->addresses[bin2hex($got['sender'])] ?? null) !== $source) {
                $this->addresses[bin2hex($got['sender'])] = $source;
                ($this->heard)($got['sender'], $source);
            }
        }
        $pair = self::pair($got['sender'], $got['session']);
        switch ($got['kind']) {
            case Carry::CHALLENGE:
                // A session with the partner is open: every ask to it ends, the one this answers and any made since. Asked
                // again on a slow medium, each took turns from the messages it was asked for.
                foreach ($this->asks as $asked => $entry) {
                    if ($entry['pk'] === $got['sender']) {
                        unset($this->asks[$asked]);
                    }
                }
                $this->answered[$pair] ??= [$got['sender'], $got['session'], $now];
                // The open is sent again with the same bytes through the challenge window, until the first receipt in the
                // session shows it came: on a medium that loses frames, one try is not enough. The window was counted
                // from before the challenge went, so the challenge's own time on the medium is off it.
                $window = ($this->profileOf)($got['sender'])['challenge_window'];
                $this->control($reply, $source, 'open', $got['sender'], $got['session'], $this->windowTries($got['sender']), $window - $this->airtime(self::ANSWER_BYTES));

                return;
            case Carry::OPEN:
                $this->settled($pair, 'challenge');

                return;
            case Carry::ASK:
                // The challenge too is sent again with the same bytes until its open comes, or the window is gone; a try is
                // left out when the challenge and its open could no longer come through within it.
                $window = ($this->profileOf)($got['sender'])['challenge_window'];
                $this->control($reply, $source, 'challenge', $got['sender'], $got['session'], $this->windowTries($got['sender']), $window - $this->airtime(self::ANSWER_BYTES));

                return;
            case Carry::RECEIPT:
                unset($this->messages[CarryState::mark($got['sender'], substr($got['content'], 1, 8), unpack('N', substr($got['content'], 9, 4))[1])]);
                $this->settled($pair, 'open');

                return;
        }
        // A message: its receipt goes back. A bound one may have opened its session itself, its open lost (A6.2 point 6), so
        // the challenge is not sent again either.
        $this->settled($pair, 'challenge');
        if ($reply !== null) {
            $this->queue($reply, $source);
        }
        if ($result === 'accepted' && $got['kind'] === Carry::MESSAGE) {
            // Said where the owner keeps its log, as aamio serve's window: it said nothing when a message came, and a
            // test between two machines had nothing to look at until the read (19 November 2026).
            ($this->log)('transport ' . $this->process->name . ': a message came in from ' . ($this->nameOf)($got['sender']));
        }
    }

    private function settled(string $pair, string $kind): void
    {
        foreach ($this->control as $key => $entry) {
            if (self::pair($entry['pk'], $entry['session']) === $pair && $entry['kind'] === $kind) {
                unset($this->control[$key]);
            }
        }
    }

    private function resend(): void
    {
        $now = ($this->clock)();
        foreach ($this->messages as $key => $entry) {
            $key = (string) $key;
            if ($now < $entry['next']) {
                continue;
            }
            if ($this->queued($key)) {
                // The last try still waits for room: a try is counted when it can be given to the transport, not while
                // copies pile up behind one that has not gone.
                $this->messages[$key]['next'] = $now + $this->retrySeconds;
                continue;
            }
            if (!$this->fitsProfile($key, $entry['pk'], $entry['envelope'])) {
                unset($this->messages[$key]);
                continue;
            }
            if ($this->state->triesLeft($key) === 0) {
                // The tries are used: the bytes go, and the outcome is unknown for good (A7).
                $this->state->tried($key);
                unset($this->messages[$key]);
                continue;
            }
            $this->messages[$key]['next'] = $now + $this->turn($entry['envelope'], $this->retrySeconds);
            // Queued, the try has used nothing: it is counted as it begins, in flush, when it can be given to the
            // transport. Counted here, every new owner used a try as it took the outbox up, room or not, and a
            // budget went with nothing sent (the check of 16 November 2026).
            $this->queue($entry['envelope'], $this->addresses[bin2hex($entry['pk'])] ?? null, $key, false);
        }
        foreach ($this->control as $key => $entry) {
            if ($now < $entry['next']) {
                continue;
            }
            if ($entry['tries'] >= $entry['most'] || $now > $entry['until']) {
                unset($this->control[$key]);
                continue;
            }
            $this->control[$key]['tries']++;
            $this->control[$key]['next'] = $now + $this->turn($entry['envelope'], $this->retrySeconds);
            // Back where what it answers came from: a challenge to where the ask came from, an open to where the
            // challenge did. An ask moves no address (see above), so the address known may be another machine's.
            $this->queue($entry['envelope'], $entry['to'] ?? ($this->addresses[bin2hex($entry['pk'])] ?? null));
        }
        foreach ($this->asks as $pair => $entry) {
            if ($now < $entry['next']) {
                continue;
            }
            unset($this->asks[$pair]);
            if ($entry['tries'] < $this->tries) {
                [$session, $envelope] = $this->state->ask($entry['pk'], ($this->profileOf)($entry['pk']), $now);
                $tries = $entry['tries'] + 1;
                $this->asks[self::pair($entry['pk'], $session)] = ['pk' => $entry['pk'], 'session' => $session, 'next' => $now + $this->turn($envelope, $this->askRetrySeconds), 'tries' => $tries];
                // A known address that has stopped answering, as a machine with a Bluetooth address of another day, is
                // not asked for good: every second ask goes to nobody in particular, as AAMIO_LAN_PEERS or a broadcast.
                $this->queue($envelope, $tries % 2 === 1 ? ($this->addresses[bin2hex($entry['pk'])] ?? null) : null);
            }
        }
    }

    private function flush(): void
    {
        while ($this->queue !== [] && $this->process->room() > 0) {
            [, , $key, $ids, $counted] = $this->queue[0];
            if ($key !== null && $ids === []) {
                // A try at a message begins here, so here is where it may still be stopped: when the message is over
                // (its receipt came, or its tries are used), or when the partner's profile is of another mode now than
                // the bytes were written under. Queued earlier, it went out all the same once there was room.
                $entry = $this->messages[$key] ?? null;
                if ($entry === null || !$this->fitsProfile($key, $entry['pk'], $entry['envelope'])) {
                    unset($this->messages[$key]);
                    array_shift($this->queue);
                    continue;
                }
                if (!$counted) {
                    // Counted in the state before its first frame goes, so a restart never gives back a try that
                    // was used, and counted only now that there is room for it.
                    if (!$this->state->tried($key)) {
                        unset($this->messages[$key]);
                        array_shift($this->queue);
                        continue;
                    }
                    $this->queue[0][4] = true;
                }
                // The next try is one turn after this one began, however long it waited for room.
                $this->messages[$key]['next'] = ($this->clock)() + $this->turn($entry['envelope'], $this->retrySeconds);
            }
            $frame = array_shift($this->queue[0][0]);
            $id = $this->process->send($frame, $this->queue[0][1]);
            if ($id === null) {
                array_unshift($this->queue[0][0], $frame);
                break;
            }
            $this->queue[0][3][] = $id;
            if ($this->queue[0][0] === []) {
                [, , $key, $ids] = array_shift($this->queue);
                if ($key !== null && !isset($this->handed[$key])) {
                    $this->given[$key][] = $ids;
                }
            }
        }
        // The host measures how long frames wait for room (B3.2), so it is told whether any do.
        $this->process->waitingForRoom($this->queue !== []);
    }
}
