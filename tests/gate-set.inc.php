<?php

declare(strict_types=1);

/**
 * Setting a gate, from the side that owns the inbox.
 *
 * The rest of the gate testing here is the writer's side: an inbox has
 * conditions and this client works out whether to meet them. This is the
 * other side, and it did not exist.
 *
 * llms.txt advises an agent, in these words, to open an inbox that asks for
 * work: {"ttl": 600, "allow": ["*"], "gate": {"require": {"pow": {"bits":
 * 20}, "per_key": 5}}}. Client::open has taken a gate since 16 September, and
 * so have the Go, Rust, Java, .NET and JS clients. Runtime::openChannel did
 * not, nor `aamio channel open`, nor the local aamio_open_channel -- the three
 * surfaces someone running this package actually uses. The gate stopped at the
 * HTTP layer, one call below where anybody stands.
 *
 * A gate is set when the inbox is opened and never changes. A surface that
 * cannot set one leaves the inbox open for its whole life without the
 * conditions its owner meant it to have, and nothing says so.
 */

use Aamio\McpServer;

$gateAdvice = ['require' => ['pow' => ['bits' => 20], 'per_key' => 5]];

$gated = $a->openChannel('gated-room', 600, null, $gateAdvice);
$check(($fake->threads[$gated['w']]['gate'] ?? null) === $gateAdvice, 'a channel opened with a gate reaches the service with it', json_encode($fake->threads[$gated['w']]['gate'] ?? null));
$check(($gated['gate'] ?? 'missing') === $gateAdvice, 'and the answer says which gate the channel was opened with');

$plain = $a->openChannel('plain-room', 600);
$check(array_key_exists('gate', $plain) && $plain['gate'] === null, 'a channel opened without one says so rather than leaving the field out, since an owner cannot read silence');
$check(($fake->threads[$plain['w']]['gate'] ?? null) === [], 'and nothing was sent that the service had to interpret');

$gateRefused = [];
foreach ([['require'], 'require', 42, ['needs' => ['pow' => ['bits' => 20]]], ['require' => 20], []] as $n => $bad) {
    try {
        $a->openChannel('bad-gate-' . $n, 600, null, $bad);
        $gateRefused[] = 'accepted: ' . json_encode($bad);
    } catch (\InvalidArgumentException $wrong) {
        if (!str_contains($wrong->getMessage(), 'require')) {
            $gateRefused[] = 'no shape in the message: ' . $wrong->getMessage();
        }
    }
}

$check($gateRefused === [], 'a gate that is not a gate is refused before the inbox is opened, with the shape in the message', implode(' / ', $gateRefused));
$check(!isset($a->channels['bad-gate-0']), 'and no channel was left behind by the refusal');

$gateTool = null;
foreach (json_decode((string) file_get_contents(__DIR__ . '/../src/mcp-tools.json'), true)['tools'] as $t) {
    if ($t['name'] === 'aamio_open_channel') {
        $gateTool = $t;
    }
}

$check(isset($gateTool['inputSchema']['properties']['gate']), 'the local open tool takes a gate, as the hosted one does');
$check(str_contains(strtolower((string) ($gateTool['description'] ?? '')), 'gate'), 'and says so where a model reads it');

$gateMcp = (new McpServer($a))->dispatch('aamio_open_channel', ['label' => 'mcp-gated', 'ttl' => 600, 'gate' => $gateAdvice]);
$check(($gateMcp['isError'] ?? true) === false && ($fake->threads[$gateMcp['structuredContent']['w']]['gate'] ?? null) === $gateAdvice, 'and passes it through to the service', json_encode($gateMcp['structuredContent'] ?? null));

$gateBad = (new McpServer($a))->dispatch('aamio_open_channel', ['label' => 'mcp-bad', 'ttl' => 600, 'gate' => 'require']);
$check(($gateBad['isError'] ?? false) === true && str_contains((string) ($gateBad['structuredContent']['fix'] ?? ''), 'require'), 'a wrong gate over MCP is a refusal with a fix, not an inbox that is already open', json_encode($gateBad['structuredContent'] ?? null));
$check(!isset($a->channels['mcp-bad']), 'and that refusal left no channel either');

// A home is one folder and two runtimes can read it, which this file's own comment
// on outboxOutcome already says. Python writes a 'stopped' entry for work that was
// interrupted before anything left; here that fell through to the attempts count,
// and attempts counts the call the work runs inside, so a message that never left
// read as one that had. The safe direction, and still wrong.
$stoppedOutcome = \Aamio\Runtime::outboxOutcome(['id' => 'm-stopped', 'status' => 'stopped', 'attempts' => 1]);
$check($stoppedOutcome === 'never_sent', 'work stopped before anything left is never_sent, not attempted', $stoppedOutcome);
$stoppedAway = \Aamio\Runtime::outboxOutcome(['id' => 'm-away', 'status' => 'stopped', 'attempts' => 1, 'posting' => true]);
$check($stoppedAway === 'attempted', 'and one whose bytes had left is attempted, whatever the status was rewritten to', $stoppedAway);
