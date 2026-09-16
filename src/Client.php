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
    public const DEFAULT_HOST = 'https://aamio.at';
    public const DEFAULT_TTL = 600;

    /** @var array<string, array|null> gates read, per address, once */
    private array $gates = [];

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
        $id = Address::newId();
        $w = Address::w($id);
        $headers = ['X-Read' => $id, 'X-TTL' => (string) $ttl, 'Content-Type' => 'application/json'];
        if ($allow !== null) {
            $headers['X-Allow'] = implode(',', $allow);
        }
        $body = $gate !== null ? Codec::json(['gate' => $gate]) : null;
        [$status, $answer] = Http::call('PUT', $this->url('/' . $w), $body, $headers, $this->timeout);

        return ['id' => $id, 'w' => $w, 'status' => $status, 'body' => $answer];
    }

    /** The gate an inbox was opened with, read once per address; {} for none, null when the thread is gone. */
    public function gate(string $w, bool $fresh = false): ?array
    {
        if (!$fresh && array_key_exists($w, $this->gates)) {
            return $this->gates[$w];
        }
        [$status, $answer] = Http::call('GET', $this->url('/' . $w . '/gate'), null, [], $this->timeout);
        $this->gates[$w] = $status === 200 && is_array($answer) ? $answer : null;

        return $this->gates[$w];
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

        $plan = Gate::plan($this->gate($w));
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
                $work = Gate::solve($w, $key, $bytes, $bits);
                $headers['X-Work'] = $work;
            }
            [$status, $answer] = Http::call('POST', $this->url('/' . $w), $bytes, $headers, $this->timeout);

            return [$status, $answer, $work];
        };

        [$status, $answer, $work] = $attempt($plan['bits']);
        if ($status === 428 && is_array($answer) && isset($answer['gate'])) {
            // The refusal carries the whole gate; meet it once, never more.
            $this->gates[$w] = is_array($answer['gate']) ? $answer['gate'] : null;
            $again = Gate::plan($this->gates[$w]);
            if ($again['stop'] === null && $again['bits'] !== null) {
                [$status, $answer, $work] = $attempt($again['bits']);
                $plan['notes'] = array_merge($plan['notes'], $again['notes']);
            }
        }

        return ['status' => $status, 'body' => $answer, 'sent' => $bytes, 'work' => $work, 'notes' => $plan['notes']];
    }

    /** Reads with the read key. $after and $wait as on the wire, wait at most 25. */
    public function read(string $w, string $id, int $after = 0, int $wait = 0): array
    {
        $path = '/' . $w . ($after > 0 || $wait > 0 ? '/after/' . $after : '') . ($wait > 0 ? '/wait/' . min($wait, 25) : '');
        [$status, $answer] = Http::call('GET', $this->url($path), null, ['X-Read' => $id], $this->timeout + 25);

        return ['status' => $status, 'body' => $answer];
    }

    /**
     * Every message read back, with a decoded form beside the raw body: for a
     * sealed message that is addressed to us and opens, 'plaintext'; for
     * plain text, the body; 'sealed', 'verified', 'from' are the service's
     * fields and never taken from the payload.
     */
    public function decode(array $message): array
    {
        $out = ['seq' => $message['seq'] ?? null, 'at' => $message['at'] ?? null, 'from' => $message['from'] ?? null, 'verified' => (bool) ($message['verified'] ?? false), 'sealed' => (bool) ($message['sealed'] ?? false), 'body' => $message['body'] ?? '', 'opened' => null, 'format' => 'text'];
        $body = (string) ($message['body'] ?? '');
        if ($out['sealed'] && $this->keys !== null && !empty($message['from'])) {
            try {
                $out['opened'] = $this->keys->open((string) $message['from'], $body);
                $out['format'] = 'sealed';
            } catch (\Throwable $error) {
                $out['format'] = 'unreadable';
                $out['error'] = $error->getMessage();
            }
        } elseif ($out['sealed']) {
            $out['format'] = 'sealed-to-someone-else';
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
