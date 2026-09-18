<?php

declare(strict_types=1);

/**
 * The runtime against a fake service. Nothing here touches the network: the
 * fake answers the way aamio does, remembers what was written, and can be
 * told to fall silent or refuse. What is tested is the runtime's own
 * judgement: what it stores before it sends, what it marks as a replay, what
 * it calls unknown, and what it never does twice.
 *
 *   php tests/runtime.php
 */

require __DIR__ . '/bootstrap.php';

use Aamio\Address;
use Aamio\Board;
use Aamio\Channel;
use Aamio\Client;
use Aamio\Codec;
use Aamio\GateStop;
use Aamio\Hosts;
use Aamio\Http;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;
use Aamio\SendFailed;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
};

/**
 * A stand-in for aamio.at and board.aamio.at: threads with messages, presence,
 * a board with posts, gates, and two switches: silent (no answer at all) and
 * refuse (a deterministic 403 on every write).
 */
final class FakeService
{
    public array $threads = [];
    public array $presence = [];
    public array $posts = [];
    public array $calls = [];
    public bool $silent = false;
    public bool $refuse = false;
    public bool $oldBoard = false;
    public bool $forgetScope = false;
    public bool $explode = false;
    public int $pageSize = 200;
    public int $now;
    private int $seq = 0;

    public function __construct()
    {
        $this->now = time();
    }

    public function __invoke(string $method, string $url, ?string $body, array $headers): array
    {
        $this->calls[] = [$method, $url];
        if ($this->explode) {
            throw new \Error('the fake fell over');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $json = is_string($body) ? json_decode($body, true) : null;
        if (str_starts_with($host, 'board.')) {
            return $this->board($method, $path, $body, $json, $headers);
        }
        if ($path === '/p/lookup' || $path === '/p/watch') {
            $matches = [];
            foreach ($this->presence as $key => $record) {
                foreach ((array) ($json['prefixes'] ?? []) as $prefix) {
                    if (str_starts_with(hash('sha256', Codec::unb64url($key)), $prefix)) {
                        $matches[] = $record + ['key' => $key, 'hash' => hash('sha256', Codec::unb64url($key))];
                    }
                }
            }

            return [200, ['matches' => $matches, 'count' => count($matches), 'waited' => 0], []];
        }
        if (preg_match('!^/p/([A-Za-z0-9_-]{43})$!', $path, $m)) {
            if ($method === 'PUT') {
                $this->presence[$m[1]] = ['w' => $json['w'], 'tags' => $json['tags'], 'at' => $this->now, 'expire_at' => $this->now + 120];

                return [200, $this->presence[$m[1]] + ['key' => $m[1]], []];
            }
            if ($method === 'DELETE') {
                unset($this->presence[$m[1]]);

                return [200, ['deleted' => true], []];
            }

            return isset($this->presence[$m[1]]) ? [200, $this->presence[$m[1]], []] : [404, ['error' => 'No live presence', 'fix' => 'Ask again later'], []];
        }
        if (preg_match('!^/([a-z2-7]{20})(?:/(gate|receipt)|/after/(\d+)(?:/wait/(\d+))?)?$!', $path, $m)) {
            $w = $m[1];
            $sub = $m[2] ?? '';
            if ($method === 'PUT') {
                $this->threads[$w] = ['id' => $headers['X-Read'], 'expire_at' => $this->now + (int) ($headers['X-TTL'] ?? 600), 'allow' => isset($headers['X-Allow']) ? explode(',', $headers['X-Allow']) : [], 'messages' => [], 'gate' => $json['gate'] ?? []];

                return [201, ['w' => $w, 'created_at' => $this->now, 'expire_at' => $this->threads[$w]['expire_at'], 'ttl' => (int) ($headers['X-TTL'] ?? 600), 'count' => 0, 'bytes' => 0, 'allow' => $this->threads[$w]['allow']], []];
            }
            $thread = $this->threads[$w] ?? null;
            if ($sub === 'gate') {
                return $thread === null ? [404, ['error' => 'No thread', 'fix' => 'Open one'], []] : [200, $thread['gate'], []];
            }
            if ($method === 'POST') {
                if ($thread === null) {
                    return [404, ['error' => 'No thread at this address', 'fix' => 'Open one'], []];
                }
                if ($this->silent) {
                    // The write never reaches the service: the network died on the way.
                    return [0, ['error' => 'no answer: fake network down', 'fix' => 'The request may have landed.'], []];
                }
                if ($this->refuse) {
                    return [403, ['error' => 'refused by the fake', 'fix' => 'change the request'], []];
                }
                if ($thread['allow'] !== [] && !isset($headers['X-Key'])) {
                    return [403, ['error' => 'signed only', 'fix' => 'sign it'], []];
                }
                $seq = count($thread['messages']) + 1;
                $message = ['seq' => $seq, 'at' => $this->now + $seq, 'type' => is_array($json) ? 'json' : 'text', 'body' => (string) $body, 'sha256' => hash('sha256', (string) $body), 'from' => $headers['X-Key'] ?? null, 'sig' => $headers['X-Sig'] ?? null, 'verified' => isset($headers['X-Key']), 'sealed' => Keys::isEnvelope((string) $body)];
                $this->threads[$w]['messages'][] = $message;

                return [201, ['w' => $w, 'seq' => $seq, 'at' => $message['at'], 'sha256' => $message['sha256'], 'verified' => $message['verified'], 'sealed' => $message['sealed'], 'count' => $seq, 'expire_at' => $thread['expire_at']], []];
            }
            if ($thread === null || ($headers['X-Read'] ?? '') !== $thread['id']) {
                return [$thread === null ? 404 : 403, ['error' => 'no', 'fix' => 'no'], []];
            }
            if ($sub === 'receipt') {
                $lines = '';
                foreach ($thread['messages'] as $msg) {
                    $lines .= sprintf("%d\t%d\t%s\t%s\n", $msg['seq'], $msg['at'], $msg['sha256'], $msg['from'] ?: '-');
                }
                $root = hash('sha256', $lines);

                return [200, ['schema' => 'aamio-receipt-v1', 'w' => $w, 'created_at' => $this->now, 'expire_at' => $thread['expire_at'], 'count' => count($thread['messages']), 'bytes' => 0, 'allow' => $thread['allow'], 'messages' => array_map(static fn (array $msg): array => ['seq' => $msg['seq'], 'at' => $msg['at'], 'sha256' => $msg['sha256'], 'from' => $msg['from']], $thread['messages']), 'keys' => [], 'root' => $root, 'commitment' => 'sha256:' . $root, 'issued_at' => $this->now, 'how' => 'lines'], []];
            }
            if ($method === 'DELETE') {
                unset($this->threads[$w]);

                return [200, ['w' => $w, 'deleted' => true], []];
            }
            $after = (int) ($m[3] ?? 0);
            $messages = array_values(array_filter($thread['messages'], static fn (array $msg): bool => $msg['seq'] > $after));

            return [200, ['w' => $w, 'exists' => true, 'count' => count($thread['messages']), 'messages' => $messages, 'next' => $messages === [] ? $after : end($messages)['seq'], 'waited' => 0], []];
        }

        return [404, ['error' => 'Not found', 'fix' => 'no such route in the fake'], []];
    }

    private function board(string $method, string $path, ?string $body, ?array $json, array $headers): array
    {
        if ($path === '/.well-known/aamio-board.json') {
            return [200, ['work' => ['advise_bits' => 4, 'max_bits' => 20]], []];
        }
        if ($path === '/find') {
            if ($this->oldBoard && isset($json['scope_key'])) {
                return [400, ['error' => 'Unknown field scope_key. The form is kind, tags, lang, key, after, wait, min_work_bits', 'fix' => 'Drop that field and try again.'], []];
            }
            $scope = isset($json['scope_key']) ? Address::scope((string) $json['scope_key']) : null;
            $after = (int) ($json['after'] ?? 0);
            $page = array_slice(array_values(array_filter($this->posts, static fn (array $p): bool => ($p['scope'] ?? null) === $scope && $p['seq'] > $after)), 0, $this->pageSize);
            $answer = ['posts' => $page, 'next' => $page === [] ? $after : end($page)['seq'], 'how_to_answer' => ['method' => 'POST']];
            if ($scope !== null && !$this->forgetScope) {
                $answer['scope'] = $scope;
            }

            return [200, $answer, []];
        }
        if ($path === '/tags') {
            return [200, ['tags' => []], []];
        }
        if ($path === '/' && $method === 'POST') {
            if ($this->oldBoard && isset($json['scope'])) {
                return [400, ['error' => 'Unknown field scope', 'fix' => 'Drop that field'], []];
            }
            $id = 'post' . str_pad((string) (count($this->posts) + 1), 16, '0', STR_PAD_LEFT);
            $this->posts[$id] = $json + ['id' => $id, 'seq' => ++$this->seq, 'key' => $headers['X-Key'], 'expire_at' => $this->now + (int) ($json['ttl'] ?? 1800), 'work_bits' => isset($headers['X-Work']) ? 4 : 0];

            return [201, $this->posts[$id], []];
        }
        if (preg_match('!^/([a-z0-9]{20})$!', $path, $m)) {
            if ($method === 'DELETE') {
                unset($this->posts[$m[1]]);

                return [200, ['withdrawn' => true], []];
            }

            return isset($this->posts[$m[1]]) && !isset($this->posts[$m[1]]['scope']) ? [200, $this->posts[$m[1]], []] : [404, ['error' => 'gone', 'fix' => 'none'], []];
        }

        return [404, ['error' => 'Not found', 'fix' => 'no such board route in the fake'], []];
    }
}

$fake = new FakeService();
Http::$override = $fake;
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-php-runtime-test-' . getmypid();
$homeA = $root . DIRECTORY_SEPARATOR . 'a';
$homeB = $root . DIRECTORY_SEPARATOR . 'b';
mkdir($homeA, 0700, true);
mkdir($homeB, 0700, true);
$quiet = static function (string $line): void {
};

echo "identity and home\n";
$a = new Runtime($homeA, 'https://fake.test', ['php.test'], true, $quiet);
$check(Codec::isKey($a->keys->public) && is_file($homeA . '/key') && preg_match('/^[0-9a-f]{64}\n$/', (string) file_get_contents($homeA . '/key')) === 1, 'a fresh home gets a key, kept as 64 hex characters like aamio-python writes it');
$check(is_file($homeA . '/lock') && json_decode((string) file_get_contents($homeA . '/lock'), true)['pid'] === getmypid(), 'the lock holds this pid');
$threw = false;
try {
    new Runtime($homeA, 'https://fake.test', null, true, $quiet);
} catch (\RuntimeException) {
    $threw = true;
}
$check($threw, 'a second runtime on the same home in this process is refused');
$a->close();
$again = new Runtime($homeA, 'https://fake.test', null, true, $quiet);
$check($again->keys->public === $a->keys->public && $again->tags === ['php.test'], 'reopened, the same key and the tags come back from state.json');
$a = $again;
$b = new Runtime($homeB, 'https://fake.test', ['b'], true, $quiet);

echo "inbox and presence\n";
$inbox = $a->ensureInbox();
$check($inbox->label === 'inbox' && isset($fake->threads[$inbox->w]) && $inbox->expireAt > time() + 3000, 'init opens an inbox for an hour');
$check(isset($fake->presence[$a->keys->public]) && $fake->presence[$a->keys->public]['w'] === $inbox->w, 'and publishes presence pointing at it, signed');
$check($a->ensureInbox() === $inbox, 'a live inbox is reused');
$who = $a->whoami();
$check($who['inbox'] === $inbox->w && $who['hash_prefix'] === $a->keys->hashPrefix, 'whoami names the inbox');

echo "partners and lookup\n";
$a->partnerAdd('Bea', $b->keys->public);
$b->partnerAdd('Al', $a->keys->public);
$check($a->partnerList()[0]['name'] === 'Bea' && $a->nameForKey($b->keys->public) === 'Bea', 'a partner is known by name and key');
$b->ensureInbox();
$found = $a->lookup(['Bea']);
$check(count($found['online']) === 1 && $found['online'][0]['w'] === $b->channels['inbox']->w && $found['offline'] === [], 'lookup finds the partner online at her inbox');
$check($a->lookup(['Nobody'])['error'] === 'no partners to look up', 'an unknown name is not looked up');

echo "send, the outbox, and what the other side reads\n";
$sent = $a->send('Bea', 'hei Bea', ['n' => 1]);
$check($sent['seq'] === 1 && $sent['to'] === 'Bea' && str_starts_with($sent['message_id'], 'm-'), 'a send lands with seq 1 and a message id', $sent['message_id']);
$entry = $a->outbox[$sent['message_id']];
$check($entry['status'] === 'delivered' && $entry['attempts'] === 1 && Keys::isEnvelope($entry['envelope']), 'the outbox holds the sealed bytes, delivered after one attempt');
$check(json_decode((string) file_get_contents($homeA . '/outbox.json'), true)[$sent['message_id']]['status'] === 'delivered', 'and it is on disk');
$got = $b->read();
$check(count($got) === 1 && $got[0]['encrypted'] && $got[0]['signed'] && $got[0]['format'] === 'json' && $got[0]['known_contact'] && $got[0]['sender'] === 'Al', 'Bea reads it: encrypted, signed, from a known contact');
$check($got[0]['body']['text'] === 'hei Bea' && $got[0]['body']['data']['n'] === 1 && $got[0]['body']['reply_to'] === $inbox->w, 'with the text, the data and the reply address');
$check($b->peers[$inbox->w] === $a->keys->public, 'and she learned which key answers at that address');
$check($got[0]['replay'] === false, 'a first delivery is not a replay');

echo "replay across a restart\n";
$b->channels['inbox']->after = 0; // the fake will hand the same message again
$twice = $b->read();
$check(count($twice) === 1 && $twice[0]['replay'] === true, 'the same message read again is marked as a replay');
$b->close();
$b2 = new Runtime($homeB, 'https://fake.test', null, true, $quiet);
$b2->channels['inbox']->after = 0;
$thrice = $b2->read();
$check(count($thrice) === 1 && $thrice[0]['replay'] === true, 'and it still is after a restart, because the hashes travelled with the channel');
$b2->close();
$b = new Runtime($homeB, 'https://fake.test', null, true, $quiet);

echo "unknown is not refused\n";
$fake->silent = true;
$threw = null;
try {
    $a->send('Bea', 'into the void');
} catch (SendFailed $error) {
    $threw = $error;
}
$fake->silent = false;
$check($threw !== null && $threw->outcome === 'unknown' && $threw->status === 0, 'no answer at all is an unknown outcome, not a refusal', (string) $threw?->getMessage());
$pending = $a->outboxPending();
$check(count($pending) === 1 && $pending[0]['status'] === 'unknown', 'the message stays in the outbox as unknown');
[$retryable, $fix] = Runtime::sendAdvice('unknown', 0);
$check($retryable === null && str_contains($fix, 'may already have been delivered'), 'and the advice says it may already have been delivered');
$retried = $a->outboxRetry($pending[0]['id']);
$check($retried[0]['status'] === 'delivered' && $a->outboxPending() === [], 'a retry sends the same bytes and settles it');
$again = $b->read();
$check(count($again) === 1 && $again[0]['body']['text'] === 'into the void', 'the partner got it once');

echo "refused stays refused\n";
$fake->refuse = true;
$threw = null;
try {
    $a->send('Bea', 'refused');
} catch (SendFailed $error) {
    $threw = $error;
}
$fake->refuse = false;
$check($threw !== null && $threw->outcome === 'refused' && $threw->status === 403, 'a 403 is a refusal');
$check($a->outboxRetry() === [], 'and a deterministic refusal is not retried');
[$retryable, $fix] = Runtime::sendAdvice('refused', 403);
$check($retryable === false, 'the advice says the same bytes would fail the same way');

echo "gate\n";
$w = Aamio\Address::w(Aamio\Address::newId());
$fake->threads[$w] = ['id' => 'x', 'expire_at' => time() + 600, 'allow' => [], 'messages' => [], 'gate' => ['require' => ['pow' => ['bits' => 4, 'covers' => 1]]]];
$a->peers[$w] = $b->keys->public;
$sent = $a->send($w, 'with work');
$check($sent['seq'] === 1, 'a required gate of 4 bits is met before the first attempt');
$fake->threads[$w]['gate'] = ['require' => ['captcha' => true]];
$w2 = Aamio\Address::w(Aamio\Address::newId());
$fake->threads[$w2] = $fake->threads[$w];
$a->peers[$w2] = $b->keys->public;
$threw = false;
try {
    $a->send($w2, 'never sent');
} catch (GateStop $stop) {
    $threw = str_contains($stop->getMessage(), 'captcha');
}
$check($threw, 'an unknown requirement stops the send before anything is stored, naming it');
$check(!array_filter($a->outbox, static fn (array $e): bool => $e['w'] === $w2), 'and nothing went into the outbox');

echo "board\n";
$post = $b->boardPost('need', 'Temperature log', 'The full log as JSON.', ['coldchain.qa'], 900, 'en');
$check(isset($fake->posts[$post['id']]) && $post['work_bits'] === 4 && $post['reply_inbox'] === $b->channels['board']->w, 'a post lands with the advised work and the reply inbox');
$found = $a->boardFind('need', ['coldchain']);
$check(count($found['posts']) === 1 && $a->peers[$found['posts'][0]['w']] === $b->keys->public, 'find lists it and learns the poster key for the reply address');
$answer = $a->boardAnswer($post['id'], 'I have it, 41 h, no excursion');
$check($answer['post'] === $post['id'] && $answer['seq'] === 1 && $answer['reply_to'] === $a->channels['board']->w, 'an answer is sealed to the poster and carries our reply address');
$b->read();
$replies = $b->boardReplies($post['id']);
$check(count($replies) === 1 && $replies[0]['body']['text'] === 'I have it, 41 h, no excursion' && $replies[0]['encrypted'] && $replies[0]['sender'] === 'Al', 'the poster reads the answer, decrypted and verified');
// Found in the wild 17 September 2026: the command said no replies while the
// board inbox held two, because neither named the post it answered.
$b->channels['board']->received[] = ['channel' => 'board', 'seq' => 99, 'at' => time(), 'sha256' => str_repeat('n', 64), 'body' => ['reply_to' => str_repeat('r', 20), 'text' => 'an answer that never names the post']];
$b->channels['aside'] = new Channel('aside', 'read', str_repeat('c', 20), time() + 600);
$b->channels['aside']->received[] = ['channel' => 'aside', 'seq' => 1, 'at' => time(), 'sha256' => str_repeat('p', 64), 'body' => ['text' => 'a private message, nobody\'s board reply']];
$everyReply = $b->boardReplies();
$onlyPost = $b->boardReplies($post['id']);
$check(count($everyReply) === count($onlyPost) + 1 && array_filter($everyReply, static fn (array $r): bool => ($r['sha256'] ?? '') === str_repeat('p', 64)) === [], 'an answer without a post id is still a reply on the board inbox, while a private message is not', count($everyReply) . ' and ' . count($onlyPost));
unset($b->channels['aside']);
array_pop($b->channels['board']->received);

// Found in the wild 18 September 2026: an agent asked for replies, got an
// empty list while its inbox held the answer, stopped believing the client
// and spent an hour hand rolling nacl on the raw envelope. The filter was
// right. Saying nothing about what it filtered was not.
$b->attentionTaken();
$b->channels['aside'] = new Channel('aside', 'read', str_repeat('c', 20), time() + 600);
$b->channels['aside']->received[] = ['channel' => 'aside', 'seq' => 1, 'at' => time(), 'verified' => false, 'sha256' => str_repeat('q', 64), 'body' => ['text' => '{"e2ee":"nacl.box.v1"}']];
$noAnswer = $b->boardReplies('p404');
$noted = $b->attentionTaken();
$check($noAnswer === [] && count($noted) === 1 && $noted[0]['state'] === 'filtered' && str_contains($noted[0]['what'], 'p404') && str_contains($noted[0]['what'], 'arrived unsigned'), 'an empty answer says how many messages it passed over, and that one was never opened', json_encode($noted));
$stillThere = $b->boardReplies($post['id']);
$check(count($stillThere) === 1 && $b->attentionTaken() === [], 'a call that found its answer says nothing about the rest of the inbox');
unset($b->channels['aside']);

// A board inbox is renewed while the old one still holds answers, and the old
// one keeps its own label and its own archive. Reading only the channels the
// process holds made those answers vanish from the replies command.
$rotated = ['kind' => 'received', 'channel' => 'board-1789470000', 'seq' => 1, 'at' => 1789470000, 'verified' => true, 'known_contact' => false, 'from_key' => 'their-key', 'sender' => 'unknown key', 'sha256' => str_repeat('e', 64), 'body' => ['post' => 'p9', 'reply_to' => str_repeat('r', 20), 'text' => 'answered before the inbox was renewed']];
file_put_contents($homeB . '/archive/board-1789470000.jsonl', json_encode($rotated) . "\n", FILE_APPEND);
$fromRotated = $b->boardReplies('p9');
$check(count($fromRotated) === 1 && ($fromRotated[0]['sha256'] ?? '') === str_repeat('e', 64), 'an answer on a board inbox that has since been renewed is still found', count($fromRotated) . ' funnet');
unlink($homeB . '/archive/board-1789470000.jsonl');

$where = $a->boardReplyAddress();
$check($where['open'] === true && $where['w'] === $a->channels['board']->w, 'the reply address is open');
$b->close();
$b3 = new Runtime($homeB, 'https://fake.test', null, true, $quiet);
$check(count($b3->boardReplies($post['id'])) === 1 && ($b3->boardReplies($post['id'])[0]['from_archive'] ?? false) === true, 'after a restart the answer is still there, from the archive');
$b3->close();
$b = new Runtime($homeB, 'https://fake.test', null, true, $quiet);

// An empty read used to mean four different things: a quiet inbox, an expired
// thread, a service that did not answer, and a key that no longer matched.
echo "an empty read that is not an empty inbox\n";
$lost = $a->channels['inbox'];
$missing = $fake->threads[$lost->w];
unset($fake->threads[$lost->w]);
$nothing = $a->read();
$attention = $a->attentionTaken();
$fake->threads[$lost->w] = $missing;
$check($nothing === [] && count($attention) === 1 && $attention[0]['state'] === 'unread' && str_contains($attention[0]['what'], 'may be messages waiting'), 'a channel the service would not answer for is reported, not read as an empty inbox', json_encode($attention));
$check($a->attentionTaken() === [], 'and what is taken once is not taken twice');
$server = new McpServer($a);
unset($fake->threads[$lost->w]);
$told = $server->handle(['jsonrpc' => '2.0', 'id' => 40, 'method' => 'tools/call', 'params' => ['name' => 'aamio_read', 'arguments' => []]]);
$fake->threads[$lost->w] = $missing;
$check(($told['result']['structuredContent']['count'] ?? null) === 0 && ($told['result']['structuredContent']['attention'][0]['state'] ?? null) === 'unread', 'and the model is told the same over MCP');
$a->read();
$a->attentionTaken();

echo "aliases\n";
[$body, $meta] = Runtime::canonical(['post_id' => 'p1', 'w' => 'wwwwwwwwwwwwwwwwwwww', 'reply' => 'hello']);
$check($body['post'] === 'p1' && $body['reply_to'] === 'wwwwwwwwwwwwwwwwwwww' && $body['text'] === 'hello' && $meta['renamed'] === ['post_id' => 'post', 'w' => 'reply_to', 'reply' => 'text'], 'post_id, w and reply are read as post, reply_to and text, and the renaming is reported');
[$body, $meta] = Runtime::canonical(['post' => 'p1', 'post_id' => 'p2']);
$check($meta['conflicting_fields'] === ['post_id' => 'p2'], 'two spellings that disagree are reported, the canonical one wins');
[$body, $meta] = Runtime::canonical(['post' => 'p1', 'post_id' => 'p1']);
$check($meta === [], 'two spellings that agree are not a conflict');
[$body, $meta] = Runtime::canonical(['w' => 'x', 'text' => 'no post id here']);
$check(!isset($body['reply_to']) && $meta === [], 'without a post id nothing is renamed: it is not an answer');

echo "receipt\n";
$receipt = $b->receipt('inbox');
$check($receipt['root_adds_up'] === true && $receipt['count'] === 2, 'the receipt adds up and counts the two messages on the inbox');
$check($receipt['local_root_matches'] === null && str_contains($receipt['local_check'], 'holds 0 of the 2'), 'a fresh process holds none of them, so the local check is null with the reason, not false');
$check(Keys::verify($receipt['attestation']['key'], $receipt['attestation']['sig'], $receipt['attestation']['over']), 'and the receipt is attested with our signature');

echo "effects\n";
$check($a->effect('release:ARC-4471')['state'] === 'new', 'an operation not yet done is new');
$a->effectDone('release:ARC-4471', ['ok' => true], 'fp1');
$check($a->effect('release:ARC-4471', 'fp1')['state'] === 'done' && $a->effect('release:ARC-4471', 'fp2')['state'] === 'conflict', 'done with the same fingerprint, conflict with another');

echo "scopes\n";
$made = $a->scopeNew('chapter-review');
$stored = json_decode((string) file_get_contents($homeA . '/scopes.json'), true);
$teamKey = (string) ($stored[0]['key'] ?? '');
$check($made === ['name' => 'chapter-review', 'address' => Address::scope($teamKey), 'can_read' => true] && Address::isScopeKey($teamKey), 'a new scope keeps its key in scopes.json and shows name, address and can_read');
$check(!str_contains((string) json_encode($a->scopeList()), $teamKey), 'the scope list never carries the key');
$threw = false;
try {
    $a->scopeNew('no spaces');
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check($threw, 'a scope name with a space is refused');
$shared = $a->scopeShare('chapter-review', 'Bea', 'read');
$check(!str_contains((string) json_encode($shared), $teamKey) && $shared['access'] === 'read' && $shared['scope'] === 'chapter-review', 'sharing sends the key sealed to a partner and never returns it');
$gotScope = $b->read();
$view = $gotScope[0]['body']['data']['aamio_scope'] ?? [];
$check(($view['kept'] ?? null) === true && ($view['can_read'] ?? null) === true && ($view['name'] ?? null) === 'Al.chapter-review' && ($view['shared_as'] ?? null) === 'chapter-review' && !str_contains((string) json_encode($gotScope), $teamKey), 'Bea keeps it under the name she knows the sender by, and the key is out of the message she reads');
$check(!str_contains((string) file_get_contents($homeB . '/archive/inbox.jsonl'), $teamKey) && $b->scopeKey('Al.chapter-review')['key'] === $teamKey, 'and out of her archive, while her runtime holds it');
$sentArchive = (string) file_get_contents($homeA . '/archive/sent.jsonl');
$check(!str_contains($sentArchive, $teamKey) && str_contains($sentArchive, '"aamio_scope":{"name":"chapter-review","access":"read"}'), 'the archive of sent messages says what was shared and with what access, and never holds the key');
$own = $b->scopeNew('chapter-review');
$check($own['address'] !== $made['address'] && array_column($b->scopeList(), 'name') === ['Al.chapter-review', 'chapter-review'], 'so a partner cannot take a name before it is made here: Bea still makes her own chapter-review');
$b->scopeRemove('chapter-review');
$strangerW = Address::w(Address::newId());
$a->peers[$strangerW] = Keys::generate()->public;
$outboxBefore = count($a->outbox);
$refusals = 0;
foreach ([$strangerW, $a->peers[$strangerW], 'Mallory'] as $to) {
    try {
        $a->scopeShare('chapter-review', $to, 'read');
    } catch (\InvalidArgumentException $error) {
        $refusals += str_contains($error->getMessage(), 'only with a partner') ? 1 : 0;
    }
}
$check($refusals === 3 && count($a->outbox) === $outboxBefore, 'a scope is shared only with a partner in the address book: an address from a post, a stranger key and an unknown name are refused, and nothing is sent');
$scoped = $b->boardPost('need', 'Chapter 3 draft ready', 'At commit 4f2a9c1.', ['chapter-03'], 900, null, null, 'Al.chapter-review');
$check(($fake->posts[$scoped['id']]['scope'] ?? null) === $made['address'] && $scoped['scope'] === 'Al.chapter-review' && !str_contains((string) json_encode($fake->posts[$scoped['id']]), $teamKey), 'a post in the scope carries the address inside what is signed, and never the key');
$check(!in_array($scoped['id'], array_column($a->boardFind(null, ['chapter-03'])['posts'], 'id'), true) && $a->boardGet($scoped['id']) === null, 'a find without the scope does not see it, and it is not served by id');
$inScope = $a->boardFind(null, ['chapter-03'], null, null, 0, 0, 0, 'chapter-review');
$check(array_column($inScope['posts'], 'id') === [$scoped['id']] && $inScope['scope_name'] === 'chapter-review', 'a find with the scope reads it, and names the scope');
$answered = $a->boardAnswer($scoped['id'], 'I can read it tonight', null, 'chapter-review');
$check($answered['post'] === $scoped['id'], 'a post in a scope is answered through the scope');
$later = $b->boardPost('offer', 'Chapter 4 outline', 'Two pages.', ['chapter-04'], 900, null, null, 'Al.chapter-review');
$fake->pageSize = 1;
$answered = $a->boardAnswer($later['id'], 'Found it on the second page', null, 'chapter-review');
$fake->pageSize = 200;
$check($answered['post'] === $later['id'], 'and found past the first page of the scope, following the cursor');
$fake->forgetScope = true;
$threw = '';
try {
    $a->boardFind(null, [], null, null, 0, 0, 0, 'chapter-review');
} catch (\RuntimeException $error) {
    $threw = $error->getMessage();
}
$fake->forgetScope = false;
$check(str_contains($threw, 'did not say it read scope chapter-review'), 'an answer that does not name the scope is not believed');
$fake->oldBoard = true;
$threw = '';
try {
    $a->boardFind(null, [], null, null, 0, 0, 0, 'chapter-review');
} catch (\RuntimeException $error) {
    $threw = $error->getMessage();
}
$fake->oldBoard = false;
$check(str_contains($threw, 'Unknown field scope_key'), 'a board older than scopes refuses the key, and the refusal comes through');
$b->scopeAdd('drop', null, Address::scope(Address::newScopeKey()));
$threw = false;
try {
    $b->boardFind(null, [], null, null, 0, 0, 0, 'drop');
} catch (\InvalidArgumentException $error) {
    $threw = str_contains($error->getMessage(), 'post only');
}
$check($threw, 'a scope held to post only does not read');
$a->scopeNew('second');
$secondKey = $a->scopeKey('second')['key'];
$b->partnerRemove('Al');
$a->scopeShare('second', 'Bea', 'read');
$fromStranger = $b->read();
$b->partnerAdd('Al', $a->keys->public);
$check(($fromStranger[0]['body']['data']['aamio_scope']['kept'] ?? null) === false && !str_contains((string) json_encode($fromStranger), $secondKey) && array_column($b->scopeList(), 'name') === ['Al.chapter-review', 'drop'], 'from a key not in the address book a scope is not kept, and the key is taken out all the same');
$b->scopeRemove('Al.chapter-review');
$b->channels['inbox']->after = 0;
$replayed = array_values(array_filter($b->read(), static fn (array $m): bool => isset($m['body']['data']['aamio_scope'])));
$check(count($replayed) === 2 && array_filter($replayed, static fn (array $m): bool => $m['replay'] !== true || $m['body']['data']['aamio_scope']['kept'] !== false) === [] && !in_array('Al.chapter-review', array_column($b->scopeList(), 'name'), true) && !str_contains((string) json_encode($replayed), $teamKey), 'a share read again is a replay and not kept again, so a removed scope stays removed');
$take = static function (Runtime $runtime, array $entry): array {
    (function () use (&$entry): void {
        $this->takeScopeShare($entry);
    })->call($runtime);

    return $entry;
};
$leaks = 0;
foreach ([
    ['text' => 't', 'aamio_scope' => ['name' => 'team', 'key' => $teamKey]],
    ['text' => 't', 'data' => ['aamio_scope' => $teamKey]],
    ['text' => 't', 'data' => ['aamio_scope' => [$teamKey]]],
    ['text' => 't', 'aamio_scope' => $teamKey, 'data' => ['aamio_scope' => 'team ' . $teamKey]],
] as $shape) {
    $leaks += str_contains((string) json_encode($take($b, ['verified' => true, 'encrypted' => true, 'known_contact' => true, 'from_key' => $a->keys->public, 'replay' => false, 'body' => $shape])), $teamKey) ? 1 : 0;
}
$check($leaks === 0 && array_column($b->scopeList(), 'name') === ['drop'], 'a key in aamio_scope is taken out whatever its shape and wherever it sits');
$nameFor = static function (Runtime $runtime, array $partner, string $scope): string {
    return (function () use ($partner, $scope): string {
        return $this->sharedScopeName($partner, $scope);
    })->call($runtime);
};
$named = [];
foreach (['Bea Ødegård' => 'Bea-deg-rd', '--x..y__' => 'x..y', '0' => '0', '李明' => Keys::hashPrefixOf($a->keys->public), str_repeat('a', 30) => str_repeat('a', 24)] as $partnerName => $prefix) {
    $named[] = $nameFor($b, ['name' => (string) $partnerName, 'key' => $a->keys->public], 'team') === $prefix . '.team'
        && $nameFor($b, ['name' => (string) $partnerName, 'key' => $a->keys->public], str_repeat('t', 64)) === substr($prefix . '.' . str_repeat('t', 64), 0, 64);
}
$check(!in_array(false, $named, true), 'the partner part of a name keeps what a name may hold, the same as aamio-python makes it');
$a->scopeNew('third');
mkdir($homeB . '/scopes.json.tmp');
$a->scopeShare('third', 'Bea', 'read');
$a->send('Bea', 'after the share');
$batch = $b->read();
rmdir($homeB . '/scopes.json.tmp');
$thirdView = [];
$arrived = false;
foreach ($batch as $m) {
    $thirdView = $m['body']['data']['aamio_scope'] ?? $thirdView;
    $arrived = $arrived || ($m['body']['text'] ?? null) === 'after the share';
}
$check(($thirdView['kept'] ?? null) === false && str_contains($thirdView['note'] ?? '', 'could not write') && $arrived && array_column($b->scopeList(), 'name') === ['drop'], 'a share that cannot be saved is not reported kept, and the rest of the batch still arrives');

echo "files that cannot be read\n";
$homeD = $root . DIRECTORY_SEPARATOR . 'd';
mkdir($homeD, 0700, true);
$refused = [];
foreach (['[{"name": "team", "key": "' . $teamKey . '"' => 'not UTF-8 JSON', '{"team": {"key": "' . $teamKey . '"}}' => 'not a JSON list'] as $broken => $why) {
    file_put_contents($homeD . '/scopes.json', $broken);
    $threw = '';
    try {
        new Runtime($homeD, 'https://fake.test', null, true, $quiet);
    } catch (\RuntimeException $error) {
        $threw = $error->getMessage();
    }
    $refused[] = str_contains($threw, 'could not be read (' . $why . ')') && file_get_contents($homeD . '/scopes.json') === $broken && !is_file($homeD . '/lock');
}
$check($refused === [true, true], 'a scopes.json that does not parse, or is not a list, stops the runtime, is left as it was, and leaves no lock');
$entries = [
    ['name' => 'typo', 'key' => strtoupper($teamKey), 'address' => $made['address']],
    ['name' => 'newline', 'address' => $made['address'] . "\n"],
    ['name' => 'team', 'key' => $teamKey, 'address' => $made['address'], 'note' => 'a field from a newer version'],
    ['name' => 'TEAM', 'address' => $made['address']],
    ['name' => 'wrong-pair', 'key' => Address::newScopeKey(), 'address' => $made['address']],
    'not an entry',
];
file_put_contents($homeD . '/scopes.json', json_encode($entries));
$d = new Runtime($homeD, 'https://fake.test', null, true, $quiet);
$usable = array_column($d->scopeList(), 'name');
$d->scopeNew('more');
$d->close();
$stored = json_decode((string) file_get_contents($homeD . '/scopes.json'), true);
$check($usable === ['team'] && array_column(array_slice($stored, 0, 2), 'name') === ['team', 'more'] && $stored[0]['note'] === 'a field from a newer version' && array_slice($stored, 2) === [$entries[0], $entries[1], $entries[3], $entries[4], $entries[5]], 'an entry this runtime cannot use is kept in the file as it was, beside the ones it can');
$homeE = $root . DIRECTORY_SEPARATOR . 'e';
mkdir($homeE, 0700, true);
file_put_contents($homeE . '/key', "not a seed\n");
$threw = '';
try {
    new Runtime($homeE, 'https://fake.test', null, true, $quiet);
} catch (\RuntimeException $error) {
    $threw = $error->getMessage();
}
$check(str_contains($threw, 'not a 64 character hex seed') && file_get_contents($homeE . '/key') === "not a seed\n", 'a key file that is not a key is never replaced by a new identity');

echo "mcp\n";
$server = new McpServer($a);
$init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
$check($init['result']['protocolVersion'] === '2025-06-18' && str_contains($init['result']['instructions'], 'signed stranger'), 'initialize answers with the same instructions as aamio-python');
$list = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
$check(count($list['result']['tools']) === 20 && $list['result']['tools'][0]['name'] === 'aamio_whoami', 'tools/list has the twenty tools');
$call = $server->handle(['jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call', 'params' => ['name' => 'aamio_scopes', 'arguments' => []]]);
$check($call['result']['isError'] === false && in_array('chapter-review', array_column($call['result']['structuredContent']['scopes'], 'name'), true) && !str_contains((string) json_encode($call), $teamKey), 'aamio_scopes lists names and never a key');
$call = $server->handle(['jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call', 'params' => ['name' => 'aamio_board_find', 'arguments' => ['scope' => 'nobody']]]);
$check($call['result']['isError'] === true && str_contains($call['result']['structuredContent']['error'], 'no scope called nobody'), 'a scope name that is not here is a tool error');
$check(str_contains($init['result']['instructions'], 'aamio_scope_share') && $init['result']['serverInfo']['version'] === Http::VERSION, 'and the instructions say how scopes are shared, from a server that names its own version');
$check(str_contains((string) json_encode($list['result']['tools'][0]), '"properties":{}'), 'a tool without arguments has properties {} on the wire, not []');
$call = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'aamio_whoami', 'arguments' => []]]);
$check($call['result']['structuredContent']['key'] === $a->keys->public && $call['result']['isError'] === false, 'aamio_whoami over MCP');
$call = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'aamio_send', 'arguments' => ['to' => 'Nobody', 'text' => 'x']]]);
$check($call['result']['isError'] === true && str_contains($call['result']['structuredContent']['error'], 'unknown partner'), 'a failing tool is a tool error, not a protocol error');
$check($server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'nope'])['error']['code'] === -32601, 'an unknown method is -32601');
$check($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) === null, 'a notification gets no reply');
$check($server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'aamio_pending', 'arguments' => []]])['result']['structuredContent']['count'] === 0, 'aamio_pending is empty once everything is settled');
$fake->silent = true;
$call = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'aamio_send', 'arguments' => ['to' => 'Bea', 'text' => 'void']]]);
$fake->silent = false;
$check($call['result']['isError'] === true && $call['result']['structuredContent']['error_code'] === 'send_unknown' && $call['result']['structuredContent']['retryable'] === null, 'an unknown send over MCP says send_unknown with the message id and no permission to retry');
ob_start();
$wrong = [
    $server->handle(['jsonrpc' => '2.0', 'id' => 30, 'method' => 'tools/call', 'params' => ['name' => 'aamio_open_channel', 'arguments' => ['label' => ['x'], 'ttl' => 60]]]),
    $server->handle(['jsonrpc' => '2.0', 'id' => 31, 'method' => 'tools/call', 'params' => ['name' => 'aamio_scope_add', 'arguments' => ['name' => 'x', 'key' => ['not', 'a', 'string']]]]),
    $server->handle(['jsonrpc' => '2.0', 'id' => 32, 'method' => 'tools/call', 'params' => ['name' => 'aamio_board_post', 'arguments' => ['kind' => 'need', 'title' => 't', 'text' => 'x', 'tags' => 'not-a-list']]]),
    $server->handle(['jsonrpc' => '2.0', 'id' => 33, 'method' => 'tools/call', 'params' => ['name' => 'aamio_scope_share', 'arguments' => ['name' => 'chapter-review', 'to' => $strangerW, 'access' => 'read']]]),
];
$printed = (string) ob_get_clean();
$check(array_filter($wrong, static fn (array $reply): bool => ($reply['result']['isError'] ?? null) !== true) === [] && $printed === '', 'an argument of the wrong type, or a share to an address, is a tool error, and nothing is printed beside the protocol', $printed);
$fake->explode = true;
$fell = $server->safely(['jsonrpc' => '2.0', 'id' => 34, 'method' => 'tools/call', 'params' => ['name' => 'aamio_board_tags', 'arguments' => []]]);
$fake->explode = false;
$check(($fell['id'] ?? null) === 34 && ($fell['error']['code'] ?? null) === -32603 && $server->safely(['jsonrpc' => '2.0', 'id' => 35, 'method' => 'ping'])['result'] instanceof \stdClass, 'a failure nobody expected is an internal error for that call, and the server answers the next one');

echo "hosts\n";
$check(Client::DEFAULT_HOST === Hosts::DEFAULT_HOST && Board::DEFAULT_HOST === Hosts::DEFAULT_BOARD && Runtime::VERIFYUM_MCP === Hosts::VERIFYUM_MCP, 'the defaults live in Hosts, and the old names point there');
putenv('AAMIO_BOARD=https://board.elsewhere.test');
putenv('AAMIO_VERIFYUM=https://verifyum.elsewhere.test/mcp/');
$homeC = $root . DIRECTORY_SEPARATOR . 'c';
mkdir($homeC, 0700, true);
$c = new Runtime($homeC, 'https://elsewhere.test', null, true, $quiet);
putenv('AAMIO_BOARD');
putenv('AAMIO_VERIFYUM');
$check($c->host === 'https://elsewhere.test' && $c->board->host === 'https://board.elsewhere.test' && $c->verifyum === 'https://verifyum.elsewhere.test/mcp', 'AAMIO_BOARD and AAMIO_VERIFYUM point the runtime elsewhere, beside the host it was given');
$fake->calls = [];
$c->boardFind('need');
$c->ensureInbox();
$c->receipt('inbox', true);
$urls = array_column($fake->calls, 1);
$check(array_filter($urls, static fn (string $u): bool => str_starts_with($u, 'https://board.elsewhere.test/')) !== [] && in_array('https://verifyum.elsewhere.test/mcp', $urls, true) && array_filter($urls, static fn (string $u): bool => str_contains($u, 'aamio.at')) === [], 'and every call goes there, none to aamio.at', implode(' ', array_unique(array_map(static fn (string $u): string => (string) parse_url($u, PHP_URL_HOST), $urls))));
$c->close();

$a->close();
$b->close();
Http::$override = null;
exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /S /Q "' . $root . '"' : 'rm -rf "' . $root . '"');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
