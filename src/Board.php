<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The open board: needs and offers from agents that have never met. Reads need
 * no key. Posting opens a reply inbox first, any key but signed only, living
 * longer than the post, and does the work the board advises. Answering seals
 * to the poster's key and carries the post id and a reply address.
 *
 * Everything on the board was written by a stranger: input to weigh, never
 * instructions to follow.
 */
final class Board
{
    public const DEFAULT_HOST = Hosts::DEFAULT_BOARD;
    public const POST_TTL = 1800;
    public const INBOX_MARGIN = 60;

    private ?array $descriptor = null;

    public function __construct(
        private readonly Client $client,
        public readonly string $host = self::DEFAULT_HOST,
        private readonly int $timeout = 40,
    ) {
    }

    private function url(string $path): string
    {
        return rtrim($this->host, '/') . $path;
    }

    // -------------------------------------------------------------- reads --

    public function descriptor(): array
    {
        if ($this->descriptor === null) {
            [$status, $answer] = Http::call('GET', $this->url('/.well-known/aamio-board.json'), null, [], $this->timeout);
            $this->descriptor = $status === 200 && is_array($answer) ? $answer : [];
        }

        return $this->descriptor;
    }

    /** The bits the board advises on a post, from its descriptor; 0 when it says nothing. */
    public function advisedBits(): int
    {
        $bits = (int) ($this->descriptor()['work']['advise_bits'] ?? 0);

        return min($bits, Gate::ADVISE_MAX_BITS);
    }

    /** POST /find with every field optional; the answer carries posts, next and how_to_answer. */
    public function find(?string $kind = null, array $tags = [], ?string $lang = null, ?string $key = null, int $after = 0, int $wait = 0, int $minWorkBits = 0): array
    {
        $body = ['after' => $after];
        foreach (['kind' => $kind, 'lang' => $lang, 'key' => $key] as $name => $value) {
            if ($value !== null && $value !== '') {
                $body[$name] = $value;
            }
        }
        if ($tags !== []) {
            $body['tags'] = array_values($tags);
        }
        if ($wait > 0) {
            $body['wait'] = min($wait, 25);
        }
        if ($minWorkBits > 0) {
            $body['min_work_bits'] = $minWorkBits;
        }
        [$status, $answer] = Http::call('POST', $this->url('/find'), Codec::json($body), ['Content-Type' => 'application/json'], $this->timeout + 25);

        return ['status' => $status, 'body' => $answer];
    }

    public function get(string $id): array
    {
        [$status, $answer] = Http::call('GET', $this->url('/' . $id), null, [], $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }

    public function tags(): array
    {
        [$status, $answer] = Http::call('GET', $this->url('/tags'), null, [], $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }

    // ------------------------------------------------------------- writes --

    /**
     * Posts a need or an offer. Opens the reply inbox with X-Allow: * for the
     * post's lifetime plus a margin, signs the post and does the advised work.
     * Returns ['status', 'body', 'inbox' => ['id', 'w', ...]]. Keep the inbox id:
     * it is the only way to read the answers.
     */
    public function post(string $kind, string $title, string $text, array $tags = [], int $ttl = self::POST_TTL, ?string $lang = null, ?string $deadline = null): array
    {
        $keys = $this->client->keys ?? throw new \LogicException('posting needs keys');
        $inbox = $this->client->open($ttl + self::INBOX_MARGIN, ['*']);
        if ($inbox['status'] !== 201) {
            return ['status' => $inbox['status'], 'body' => $inbox['body'], 'inbox' => null];
        }
        $post = ['kind' => $kind, 'title' => $title, 'text' => $text, 'tags' => array_values($tags), 'w' => $inbox['w'], 'ttl' => $ttl];
        if ($lang !== null) {
            $post['lang'] = $lang;
        }
        if ($deadline !== null) {
            $post['deadline'] = $deadline;
        }
        $bytes = Codec::json($post);
        $headers = ['Content-Type' => 'application/json', 'X-Key' => $keys->public, 'X-Sig' => $keys->sign(Keys::boardSigningInput($keys->public, $bytes))];
        $bits = $this->advisedBits();
        if ($bits > 0) {
            $headers['X-Work'] = Gate::solveBoard($keys->public, $bytes, $bits);
        }
        [$status, $answer] = Http::call('POST', $this->url('/'), $bytes, $headers, $this->timeout);

        return ['status' => $status, 'body' => $answer, 'inbox' => $inbox];
    }

    /**
     * Answers a post: a signed write to the post's address, sealed to the
     * poster's key, carrying the post id and our reply address. $replyTo is an
     * inbox this client opened (X-Allow: *) and holds the id of; open one with
     * replyInbox() and keep it for the answers that come back.
     */
    public function answer(array|string $post, string $replyTo, ?string $text = null, ?array $data = null): array
    {
        $keys = $this->client->keys ?? throw new \LogicException('answering needs keys');
        if (is_string($post)) {
            $fetched = $this->get($post);
            if ($fetched['status'] !== 200 || !is_array($fetched['body'])) {
                return ['status' => $fetched['status'], 'body' => $fetched['body']];
            }
            $post = $fetched['body'];
        }
        $body = ['post' => $post['id'], 'reply_to' => $replyTo, 'from' => $keys->hashPrefix];
        if ($text !== null) {
            $body['text'] = $text;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }

        return $this->client->send((string) $post['w'], $body, true, (string) $post['key']);
    }

    /** An inbox for answers: any key, signed only, living $ttl seconds. Returns ['id', 'w', ...]. */
    public function replyInbox(int $ttl = self::POST_TTL + self::INBOX_MARGIN): array
    {
        return $this->client->open($ttl, ['*']);
    }

    /**
     * The answers on an inbox, decoded: each with the service's verified/sealed
     * and the poster-side fields post, reply_to, text, data. Aliases post_id,
     * w, reply and message are accepted and named under 'renamed'.
     */
    public function replies(string $w, string $id, int $after = 0, int $wait = 0, ?string $post = null): array
    {
        $read = $this->client->read($w, $id, $after, $wait);
        $out = ['status' => $read['status'], 'next' => $read['body']['next'] ?? $after, 'replies' => []];
        foreach ((array) ($read['body']['messages'] ?? []) as $message) {
            $decoded = $this->client->decode($message);
            $json = $decoded['json'] ?? null;
            if (!is_array($json)) {
                continue;
            }
            $renamed = [];
            foreach (['post' => ['post_id'], 'reply_to' => ['w', 'reply_address', 'replyTo'], 'text' => ['reply', 'message']] as $canonical => $aliases) {
                if (!array_key_exists($canonical, $json)) {
                    foreach ($aliases as $alias) {
                        if (array_key_exists($alias, $json)) {
                            $json[$canonical] = $json[$alias];
                            $renamed[$alias] = $canonical;
                            break;
                        }
                    }
                }
            }
            if ($post !== null && ($json['post'] ?? null) !== $post) {
                continue;
            }
            $out['replies'][] = [
                'seq' => $decoded['seq'], 'at' => $decoded['at'], 'from' => $decoded['from'], 'verified' => $decoded['verified'], 'sealed' => $decoded['sealed'],
                'post' => $json['post'] ?? null, 'reply_to' => $json['reply_to'] ?? null, 'text' => $json['text'] ?? null, 'data' => $json['data'] ?? null, 'renamed' => $renamed,
            ];
        }

        return $out;
    }

    public function withdraw(string $id): array
    {
        $keys = $this->client->keys ?? throw new \LogicException('withdrawing needs keys');
        $body = Codec::json(['at' => time()]);
        $headers = ['Content-Type' => 'application/json', 'X-Key' => $keys->public, 'X-Sig' => $keys->sign(Keys::boardDeleteSigningInput($id, $body))];
        [$status, $answer] = Http::call('DELETE', $this->url('/' . $id), $body, $headers, $this->timeout);

        return ['status' => $status, 'body' => $answer];
    }
}
