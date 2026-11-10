<?php

declare(strict_types=1);

/*
 * What the release checks of 9 November 2026 found in the Node runtime, where
 * this runtime had it too (code/php/docs/aamio-node-utveksling-gjennomgang-2026-11-09.md
 * in the service). A change to the address book or to the inbox was put in
 * place before the file that holds it was written, so a write that failed left
 * this process on one state and the next start on another. A lookup by name
 * matched without regard to case. A partner form over 2026-07-28 was not bound
 * to the entry it was shown for, so an answer to an older form replaced a newer
 * key without a question. The way by hand named no home and no shell. The same
 * checks as aamio-python's tests/test_release_check_2026_11_09.py.
 *
 * Included by tests/runtime.php after partner-add.inc.php, with its helpers,
 * $fake, $root and $check in scope. A write is made to fail by a folder where
 * its temporary file goes.
 */

use Aamio\AmbiguousPartner;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

echo "the release checks of 9 November: a change counts once it is written, names are chosen, forms are bound\n";

$rcQuiet = static function (string $line): void {
};
$rcHomes = 0;
$rcHome = static function () use ($root, &$rcHomes): string {
    $home = $root . DIRECTORY_SEPARATOR . 'release-check-' . (++$rcHomes);
    mkdir($home, 0700, true);

    return $home;
};
$rcOpen = static fn (string $home): Runtime => new Runtime($home, 'https://fake.test', ['rc'], false, $rcQuiet);
/** Writes of this file fail while the folder in the way of its temporary file is there. */
$blocked = static function (Runtime $runtime, string $file, bool $on): void {
    $tmp = $runtime->home . DIRECTORY_SEPARATOR . $file . '.tmp';
    if ($on && !is_dir($tmp)) {
        mkdir($tmp);
    } elseif (!$on && is_dir($tmp)) {
        rmdir($tmp);
    }
};
$caughtBy = static function (callable $try): ?\Throwable {
    try {
        $try();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
};

// A partner change that could not be written is not in this process either.
$home = $rcHome();
$a = $rcOpen($home);
$first = Keys::generate()->public;
$a->partnerAdd('carol', $first);
$a->ensureInbox();
$allow = $a->channels['inbox']->allow;
$blocked($a, 'partners.json', true);
$failedAdd = $caughtBy(static fn () => $a->partnerAdd('carol', Keys::generate()->public));
$failedNew = $caughtBy(static fn () => $a->partnerAdd('dave', Keys::generate()->public));
$failedRemove = $caughtBy(static fn () => $a->partnerRemove('carol'));
$check($failedAdd instanceof \RuntimeException && $failedNew instanceof \RuntimeException && $failedRemove instanceof \RuntimeException && $bookOf($a) === ['carol' => $first], 'a partner change partners.json could not take is not made in this process either');
$blocked($a, 'partners.json', false);
$a->ensureInbox();
$check($a->channels['inbox']->allow === $allow, 'and the next read keeps the inbox\'s list: no key the book does not hold can write');
$a->close();
$a = $rcOpen($home);
$check($bookOf($a) === ['carol' => $first], 'and a restart finds the same book');
$a->close();

// An inbox that state.json does not hold is never used.
$home = $rcHome();
$a = $rcOpen($home);
$blocked($a, 'state.json', true);
$noInbox = $caughtBy(static fn () => $a->ensureInbox());
$check($noInbox instanceof \RuntimeException && str_contains($noInbox->getMessage(), 'could not be written down') && !isset($a->channels['inbox']) && in_array('rotation_failed', array_column($a->attentionTaken(), 'state'), true), 'a first inbox that cannot be written down is not kept, and it is said');
$blocked($a, 'state.json', false);
$inbox = $a->ensureInbox();
$written = json_decode((string) file_get_contents($home . DIRECTORY_SEPARATOR . 'state.json'), true);
$check(array_column($written['channels'] ?? [], 'read_key', 'label')['inbox'] === $inbox->readKey, 'the inbox used is the inbox written');
$blocked($a, 'state.json', true);
$outcome = $a->partnerAdd('carol', Keys::generate()->public);
$check(($outcome['rotated'] ?? null) === false && str_contains((string) ($outcome['error'] ?? ''), 'could not be written down') && array_keys($a->channels) === ['inbox'] && $a->channels['inbox'] === $inbox && $inbox->label === 'inbox' && $inbox->muted === false, 'an inbox to open again for a new partner, with the disk full: the old one stays, as the file has it', json_encode($outcome));
$noBoard = $caughtBy(static fn () => $a->ensureBoardInbox(600));
$check($noBoard instanceof \RuntimeException && str_contains($noBoard->getMessage(), 'board inbox could not be written down') && !isset($a->channels['board']), 'a board inbox that cannot be written down is never put on a post');
$blocked($a, 'state.json', false);
$a->close();

// A lookup by name chooses as a send does.
$s = $rcOpen($rcHome());
$one = $rcOpen($rcHome());
$two = $rcOpen($rcHome());
foreach ([$one, $two] as $target) {
    $target->partnerAdd('s', $s->keys->public);
    $target->ensureInbox();
}
$s->partners = [['name' => 'Ada', 'key' => $one->keys->public], ['name' => 'ADA', 'key' => $two->keys->public]];
$exact = $s->lookup(['ADA']);
$check(array_column($exact['online'], 'name') === ['ADA'] && array_column($exact['online'], 'w') === [$two->channels['inbox']->w], 'a lookup of ADA answers for ADA alone, at its own address');
$check($caughtBy(static fn () => $s->lookup(['ada'])) instanceof AmbiguousPartner, 'a name that is both apart from case is an error, never two answers');
$some = $s->lookup(['nobody', 'Ada', 'Ada']);
$check(array_column($some['online'], 'name') === ['Ada'] && ($some['unknown'] ?? null) === ['nobody'], 'a name given twice is looked up once, and an unknown one is said');
foreach ([$s, $one, $two] as $runtime) {
    $runtime->close();
}

// Two open forms for one new contact: the older answer asks again before it replaces the newer.
$r = $askRuntime();
$server = new McpServer($r);
$keyA = Keys::generate()->public;
$keyB = Keys::generate()->public;
$older = $firstCall($server);
$newer = $firstCall($server);
$check(($saidBy($again($server, $newer, $accept($keyA)))['added'] ?? null) === true, 'the newer form, answered first, adds A');
$late = $again($server, $older, $accept($keyB));
$lateMessage = (string) ($late['result']['inputRequests'][McpServer::PARTNER_QUESTION]['params']['message'] ?? '');
$check(($late['result']['resultType'] ?? null) === 'input_required' && str_contains($lateMessage, 'was put in your address book with another key') && str_contains($lateMessage, 'replaces that one') && ($bookOf($r)['carol'] ?? null) === $keyA, 'the older answer is not applied: the user is asked whether to replace A, and A stays meanwhile', json_encode($late));
$cancelled = $again($server, $late, ['action' => 'cancel']);
$check(($saidBy($cancelled)['added'] ?? null) === false, 'the new question cancelled adds nothing');
$home = $r->home;
$r->close();
$r = $rcOpen($home);
$check(($bookOf($r)['carol'] ?? null) === $keyA, 'after a restart A is still there');
$server = new McpServer($r);
$asked = $firstCall($server);
$check(str_contains((string) ($asked['result']['inputRequests'][McpServer::PARTNER_QUESTION]['params']['message'] ?? ''), 'is in your address book already, and the key you give replaces'), 'a new question says the key replaces the one there');
$check(($saidBy($again($server, $asked, $accept($keyB)))['added'] ?? null) === true && ($bookOf($r)['carol'] ?? null) === $keyB, 'and a replacement the user approved as one is made');
$r->close();

// An older form answered with the key the newer one put there changes nothing and asks nothing.
$r = $askRuntime();
$server = new McpServer($r);
$same = Keys::generate()->public;
$older = $firstCall($server);
$newer = $firstCall($server);
$again($server, $newer, $accept($same));
$sameReply = $again($server, $older, $accept($same));
$check(($sameReply['result']['resultType'] ?? null) === 'complete' && ($saidBy($sameReply)['added'] ?? null) === true && ($bookOf($r)['carol'] ?? null) === $same, 'an older form answered with the key already there is no conflict');
$r->close();

// The way by hand runs as written in the shell it names, and changes this server's home only.
$place = $root . DIRECTORY_SEPARATOR . 'by-hand';
$served = $place . DIRECTORY_SEPARATOR . "Aamio-%TEAM% \$TEAM it's" . DIRECTORY_SEPARATOR . 'home';
$other = $place . DIRECTORY_SEPARATOR . 'the default home';
mkdir($served, 0700, true);
$r = $rcOpen($served);
$server = new McpServer($r);
$reply = $server->handle($askRequest($adding('bob'), []));
$hand = $saidBy($reply)['by_hand'] ?? [];
$r->close();
$bob = Keys::generate()->public;
$command = preg_replace('/ KEY$/', ' ' . $bob, (string) ($hand['command'] ?? ''));
$shell = ($hand['shell'] ?? '') === 'PowerShell' ? ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $command] : ['sh', '-c', $command];
$environment = getenv() + [];
$environment['TEAM'] = 'blue';
$environment['AAMIO_HOME'] = $other;
$environment['AAMIO_HOST'] = 'http://127.0.0.1:9';
$process = proc_open($shell, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
$book = json_decode((string) @file_get_contents($served . DIRECTORY_SEPARATOR . 'partners.json'), true);
$entries = array_values(array_diff(scandir($place) ?: [], ['.', '..']));
sort($entries);
$check($status === 0 && is_array($book) && array_column($book, 'name') === ['bob'] && !is_file($other . DIRECTORY_SEPARATOR . 'partners.json') && $entries === ["Aamio-%TEAM% \$TEAM it's"], 'the way by hand, run as written in ' . ($hand['shell'] ?? '?') . ', changes the home this server holds and no other', $status . ' ' . $out . ' ' . json_encode($entries));

// A lookup that fails still says which names are nobody's, as one that succeeds does.
$r = $rcOpen($rcHome());
$r->partnerAdd('Ada', Keys::generate()->public);
$fake->lookupDown = true;
$downLookup = $r->lookup(['Ada', 'nobody']);
$fake->lookupDown = false;
$check(($downLookup['online'] ?? null) === [] && ($downLookup['offline'] ?? null) === ['Ada'] && str_contains((string) ($downLookup['error'] ?? ''), '503') && ($downLookup['unknown'] ?? null) === ['nobody'], 'a lookup the service fails still says which names are nobody\'s', json_encode($downLookup));
$r->close();
