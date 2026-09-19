<?php

declare(strict_types=1);

namespace Aamio;

/**
 * One thread this runtime reads: its label, read key, address, expiry,
 * allowlist, cursor, and the hash of every message it has already handed
 * over. The hashes travel with the channel in state.json: without them a
 * restart could not tell a redelivered message from a new one, and a model
 * might act twice.
 */
final class Channel
{
    /** @var array<string, true> */
    public array $seen = [];
    /** @var array<int, array> entries this process has received, in order */
    public array $received = [];
    /** Metadata of all messages observed in this process, including kept-out ones. */
    public array $observed = [];
    public bool $closed = false;
    /** Told already that the thread at this address is gone, so it is said once. */
    public bool $gone = false;

    /**
     * How many messages the last poll had fetched and left for the next one,
     * because the caller's limit was reached. They are still at the service,
     * and the cursor stands before them.
     */
    public int $leftWaiting = 0;

    /**
     * $createdAt is which thread at this address the cursor and the hashes
     * belong to. A restart can take the thread and a write can open a new one
     * at the same address, counting from one again, and created_at is the
     * only thing that tells the two apart.
     */
    public function __construct(
        public string $label,
        public readonly string $readKey,
        public readonly string $w,
        public int $expireAt,
        public array $allow = [],
        public int $after = 0,
        public ?int $createdAt = null,
    ) {
        $this->allow = Client::normalizeAllow($allow);
    }

    /**
     * The cursor belonged to a thread that is not there now. The hashes stay:
     * they are what this reader has been handed, whichever thread carried it.
     * They used to be cleared here with the cursor, which lost the replay mark
     * when it is most needed: after the service loses its store, every sender
     * whose message went with it sends the same bytes again.
     */
    public function forgetThread(): void
    {
        $this->after = 0;
        $this->createdAt = null;
        $this->observed = [];
    }

    public function secondsLeft(int $now): int
    {
        return max(0, $this->expireAt - $now);
    }

    public function toState(): array
    {
        $seen = array_values(array_filter(array_keys($this->seen), static fn ($hash): bool => is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1));
        sort($seen);

        return ['label' => $this->label, 'read_key' => $this->readKey, 'w' => $this->w, 'expire_at' => $this->expireAt, 'allow' => array_values($this->allow), 'after' => $this->after, 'created_at' => $this->createdAt, 'gone' => $this->gone, 'seen' => $seen];
    }

    public static function fromState(array $item): self
    {
        $channel = new self((string) $item['label'], (string) $item['read_key'], (string) $item['w'], (int) $item['expire_at'], array_values((array) ($item['allow'] ?? [])), (int) ($item['after'] ?? 0), isset($item['created_at']) ? (int) $item['created_at'] : null);
        foreach ((array) ($item['seen'] ?? []) as $hash) {
            if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1) { $channel->seen[$hash] = true; }
        }
        $channel->gone = ($item['gone'] ?? null) === true;

        return $channel;
    }
}
