<?php

declare(strict_types=1);

// Included by runtime.php, sharing its fake service, its checks and its temporary homes.
//
// What stays on this machine, who can read it, for how long, and whether this
// client fits the service at all.
//
// From an outside assessment of aamio in use, 18 September 2026, and from a
// field report by an agent that ran the client for seventeen hours. The service
// forgets a thread when it expires and this runtime does not: it kept decrypted
// messages with no lifetime, and "ephemeral" was easy to read as a promise
// about the whole system. The service said 0.7.0, GitHub 0.6.4 and Packagist
// 0.2.7, and nothing said which of those go together. And a channel past its
// expiry was listed like any other.
use Aamio\Channel;
use Aamio\Codec;
use Aamio\Compat;
use Aamio\Http;
use Aamio\Keys;
use Aamio\Runtime;
use Aamio\SendFailed;
use Aamio\Storage;

echo "what stays on this machine\n";

$everything = array_merge(Compat::NEEDS, array_keys(Compat::USES));
$declares = static fn (array $capabilities, int $version = Compat::PROTOCOL): array => ['version' => '9.9.9', 'protocol' => ['version' => $version, 'capabilities' => array_values($capabilities)]];

$full = Compat::check($declares(array_merge($everything, ['something-newer'])));
$check($full['verdict'] === 'full' && $full['missing'] === [] && $full['unknown_to_this_client'] === ['something-newer'], 'a service that offers everything this client uses is fully supported, and more than it knows is said, not counted against it');
$withoutScopes = Compat::check($declares(array_values(array_diff($everything, ['scopes']))));
$check($withoutScopes['verdict'] === 'partial' && $withoutScopes['missing'] === ['scopes'] && str_contains($withoutScopes['why'], 'unlisted posts'), 'a capability used and not offered is partial, named with what it is for');
$check(Compat::check($declares($everything, Compat::PROTOCOL + 1))['verdict'] === 'refuse', 'another protocol version is refused');
$withoutThreads = Compat::check($declares(array_values(array_diff($everything, ['threads']))));
$check($withoutThreads['verdict'] === 'refuse' && $withoutThreads['missing'] === ['threads'] && str_contains($withoutThreads['why'], 'cannot work without'), 'and so is a service missing something this client needs, with the reason');
$silent = array_map(static fn ($descriptor): string => (string) Compat::check($descriptor)['verdict'], [['version' => '0.7.0'], [], null, ['protocol' => ['version' => 1]]]);
$check($silent === ['partial', 'partial', 'partial', 'partial'], 'a service that declares nothing is partial, and never full');

// The archive is a choice with a lifetime.
$check(Storage::readPolicy($root . '/nowhere') === Storage::DEFAULT_POLICY, 'no config means keep, as every version did');
$check(Storage::parsePolicy('days:30', 5.0) === ['mode' => 'days', 'days' => 30, 'max_mb' => 5.0], 'days:N with a ceiling is read as written');
$refused = 0;
foreach (['forever', 'days:0', 'days:x', ''] as $bad) {
    try {
        Storage::parsePolicy($bad);
    } catch (InvalidArgumentException $error) {
        $refused += str_contains($error->getMessage(), 'keep, off or days:N') ? 1 : 0;
    }
}
$check($refused === 4, 'and a policy that is not one of the three is refused, saying which three');
$check(str_contains(Storage::describe(['mode' => 'off', 'days' => null, 'max_mb' => null]), 'Nothing decrypted is written'), 'off says what is still written and why');

$archiveHome = $root . '/keeping';
Storage::makePrivateDir($archiveHome . '/archive');
$now = 1800000000.0;
$lines = [['kind' => 'received', 'at' => $now - 40 * 86400, 'n' => 'old'], ['kind' => 'received', 'at' => $now - 86400, 'n' => 'new'], ['kind' => 'received', 'n' => 'undated']];
file_put_contents($archiveHome . '/archive/inbox.jsonl', implode('', array_map(static fn (array $line): string => Codec::json($line) . "\n", $lines)) . '{"kind": "received", "at": 5, "n": "torn');
$pruned = Storage::prune($archiveHome, Storage::parsePolicy('days:30'), $now);
$left = array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", (string) file_get_contents($archiveHome . '/archive/inbox.jsonl'))), 'is_array'));
$check($pruned['removed'] === 1 && array_column($left, 'n') === ['new', 'undated'], 'a lifetime removes what is older and keeps what cannot be dated: what cannot be told old is not thrown away as old');
$check(!file_exists($archiveHome . '/archive/inbox.jsonl.tmp') && Storage::prune($archiveHome, Storage::parsePolicy('keep'), $now)['removed'] === 0, 'the rewrite leaves nothing behind, and keep removes nothing');

$sizeHome = $root . '/ceiling';
Storage::makePrivateDir($sizeHome . '/archive');
foreach (['inbox' => 100, 'board' => 200] as $name => $first) {
    $text = '';
    for ($n = 0; $n < 100; $n++) {
        $text .= Codec::json(['at' => $first + $n, 'pad' => str_repeat('x', 1000)]) . "\n";
    }
    file_put_contents($sizeHome . '/archive/' . $name . '.jsonl', $text);
}
$capped = Storage::prune($sizeHome, Storage::parsePolicy('keep', 0.1), 1000.0);
$oldestLeft = PHP_INT_MAX;
foreach (['inbox', 'board'] as $name) {
    foreach (explode("\n", (string) file_get_contents($sizeHome . '/archive/' . $name . '.jsonl')) as $line) {
        $record = json_decode($line, true);
        if (is_array($record)) {
            $oldestLeft = min($oldestLeft, (int) $record['at']);
        }
    }
}
$check($capped['bytes'] <= 0.1 * 1024 * 1024 && $capped['removed'] > 0 && $oldestLeft >= 190, 'a ceiling on size lets the oldest go first, whichever channel it was on: oldest left ' . $oldestLeft);

// Who can read the home.
$check(Storage::check($root . '/not-there')['private'] === null, 'a folder that is not there is not called private');
$me = 'S-1-5-21-1-2-3-1001';
$found = Storage::windowsAclFindings([$me . '|FullControl|Allow', 'S-1-5-18|FullControl|Allow', 'S-1-5-32-544|FullControl|Allow', 'S-1-5-32-545|ReadAndExecute, Synchronize|Allow', 'S-1-1-0|Read|Deny', 'S-1-5-11|Modify|Allow'], $me);
$check(array_column($found, 'who') === ['Users', 'Authenticated Users'], 'on Windows it is the access list that says who can read: you, the system and the administrators are expected, and a deny rule grants nothing');
$check(Storage::windowsAclFindings([$me . '|FullControl|Allow'], $me) === [], 'a folder only you can reach has nothing to report');
$own = Storage::check($root);
$check(in_array($own['private'], [true, false, null], true) && is_string($own['how']) && ($own['private'] === false) === ($own['findings'] !== []), 'the check never calls something private that it could not check');

// The Windows check, with a shell that answers from a script.
//
// From the deep health check of 21 September 2026 (F7). windowsRules wrote the
// identity before Get-Acl ran, merged stderr into stdout, took any output with
// me| in it as a read, skipped every line it could not parse as an unreadable
// rule, and an empty list of findings became private: true. On a machine where
// Get-Acl failed to load its module, the doctor said the key folder was
// private. It had read nothing. Each case below is driven through the same
// windowsCheck the doctor uses, on any platform, with the shell replaced.
$ran = null;
$scripted = static function (int $status, string $out, string $err = '') use (&$ran): void {
    Storage::$shell = static function (array $command) use ($status, $out, $err, &$ran): array {
        $ran = $command;

        return [$status, $out, $err];
    };
};
$notPrivate = static fn (array $found): bool => $found['private'] === null && $found['findings'] === [] && str_starts_with((string) $found['how'], 'not checked') && str_contains((string) ($found['fix'] ?? ''), 'icacls');
$whole = "me|$me\r\nrule|$me|FullControl|Allow\r\nrule|S-1-5-18|FullControl|Allow\r\nrule|S-1-5-32-544|FullControl|Allow\r\nend|3\r\n";

$scripted(0, $whole);
$found = Storage::windowsCheck($root);
$check($found['private'] === true && $found['findings'] === [] && !isset($found['fix']), 'a list read to the end with only you, SYSTEM and Administrators on it is private');
$script = implode(' ', is_array($ran) ? $ran : []);
$check(is_array($ran) && $ran[0] === 'powershell' && in_array('-NonInteractive', $ran, true) && str_contains($script, 'Get-Acl') && str_contains($script, "\$ErrorActionPreference = 'Stop'"), 'through PowerShell without a profile or a prompt, with every error terminating');
$check(preg_match('/Set-Acl|icacls|\/grant|\/inheritance|Remove-|Add-/i', $script) !== 1, 'and the script only reads: a diagnostic never changes an access list');

$scripted(0, "me|$me\r\nrule|$me|FullControl|Allow\r\nrule|S-1-5-32-545|ReadAndExecute, Synchronize|Allow\r\nend|2\r\n");
$found = Storage::windowsCheck($root);
$check($found['private'] === false && array_column($found['findings'], 'who') === ['Users'] && str_contains((string) $found['fix'], '/inheritance:r'), 'a list with Users on it is not private, and names them');

$scripted(3, "me|$me\r\nerror|Get-Acl : The 'Get-Acl' command was found in the module 'Microsoft.PowerShell.Security', but the module could not be loaded.\r\n");
$found = Storage::windowsCheck($root);
$check($notPrivate($found) && str_contains((string) $found['how'], 'could not be loaded'), 'the identity written and then Get-Acl failing is not private, and the error is quoted', (string) $found['how']);

$scripted(3, "error|Get-Acl : Attempted to perform an unauthorized operation.\r\n");
$found = Storage::windowsCheck($root);
$check($notPrivate($found) && str_contains((string) $found['how'], 'unauthorized'), 'access denied is not private');

$scripted(1, '', "The term 'powershell' is not recognized\r\n");
$found = Storage::windowsCheck($root);
$check($notPrivate($found) && str_contains((string) $found['how'], 'status 1') && str_contains((string) $found['how'], 'not recognized'), 'a shell that ended badly with nothing on stdout is not private, with what stderr said');

$scripted(0, "me|$me\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'the identity alone, with no end to the list, is not private: this is exactly what the old script left behind when Get-Acl failed');

$scripted(0, "me|$me\r\nGet-Acl : Could not load file or assembly\r\n+ CategoryInfo : NotSpecified\r\nend|0\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'error text among the rules is not skipped as an unreadable rule: it makes the whole read untrusted');

$scripted(0, "me|$me\r\nrule|$me|FullControl|Allow\r\nend|2\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'a count that does not add up is not private');

$scripted(0, "me|$me\r\nrule|$me|FullControl|Allow\r\nend|1\r\nrule|S-1-1-0|FullControl|Allow\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'nor is a list that goes on after its end');

$scripted(0, "me|\r\nrule|S-1-5-18|FullControl|Allow\r\nend|1\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'nor an empty identity');

$scripted(0, "me|$me\r\nend|0\r\n");
$found = Storage::windowsCheck($root);
$check($notPrivate($found) && str_contains((string) $found['how'], 'empty'), 'an empty access list is not private either: it is nobody, or with no list at all everybody, and the check says it cannot tell');

$scripted(0, "rule|$me|FullControl|Allow\r\nend|1\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'and no identity at all is not private');

$scripted(0, "me|$me\r\nerror|Something went wrong after all\r\n");
$check($notPrivate(Storage::windowsCheck($root)), 'an error line is refused whatever the exit status says');

Storage::$shell = null;

// The runtime writes privately, prunes what it should, and stops writing when it is closed.
$privateHome = $root . '/private-home';
$runtime = new Runtime($privateHome, 'https://fake.test', null, null, $quiet);
$runtime->partnerAdd('alice', str_repeat('A', 43));
$runtime->effectDone('order-1', ['ok' => true], 'fingerprint');
$runtime->saveState();
$runtime->archive('inbox', ['kind' => 'received', 'at' => time(), 'body' => ['text' => 'decrypted']]);
$runtime->close();
$modes = [];
foreach (['key', 'partners.json', 'state.json', 'effects.json', 'archive/inbox.jsonl'] as $name) {
    $path = $privateHome . '/' . $name;
    $modes[$name] = file_exists($path) ? (fileperms($path) & 0777) : null;
}
$readableByOthers = array_keys(array_filter($modes, static fn (?int $mode): bool => $mode === null || (PHP_OS_FAMILY !== 'Windows' && ($mode & 0077) !== 0)));
$check($readableByOthers === [], 'every file the runtime writes is private from its first byte' . ($readableByOthers === [] ? '' : ', but not ' . implode(', ', $readableByOthers)));

$runtime = new Runtime($privateHome, 'https://fake.test', null, null, $quiet);
$check($runtime->archivePolicy === Storage::DEFAULT_POLICY && $runtime->archiveEnabled, 'a home with no choice made keeps its archive');
$runtime->setArchive(Storage::parsePolicy('off'));
$runtime->archive('inbox', ['kind' => 'received', 'at' => time(), 'body' => ['text' => 'not written']]);
$runtime->close();
$runtime = new Runtime($privateHome, 'https://fake.test', null, null, $quiet);
$check(!$runtime->archiveEnabled && !str_contains((string) file_get_contents($privateHome . '/archive/inbox.jsonl'), 'not written'), 'off is the folder\'s own choice and holds for the next command, without a flag on every one');
$runtime->close();

$agedHome = $root . '/aged';
Storage::makePrivateDir($agedHome . '/archive');
file_put_contents($agedHome . '/config.json', Codec::json(['archive' => ['mode' => 'days', 'days' => 7]]));
file_put_contents($agedHome . '/archive/inbox.jsonl', Codec::json(['kind' => 'received', 'at' => time() - 30 * 86400, 'n' => 'old']) . "\n" . Codec::json(['kind' => 'received', 'at' => time(), 'n' => 'new']) . "\n");
$runtime = new Runtime($agedHome, 'https://fake.test', null, null, $quiet);
$runtime->close();
$kept = array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", (string) file_get_contents($agedHome . '/archive/inbox.jsonl'))), 'is_array'));
$check(array_column($kept, 'n') === ['new'], 'a runtime with a lifetime prunes when it starts');

$tornHome = $root . '/torn';
$runtime = new Runtime($tornHome, 'https://fake.test', null, null, $quiet);
$runtime->archive('board', ['kind' => 'received', 'at' => 1, 'body' => ['text' => 'whole']]);
file_put_contents($tornHome . '/archive/board.jsonl', '{"kind": "received", "at": 2, "body": {"te', FILE_APPEND);
$runtime->archive('board', ['kind' => 'received', 'at' => 3, 'body' => ['text' => 'after']]);
$readable = array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", (string) file_get_contents($tornHome . '/archive/board.jsonl'))), 'is_array'));
$check(count($readable) === 2 && ($readable[1]['body']['text'] ?? null) === 'after', 'a torn last line costs that line only: the next record is not swallowed by it');
file_put_contents($tornHome . '/state.json.tmp', '{"channels": [{"label": "inb');
$runtime->close();
$runtime = new Runtime($tornHome, 'https://fake.test', null, null, $quiet);
$check(!file_exists($tornHome . '/state.json.tmp'), 'what an interrupted write left behind is cleared, and the file it was to replace is whole');
$runtime->archive('board', ['kind' => 'received', 'at' => 4, 'body' => ['text' => 'before close']]);
$runtime->saveState();
$runtime->close();
$before = (string) file_get_contents($tornHome . '/state.json');
$runtime->archive('board', ['kind' => 'received', 'at' => 5, 'body' => ['text' => 'after close']]);
// Something that would show in the file, so an unchanged file means the write
// was refused and not that there was nothing to write.
$runtime->channels['after-close'] = new Channel('after-close', 'read', str_repeat('z', 20), time() + 600);
$runtime->saveState();
$after = (string) file_get_contents($tornHome . '/state.json');
$check(!str_contains((string) file_get_contents($tornHome . '/archive/board.jsonl'), 'after close') && $after === $before && !str_contains($after, 'after-close'), 'after close nothing more is written: the home may be another process\'s');

// A channel past its expiry, and what the command line says.
$listHome = $root . '/listing';
$runtime = new Runtime($listHome, 'https://fake.test', null, false, $quiet);
$runtime->channels['old'] = new Channel('old', 'read', str_repeat('o', 20), time() - 5);
$runtime->channels['live'] = new Channel('live', 'read', str_repeat('l', 20), time() + 600);
$listed = [];
foreach ($runtime->channelList() as $channel) {
    $listed[$channel['label']] = $channel;
}
$check(($listed['old']['expired'] ?? null) === true && ($listed['old']['seconds_left'] ?? null) === 0 && ($listed['live']['expired'] ?? null) === false, 'a channel past its expiry is listed as expired, not like any other');
$runtime->close();

// board replies reads the board inboxes before it says nobody answered.
$replyHome = $root . '/replies';
$runtime = new Runtime($replyHome, 'https://fake.test', null, false, $quiet);
$answerW = str_repeat('r', 20);
$otherW = str_repeat('m', 20);
$runtime->channels['board'] = new Channel('board', 'read', $answerW, time() + 600, ['*']);
$runtime->channels['inbox'] = new Channel('inbox', 'read', $otherW, time() + 600);
$answer = Codec::json(['post' => 'p1', 'text' => 'I have the log']);
$sealed = $b->keys->seal($runtime->keys->public, $answer);
$asked = [];
Http::$override = static function (string $method, string $url) use (&$asked, $answerW, $sealed, $b): array {
    $asked[] = $url;
    if (!str_contains($url, $answerW)) {
        return [200, ['exists' => true, 'created_at' => 1, 'messages' => [], 'next' => 0]];
    }
    $message = ['seq' => 1, 'at' => 1, 'type' => 'json', 'body' => $sealed, 'sha256' => hash('sha256', $sealed), 'from' => $b->keys->public, 'sig' => $b->keys->sign(Keys::threadSigningInput($answerW, $sealed)), 'verified' => true, 'sealed' => true];

    return [200, ['exists' => true, 'created_at' => 1, 'messages' => [$message], 'next' => 1]];
};
$found = $runtime->boardPoll(0);
$replies = $runtime->boardReplies('p1');
Http::$override = $fake;
$check(count($replies) === 1 && ($replies[0]['body']['text'] ?? null) === 'I have the log', 'board replies reads the board inbox before it answers: an answer that was waiting is an answer');
$check(count(array_filter($asked, static fn (string $url): bool => str_contains($url, $otherW))) === 0 && $runtime->channels['inbox']->after === 0, 'and only the board inboxes: what waits elsewhere is still there for read');
$runtime->close();

// A channel whose address could not be handed over.
$handHome = $root . '/handover';
$runtime = new Runtime($handHome, 'https://fake.test', null, false, $quiet);
Http::$override = static function (string $method, string $url): array {
    if ($method === 'PUT') {
        return [201, ['w' => str_repeat('n', 20), 'expire_at' => time() + 900, 'allow' => []]];
    }

    return [403, ['error' => 'This thread accepts only signed messages from its allowed keys', 'fix' => 'Sign with an allowed key.']];
};
$thrown = null;
try {
    $runtime->openChannelWith($b->keys->public, 900, null, str_repeat('t', 20));
} catch (\Throwable $error) {
    $thrown = $error;
}
Http::$override = $fake;
$check($thrown instanceof SendFailed && $thrown->outcome === 'refused' && ($runtime->outbox[$thrown->messageId]['status'] ?? null) === 'refused', 'a handover that was refused is a send that was refused, not a bare error', $thrown === null ? 'nothing was thrown' : get_class($thrown) . ': ' . $thrown->getMessage());
$check(is_array($thrown->opened ?? null) && isset($runtime->channels[$thrown->opened['label']]), 'and the caller is told which channel is open, since it is: ' . json_encode($thrown->opened ?? null));
$runtime->close();


// A repair that never looked.
//
// appendPrivate opened the file with fopen($path, 'ab') and then read its last
// byte from that same handle. An append handle is write-only, so fread gave
// false, false !== "\n" is true, and the repair fired on every append: a blank
// line before every record after the first, and a torn line 'repaired' by luck
// rather than by looking. PHP said so fifty-four times a run, as a Notice
// nobody read. aamio-python had it right: it opens a second handle to read.
$plain = sys_get_temp_dir() . '/aamio-append-' . getmypid() . '.jsonl';
@unlink($plain);

foreach ([1, 2, 3] as $which) {
    Storage::appendPrivate($plain, '{"n":' . $which . '}');
}

$written = (string) file_get_contents($plain);
$check($written === '{"n":1}' . "\n" . '{"n":2}' . "\n" . '{"n":3}' . "\n", 'three appends to a whole file write three lines and nothing between them', str_replace("\n", '[LF]', $written));

// And the repair still does its job where there is something to repair.
file_put_contents($plain, '{"n":1}' . "\n" . '{"n":2');
Storage::appendPrivate($plain, '{"n":3}');
$repaired = (string) file_get_contents($plain);
$check($repaired === '{"n":1}' . "\n" . '{"n":2' . "\n" . '{"n":3}' . "\n", 'a last line with no end costs that line only, and the next record starts on its own', str_replace("\n", '[LF]', $repaired));
@unlink($plain);


// A message is only called archived when something was written.
//
// Found by a Codex project review, 20 September 2026 (F2). archive() returned
// without writing when the folder keeps nothing, and the receiving loop set
// archived => true regardless, so an application was told to look in an archive
// that does not exist. Off is still not a failure: no archive_error, and the
// reason is said.
$quiet = sys_get_temp_dir() . '/aamio-archive-off-' . getmypid();
@mkdir($quiet . '/archive', 0700, true);
$offRuntime = new Runtime($quiet, null, [], false);

$check($offRuntime->archive('board', ['kind' => 'received', 'at' => 1]) === false, 'a folder that keeps nothing says it wrote nothing');
$check(!is_file($quiet . '/archive/board.jsonl'), 'and no file appears');

$loudHome = sys_get_temp_dir() . '/aamio-archive-on-' . getmypid();
@mkdir($loudHome . '/archive', 0700, true);
$onRuntime = new Runtime($loudHome);
$check($onRuntime->archive('board', ['kind' => 'received', 'at' => 1]) === true, 'a folder that keeps its archive says it wrote');
$check(is_file($loudHome . '/archive/board.jsonl'), 'and the file is there');
$offRuntime->close();
$onRuntime->close();
