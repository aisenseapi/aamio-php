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
 *
 * A post with a scope address is unlisted, and only a find with that scope's
 * key returns it. The key is the read capability and the address the write
 * capability. Unlisted is not private.
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

    /**
     * POST /find with every field optional; the answer carries posts, next and how_to_answer.
     * With $scopeKey it reads that scope instead of the public board, the key in
     * the body and never in a path, and an answer that does not name the scope
     * throws, since it did not read the scope.
     */
    public function find(?string $kind = null, array $tags = [], ?string $lang = null, ?string $key = null, int $after = 0, int $wait = 0, int $minWorkBits = 0, ?string $scopeKey = null): array
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
        if ($scopeKey !== null) {
            $body['scope_key'] = $scopeKey;
        }
        [$status, $answer] = Http::call('POST', $this->url('/find'), Codec::json($body), ['Content-Type' => 'application/json'], $this->timeout + 25);
        if ($scopeKey !== null && $status === 200 && (!is_array($answer) || ($answer['scope'] ?? null) !== Address::scope($scopeKey))) {
            throw new \UnexpectedValueException('the board did not say it read that scope, so its answer is not that scope');
        }

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
     * it is the only way to read the answers. $scope is the 20 character address
     * of a scope, from Address::scope(), and never the key.
     */
    public function post(string $kind, string $title, string $text, array $tags = [], int $ttl = self::POST_TTL, ?string $lang = null, ?string $deadline = null, ?string $scope = null): array
    {
        $keys = $this->client->keys ?? throw new \LogicException('posting needs keys');
        if ($scope !== null && !Address::isW($scope)) {
            throw new \InvalidArgumentException('scope is the 20 character address of a scope, never its key');
        }
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
        if ($scope !== null) {
            $post['scope'] = $scope;
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
     * Listless compatibility reader. Use repliesThread() to retain the inbox policy.
     * The answers on an inbox, decoded: verified/from are locally checked,
     * and the poster-side fields post, reply_to, text, data. Aliases post_id,
     * w, reply and message are accepted and named under 'renamed'.
     */
    public function replies(string $w, string $id, int $after = 0, int $wait = 0, ?string $post = null): array
    {
        $read = $this->client->read($w, $id, $after, $wait);
        return $this->decodeReplies($read, $after, $post);
    }

    public function repliesThread(array $inbox, int $after = 0, int $wait = 0, ?string $post = null): array
    {
        return $this->decodeReplies($this->client->readThread($inbox, $after, $wait), $after, $post);
    }

    private function decodeReplies(array $read, int $after, ?string $post): array
    {
        $out = ['status' => $read['status'], 'next' => $read['body']['next'] ?? $after, 'replies' => [], 'kept_out' => $read['kept_out'] ?? [], 'left_out' => 0];
        foreach ((array) ($read['body']['messages'] ?? []) as $message) {
            $decoded = $this->client->decode($message);
            // A reply that is not a JSON object is still a reply. Dropping it
            // here, with nothing counting what was dropped, told the poster
            // that nobody had written.
            $json = is_array($decoded['json'] ?? null) ? $decoded['json'] : [];
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
                $out['left_out']++;
                continue;
            }
            $out['replies'][] = [
                'seq' => $decoded['seq'], 'at' => $decoded['at'], 'from' => $decoded['from'], 'verified' => $decoded['verified'], 'sealed' => $decoded['sealed'],
                'format' => $decoded['format'],
                'unverified_because' => $decoded['unverified_because'] ?? null, 'service_verified' => $decoded['service_verified'] ?? false,
                // A reply that is plain text, or an envelope this client cannot
                // open, carries what there is instead of nothing at all.
                'post' => $json['post'] ?? null, 'reply_to' => $json['reply_to'] ?? null,
                'text' => $json['text'] ?? ($json === [] ? ($decoded['opened'] ?? $decoded['body']) : null),
                'data' => $json['data'] ?? null, 'renamed' => $renamed,
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
