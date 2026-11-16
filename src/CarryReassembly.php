<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Frames from the medium, sorted by tag into envelopes, within the limits of A10.
 *
 * A copy of a frame already held is not held again, and room is made before a
 * frame is held, so what is held never passes BYTES, whatever comes before
 * anything is verified.
 *
 * A group is let go SECONDS after its first frame, or on a slow medium after
 * twice the time its frames take there, whichever is longer. $frameSeconds is a
 * full frame's time on the medium, as the transport's rate says. Over sound a
 * frame takes 16 seconds and more, so a message of more than six frames could
 * never be put together within 120 seconds, and went again and again for nothing.
 */
final class CarryReassembly
{
    public const GROUPS = 32;
    public const BYTES = 262144;
    public const SECONDS = 120;

    /** @var array<string, array{first: float, frames: array<int, string>, bytes: int, count: int}> 't' and the tag in hex => the group */
    public array $groups = [];
    /** groups and frames let go to stay within A10, counted */
    public int $dropped = 0;
    /** @var callable(): float */
    private $clock;

    public function __construct(?callable $clock = null, private float $frameSeconds = 0.0)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** How long a group of $count frames is kept after its first frame. */
    public function lifetime(int $count): float
    {
        return max((float) self::SECONDS, 2 * $count * $this->frameSeconds);
    }

    public function held(): int
    {
        return array_sum(array_column($this->groups, 'bytes'));
    }

    /** An envelope when a group is complete, null while it is not; CarryRefused for a frame or a group that cannot be. */
    public function add(string $frame): ?string
    {
        $now = ($this->clock)();
        foreach ($this->groups as $tag => $group) {
            if ($now - $group['first'] > $this->lifetime($group['count'])) {
                unset($this->groups[$tag]);
            }
        }
        if (strlen($frame) < Carry::FRAME_HEADER + 1) {
            throw new CarryRefused('frame_bad_length');
        }
        // A letter first, so a tag of only digits never becomes a number as an array key.
        $tag = 't' . bin2hex(substr($frame, 3, 4));
        $index = ord($frame[7]);
        if (isset($this->groups[$tag]['frames'][$index])) {
            if ($this->groups[$tag]['frames'][$index] === $frame) {
                return null;
            }
            unset($this->groups[$tag]);
            throw new CarryRefused('frame_conflict');
        }
        if (strlen($frame) > self::BYTES) {
            $this->dropped++;

            return null;
        }
        while ($this->groups !== [] && ($this->held() + strlen($frame) > self::BYTES || (!isset($this->groups[$tag]) && count($this->groups) >= self::GROUPS))) {
            $oldest = null;
            foreach ($this->groups as $key => $group) {
                if ($oldest === null || $group['first'] < $this->groups[$oldest]['first']) {
                    $oldest = $key;
                }
            }
            unset($this->groups[$oldest]);
            $this->dropped++;
        }
        $this->groups[$tag] ??= ['first' => $now, 'frames' => [], 'bytes' => 0, 'count' => ord($frame[8])];
        $this->groups[$tag]['frames'][$index] = $frame;
        $this->groups[$tag]['bytes'] += strlen($frame);
        $frames = array_values($this->groups[$tag]['frames']);
        if (count($frames) < ord($frame[8])) {
            try {
                Carry::reassemble($frames);
            } catch (CarryRefused $refused) {
                if ($refused->carryCode !== 'frame_incomplete') {
                    unset($this->groups[$tag]);
                    throw $refused;
                }
            }

            return null;
        }
        unset($this->groups[$tag]);

        return Carry::reassemble($frames);
    }
}
