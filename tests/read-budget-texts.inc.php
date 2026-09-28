<?php

declare(strict_types=1);

/*
 * What the read budget says, against what this runtime does.
 *
 * The description of max_bytes came from aamio-python, where a listener has
 * fetched a message before anyone reads, so one larger than the budget "is
 * handed over on its own ... because it is already on this machine". This
 * runtime has no listener. It asks the service with the budget, the service
 * answers too_large and sends nothing, and what was written after that
 * message waits behind it. The text promised a message that never came, the
 * README named a state this runtime never reports, and the description said
 * its first sentence twice. Found in a review on 28 September 2026.
 *
 * The note in attention carried the service's advice word for word: a header
 * by name and a cursor to step past the message with. Whoever reads the note
 * set max_bytes and holds neither.
 *
 * Included by tests/runtime.php with $fake, $root and $check in scope.
 */

use Aamio\McpServer;
use Aamio\Runtime;

echo "the read budget: what the texts say, and that it is so\n";

$budgetSpec = json_decode((string) file_get_contents(dirname(__DIR__) . '/src/mcp-tools.json'), true);
$budgetTool = array_values(array_filter($budgetSpec['tools'], static fn (array $t): bool => $t['name'] === 'aamio_read'))[0];
$budgetSaid = (string) $budgetTool['inputSchema']['properties']['max_bytes']['description'];
$budgetReadme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
$budgetHelp = (string) file_get_contents(dirname(__DIR__) . '/bin/aamio');

$check(substr_count($budgetSaid, 'how many bytes of messages to hand over') === 1, 'the description of max_bytes says its first sentence once', $budgetSaid);
$check(
    !str_contains($budgetSaid, 'handed over on its own') && !str_contains($budgetSaid, 'already on this machine')
    && str_contains($budgetSaid, 'is not handed over') && str_contains($budgetSaid, 'too_large')
    && str_contains($budgetSaid, 'waits behind it') && str_contains($budgetSaid, 'larger max_bytes'),
    'and says that a message larger than the budget stays at the service, with the way to take it',
    $budgetSaid
);
$check(
    !str_contains($budgetReadme, 'over_budget') && !str_contains($budgetReadme, 'already on this machine')
    && str_contains($budgetReadme, 'This runtime has no listener, so nothing is fetched ahead of a read'),
    'the README names no state this runtime never reports'
);
$check(!str_contains($budgetHelp, 'still comes') && str_contains($budgetHelp, 'a message larger than it stays at the service'), 'and the help of the command line says the same');

// ---------------------------------------------------------------- and that it is so
$budgetQuiet = static function (string $line): void {
};
$budgetHomes = [];
foreach (['writer', 'reader'] as $who) {
    $budgetHomes[$who] = $root . DIRECTORY_SEPARATOR . 'budget-texts-' . $who;
    mkdir($budgetHomes[$who], 0700, true);
}
$budgetWriter = new Runtime($budgetHomes['writer'], 'https://fake.test', ['writer'], false, $budgetQuiet);
$budgetReader = new Runtime($budgetHomes['reader'], 'https://fake.test', ['reader'], false, $budgetQuiet);
$budgetWriter->partnerAdd('Reader', $budgetReader->keys->public);
$budgetReader->partnerAdd('Writer', $budgetWriter->keys->public);
$budgetWriter->ensureInbox();
$budgetInbox = $budgetReader->ensureInbox();
$budgetWriter->peers[$budgetInbox->w] = $budgetReader->keys->public;
$budgetWriter->send($budgetInbox->w, str_repeat('large ', 400));
$budgetWriter->send($budgetInbox->w, 'small, after the large one');

$budgetSmall = (new McpServer($budgetReader))->dispatch('aamio_read', ['wait' => 0, 'max_bytes' => 1024]);
$budgetGot = $budgetSmall['structuredContent'];
$budgetNotes = array_column($budgetGot['attention'] ?? [], 'what', 'state');
$check(
    ($budgetSmall['isError'] ?? true) === false && $budgetGot['messages'] === [] && isset($budgetNotes['too_large'])
    && $budgetReader->channels['inbox']->after === 0,
    'a message larger than the budget is not handed over, the one behind it waits, and the cursor stays before both',
    (string) json_encode($budgetGot)
);
$budgetNote = (string) ($budgetNotes['too_large'] ?? '');
$check(
    str_contains($budgetNote, 'max_bytes') && str_contains($budgetNote, '--max-bytes') && !str_contains($budgetNote, 'X-Max-Bytes')
    && !str_contains($budgetNote, 'pass its seq as after') && str_contains($budgetNote, 'what was written after it waits behind it'),
    'and the note says what its reader can do, with no header it never set and no cursor it does not hold',
    $budgetNote === '' ? 'no note' : $budgetNote
);

$budgetAgain = (new McpServer($budgetReader))->dispatch('aamio_read', ['wait' => 0, 'max_bytes' => 1024])['structuredContent'];
$check(
    $budgetAgain['messages'] === [] && in_array('too_large', array_column($budgetAgain['attention'] ?? [], 'state'), true)
    && $budgetReader->channels['inbox']->after === 0,
    'the same read again is the same answer, said again',
    (string) json_encode($budgetAgain)
);

$budgetWhole = (new McpServer($budgetReader))->dispatch('aamio_read', ['wait' => 0, 'max_bytes' => 8192])['structuredContent'];
$budgetTexts = array_map(static fn (array $entry): string => (string) ($entry['body']['text'] ?? ''), $budgetWhole['messages']);
$check(
    count($budgetTexts) === 2 && str_starts_with($budgetTexts[0], 'large large') && $budgetTexts[1] === 'small, after the large one'
    && !in_array('too_large', array_column($budgetWhole['attention'] ?? [], 'state'), true),
    'a larger max_bytes takes both, in the order they were written',
    (string) json_encode(array_map(static fn (string $text): string => substr($text, 0, 30), $budgetTexts))
);
$budgetWriter->close();
$budgetReader->close();
