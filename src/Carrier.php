<?php

declare(strict_types=1);

namespace Aamio;

/**
 * One transport at work: frames in and out, envelopes delivered, receipts awaited.
 *
 * A Carrier joins a running TransportProcess to the home's CarryState for the
 * partners bound to that transport, under one security profile. What comes in
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
 * as confirmed.
 *
 * What came in is the state's until the program says it has it: inbox() is what
 * the state keeps that the program has not taken, read as often as needed, and
 * take(mark) says the program has it. A crash before take offers it again.
 *
 * The challenge and the open are sent again with the same bytes through the
 * challenge window, since on a medium that loses frames one try is not enough;
 * an ask no challenge answers is asked again with a new session.
 */
final class Carrier
{
    public const UNVERIFIED_PER_SECOND = 20;

    private CarryReassembly $reassembly;
    private int $frameLimit;
    private array $queue = [];
    /** @var array<string, array{envelope: string, pk: string, session: string, next: float}> mark => sent, its receipt not come */
    public array $messages = [];
    /** @var array<string, array> challenges and opens sent again through the window */
    private array $control = [];
    private array $asks = [];
    /** @var array<string, array{0: string, 1: string}> pair => [partner pk, session] */
    public array $answered = [];
    private array $addresses = [];
    /** @var list<string> */
    public array $refused = [];
    private float $budget = self::UNVERIFIED_PER_SECOND;
    private float $budgetAt;
    private int $counter = 0;
    /** @var callable(): float */
    private $clock;
    /** @var callable(): int */
    private $wall;

    public function __construct(private CarryState $state, private TransportProcess $process, private array $profile, private array $known,
        ?callable $clock = null, ?callable $wall = null, private float $retrySeconds = 2.0, private float $askRetrySeconds = 5.0, private int $tries = CarryState::TRIES_DEFAULT)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->wall = $wall ?? static fn (): int => time();
        $this->reassembly = new CarryReassembly($this->clock);
        $this->frameLimit = $process->properties['max_frame_up'];
        $this->budgetAt = ($this->clock)();
        $knownSet = array_fill_keys(array_map('bin2hex', $known), true);
        foreach ($state->unconfirmed() as [$key, $pk, $session, , $envelope]) {
            // Sent before this process started: taken up with the same bytes and the tries that are left.
            if (isset($knownSet[bin2hex($pk)])) {
                $this->messages[$key] = ['envelope' => $envelope, 'pk' => $pk, 'session' => $session, 'next' => ($this->clock)()];
            }
        }
    }

    private static function pair(string $pk, string $session): string
    {
        return bin2hex($pk) . ':' . bin2hex($session);
    }

    private function queue(string $envelope, ?string $to): void
    {
        $this->queue[] = [Carry::split($envelope, $this->frameLimit), $to];
    }

    private function control(string $envelope, ?string $to, string $kind, string $pk, string $session, int $most): void
    {
        $this->counter++;
        $this->control['c-' . $this->counter] = ['envelope' => $envelope, 'to' => $to, 'kind' => $kind, 'pk' => $pk, 'session' => $session,
            'next' => ($this->clock)() + $this->retrySeconds, 'tries' => 1, 'most' => $most];
        $this->queue($envelope, $to);
    }

    private function windowTries(): int
    {
        return max(1, (int) ($this->profile['challenge_window'] / $this->retrySeconds));
    }

    /** Ask the partner for a session of this party's: the challenge and the open follow in pump. */
    public function open(string $partnerPk): string
    {
        [$session, $envelope] = $this->state->ask($partnerPk, $this->profile, ($this->clock)());
        $this->asks[self::pair($partnerPk, $session)] = ['pk' => $partnerPk, 'session' => $session, 'next' => ($this->clock)() + $this->askRetrySeconds, 'tries' => 1];
        $this->queue($envelope, $this->addresses[bin2hex($partnerPk)] ?? null);

        return $session;
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

    /** A message in an open session, sent again with the same bytes until its receipt comes: its mark. */
    public function send(string $partnerPk, string $session, string $content, int $deadline = 0): string
    {
        $envelope = $this->state->send($partnerPk, $session, $content, $this->profile, $deadline, null, $this->tries);
        $key = CarryState::mark($partnerPk, $session, $this->state->counterOf($partnerPk, $session));
        $this->messages[$key] = ['envelope' => $envelope, 'pk' => $partnerPk, 'session' => $session, 'next' => ($this->clock)() + $this->retrySeconds];
        $this->queue($envelope, $this->addresses[bin2hex($partnerPk)] ?? null);

        return $key;
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
            [$result, $reply, $got] = $this->state->deliver($envelope, $this->known, $this->profile, $now, ($this->wall)());
        } catch (CarryRefused $refused) {
            // Refusals stay here (A7): logged and counted, never answered over the medium.
            $this->refused[] = $refused->carryCode;

            return;
        }
        if ($source !== null) {
            $this->addresses[bin2hex($got['sender'])] = $source;
        }
        $pair = self::pair($got['sender'], $got['session']);
        switch ($got['kind']) {
            case Carry::CHALLENGE:
                unset($this->asks[$pair]);
                $this->answered[$pair] ??= [$got['sender'], $got['session']];
                // The open is sent again with the same bytes through the challenge window, until the first receipt in the
                // session shows it came: on a medium that loses frames, one try is not enough.
                $this->control($reply, $source, 'open', $got['sender'], $got['session'], $this->windowTries());

                return;
            case Carry::OPEN:
                $this->settled($pair, 'challenge');

                return;
            case Carry::ASK:
                $this->control($reply, $source, 'challenge', $got['sender'], $got['session'], $this->windowTries());

                return;
            case Carry::RECEIPT:
                unset($this->messages[CarryState::mark($got['sender'], substr($got['content'], 1, 8), unpack('N', substr($got['content'], 9, 4))[1])]);
                $this->settled($pair, 'open');

                return;
        }
        if ($reply !== null) {
            $this->queue($reply, $source);
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
            if ($now < $entry['next']) {
                continue;
            }
            // The try is counted in the state before it goes, so a restart never gives back tries that were used.
            if (!$this->state->tried((string) $key)) {
                unset($this->messages[$key]);
                continue;
            }
            $this->messages[$key]['next'] = $now + $this->retrySeconds;
            $this->queue($entry['envelope'], $this->addresses[bin2hex($entry['pk'])] ?? null);
        }
        foreach ($this->control as $key => $entry) {
            if ($now < $entry['next']) {
                continue;
            }
            if ($entry['tries'] >= $entry['most']) {
                unset($this->control[$key]);
                continue;
            }
            $this->control[$key]['tries']++;
            $this->control[$key]['next'] = $now + $this->retrySeconds;
            $this->queue($entry['envelope'], $this->addresses[bin2hex($entry['pk'])] ?? $entry['to']);
        }
        foreach ($this->asks as $pair => $entry) {
            if ($now < $entry['next']) {
                continue;
            }
            unset($this->asks[$pair]);
            if ($entry['tries'] < $this->tries) {
                [$session, $envelope] = $this->state->ask($entry['pk'], $this->profile, $now);
                $this->asks[self::pair($entry['pk'], $session)] = ['pk' => $entry['pk'], 'session' => $session, 'next' => $now + $this->askRetrySeconds, 'tries' => $entry['tries'] + 1];
                $this->queue($envelope, $this->addresses[bin2hex($entry['pk'])] ?? null);
            }
        }
    }

    private function flush(): void
    {
        while ($this->queue !== [] && $this->process->room() > 0) {
            $frame = array_shift($this->queue[0][0]);
            if ($this->process->send($frame, $this->queue[0][1]) === null) {
                array_unshift($this->queue[0][0], $frame);
                break;
            }
            if ($this->queue[0][0] === []) {
                array_shift($this->queue);
            }
        }
        // The host measures how long frames wait for room (B3.2), so it is told whether any do.
        $this->process->waitingForRoom($this->queue !== []);
    }
}
