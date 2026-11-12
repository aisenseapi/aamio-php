<?php

declare(strict_types=1);

/*
 * Three things found while the Node runtime's archive and channels were built
 * (a check of 12 November 2026).
 *
 * A channel's label was a file's name as it stood: ../../notes put what was
 * read on that channel outside the home. A record that could not be written
 * after the thing itself was done was thrown, so an answer on the board that
 * was delivered was reported as an error, with the advice to call again. And a
 * close the service did not answer took the channel out of the home while the
 * thread lived on, so whoever wrote there wrote where nobody read. Each is
 * tried here against the fake service, with the case that has to keep working
 * beside it, as aamio-python tries the same in tests/test_check_2026_11_12.py.
 *
 * Included by tests/runtime.php, with $fake, $root and $check in scope.
 */

use Aamio\Channel;
use Aamio\Codec;
use Aamio\Http;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;
use Aamio\SendFailed;
use Aamio\Storage;

echo "a label is a file's name, a record of what is done already, and a close the service did not confirm\n";

$checkQuiet = static function (string $line): void {
};
$checkOpen = [];

/** Two runtimes that know each other, in homes of their own, each keeping an archive. */
$checkPair = static function (string $name) use ($root, $checkQuiet, &$checkOpen): array {
    $homes = [];
    foreach (['a', 'b'] as $letter) {
        $homes[$letter] = $root . DIRECTORY_SEPARATOR . 'check1112-' . $name . '-' . $letter;
        mkdir($homes[$letter], 0700, true);
    }
    $one = new Runtime($homes['a'], 'https://fake.test', ['a'], true, $checkQuiet);
    $two = new Runtime($homes['b'], 'https://fake.test', ['b'], true, $checkQuiet);
    $one->partnerAdd('b', $two->keys->public);
    $two->partnerAdd('a', $one->keys->public);
    $one->ensureInbox();
    $two->ensureInbox();
    $checkOpen[] = $one;
    $checkOpen[] = $two;

    return [$one, $two, $homes];
};

/** A sealed and signed message written straight at a thread, the way any client could write it. */
$checkDirect = static function (Runtime $from, string $w, string $sealTo, string $text) use ($fake): void {
    $wire = $from->keys->seal($sealTo, Codec::json(['text' => $text]));
    $seq = count($fake->threads[$w]['messages']) + 1;
    $fake->threads[$w]['messages'][] = ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $wire, 'sha256' => hash('sha256', $wire), 'from' => $from->keys->public, 'sig' => $from->keys->sign(Keys::threadSigningInput($w, $wire)), 'verified' => true, 'sealed' => true];
};

/** One file of the archive that cannot be written until the answer is called: a folder stands where the file goes. */
$checkBlock = static function (Runtime $runtime, string $name): callable {
    $file = $runtime->home . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . $name . '.jsonl';
    $kept = is_file($file) ? $file . '.kept' : null;
    if ($kept !== null) {
        rename($file, $kept);
    }
    mkdir($file);

    return static function () use ($file, $kept): void {
        rmdir($file);
        if ($kept !== null) {
            rename($kept, $file);
        }
    };
};

/** What attention holds about the archive, taken. */
$checkArchiveNotes = static fn (Runtime $runtime): array => array_values(array_map(static fn (array $note): string => $note['what'], array_filter($runtime->attentionTaken(), static fn (array $note): bool => [$note['channel'], $note['state']] === ['archive', 'archive_failed'])));

/** Every .jsonl under a folder that is not directly in a folder called archive. */
$checkStrays = static function (string $base): array {
    $strays = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.jsonl') && basename($file->getPath()) !== 'archive') {
            $strays[] = $file->getPathname();
        }
    }

    return $strays;
};

$checkTexts = static fn (array $entries): array => array_map(static fn (array $entry): ?string => $entry['body']['text'] ?? null, $entries);

/** What a call threw, or null. */
$checkThrown = static function (callable $call): ?\Throwable {
    try {
        $call();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
};

// ---------------------------------------------------------------- a label is a file's name
$checkUnsafe = ['../outside-the-archive', '../../outside-the-home', 'deep/er', 'back\\slash', 'with:colon', 'con', 'NUL', 'nul.txt', 'COM1', 'lpt9.log', 'trailing.', '.hidden', '', ' ', 'a b', str_repeat('x', 65), "tab\t", "new\nline", 'ünïcode', '*', 'a?b'];

[$a, $b] = $checkPair('labels');
$threads = count($fake->threads);
$held = array_keys($a->channels);
$refused = 0;
foreach ($checkUnsafe as $label) {
    foreach ([static fn () => $a->openChannel($label, 600, ['b']), static fn () => $label === '' ? Runtime::checkLabel($label) : $a->openChannelWith($b->keys->public, 600, $label)] as $try) {
        $error = $checkThrown($try);
        $refused += $error instanceof \InvalidArgumentException && str_starts_with($error->getMessage(), "a channel's label is 1 to 64 letters, digits, dots, dashes and underscores, starting with a letter or a digit") ? 1 : 0;
    }
}
$check($refused === 2 * count($checkUnsafe) && count($fake->threads) === $threads && array_keys($a->channels) === $held, "a label that cannot be a file's name opens no channel, with a key or without: nothing is opened, and the home is as it was", $refused . ' of ' . 2 * count($checkUnsafe) . ' refused');

$fine = 0;
foreach (['tender', 'tender-2026.Q4', 'a', 'With_under-score.1', str_repeat('x', 64), 'inb', 'boar', '2026'] as $label) {
    $fine += $checkThrown(static fn () => Runtime::checkLabel($label)) === null ? 1 : 0;
}
$own = 0;
foreach (['inbox', 'inbox-1800000000', 'Inbox2', 'board', 'BOARD-x', 'boardroom'] as $label) {
    $error = $checkThrown(static fn () => $a->openChannel($label, 600));
    $own += $error instanceof \InvalidArgumentException && str_contains($error->getMessage(), 'a label that starts with inbox or board names an inbox or a board inbox') ? 1 : 0;
}
$other = 0;
foreach ([7, null, ['tender']] as $value) {
    $error = $checkThrown(static fn () => Runtime::checkLabel($value));
    $other += $error instanceof \InvalidArgumentException && str_starts_with($error->getMessage(), "a channel's label is 1 to 64 letters") ? 1 : 0;
}
$check($fine === 8 && $own === 6 && $other === 3 && count($fake->threads) === $threads, "a label of letters, digits, dots, dashes and underscores is taken, the runtime's own labels are refused, and what is not a string is no label", "$fine $own $other");

$a->openChannel('tender', 600, ['b']);
$threads = count($fake->threads);
$taken = 0;
foreach (['tender', 'TENDER', 'Tender'] as $label) {
    $error = $checkThrown(static fn () => $a->openChannel($label, 600));
    $taken += $error instanceof \InvalidArgumentException && $error->getMessage() === 'there is a channel called tender already. Close it or choose another label' ? 1 : 0;
}
$check($taken === 3 && count($fake->threads) === $threads, 'a label names one channel apart from upper and lower case: the two would be one file in the archive where the file system says so');
$with = $a->openChannelWith($b->keys->public, 600, 'Tender');
$check(preg_match('/\ATender-\d+\z/', $with['label']) === 1 && isset($a->channels[$with['label']]), 'and a channel opened with a key under a label that is taken apart from case gets a name of its own', $with['label']);

// A label of digits is a number as a key in a PHP array, and board replies read
// the archive by the keys: it stopped with a TypeError once such a channel was held.
$a->openChannel('2026', 600);
$error = $checkThrown(static fn () => $a->boardReplies());
$closed = $a->closeChannel('2026');
$check($error === null && $closed['closed_here'] === true && $closed['label'] === '2026', 'a label of digits is a label like any other: board replies read the archive with it held, and it is closed by its label', $error === null ? '' : get_class($error) . ': ' . $error->getMessage());

$same = 0;
foreach (['inbox', 'inbox-1800000000', 'board-1800000000-1', 'with-0a1b2c3d', 'tender.2026_Q4', 'A'] as $label) {
    $same += Storage::isSafeLabel($label) && Storage::archiveName($label) === $label ? 1 : 0;
}
$hashed = 0;
foreach ($checkUnsafe as $label) {
    $hashed += !Storage::isSafeLabel($label) && Storage::archiveName($label) === 'channel-' . substr(hash('sha256', $label), 0, 16) ? 1 : 0;
}
$none = 0;
foreach ([null, 7, ['inbox'], new \stdClass()] as $value) {
    $none += Storage::isSafeLabel($value) ? 0 : 1;
}
$check($same === 6 && $hashed === count($checkUnsafe) && $none === 4, "the file of a label is the label when that can be a file's name, and a name made of its hash when it cannot", "$same $hashed $none");
// The names aamio-python and the Node runtime give the same labels: a home has one file for a channel.
$check(
    Storage::archiveName('../../outside-the-home') === 'channel-7285706e4214455f' && Storage::archiveName('with:colon') === 'channel-190de318d1fd8776' && Storage::archiveName('con') === 'channel-1143da2bc54c495c'
        && Storage::archiveName('a b') === 'channel-c8687a08aa5d6ed2' && Storage::archiveName('ünïcode') === 'channel-b8be8967e4de3eb2',
    'and it is the name the other two runtimes give the same label',
    implode(' ', array_map(static fn (string $label): string => Storage::archiveName($label), ['../../outside-the-home', 'with:colon', 'con', 'a b', 'ünïcode']))
);

foreach (['../../outside-the-home', 'with:colon', 'deep/er', 'a b'] as $n => $label) {
    [$a, $b, $homes] = $checkPair('old-label-' . $n);
    // As an earlier version opened it, and left it in state.json: the label as it was given.
    $opened = $a->client->open(600, [$b->keys->public]);
    $a->channels[$label] = new Channel($label, $opened['id'], $opened['w'], (int) $opened['body']['expire_at'], [$b->keys->public]);
    $checkDirect($b, $opened['w'], $a->keys->public, 'a private line');

    [$state, $entries] = $a->poll($a->channels[$label], 0);

    $archive = $homes['a'] . DIRECTORY_SEPARATOR . 'archive';
    $file = $archive . DIRECTORY_SEPARATOR . Storage::archiveName($label) . '.jsonl';
    $rows = is_file($file) ? array_map(static fn (string $line): array => (array) json_decode($line, true), (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    $listed = array_values(array_diff((array) scandir($archive), ['.', '..']));
    $check(
        $checkTexts($entries) === ['a private line'] && ($entries[0]['archived'] ?? null) === true && count($rows) === 1 && ($rows[0]['channel'] ?? null) === $label && ($rows[0]['body']['text'] ?? null) === 'a private line'
            && $checkStrays($root) === [] && array_filter($listed, static fn (string $name): bool => !str_ends_with($name, '.jsonl')) === [] && $checkTexts($a->archived($label, 'received')) === ['a private line'],
        'a channel an earlier version let through as ' . json_encode($label) . ' is written down inside the archive, under no name the file system hides, and read from there',
        json_encode(['archived' => $entries[0]['archived'] ?? null, 'listed' => $listed, 'strays' => array_map('basename', $checkStrays($root))])
    );
}

[$a, , $homes] = $checkPair('old-file');
$row = ['kind' => 'received', 'channel' => 'board notes', 'w' => str_repeat('w', 20), 'seq' => 1, 'at' => 1, 'sha256' => str_repeat('a', 64), 'from_key' => 'k', 'verified' => true, 'body' => ['post' => 'p1', 'text' => 'written down before']];
file_put_contents($homes['a'] . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'board notes.jsonl', json_encode($row) . "\n");
$check(
    $checkTexts($a->boardReplies('p1')) === ['written down before'] && $checkTexts($a->archived('board notes', 'received')) === ['written down before'] && $a->archived('../archive/board notes', 'received') === [],
    'a file an earlier version wrote under a label as it stood is still read, by the name the folder lists it under, and a path is never such a name'
);
// And with the file this version writes for the same label beside it, each is
// read once: the first form of this read the new one twice and the old one never.
$newer = ['seq' => 2, 'at' => 2, 'sha256' => str_repeat('b', 64), 'body' => ['post' => 'p1', 'text' => 'written down now']] + $row;
file_put_contents($homes['a'] . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . Storage::archiveName('board notes') . '.jsonl', json_encode($newer) . "\n");
$check($checkTexts($a->boardReplies('p1')) === ['written down before', 'written down now'], 'and with the file this version writes for the same label beside it, each is read once', json_encode($checkTexts($a->boardReplies('p1'))));

// A home in a folder with a bracket in its name: to glob the path was a pattern,
// no file of the archive matched it, and what was written down for a board
// inbox that had expired was left out of board replies with nothing said.
$bracketHome = $root . DIRECTORY_SEPARATOR . 'check1112-[bracket]';
mkdir($bracketHome, 0700, true);
$bracket = new Runtime($bracketHome, 'https://fake.test', ['c'], true, $checkQuiet);
$checkOpen[] = $bracket;
file_put_contents($bracketHome . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'board-1700000000.jsonl', json_encode(['channel' => 'board-1700000000'] + $row) . "\n");
$check($checkTexts($bracket->boardReplies('p1')) === ['written down before'], 'the answers written down for a board inbox that has expired are listed from a home whose folder has a bracket in its name');

// ---------------------------------------------------------------- a record of what is done already
[$a, $b] = $checkPair('done');
$wanted = $b->boardPost('need', 'made', 'and answered');
$unblock = $checkBlock($a, 'board');
$error = $checkThrown(static function () use ($a, $wanted, &$answer): void {
    $answer = $a->boardAnswer($wanted['post']['id'], 'I can');
});
$notes = $checkArchiveNotes($a);
$check(
    $error === null && ($answer['seq'] ?? null) === 1 && str_contains((string) ($answer['archive_error'] ?? ''), 'could not append') && count($fake->threads[$wanted['inbox']]['messages']) === 1
        && array_column($a->outbox, 'status') === ['delivered'],
    'an answer on the board that is delivered is not an error when the archive cannot be written: it says the answer, and what could not be recorded beside it',
    $error === null ? json_encode(array_intersect_key((array) $answer, ['seq' => 1, 'archive_error' => 1])) : get_class($error) . ': ' . $error->getMessage()
);
$check(
    count($notes) === 1 && str_starts_with($notes[0], 'the answer ' . ($answer['message_id'] ?? '?') . ' could not be written to the archive: could not append') && str_ends_with($notes[0], 'The archive does not have it.'),
    'and attention says so, once',
    json_encode($notes)
);
$unblock();
$second = $a->boardAnswer($wanted['post']['id'], 'and again');
$check(!array_key_exists('archive_error', $second) && $checkArchiveNotes($a) === [], 'one that is written down says nothing of it');

$unblock = $checkBlock($a, 'board');
$posted = $a->boardPost('need', 'made', 'and not written down');
$overMcp = (new McpServer($a))->dispatch('aamio_board_post', ['kind' => 'need', 'title' => 'over mcp', 'text' => 'made as well']);
$mine = array_filter($fake->posts, static fn (array $post): bool => $post['key'] === $a->keys->public);
$check(
    isset($fake->posts[$posted['post']['id']]) && str_contains((string) ($posted['archive_error'] ?? ''), 'could not append') && ($overMcp['isError'] ?? null) === false
        && str_contains((string) ($overMcp['structuredContent']['archive_error'] ?? ''), 'could not append') && count($mine) === 2,
    'a post that is made says so with what could not be recorded, on the command line and over MCP, and each post is on the board once'
);
$withdrawn = $a->boardWithdraw($posted['post']['id']);
$check(($withdrawn['deleted'] ?? null) === true && ($withdrawn['id'] ?? null) === $posted['post']['id'] && str_contains((string) ($withdrawn['archive_error'] ?? ''), 'could not append') && !isset($fake->posts[$posted['post']['id']]), 'so does a withdrawal');
$unblock();
$checkDirect($b, $a->channels['inbox']->w, $a->keys->public, 'one line');
$a->poll($a->channels['inbox'], 0);
$unblock = $checkBlock($a, 'inbox');
$receipt = $a->receipt('inbox');
$unblock();
$check($receipt['count'] === 1 && $receipt['root_adds_up'] === true && str_contains((string) ($receipt['archive_error'] ?? ''), 'could not append'), 'and a receipt that is taken');
// Attention keeps one note for the archive, and each record that is missing is in it.
$notes = $checkArchiveNotes($a);
$check(
    count($notes) === 1 && substr_count($notes[0], 'could not be written to the archive: could not append') === 4 && str_ends_with($notes[0], 'The archive does not have them.')
        && [substr_count($notes[0], 'the post '), substr_count($notes[0], 'the withdrawal of post '), substr_count($notes[0], 'the receipt for inbox ')] === [2, 1, 1],
    'attention keeps one note for the archive, and each record that is missing is in it',
    json_encode($notes)
);
$again = $a->boardPost('need', 'kept', 'and written down');
$check(
    !array_key_exists('archive_error', $again) && !array_key_exists('archive_error', $a->boardWithdraw($again['post']['id'])) && !array_key_exists('archive_error', $a->receipt('inbox')) && $checkArchiveNotes($a) === [],
    'written down, none of them says anything of it'
);

[$a, $b] = $checkPair('send');
$target = $b->channels['inbox']->w;
$a->peers[$target] = $b->keys->public;
$unblock = $checkBlock($a, 'sent');
$fake->refuse = true;
$turned = $checkThrown(static fn () => $a->send($target, 'turned away, and not written down'));
$overMcp = (new McpServer($a))->dispatch('aamio_send', ['to' => $target, 'text' => 'the same over the local server']);
$fake->refuse = false;
$unblock();
$check(
    $turned instanceof SendFailed && [$turned->outcome, $turned->status] === ['refused', 403] && str_contains((string) $turned->archiveError, 'could not append') && array_keys($turned->archiveSaid()) === ['archive_error'],
    'a send that failed says the archive that could not be written too',
    $turned === null ? 'nothing was thrown' : get_class($turned) . ': ' . $turned->getMessage()
);
$check(
    ($overMcp['isError'] ?? null) === true && str_contains((string) ($overMcp['structuredContent']['archive_error'] ?? ''), 'could not append')
        && $turned instanceof SendFailed && str_contains((string) (Runtime::commandLineStop($turned)['archive_error'] ?? ''), 'could not append')
        && str_contains((string) file_get_contents(dirname(__DIR__) . '/bin/aamio'), 'out(Runtime::commandLineStop($error));'),
    'over MCP and on the command line alike'
);
$delivered = $a->send($target, 'delivered, and written down');
$check(!array_key_exists('archive_error', $delivered) && (new SendFailed('refused', null, 403, 'no'))->archiveSaid() === [], 'and nothing of it when there is nothing to say');

// ---------------------------------------------------------------- a close the service did not confirm
[$a, $b, $homes] = $checkPair('close');
$opened = $a->openChannel('tender', 600, ['b']);
$w = $opened['w'];
$kept = 0;
foreach ([[0, 'The service did not answer'], [500, 'The service answered 500']] as [$status, $words]) {
    // No answer, and then a refusal: whether the thread is gone is not known.
    $fake->closing = [$status];
    $said = $a->closeChannel('tender');
    $kept += [$said['status'], $said['deleted'], $said['closed_here'], $said['w']] === [$status, false, false, $w]
        && $said['note'] === $words . ', so the thread may still be there, and the channel is kept and still read. Close it again, or let it expire at ' . $opened['expire_at'] . '.' ? 1 : 0;
}
$checkDirect($b, $w, $a->keys->public, 'written while the close was unsettled');
[$state, $entries] = $a->poll($a->channels['tender'], 0);
$check(
    $kept === 2 && isset($a->channels['tender']) && isset($fake->threads[$w]) && $checkTexts($entries) === ['written while the close was unsettled'],
    'a channel stays in the home when the service does not say its thread is gone, and what is written there meanwhile is read, not lost',
    $kept . ' of 2'
);

// Deleted at the service, and the home could not be written: it still lists the channel, and says why.
$stateBlock = $homes['a'] . DIRECTORY_SEPARATOR . 'state.json.tmp';
mkdir($stateBlock);
$error = $checkThrown(static fn () => $a->closeChannel('tender'));
rmdir($stateBlock);
$check(
    $error instanceof \RuntimeException && str_starts_with($error->getMessage(), 'the thread is deleted at the service, and state.json could not be written (') && str_contains($error->getMessage(), '), so this home still lists tender. Close it again')
        && isset($a->channels['tender']) && !isset($fake->threads[$w]),
    'a thread deleted at the service whose channel could not be taken out of state.json is still listed here, and the error says both',
    $error === null ? 'nothing was thrown' : $error->getMessage()
);
// Closed again: the service has no thread there any more, which is what was wanted, and the channel leaves the home.
$again = $a->closeChannel('tender');
$gone = $checkThrown(static fn () => $a->closeChannel('tender'));
$check(
    [$again['status'], $again['deleted'], $again['closed_here']] === [404, false, true] && str_starts_with((string) ($again['note'] ?? ''), 'There was no thread at this address any more') && !isset($a->channels['tender'])
        && $gone instanceof \InvalidArgumentException && $gone->getMessage() === 'no such channel: tender',
    'closed again, the service has no thread there any more, and the channel leaves the home'
);
// And the ordinary way: deleted, and out of the home.
$offer = $a->openChannel('offer', 600);
$closed = $a->closeChannel('offer');
$overMcp = (new McpServer($a))->dispatch('aamio_close_channel', ['label' => 'offer']);
$check(
    $closed === ['label' => 'offer', 'w' => $offer['w'], 'status' => 200, 'deleted' => true, 'closed_here' => true] && !isset($a->channels['offer']) && !isset($fake->threads[$offer['w']])
        && ($overMcp['isError'] ?? null) === true && str_contains((string) ($overMcp['structuredContent']['error'] ?? ''), 'no such channel'),
    'a close the service confirms deletes the thread and takes the channel out of the home, and says so',
    json_encode($closed)
);
$checkTools = array_column(json_decode((string) file_get_contents(dirname(__DIR__) . '/src/mcp-tools.json'), true)['tools'], null, 'name');
$check(
    str_contains($checkTools['aamio_close_channel']['description'], 'when the service does not say the thread is gone, the channel is kept and still read')
        && str_contains($checkTools['aamio_close_channel']['description'], 'The address is not closed: whoever still holds it can write there afterwards')
        && !str_contains($checkTools['aamio_close_channel']['description'], 'gone for everyone')
        && str_contains((string) ($checkTools['aamio_close_channel']['inputSchema']['properties']['label']['description'] ?? ''), 'as aamio_channels shows it')
        && str_contains((string) ($checkTools['aamio_open_channel']['inputSchema']['properties']['label']['description'] ?? ''), 'letters, digits, dots, dashes and underscores, and not starting with inbox or board'),
    'and the tools say what a close answers and what a label may be'
);

// ---------------------------------------------------------------- what is said of a handover follows what is known
//
// Found in a check of the candidate for Node the same day: an invitation that
// was stored, with its answer lost on the way back, was said not to have gone.
// The agent's next move is then another channel and another invitation, and
// whoever got the first has two.
[$a, $b] = $checkPair('handover');
$a->peers[$b->channels['inbox']->w] = $b->keys->public;
$checkHanded = static function (string $label, int $status, bool $stored) use ($a, $fake, $answerWith): array {
    Http::$override = $answerWith($status, $stored);
    try {
        $answer = (new McpServer($a))->dispatch('aamio_open_channel', ['label' => $label, 'ttl' => 600, 'to' => 'b']);
    } finally {
        Http::$override = $fake;
    }

    return ($answer['isError'] ?? null) === true ? ($answer['structuredContent'] ?? []) : [];
};
$checkMay = 'and the message carrying its address may have arrived: its outcome is not known. Do not open another channel for this: a new attempt is the same message, under the same message_id.';

$lost = $checkHanded('lost', 0, true);
$check(
    [$lost['operation'] ?? null, $lost['outcome'] ?? null, $lost['status'] ?? null, $lost['opened']['label'] ?? null] === ['open_channel', 'unknown', 0, 'lost'] && array_key_exists('retryable', $lost) && $lost['retryable'] === null
        && str_starts_with((string) ($lost['fix'] ?? ''), 'The channel lost is open and listed by aamio_channels, ' . $checkMay . ' No answer came back, so this message may already have been delivered.')
        && !str_contains(json_encode($lost), 'did not go') && !str_contains(json_encode($lost), 'did not land'),
    'a handover whose answer was lost says the channel is open and that the message may have arrived',
    json_encode($lost)
);
$got = $b->read();
$check(
    array_map(static fn (array $m): array => [$m['body']['channel'] ?? null, $m['replay'] ?? null], $got) === [[$lost['opened']['w'] ?? '', false]],
    'and it had arrived: the address is read before anything is sent again',
    json_encode($got)
);
$retried = $a->outboxRetry((string) ($lost['message_id'] ?? ''));
$again = $b->read();
$check(
    array_column($retried, 'status') === ['delivered'] && array_map(static fn (array $m): array => [$m['body']['channel'] ?? null, $m['replay'] ?? null], $again) === [[$lost['opened']['w'] ?? '', true]],
    'the same message sent again under its id arrives marked as the copy it is',
    json_encode([$retried, $again])
);

$failing = $checkHanded('failing', 500, false);
$check(
    ($failing['outcome'] ?? null) === 'attempted' && str_starts_with((string) ($failing['fix'] ?? ''), 'The channel failing is open and listed by aamio_channels, ' . $checkMay . ' '),
    'an answer that settles nothing is not called a refusal either',
    json_encode($failing)
);
foreach ([['refused', 403], ['later', 429]] as [$label, $status]) {
    $refused = $checkHanded($label, $status, false);
    $check(
        [$refused['outcome'] ?? null, $refused['status'] ?? null] === ['refused', $status]
            && str_starts_with((string) ($refused['fix'] ?? ''), 'The channel ' . $label . ' is open and listed by aamio_channels: only the message carrying its address did not go. ')
            && !str_contains((string) ($refused['fix'] ?? ''), 'may have arrived'),
        'a handover the service turned away with ' . $status . ' did not go, and the words say so',
        json_encode($refused)
    );
}
$check(
    array_map([Runtime::class, 'handoverUnsettled'], ['refused', 'never_sent', 'unknown', 'attempted', 'sending', 'something new']) === [false, false, true, true, true, true]
        && McpServer::stillOpen(null, 'unknown') === '' && str_ends_with(McpServer::stillOpen(['label' => 'x']), 'did not go. '),
    'the words know which outcomes are settled, and a stop at the gate went nowhere'
);

// ---------------------------------------------------------------- the command line says the channel that is open
//
// board channel opens a channel and then sends its address. What stopped
// that send was printed without the channel, so it stayed open with nobody
// told. Only the local server said it.
[$a, $b] = $checkPair('line');
$lineInbox = $b->channels['inbox']->w;
$a->peers[$lineInbox] = $b->keys->public;
$lineStop = static function (string $label, int $status, bool $stored) use ($a, $b, $fake, $answerWith, $checkThrown, $lineInbox): ?\Throwable {
    Http::$override = $answerWith($status, $stored);
    try {
        return $checkThrown(static fn () => $a->openChannelWith($b->keys->public, 600, $label, $lineInbox));
    } finally {
        Http::$override = $fake;
    }
};
$lost = $lineStop('lost', 0, true);
$said = $lost instanceof SendFailed ? Runtime::commandLineStop($lost) : [];
$check(
    array_keys($said) === ['opened', 'error', 'error_code', 'outcome', 'message_id', 'status', 'retryable', 'fix'] && [$said['opened']['label'], $said['error_code'], $said['outcome'], $said['status'], $said['retryable']] === ['lost', 'send_unknown', 'unknown', 0, null]
        && isset($a->channels['lost'], $fake->threads[$said['opened']['w']])
        && str_starts_with($said['fix'], 'The channel lost is open and listed by `aamio channel list`, and the message carrying its address may have arrived: its outcome is not known. Do not open another channel for this: a new attempt is the same message, under the same message_id. No answer came back, so this message may already have been delivered.'),
    'the command line says the channel that is open when its address was sent and no answer came back, and that it may have arrived',
    json_encode($said)
);
$refused = $lineStop('refused', 403, false);
$said = $refused instanceof SendFailed ? Runtime::commandLineStop($refused) : [];
$check(
    ($said['opened']['label'] ?? null) === 'refused' && ($said['outcome'] ?? null) === 'refused'
        && ($said['fix'] ?? null) === 'The channel refused is open and listed by `aamio channel list`: only the message carrying its address did not go. aamio refused it, so nobody has been given its address. Close it with `aamio channel close refused`, put right what the error says, and open a new one.',
    'and when the service turned the address away, with what to do about a channel nobody has the address of',
    json_encode($said)
);
$unsent = Runtime::commandLineStop(new SendFailed('never_sent', null, 0, 'RuntimeException: no room', ['label' => 'unsent', 'w' => str_repeat('u', 20), 'expire_at' => 1]));
$check(
    $unsent['fix'] === 'The channel unsent is open and listed by `aamio channel list`: only the message carrying its address did not go. Nothing left this machine, so nobody has been given its address. Close it with `aamio channel close unsent`, put right what the error says, and open a new one.',
    'a channel whose address never left is answered with what to do about it',
    json_encode($unsent)
);
$gated = new \Aamio\GateStop('The inbox asks for more work than this call may take', 'Ask its owner for an inbox that asks for less.');
$plainGate = Runtime::commandLineStop($gated);
$gated->opened = ['label' => 'gated', 'w' => str_repeat('g', 20), 'expire_at' => 1];
$check(
    $plainGate === ['error' => 'The inbox asks for more work than this call may take', 'error_code' => 'gate', 'fix' => 'Ask its owner for an inbox that asks for less.']
        && Runtime::commandLineStop($gated) === ['opened' => $gated->opened, 'error' => 'The inbox asks for more work than this call may take', 'error_code' => 'gate', 'fix' => 'The channel gated is open and listed by `aamio channel list`: only the message carrying its address did not go. Nothing was sent, so nobody has been given its address: close it with `aamio channel close gated`, and open a new one when the inbox\'s conditions can be met. Ask its owner for an inbox that asks for less.'],
    'a stop at the gate says the channel too, and is said as before when there is none'
);
$fake->refuse = true;
$plainSend = $checkThrown(static fn () => $a->send($lineInbox, 'turned away'));
$fake->refuse = false;
$check(
    $plainSend instanceof SendFailed && array_keys(Runtime::commandLineStop($plainSend)) === ['error', 'error_code', 'outcome', 'message_id', 'status', 'retryable', 'fix'] && Runtime::commandLineStop($plainSend)['error_code'] === 'send_refused',
    'and a send with no channel behind it is printed in the fields the other two runtimes print, error_code among them'
);
$lineSource = (string) file_get_contents(dirname(__DIR__) . '/bin/aamio');
$check(
    substr_count($lineSource, 'out(Runtime::commandLineStop($error));') === 1 && str_contains($lineSource, 'catch (SendFailed | GateStop $error)') && !str_contains($lineSource, "'outcome' => \$error->outcome"),
    'the command line prints both through that one function'
);

// ---------------------------------------------------------------- a post as long as an inbox lives
//
// The lifetime is in the body the board's work covers, so it is set before
// the work: this runtime did not shorten it, and the board refused it.
[$a, $b] = $checkPair('hour');
$hour = $a->boardPost('need', 'a whole hour', 'as long as it may', null, 3600);
$usual = $a->boardPost('need', 'twenty minutes', 'well inside', null, 1200);
// The inbox's time as the runtime counts it: the stand-in's clock is the one this suite started with.
$hourLeft = $a->channels['board']->expireAt - time();
$check(
    Runtime::BOARD_POST_MARGIN === 30 && $hour['post']['ttl'] <= $hourLeft - 28 && $hour['post']['ttl'] >= $hourLeft - 32 && $hour['post']['ttl'] < 3600
        && $usual['post']['ttl'] === 1200,
    'a post asked for as long as an inbox lives leaves the inbox room for the work and the round trip, and one well inside keeps its lifetime',
    json_encode([$hour['post']['ttl'] ?? null, $usual['post']['ttl'] ?? null])
);

// ---------------------------------------------------------------- board channel reads the gate before it opens
//
// A gate that cannot be met stopped the handover after the channel was open,
// and it stayed open with nobody given its address.
[$a, $b] = $checkPair('gated');
$gatedInbox = $b->channels['inbox']->w;
$a->peers[$gatedInbox] = $b->keys->public;
$fake->threads[$gatedInbox]['gate'] = ['require' => ['captcha' => true]];
$threadsBefore = count($fake->threads);
$channelsBefore = array_keys($a->channels);
$stopped = $checkThrown(static fn () => $a->openChannelWith($b->keys->public, 600, null, $gatedInbox));
unset($fake->threads[$gatedInbox]['gate']);
$check(
    $stopped instanceof \Aamio\GateStop && count($fake->threads) === $threadsBefore && array_keys($a->channels) === $channelsBefore,
    'board channel reads the gate of the inbox before it opens anything, and a gate that cannot be met leaves no channel behind',
    $stopped === null ? 'nothing was thrown' : get_class($stopped) . ': ' . $stopped->getMessage()
);

// ---------------------------------------------------------------- the same bytes, read the same way
//
// What the Node runtime is held to as well: a byte order mark in front of a
// sealed text is kept, so what follows is not JSON, and an address in it
// binds nothing.
[$a, $b] = $checkPair('mark');
$markInbox = $a->channels['inbox']->w;
$markOffered = str_repeat('c', 20);
$markBody = Codec::json(['channel' => $markOffered, 'text' => 'write here']);
$markWrite = static function (string $text) use ($a, $b, $fake, $markInbox): void {
    $wire = $b->keys->seal($a->keys->public, $text);
    $seq = count($fake->threads[$markInbox]['messages']) + 1;
    $fake->threads[$markInbox]['messages'][] = ['seq' => $seq, 'at' => $fake->now + $seq, 'type' => 'text', 'body' => $wire, 'sha256' => hash('sha256', $wire), 'from' => $b->keys->public, 'sig' => $b->keys->sign(Keys::threadSigningInput($markInbox, $wire)), 'verified' => true, 'sealed' => true];
};
$markWrite("\xEF\xBB\xBF" . $markBody);
$marked = $a->read();
$check(
    array_map(static fn (array $m): array => [$m['format'] ?? null, $m['verified'] ?? null, $m['body'] ?? null], $marked) === [['text', true, ['text' => "\xEF\xBB\xBF" . $markBody]]] && !isset($a->peers[$markOffered]),
    'a sealed message with a byte order mark in front is text, and the address in it binds nothing',
    json_encode($marked)
);
$markWrite($markBody);
$plain = $a->read();
$check(
    array_map(static fn (array $m): array => [$m['format'] ?? null, $m['body']['channel'] ?? null], $plain) === [['json', $markOffered]] && ($a->peers[$markOffered] ?? null) === $b->keys->public,
    'and without the mark the same text is the handover it says it is',
    json_encode($plain)
);

// ---------------------------------------------------------------- the record of a post is the post
//
// As aamio-python and the Node runtime write it, so the archive of a home
// reads the same whichever runtime wrote it. This runtime wrote the id alone
// with the title, the inbox and the status beside it, and wrote a row called
// posted for a post the board had refused as well.
[$a, $b] = $checkPair('posted');
$made = $a->boardPost('offer', 'written down as the post', 'the whole of it');
$rows = $a->archived('board', 'posted');
$check(
    count($rows) === 1 && array_keys($rows[0]) === ['kind', 'at', 'post'] && $rows[0]['post'] === $made['post'] && is_int($rows[0]['at']),
    'the record of a post is the post as the board stored it, in the fields the other two runtimes write',
    json_encode($rows)
);
$refusedPost = $checkThrown(static function () use ($a, $fake): void {
    $held = Http::$override;
    Http::$override = static function (string $method, string $url, ?string $body, array $headers) use ($fake): array {
        return $method === 'POST' && str_contains($url, 'board') ? [422, ['error' => 'the fixture refused the post', 'fix' => 'none'], []] : $fake($method, $url, $body, $headers);
    };
    try {
        $a->boardPost('offer', 'refused by the board', 'never made');
    } finally {
        Http::$override = $held;
    }
});
$check(
    $refusedPost instanceof \RuntimeException && str_contains($refusedPost->getMessage(), 'post failed: 422') && count($a->archived('board', 'posted')) === 1,
    'and a post the board refused is not written down as posted',
    $refusedPost === null ? 'it did not fail' : $refusedPost->getMessage() . ' ' . json_encode($a->archived('board', 'posted'))
);

// ---------------------------------------------------------------- a withdrawal as the other two make it
//
// The board's own answer, a failure when the board does not take the post
// down, and the record of a withdrawal only when there was one, with the id
// under id. This answered every status alike and wrote the record anyway.
[$a, $b] = $checkPair('withdraw');
$kept = $a->boardPost('offer', 'to be taken down', 'and then gone');
$gone = $a->boardWithdraw($kept['post']['id']);
$rows = $a->archived('board', 'withdrawn');
$check(
    $gone === ['id' => $kept['post']['id'], 'deleted' => true] && count($rows) === 1 && array_keys($rows[0]) === ['kind', 'at', 'id'] && $rows[0]['id'] === $kept['post']['id'],
    'a withdrawal answers with what the board answered, and its record is the one the other two write',
    json_encode([$gone, $rows])
);
$again = $checkThrown(static fn () => $a->boardWithdraw($kept['post']['id']));
$check(
    $again instanceof \RuntimeException && str_starts_with($again->getMessage(), 'withdraw failed: 404') && count($a->archived('board', 'withdrawn')) === 1,
    'and a post the board did not take down is a failure, with no record of a withdrawal',
    $again === null ? 'nothing was thrown' : $again->getMessage()
);
$overMcp = (new McpServer($a))->dispatch('aamio_board_withdraw', ['post' => $kept['post']['id']]);
$check(($overMcp['isError'] ?? null) === true && str_contains((string) ($overMcp['structuredContent']['error'] ?? ''), 'withdraw failed: 404'), 'over the local server as well', json_encode($overMcp['structuredContent'] ?? null));

// ---------------------------------------------------------------- partner remove takes the partner the name finds
//
// A name in another case was said to be known and removed nothing.
[$a, $b] = $checkPair('remove');
$removed = $a->partnerRemove('B');
$check(
    [$removed['partner'], $removed['known']] === ['b', true] && $a->partnerList() === [] && json_decode((string) file_get_contents($a->home . DIRECTORY_SEPARATOR . 'partners.json'), true) === [],
    'partner remove takes the partner a name in another case finds, as a send finds it',
    json_encode($removed)
);
$check($a->partnerRemove('nobody') === ['partner' => 'nobody', 'known' => false, 'inbox' => $a->channels['inbox']->w], 'and a name nobody has changes nothing');
$a->partners = [['name' => 'Ann', 'key' => $b->keys->public], ['name' => 'ANN', 'key' => $a->keys->public]];
$a->saveJson('partners.json', $a->partners);
$ambiguous = $checkThrown(static fn () => $a->partnerRemove('ann'));
$check(
    $ambiguous instanceof \Aamio\AmbiguousPartner && array_column($a->partnerList(), 'name') === ['Ann', 'ANN'],
    'two names that differ only in case are refused as ambiguous, and nothing is removed',
    $ambiguous === null ? 'nothing was thrown' : get_class($ambiguous)
);
$check($a->partnerRemove('ANN')['partner'] === 'ANN' && array_map(static fn (array $p): array => [$p['name'], $p['key']], $a->partnerList()) === [['Ann', $b->keys->public]], 'and the exact name removes that one alone');
$byKey = $a->partnerRemove($b->keys->public);
$check([$byKey['partner'], $byKey['known']] === ['Ann', true] && $a->partnerList() === [] && $a->partnerRemove($b->keys->public)['known'] === false, 'partner remove takes a key as well as a name, as a send does', json_encode($byKey));

// ---------------------------------------------------------------- names apart from upper and lower case, outside ASCII too
//
// aamio-python and the Node runtime lower a name in all of Unicode before they
// compare it. This runtime lowered ASCII alone, so where a letter outside it
// made the difference, a name in another case was another partner here and the
// same partner there, in a home all three can open.
$folds = [
    // A text, and its lower case as str.lower() of Python 3.14 gives it, in hex.
    ["\u{C5}se", 'c3a57365'],
    ["\u{D8}YSTEIN", 'c3b879737465696e'],
    ["\u{C9}lise", 'c3a96c697365'],
    ["\u{130}STANBUL", '69cc877374616e62756c'],
    ["\u{39F}\u{394}\u{3A5}\u{3A3}\u{3A3}\u{395}\u{391}\u{3A3}", 'cebfceb4cf85cf83cf83ceb5ceb1cf82'],
    ["\u{3A3}\u{391}", 'cf83ceb1'],
    ["\u{3A3}", 'cf83'],
    ["\u{391}\u{3A3}.\u{392}", 'ceb1cf832eceb2'],
    ["\u{391}\u{3A3}.", 'ceb1cf822e'],
    ["\u{5D0}\u{3A3}", 'd790cf83'],
    ["\u{391}\u{3A3}\u{301}", 'ceb1cf82cc81'],
    ["\u{212A}", '6b'],
    ["\u{1E9E}", 'c39f'],
    ["\u{1C4}\u{1C5}", 'c786c786'],
    ["\u{10400}", 'f09090a8'],
    ["\u{13A0}", 'eaadb0'],
    ["ABC", '616263'],
    // Not UTF-8: lowered as bytes, as before.
    ["\xC5SE", 'c57365'],
];
$foldedWrong = [];
foreach ($folds as [$text, $lower]) {
    if (bin2hex(\Aamio\CaseFold::lower($text)) !== $lower) {
        $foldedWrong[] = [bin2hex($text), bin2hex(\Aamio\CaseFold::lower($text)), $lower];
    }
}
$check(
    $foldedWrong === [] && \Aamio\CaseFold::UNICODE === '16.0.0',
    'a name is lowered as aamio-python lowers it, outside ASCII and at the end of a Greek word too, with the Unicode of Python 3.14',
    json_encode($foldedWrong)
);
[$a, $b] = $checkPair('fold');
$a->partnerAdd("\u{C5}se", Keys::generate()->public);
$twin = $checkThrown(static fn () => $a->partnerAdd("\u{E5}se", Keys::generate()->public));
$check(
    $twin instanceof \Aamio\AmbiguousPartner && array_column($a->partnerList(), 'name') === ['b', "\u{C5}se"],
    'a name that differs from one in the book only in the case of a letter outside ASCII is refused with another key, as the other two runtimes refuse it',
    $twin === null ? json_encode(array_column($a->partnerList(), 'name')) : get_class($twin)
);
$found = $a->partnerByName("\u{C5}SE");
$gone = $a->partnerRemove("\u{E5}SE");
$check(
    ($found['name'] ?? null) === "\u{C5}se" && [$gone['partner'], $gone['known']] === ["\u{C5}se", true] && array_column($a->partnerList(), 'name') === ['b'],
    'and a name in another case finds that partner, and removes it',
    json_encode([$found, $gone])
);
$a->partners = [['name' => "\u{D8}ystein", 'key' => Keys::generate()->public], ['name' => "\u{D8}YSTEIN", 'key' => Keys::generate()->public]];
$a->saveJson('partners.json', $a->partners);
$both = $checkThrown(static fn () => $a->partnerRemove("\u{F8}ystein"));
$check(
    $both instanceof \Aamio\AmbiguousPartner && count($a->partnerList()) === 2,
    'two names that differ only so are refused as ambiguous, and nothing is removed',
    $both === null ? 'nothing was thrown' : get_class($both)
);

// ---------------------------------------------------------------- the inbox that named a removed key is read on
//
// Read no more, the old inbox lost what a partner wrote there before it had
// the new address. It is read until it expires: what the partners still in the
// book write there is handed over, what the removed key writes is not and is
// said, and a key taken back into the book is read again.
[$a, $b] = $checkPair('readon');
$homeC = $root . DIRECTORY_SEPARATOR . 'check1112-readon-c';
mkdir($homeC, 0700, true);
$c = new Runtime($homeC, 'https://fake.test', ['c'], true, $checkQuiet);
$checkOpen[] = $c;
$a->partnerAdd('c', $c->keys->public);
$old = $a->channels['inbox'];
$gone = $a->partnerRemove('c');
$a->attentionTaken();
$checkDirect($b, $old->w, $a->keys->public, 'from a partner who stays');
$checkDirect($c, $old->w, $a->keys->public, 'from the key that was removed');
$got = array_values(array_filter($a->read(), static fn (array $m): bool => $m['w'] === $old->w));
$said = array_values(array_filter($a->attentionTaken(), static fn (array $n): bool => $n['state'] === 'removed_key'));
$check(
    ($gone['old_inbox']['w'] ?? null) === $old->w && ($gone['old_inbox']['muted'] ?? null) === true
        && array_map(static fn (array $m): array => [$m['sender'], $m['body']['text'] ?? null], $got) === [['b', 'from a partner who stays']]
        && count($said) === 1 && ($said[0]['seqs'] ?? null) === [2] && $old->after === 2,
    'the inbox that named a removed key is read on: a partner still in the book arrives there, the removed key does not, and that is said',
    json_encode([$got, $said])
);
$a->partnerAdd('c', $c->keys->public);
$checkDirect($c, $old->w, $a->keys->public, 'back in the book');
$back = array_values(array_filter($a->read(), static fn (array $m): bool => $m['w'] === $old->w));
$check(array_map(static fn (array $m): array => [$m['sender'], $m['body']['text'] ?? null], $back) === [['c', 'back in the book']], 'and a key taken back into the book is read there again', json_encode($back));

// ---------------------------------------------------------------- a 401 or a 409 is the service saying no
//
// Both are answered before anything is stored. A handover answered so said
// the address may have arrived, and retry sent the same bytes again.
[$a, $b] = $checkPair('refusals');
$a->peers[$b->channels['inbox']->w] = $b->keys->public;
foreach ([['full', 409], ['unsigned', 401]] as [$label, $status]) {
    Http::$override = $answerWith($status, false);
    try {
        $answer = (new McpServer($a))->dispatch('aamio_open_channel', ['label' => $label, 'ttl' => 600, 'to' => 'b']);
    } finally {
        Http::$override = $fake;
    }
    $said = $answer['structuredContent'] ?? [];
    $check(
        [$said['outcome'] ?? null, $said['status'] ?? null, $said['retryable'] ?? null, $said['opened']['label'] ?? null] === ['refused', $status, false, $label]
            && ($said['fix'] ?? null) === 'The channel ' . $label . ' is open and listed by aamio_channels: only the message carrying its address did not go. aamio refused it, so nobody has been given its address. Close it with aamio_close_channel, put right what the error says, and open a new one with to.',
        'a handover answered ' . $status . ' did not go, and the words say so',
        json_encode($said)
    );
}
$check($a->outboxPending() === [] && $a->outboxRetry() === [], 'and neither waits to be sent again, or is sent again by a retry of everything');

// ---------------------------------------------------------------- a file of the home with a mark in front
//
// An editor on Windows may save a file with a byte order mark in front. The
// Node runtime read the home's state past it and this runtime stopped, and a
// policy file with one was read as no choice at all. The home's own files
// are read past the mark by all three now.
$checkMarked = static function (string $path): void {
    $raw = (string) file_get_contents($path);
    if ($raw === '' || str_starts_with($raw, "\xEF\xBB\xBF")) {
        throw new \RuntimeException('nothing to mark in ' . $path);
    }
    file_put_contents($path, "\xEF\xBB\xBF" . $raw);
};
[$a, $b, $homes] = $checkPair('marked');
$a->scopeNew('team');
$markedInbox = $a->channels['inbox']->w;
$checkDirect($b, $markedInbox, $a->keys->public, 'written down before the mark');
$markedRead = $a->read();
$markedPost = $a->boardPost('need', 'kept by php', 'who answers?');
$a->peers[$b->channels['inbox']->w] = $b->keys->public;
$a->send($b->channels['inbox']->w, 'one in the outbox');
$markedBefore = [$a->keys->public, $a->partnerList(), $a->scopeList(), $a->outbox, array_keys($a->channels)];
$a->close();
foreach (['partners.json', 'scopes.json', 'state.json', 'outbox.json', 'archive/inbox.jsonl', 'archive/board.jsonl'] as $name) {
    $checkMarked($homes['a'] . DIRECTORY_SEPARATOR . $name);
}
$again = null;
try {
    $again = new Runtime($homes['a'], 'https://fake.test', ['a'], true, $checkQuiet);
    $checkOpen[] = $again;
} catch (\Throwable $error) {
    $again = $error;
}
$check(
    $again instanceof Runtime && [$again->keys->public, $again->partnerList(), $again->scopeList(), $again->outbox, array_keys($again->channels)] === $markedBefore && $again->channels['inbox']->w === $markedInbox,
    'a home whose state and lists have a byte order mark in front opens with the key, the partners, the scopes, the outbox and the channels it had',
    $again instanceof \Throwable ? $again->getMessage() : json_encode(array_keys($again->channels))
);
if ($again instanceof Runtime) {
    $posted = $again->archived('board', 'posted');
    $dated = (static fn (): array => Storage::records($homes['a'] . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'inbox.jsonl'))->bindTo(null, Storage::class)();
    $check(
        array_map(static fn (array $row): mixed => $row['post']['id'] ?? null, $posted) === [$markedPost['post']['id']] && $dated !== [] && !in_array(null, array_column($dated, 0), true)
            && count($markedRead) === 1,
        "the archive's first line is a record like the rest, with its time, so a lifetime reaches it",
        json_encode([$posted, $dated])
    );
    $again->partnerAdd('c', $again->keys->public);
    $check(!str_starts_with((string) file_get_contents($homes['a'] . DIRECTORY_SEPARATOR . 'partners.json'), "\xEF\xBB\xBF"), 'and what is saved next is written without the mark');
}

// Read as no choice at all, an archive told to keep nothing was kept again, and nothing said so.
[$a, $b, $homes] = $checkPair('policy');
$a->setArchive(Storage::parsePolicy('off'));
$a->close();
$checkMarked($homes['a'] . DIRECTORY_SEPARATOR . 'config.json');
$again = new Runtime($homes['a'], 'https://fake.test', ['a'], null, $checkQuiet);
$checkOpen[] = $again;
$checkDirect($b, $again->channels['inbox']->w, $again->keys->public, 'not to be written down');
$got = $again->read();
$check(
    Storage::readPolicy($homes['a'])['mode'] === 'off' && array_map(static fn (array $m): mixed => $m['body']['text'] ?? null, $got) === ['not to be written down']
        && !is_file($homes['a'] . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'inbox.jsonl'),
    'a policy file with a mark in front is still the choice that was made: the home keeps nothing, and nothing is written down',
    json_encode([Storage::readPolicy($homes['a']), $got])
);
file_put_contents($homes['a'] . DIRECTORY_SEPARATOR . 'config.json', "\xEF\xBB\xBF{ nope");
$check(Storage::readPolicy($homes['a'])['mode'] === 'keep', 'a file that is not JSON at all is no choice, as before: only the mark is passed');

// Only the mark is read past. What follows it has to be what the file holds.
[$a, $b, $homes] = $checkPair('stops');
$a->close();
$stops = [];
foreach (["\xEF\xBB\xBF\xEF\xBB\xBF[]", "\xFF\xFE[\x00]\x00"] as $bytes) {
    file_put_contents($homes['a'] . DIRECTORY_SEPARATOR . 'partners.json', $bytes);
    $stopped = $checkThrown(static fn () => new Runtime($homes['a'], 'https://fake.test', ['a'], true, $checkQuiet));
    $stops[] = $stopped instanceof \RuntimeException && str_contains($stopped->getMessage(), 'partners.json could not be read (not UTF-8 JSON)');
}
$check($stops === [true, true], 'a state file that is not JSON after the mark, or not UTF-8, still stops the runtime', json_encode($stops));
// A file that holds the mark and nothing else is an empty file, as the other two runtimes read it.
file_put_contents($homes['a'] . DIRECTORY_SEPARATOR . 'partners.json', "\xEF\xBB\xBF");
$emptied = $checkThrown(static function () use ($homes, $checkQuiet, &$checkOpen): void {
    $one = new Runtime($homes['a'], 'https://fake.test', ['a'], true, $checkQuiet);
    $checkOpen[] = $one;
    if ($one->partnerList() !== []) {
        throw new \RuntimeException('it read partners out of a mark');
    }
    $one->close();
});
$check($emptied === null, 'a state file that holds the mark and nothing else is an empty file', $emptied?->getMessage() ?? '');
// The key holds the seed and nothing else. The three runtimes refuse one with a mark in front, and the Node runtime's tests hold it to that.
[$a, $b, $homes] = $checkPair('key');
$a->close();
$checkMarked($homes['a'] . DIRECTORY_SEPARATOR . 'key');
$stopped = $checkThrown(static fn () => new Runtime($homes['a'], 'https://fake.test', ['a'], true, $checkQuiet));
$check(
    $stopped instanceof \RuntimeException && str_contains($stopped->getMessage(), 'key could not be read (not a 64 character hex seed)'),
    'the key is not read past a mark',
    $stopped === null ? 'it opened' : $stopped->getMessage()
);
$check(Storage::pastMark("\xEF\xBB\xBFx") === 'x' && Storage::pastMark("x\xEF\xBB\xBF") === "x\xEF\xBB\xBF" && Storage::pastMark('') === '' && Storage::pastMark("\xEF\xBB") === "\xEF\xBB", 'the mark is passed at the start only, and only when it is whole');

foreach ($checkOpen as $one) {
    $one->close();
}
