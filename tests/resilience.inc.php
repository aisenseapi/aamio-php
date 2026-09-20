<?php

declare(strict_types=1);

// Included by runtime.php, sharing only its isolated fake and temporary homes.
use Aamio\Channel;
use Aamio\Client;
use Aamio\Http;
use Aamio\Keys;
use Aamio\Receipt;
use Aamio\Runtime;

echo "reader resilience and durable rotation\n";
$vectors = json_decode((string) file_get_contents(__DIR__ . '/vectors.json'), true);
$check(Keys::verify($vectors['a']['public'], $vectors['strayBits']['signature'], $vectors['signInput']) && Keys::verify($vectors['strayBits']['key'], $vectors['signature'], $vectors['signInput']), 'historical trailing bits verify as the same bytes');
$check(Receipt::verify(['messages' => [], 'root' => Receipt::root([])], ['seen'])['local_hashes_match'] === false, 'a shortened receipt is not a matching prefix');
$headersSent = [];
Http::$override = static function ($method, $url, $body, $headers) use (&$headersSent): array { $headersSent[] = $headers; return [201, ['allow' => ['*']]]; };
$normalized = $a->client->open(600, [' ' . $b->keys->public . ', ', '', $b->keys->public]);
$check($normalized['allow'] === [$b->keys->public] && $headersSent[0]['X-Allow'] === $b->keys->public, 'open sends and retains its normalized policy, not the echo');
try { $a->client->open(600, ['*', null]); $check(false, 'null must refuse'); } catch (InvalidArgumentException) { $check(count($headersSent) === 1, 'null is rejected before any request'); }
$check(Client::normalizeAllow([' ', '*', $b->keys->public]) === ['*'] && Client::normalizeAllow([' ']) === [], 'wildcard and empty list normalize consistently');

Http::$override = $fake;
foreach (['inbox', 'board'] as $label) {
    $rotationHome = $root . '/rotation-' . $label;
    $runtime = new Runtime($rotationHome, 'https://fake.test', null, false, $quiet);
    $old = new Channel($label, 'old-read-key', str_repeat('o', 20), time() + 60, [$b->keys->public]);
    $old->seen[str_repeat('a', 64)] = true;
    $runtime->channels[$label] = $old;
    $new = $label === 'inbox' ? $runtime->ensureInbox() : $runtime->ensureBoardInbox(900);
    $new->seen[str_repeat('b', 64)] = true;
    $newKey = $new->readKey;
    $runtime->saveState(); $runtime->close();
    $runtime = new Runtime($rotationHome, 'https://fake.test', null, false, $quiet);
    $retired = array_values(array_filter($runtime->channels, static fn (Channel $c): bool => $c->readKey === 'old-read-key'));
    $check(count($runtime->channels) === 2 && $runtime->channels[$label]->readKey === $newKey && isset($runtime->channels[$label]->seen[str_repeat('b', 64)]) && count($retired) === 1 && $retired[0]->label !== $label && $retired[0]->allow === [$b->keys->public] && isset($retired[0]->seen[str_repeat('a', 64)]), $label . ' rotation retains both keys, policies and replay sets after restart');
    $runtime->channels[$label]->gone = true; $runtime->saveState(); $runtime->close();
    $runtime = new Runtime($rotationHome, 'https://fake.test', null, false, $quiet);
    $check($runtime->channels[$label]->gone, $label . ' missing state survives a reload');
    $runtime->close();
    $state = json_decode((string) file_get_contents($rotationHome . '/state.json'), true);
    foreach ($state['channels'] as &$item) { $item['label'] = $label; } unset($item);
    file_put_contents($rotationHome . '/state.json', json_encode($state));
    $runtime = new Runtime($rotationHome, 'https://fake.test', null, false, $quiet);
    $check(count($runtime->channels) === 2 && $runtime->channels[$label]->readKey === $newKey, 'legacy duplicate labels never overwrite the newer ' . $label);
    $runtime->close();
}

$good = ['seq' => 3, 'at' => 3, 'body' => 'perform once', 'sha256' => hash('sha256', 'perform once'), 'from' => $b->keys->public, 'sig' => $b->keys->sign(Keys::threadSigningInput($openW, 'perform once')), 'verified' => true];
$bad = ['seq' => 1, 'at' => 1, 'body' => null, 'sha256' => $good['sha256'], 'verified' => true, 'from' => $b->keys->public];
$messages = [$bad, array_replace($bad, ['seq' => 2, 'sha256' => []]), $good];
Http::$override = static fn () => [200, ['exists' => true, 'messages' => $messages, 'next' => 3]];
$channel = new Channel('resilience', 'key', $openW, time() + 600);
$a->channels['resilience'] = $channel;
[, $entries] = $a->poll($channel);
$check(count($entries) === 3 && $entries[0]['sha256'] === null && $entries[1]['sha256'] === null && $entries[2]['verified'] && !$entries[2]['replay'] && count($channel->seen) === 1 && $channel->after === 3, 'malformed bodies cannot poison replay or stop the next valid message');
$channel->createdAt = 123;
Http::$override = static fn () => [200, '<html>proxy page</html>'];
$a->attentionTaken();
[$state] = $a->poll($channel);
$check($state === 'error' && $channel->createdAt === 123 && $a->attentionTaken()[0]['state'] === 'unread', 'malformed read answer is not an empty inbox and does not erase thread identity');

$forgedMessage = array_replace($good, ['seq' => 1, 'sig' => $stranger->sign(Keys::threadSigningInput($openW, $good['body']))]);
$counter = 0;
Http::$override = static function () use (&$counter, $forgedMessage): array { $counter++; return [200, ['messages' => [array_replace($forgedMessage, ['seq' => $counter])], 'next' => $counter]]; };
$channel->allow = [$b->keys->public]; $channel->forgetThread(); $a->attentionTaken();
$a->poll($channel); $a->poll($channel);
$attention = array_column($a->attentionTaken(), null, 'state');
$check($attention['kept_out']['count'] === 2 && $attention['kept_out']['seqs'] === [1, 2] && $attention['unverified']['count'] === 2 && str_contains($attention['unverified']['what'], 'kept out'), 'attention accumulates counts and sequences and states the kept-out disposition');
Http::$override = static fn () => [200, ['messages' => [$forgedMessage, array_replace($bad, ['seq' => 2])], 'next' => 2]];
$boardRead = $a->board->repliesThread(['id' => 'key', 'w' => $openW, 'allow' => ['*']]);
$check($boardRead['replies'] === [] && count($boardRead['kept_out']) === 2 && str_contains($boardRead['kept_out'][0]['unverified_because'], 'signature'), 'board reader enforces local policy and retains verification reasons');
$check(count($a->board->replies($openW, 'key')['replies']) === 2, 'compatibility board reader remains explicitly listless');

$channel->observed = []; $channel->after = 0;
Http::$override = static fn () => [200, ['messages' => [$forgedMessage], 'next' => 1]];
$a->poll($channel);
$lines = [array_intersect_key($forgedMessage, array_flip(['seq', 'at', 'sha256', 'from']))];
$receipt = ['count' => 1, 'messages' => $lines, 'keys' => [$b->keys->public], 'root' => Receipt::root($lines)];
Http::$override = static fn () => [200, $receipt];
$taken = $a->receipt('resilience');
$check($taken['held_locally'] === 1 && $taken['local_root_matches'] === true && $taken['keys'] === [$b->keys->public] && $taken['keys_unverified_count'] === 1 && $taken['signers_not_verified_locally'] === [1], 'receipt observes kept-out metadata without turning a forged key claim into a contact name');
$receipt['messages'][0]['at'] = 999; $receipt['root'] = Receipt::root($receipt['messages']);
Http::$override = static fn () => [200, $receipt];
$taken = $a->receipt('resilience');
$check($taken['local_root_matches'] === false && $taken['local_differences'] === [['seq' => 1, 'fields' => ['at']]], 'receipt names the locally differing field');
$receipt = ['count' => 0, 'messages' => [], 'keys' => [], 'root' => Receipt::root([])];
Http::$override = static fn () => [200, $receipt];
$check($a->receipt('resilience')['local_root_matches'] === false, 'runtime reports fewer receipt lines as a mismatch');
