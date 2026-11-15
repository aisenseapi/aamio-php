<?php

declare(strict_types=1);

namespace Aamio;

/** Frames from the medium, sorted by tag into envelopes, within the limits of A10. */
final class CarryReassembly
{
    public const GROUPS = 32;
    public const BYTES = 262144;
    public const SECONDS = 120;

    /** @var array<string, array{first: float, frames: list<string>, bytes: int}> */
    public array $groups = [];
    /** @var callable(): float */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** An envelope when a group is complete, null while it is not; CarryRefused for a frame or a group that cannot be. */
    public function add(string $frame): ?string
    {
        $now = ($this->clock)();
        foreach ($this->groups as $tag => $group) {
            if ($now - $group['first'] > self::SECONDS) {
                unset($this->groups[$tag]);
            }
        }
        if (strlen($frame) < Carry::FRAME_HEADER + 1) {
            throw new CarryRefused('frame_bad_length');
        }
        $tag = bin2hex(substr($frame, 3, 4));
        if (!isset($this->groups[$tag])) {
            while ($this->groups !== [] && (count($this->groups) >= self::GROUPS || array_sum(array_column($this->groups, 'bytes')) + strlen($frame) > self::BYTES)) {
                $oldest = null;
                foreach ($this->groups as $key => $group) {
                    if ($oldest === null || $group['first'] < $this->groups[$oldest]['first']) {
                        $oldest = $key;
                    }
                }
                unset($this->groups[$oldest]);
            }
            $this->groups[$tag] = ['first' => $now, 'frames' => [], 'bytes' => 0];
        }
        $this->groups[$tag]['frames'][] = $frame;
        $this->groups[$tag]['bytes'] += strlen($frame);
        $indices = [];
        foreach ($this->groups[$tag]['frames'] as $each) {
            $indices[ord($each[7])] = true;
        }
        if (count($indices) < ord($frame[8])) {
            try {
                Carry::reassemble($this->groups[$tag]['frames']);
            } catch (CarryRefused $refused) {
                if ($refused->carryCode !== 'frame_incomplete') {
                    unset($this->groups[$tag]);
                    throw $refused;
                }
            }

            return null;
        }
        $frames = $this->groups[$tag]['frames'];
        unset($this->groups[$tag]);

        return Carry::reassemble($frames);
    }
}
