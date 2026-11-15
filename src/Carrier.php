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
 * A message is sent again with the same bytes until its receipt comes; it is
 * never sealed again or moved to another session, so a copy is known as one.
 * It is confirmed only when its receipt comes: when the tries run out without
 * one, its outcome is unknown, since it may still have arrived, and it is never
 * reported as confirmed. The challenge and the open are sent again with the same bytes through the
 * challenge window, since on a medium that loses frames one try is not enough;
 * an ask no challenge answers is asked again with a new session.
 */
final class Carrier
{
    public const UNVERIFIED_PER_SECOND = 20;
    public const OUTCOMES_KEPT = 10000;

    private CarryReassembly $reassembly;
    private int $frameLimit;
    private array $queue = [];
    public array $waiting = [];
    private array $asks = [];
    /** @var array<string, array{0: string, 1: string}> pair => [partner pk, session] */
    public array $answered = [];
    private array $addresses = [];
    /** @var list<array{0: string, 1: string, 2: int, 3: string}> */
    public array $inbox = [];
    /** @var list<string> */
    public array $refused = [];
    /** @var array<string, string> envelope id => confirmed or unknown, the newest OUTCOMES_KEPT */
    public array $outcomes = [];
    private float $budget = self::UNVERIFIED_PER_SECOND;
    private float $budgetAt;
    private int $counter = 0;
    /** @var callable(): float */
    private $clock;
    /** @var callable(): int */
    private $wall;

    public function __construct(private CarryState $state, private TransportProcess $process, private array $profile, private array $known,
        ?callable $clock = null, ?callable $wall = null, private float $retrySeconds = 2.0, private float $askRetrySeconds = 5.0, private int $tries = 30)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->wall = $wall ?? static fn (): int => time();
        $this->reassembly = new CarryReassembly($this->clock);
        $this->frameLimit = $process->properties['max_frame_up'];
        $this->budgetAt = ($this->clock)();
    }

    private static function pair(string $pk, string $session): string
    {
        return bin2hex($pk) . ':' . bin2hex($session);
    }

    private function queue(string $envelope, ?string $to): void
    {
        $this->queue[] = [Carry::split($envelope, $this->frameLimit), $to];
    }

    private function await(string $envelope, ?string $to, string $kind, string $pk, string $session, ?int $counter = null, ?int $tries = null): string
    {
        $this->counter++;
        $key = 'e-' . $this->counter;
        $this->waiting[$key] = ['envelope' => $envelope, 'to' => $to, 'next' => ($this->clock)() + $this->retrySeconds, 'tries' => 1, 'kind' => $kind,
            'pk' => $pk, 'session' => $session, 'counter' => $counter, 'most' => $tries ?? $this->tries];
        $this->queue($envelope, $to);

        return $key;
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

    /** A message in an open session, sent again with the same bytes until its receipt comes: its id. */
    public function send(string $partnerPk, string $session, string $content, int $deadline = 0): string
    {
        $envelope = $this->state->send($partnerPk, $session, $content, $this->profile, $deadline);

        return $this->await($envelope, $this->addresses[bin2hex($partnerPk)] ?? null, 'message', $partnerPk, $session, $this->state->counterOf($partnerPk, $session));
    }

    /** True only when the receipt came; never for a message whose tries ran out without one. */
    public function confirmed(string $key): bool
    {
        return ($this->outcomes[$key] ?? null) === 'confirmed';
    }

    /**
     * waiting while it is sent again, confirmed when its receipt came, and unknown when
     * the tries ran out without one (it may still have arrived) or the id is no longer remembered.
     */
    public function outcome(string $key): string
    {
        if (isset($this->waiting[$key])) {
            return 'waiting';
        }

        return $this->outcomes[$key] ?? 'unknown';
    }

    private function finish(string $key, string $outcome): void
    {
        unset($this->waiting[$key]);
        $this->outcomes[$key] = $outcome;
        while (count($this->outcomes) > self::OUTCOMES_KEPT) {
            unset($this->outcomes[array_key_first($this->outcomes)]);
        }
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
                $this->answered[$pair] = [$got['sender'], $got['session']];
                $this->await($reply, $source, 'open', $got['sender'], $got['session'], null, $this->windowTries());

                return;
            case Carry::OPEN:
                $this->settled($pair, 'challenge');

                return;
            case Carry::ASK:
                $this->await($reply, $source, 'challenge', $got['sender'], $got['session'], null, $this->windowTries());

                return;
            case Carry::RECEIPT:
                $counter = unpack('N', substr($got['content'], 9, 4))[1];
                foreach ($this->waiting as $key => $entry) {
                    if (self::pair($entry['pk'], $entry['session']) === $pair && ($entry['kind'] === 'open' || $entry['counter'] === $counter)) {
                        $this->finish($key, 'confirmed');
                    }
                }

                return;
        }
        if ($got['kind'] === Carry::MESSAGE && $result === 'accepted') {
            $this->inbox[] = [$got['sender'], $got['session'], $got['counter'], $got['content']];
        }
        if ($reply !== null) {
            $this->queue($reply, $source);
        }
    }

    private function settled(string $pair, string $kind): void
    {
        foreach ($this->waiting as $key => $entry) {
            if (self::pair($entry['pk'], $entry['session']) === $pair && $entry['kind'] === $kind) {
                $this->finish($key, 'confirmed');
            }
        }
    }

    private function resend(): void
    {
        $now = ($this->clock)();
        foreach ($this->waiting as $key => $entry) {
            if ($now < $entry['next']) {
                continue;
            }
            if ($entry['tries'] >= $entry['most']) {
                // The outcome stays unknown; nothing is wrapped again or sent as a new message on its own.
                $this->finish($key, 'unknown');
                continue;
            }
            $this->waiting[$key]['tries']++;
            $this->waiting[$key]['next'] = $now + $this->retrySeconds;
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

                return;
            }
            if ($this->queue[0][0] === []) {
                array_shift($this->queue);
            }
        }
    }
}
