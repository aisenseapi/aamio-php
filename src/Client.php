<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Threads and presence against one aamio host. Every method returns what the
 * service answered, decoded, with the status beside it, and throws only when
 * the caller gave it something it cannot send. The three outcomes are kept
 * apart: a 4xx is a refusal, a 429 a rate window, and status 0 is unknown,
 * which is not the same as refused.
 */
final class Client
{
    public const DEFAULT_HOST = Hosts::DEFAULT_HOST;
    public const DEFAULT_TTL = 600;

    /** @var array<string, array|null> gates read, per address, once */
    private array $gates = [];
    /** @var array<string, array{0: int, 1: float}> X-Seconds-Left per address, and when it was read */
    private array $gateLeft = [];
    /**
     * How long a caller may be held for proof of work, in seconds, or null for
     * as long as it takes. The MCP server sets it, since its host cuts a tool
     * call after a minute or so.
     */
    public ?float $workBudget = null;

    public function __construct(
        public readonly string $host = self::DEFAULT_HOST,
        public readonly ?Keys $keys = null,
        private readonly int $timeout = 40,
    ) {
    }

    private function url(string $path): string
    {
        return rtrim($this->host, '/') . $path;
    }

    // ------------------------------------------------------------ threads --

    /**
     * Opens a thread with a lifetime and returns ['id', 'w', 'status', 'body'].
     * The id is made here and never leaves this process except in X-Read.
     * $allow: signer keys, or ['*'] for any key as long as the message is signed.
     * $gate: the conditions, as an array, or null.
     */
    public function open(int $ttl = self::DEFAULT_TTL, ?array $allow = null, ?array $gate = null): array
    {
        $allow = self::normalizeAllow($allow);
        $id = Address::newId();
        $w = Address::w($id);
        $headers = ['X-Read' => $id, 'X-TTL' => (string) $ttl, 'Content-Type' => 'application/json'];
        if ($allow !== null) {
            $headers['X-Allow'] = implode(',', $allow);
        }
        $body = $gate !== null ? Codec::json(['gate' => $gate]) : null;
        [$status, $answer] = Http::call('PUT', $this->url('/' . $w), $body, $headers, $this->timeout);

        return ['id' => $id, 'w' => $w, 'allow' => $allow, 'status' => $status, 'body' => $answer];
    }

    public static function normalizeAllow(?array $allow): array
    {
        $out = [];
        foreach ($allow ?? [] as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException('an allowlist entry must be a string');
            }
            foreach (explode(',', $entry) as $part) {
                $key = trim($part);
                if ($key !== '' && !in_array($key, $out, true)) { $out[] = $key; }
            }
        }
        return in_array('*', $out, true) ? ['*'] : $out;
    }

    /** Checks one remote record; only locally computed hashes survive. */
    private function checkedMessage(string $w, mixed $raw): array
    {
        $message = is_array($raw) ? $raw : [];
        $message['service_verified'] = ($message['verified'] ?? null) === true;
        $message['service_from'] = $message['from'] ?? null;
        unset($message['unverified_because']);
        try {
            if (!is_int($message['seq'] ?? null) || !is_int($message['at'] ?? null)) {
                throw new \UnexpectedValueException('invalid message metadata');
            }
            $checked = Keys::checkMessage($w, $message);
        } catch (\Throwable $error) {
            $checked = ['verified' => false, 'sha256' => null, 'why_not' => 'the message could not be checked here: ' . (new \ReflectionClass($error))->getShortName()];
        }
        $message['seq'] = is_int($message['seq'] ?? null) ? $message['seq'] : 0;
        $message['at'] = is_int($message['at'] ?? null) ? $message['at'] : 0;
        $message['verified'] = $checked['verified'];
        $message['from'] = $checked['verified'] ? $message['from'] : null;
        $message['sha256'] = $checked['sha256'];
        if ($checked['why_not'] !== null) { $message['unverified_because'] = $checked['why_not']; }
        return $message;
    }

    /** Checks, then decodes one remote message without aborting the batch. */
    public function decodeAt(string $w, mixed $raw): array
    {
        $message = $this->checkedMessage($w, $raw);
        try {
            return $this->decode($message);
        } catch (\Throwable $error) {
            $why = 'the message could not be checked here: ' . (new \ReflectionClass($error))->getShortName();
            return ['seq' => $message['seq'], 'at' => $message['at'], 'from' => null, 'verified' => false, 'sealed' => false, 'body' => $message['body'] ?? null, 'opened' => null, 'format' => 'unreadable', 'error' => $why, 'unverified_because' => $why];
        }
    }

    /** Policy-aware reader for the object returned by open(). */
    public function readThread(array $thread, int $after = 0, int $wait = 0): array
    {
        return $this->read($thread['w'], $thread['id'], $after, $wait, $thread['allow'] ?? []);
    }

    /** The gate an inbox was opened with, read once per address; {} for none, null when the thread is gone. */
    public function gate(string $w, bool $fresh = false): ?array
    {
        if (!$fresh && array_key_exists($w, $this->gates)) {
            return $this->gates[$w];
        }
        [$status, $answer, $headers] = Http::call('GET', $this->url('/' . $w . '/gate'), null, [], $this->timeout) + [2 => []];
        $this->gates[$w] = $status === 200 && is_array($answer) ? $answer : null;
        // The time left rides in a header, since the body is the exact bytes
        // the gate hash is taken over.
        $left = $headers['x-seconds-left'] ?? null;
        if ($status === 200 && is_string($left) && ctype_digit($left)) {
            $this->gateLeft[$w] = [(int) $left, microtime(true)];
        }

        return $this->gates[$w];
    }

    /** The gate kept for w, and the time it said, belong to an inbox that may not be there now. */
    public function forgetGate(string $w): void
    {
        unset($this->gates[$w], $this->gateLeft[$w]);
    }

    /**
     * The plan for w's gate, read again once before a no that rests on a gate
     * read earlier. A gate never changes while its thread lives, which is why
     * it is kept, but an address can have more than one life: the time a kept
     * gate said counted down to nothing and stayed there, and a new inbox at
     * the same address was refused on the old one's terms without the service
     * being asked. One more read, only when the answer would be no.
     */
    public function planFor(string $w): array
    {
        $cached = array_key_exists($w, $this->gates);
        $plan = Gate::plan($this->gate($w), $this->secondsLeft($w), $this->workBudget);
        if ($plan['stop'] !== null && $cached) {
            $this->forgetGate($w);
            $plan = Gate::plan($this->gate($w), $this->secondsLeft($w), $this->workBudget);
        }

        return $plan;
    }

    /** How long w still takes writes, counted down from what its gate said, or null. */
    public function secondsLeft(string $w): ?float
    {
        if (!isset($this->gateLeft[$w])) {
            return null;
        }
        [$left, $at] = $this->gateLeft[$w];

        return max(0.0, $left - (microtime(true) - $at));
    }

    /**
     * Writes to an address. Text or an array (sent as JSON). Signed when this
     * client has keys and $sign is true; sealed to $sealTo when given. Reads the
     * gate once, does the work it asks for within the ceilings, answers a 428
     * once, and stops with a reason rather than send what the gate would refuse.
     *
     * Returns ['status', 'body', 'sent' => the exact bytes, 'work' => nonce|null, 'notes' => []].
     */
    public function send(string $w, string|array $body, bool $sign = true, ?string $sealTo = null): array
    {
        if (!Address::isW($w)) {
            throw new \InvalidArgumentException('not a write address');
        }
        $bytes = is_array($body) ? Codec::json($body) : $body;
        $contentType = is_array($body) ? 'application/json' : 'text/plain; charset=utf-8';
        if ($sealTo !== null) {
            if ($this->keys === null) {
                throw new \LogicException('sealing needs keys');
            }
            $bytes = $this->keys->seal($sealTo, $bytes);
            $contentType = 'application/json';
        }
        if (strlen($bytes) > 65536) {
            throw new \InvalidArgumentException('a message is at most 65536 bytes; send a URL and a hash instead');
        }
        $signing = $sign && $this->keys !== null;
        $key = $signing ? $this->keys->public : '';

        $plan = $this->planFor($w);
        if ($plan['stop'] !== null) {
            return ['status' => 0, 'stopped' => true, 'body' => ['error' => $plan['stop'], 'fix' => 'Open an address whose conditions this client can meet, or update the client.'], 'sent' => null, 'work' => null, 'notes' => $plan['notes']];
        }

        $attempt = function (?int $bits) use ($w, $bytes, $contentType, $signing, $key): array {
            $headers = ['Content-Type' => $contentType];
            if ($signing) {
                $headers['X-Key'] = $key;
                $headers['X-Sig'] = $this->keys->sign(Keys::threadSigningInput($w, $bytes));
            }
            $work = null;
            if ($bits !== null && $bits > 0) {
                // The work stops when the inbox would close, less a few seconds
                // for the post itself: past that a nonce buys nothing but a 410.
                $left = $this->secondsLeft($w);
                $work = Gate::solve($w, $key, $bytes, $bits, $left === null ? null : microtime(true) + max(0.0, $left - 5));
                if ($work === null) {
                    return [0, ['error' => 'the proof of work of ' . $bits . ' bits was not done before the inbox stops taking writes, so the work was stopped and nothing was sent', 'fix' => 'The estimate before it started said it would fit, and this time it took longer, which happens: the work is a lottery. Ask the owner for a longer inbox, or send from a machine with more compute.'], null, true];
                }
                $headers['X-Work'] = $work;
            }
            [$status, $answer] = Http::call('POST', $this->url('/' . $w), $bytes, $headers, $this->timeout);

            return [$status, $answer, $work, false];
        };

        [$status, $answer, $work, $stopped] = $attempt($plan['bits']);
        if ($status === 428 && is_array($answer) && isset($answer['gate'])) {
            // The refusal carries the whole gate; meet it once, never more.
            $this->gates[$w] = is_array($answer['gate']) ? $answer['gate'] : null;
            if (is_int($answer['seconds_left'] ?? null)) {
                $this->gateLeft[$w] = [$answer['seconds_left'], microtime(true)];
            }
            $again = Gate::plan($this->gates[$w], $this->secondsLeft($w), $this->workBudget);
            if ($again['stop'] !== null) {
                return ['status' => 0, 'stopped' => true, 'body' => ['error' => $again['stop'], 'fix' => 'Open an address whose conditions this client can meet, or update the client.'], 'sent' => null, 'work' => null, 'notes' => array_merge($plan['notes'], $again['notes'])];
            }
            if ($again['bits'] !== null) {
                [$status, $answer, $work, $stopped] = $attempt($again['bits']);
                $plan['notes'] = array_merge($plan['notes'], $again['notes']);
            }
        }
        if ($stopped) {
            return ['status' => 0, 'stopped' => true, 'body' => $answer, 'sent' => null, 'work' => null, 'notes' => $plan['notes']];
        }
        // An inbox that is not there, or has expired, takes its gate with it:
        // the next send here reads the gate of whatever is there then.
        if ($status === 404 || $status === 410) {
            $this->forgetGate($w);
        }

        return ['status' => $status, 'body' => $answer, 'sent' => $bytes, 'work' => $work, 'notes' => $plan['notes']];
    }

    /**
     * Reads with the read key. $after and $wait as on the wire, wait at most 25.
     *
     * Every message is checked here before it is handed over: the body is
     * hashed and compared with the sha256 beside it, and the signature is
     * verified over this address. verified and from on what comes back are this
     * client's result, not the service's word. A message the service called
     * verified that does not check out comes back unverified, without the key it
     * claimed, with unverified_because, and service_verified says what the
     * service had said.
     *
     * $allow is the allowlist the thread was opened with. The service holds it
     * in memory, and a write to the address after its store was emptied opens a
     * thread with none, so a reader applies its own: what the list does not
     * allow is left out of messages and listed under kept_out, never dropped in
     * silence.
     */
    public function read(string $w, string $id, int $after = 0, int $wait = 0, ?array $allow = null): array
    {
        $allow = self::normalizeAllow($allow);
        $path = '/' . $w . ($after > 0 || $wait > 0 ? '/after/' . $after : '') . ($wait > 0 ? '/wait/' . min($wait, 25) : '');
        [$status, $answer] = Http::call('GET', $this->url($path), null, ['X-Read' => $id], $this->timeout + 25);
        $out = ['status' => $status, 'body' => $answer];
        if ($status !== 200 || !is_array($answer) || !is_array($answer['messages'] ?? null)) {
            return $out;
        }
        $any = in_array('*', $allow, true);
        $handed = [];
        $keptOut = [];
        $out['observed'] = [];
        foreach ($answer['messages'] as $raw) {
            $message = $this->checkedMessage($w, $raw);
            $excluded = $allow !== [] && !($message['verified'] && ($any || in_array($message['from'], $allow, true)));
            $out['observed'][] = ['seq' => $message['seq'], 'at' => $message['at'], 'sha256' => $message['sha256'], 'from' => $message['service_from'], 'from_key' => $message['from'], 'verified' => $message['verified'], 'service_verified' => $message['service_verified'], 'unverified_because' => $message['unverified_because'] ?? null, 'kept_out' => $excluded];
            if ($excluded) {
                $keptOut[] = ['seq' => $message['seq'], 'why' => $any ? 'this thread was opened for signed messages only, and this one did not verify here' : 'this thread was opened for named keys, and this one was not signed by one of them, as checked here', 'unverified_because' => $message['unverified_because'] ?? null];
                continue;
            }
            $handed[] = $message;
        }
        $answer['messages'] = $handed;
        $out['body'] = $answer;
        if ($keptOut !== []) {
            $out['kept_out'] = $keptOut;
        }

        return $out;
    }

    /**
     * Every message read back, with a decoded form beside the raw body: for a
     * sealed message that is addressed to us and opens, 'plaintext'; for
     * plain text, the body; 'sealed', 'verified', 'from' are never taken from
     * the payload. On a message that came through read(), verified and from
     * are this client's own result. UNSAFE for raw remote input: this legacy
     * method trusts supplied fields. Use decodeAt() or read() for such input.
     */
    public function decode(array $message): array
    {
        $out = ['seq' => $message['seq'] ?? null, 'at' => $message['at'] ?? null, 'from' => $message['from'] ?? null, 'verified' => (bool) ($message['verified'] ?? false), 'sealed' => (bool) ($message['sealed'] ?? false), 'body' => $message['body'] ?? '', 'opened' => null, 'format' => 'text'];
        foreach (['unverified_because', 'service_verified'] as $field) {
            if (array_key_exists($field, $message)) { $out[$field] = $message[$field]; }
        }
        $body = is_string($message['body'] ?? null) ? $message['body'] : '';
        if ($out['sealed']) {
            // The envelope names who it is sealed to, as the first 8 hex of
            // sha256 over the recipient key, so that question is answered by
            // reading it rather than guessed at. Without keys, or without a
            // sender to open against, this client cannot tell and says so:
            // it used to answer "sealed to someone else" for envelopes that
            // were sealed to the reader, with no error beside the claim.
            $envelope = json_decode($body, true);
            $to = is_array($envelope) ? ($envelope['to'] ?? null) : null;
            $mine = $this->keys !== null ? $this->keys->hashPrefix : null;
            if (is_string($to) && $mine !== null && $to !== $mine) {
                $out['format'] = 'sealed-to-someone-else';
                $out['error'] = 'this envelope is sealed to ' . $to . ', not to ' . $mine;
            } elseif ($this->keys === null || empty($message['from'])) {
                $out['format'] = 'sealed-unchecked';
            } else {
                try {
                    $out['opened'] = $this->keys->open((string) $message['from'], $body);
                    $out['format'] = 'sealed';
                } catch (\Throwable $error) {
                    $out['format'] = 'unreadable';
                    $out['error'] = $error->getMessage();
                }
            }
        }
        $text = $out['opened'] ?? $body;
        $json = json_decode($text, true);
        if (is_array($json)) {
            $out['json'] = $json;
        }

        return $out;
    }

    public function receipt(string $w, string $id): array
    {
        [$status, $answer] = Http::call('GET', $this->url('/' . $w . '/receipt'), null, ['X-Read' => $id], $this->timeout);
        $out = ['status' => $status, 'body' => $answer];
        if ($status === 200 && is_array($answer)) {
            $out['check'] = Receipt::verify($answer);
        }

        return $out;
    }

    public function close(string $w, string $id): array
    {
        [$status, $answer] = Http::call('DELETE', $this->url('/' . $w), null, ['X-Read' => $id], $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }

    // ----------------------------------------------------------- presence --

    /** Publishes where this key can be reached, for up to 120 seconds. */
    public function presencePublish(string $w, array $tags = [], int $ttl = 60): array
    {
        $this->needKeys();
        $body = Codec::json(['w' => $w, 'tags' => array_values($tags), 'ttl' => $ttl]);
        $headers = ['Content-Type' => 'application/json', 'X-Key' => $this->keys->public, 'X-Sig' => $this->keys->sign(Keys::presenceSigningInput($this->keys->public, $body))];
        [$status, $answer] = Http::call('PUT', $this->url('/p/' . $this->keys->public), $body, $headers, $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }

    public function presenceGet(string $key): array
    {
        [$status, $answer] = Http::call('GET', $this->url('/p/' . $key), null, [], $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }

    /** @param string[] $prefixes 8 to 64 hex characters of a key's hash each */
    public function presenceLookup(array $prefixes, int $wait = 0): array
    {
        $route = $wait > 0 ? '/p/watch' : '/p/lookup';
        $body = ['prefixes' => array_values($prefixes)];
        if ($wait > 0) {
            $body['wait'] = min($wait, 25);
        }
        [$status, $answer] = Http::call('POST', $this->url($route), Codec::json($body), ['Content-Type' => 'application/json'], $this->timeout + 25);

        return ['status' => $status, 'body' => $answer];
    }

    public function presenceDelete(): array
    {
        $this->needKeys();
        $body = Codec::json(['at' => time()]);
        $headers = ['Content-Type' => 'application/json', 'X-Key' => $this->keys->public, 'X-Sig' => $this->keys->sign(Keys::presenceDeleteSigningInput($this->keys->public, $body))];
        [$status, $answer] = Http::call('DELETE', $this->url('/p/' . $this->keys->public), $body, $headers, $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }

    private function needKeys(): void
    {
        if ($this->keys === null) {
            throw new \LogicException('this call needs keys');
        }
    }
}
