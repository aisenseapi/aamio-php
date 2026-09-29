<?php

declare(strict_types=1);

/*
 * One owner per home, taken in one step.
 *
 * A review on 29 September 2026: taking the home was three steps, a read of the
 * pid file, a check and a write, and two runtimes started at once could both
 * read that nobody had it and both go on. The operating system's lock on
 * owner.lock is one step, and it goes with its process, however that ends.
 *
 * Included by tests/runtime.php after runtime-lock-status.inc.php, with $root,
 * $check and $childPhp in scope. No network: the runtimes here never ask one.
 */

use Aamio\Runtime;

echo "one owner per home, taken in one step\n";

$ownerRoot = $root . DIRECTORY_SEPARATOR . 'owner-lock';
mkdir($ownerRoot, 0700, true);
$ownerBootstrap = var_export(__DIR__ . '/bootstrap.php', true);

if (!isset($childPhp)) {
    echo "  skip  one owner per home: no child PHP here\n";
} else {
    // Four runtimes wait at a line, then all start on one home at once.
    $ownerHome = $ownerRoot . DIRECTORY_SEPARATOR . 'at-once';
    $ownerGo = $ownerRoot . DIRECTORY_SEPARATOR . 'go';
    $ownerDone = $ownerRoot . DIRECTORY_SEPARATOR . 'done';
    $ownerStarter = $ownerRoot . DIRECTORY_SEPARATOR . 'starter.php';
    file_put_contents($ownerStarter, "<?php\ndeclare(strict_types=1);\nrequire " . $ownerBootstrap . ";\n[, \$home, \$go, \$done] = \$argv;\nwhile (!file_exists(\$go)) {\n    usleep(5000);\n}\ntry {\n    \$runtime = new Aamio\\Runtime(\$home, 'https://fake.test', ['at-once'], false, static function (string \$line): void {\n    });\n} catch (RuntimeException \$error) {\n    echo 'refused: ' . str_replace(\"\\n\", ' ', \$error->getMessage()) . \"\\n\";\n    exit(0);\n}\necho \"owned\\n\";\nfflush(STDOUT);\nwhile (!file_exists(\$done)) {\n    usleep(10000);\n}\n\$runtime->close();\n");
    $ownerRunning = [];
    for ($n = 0; $n < 4; $n++) {
        $pipes = [];
        $ownerRunning[] = [proc_open(array_merge($childPhp, [$ownerStarter, $ownerHome, $ownerGo, $ownerDone]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    usleep(1000000);
    touch($ownerGo);
    $ownerSaid = [];
    foreach ($ownerRunning as [, $pipes]) {
        $ownerSaid[] = trim((string) fgets($pipes[1]));
    }
    touch($ownerDone);
    foreach ($ownerRunning as [$process, $pipes]) {
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (trim($err) !== '') {
            $ownerSaid[] = 'stderr: ' . trim($err);
        }
    }
    $ownerRefused = array_filter($ownerSaid, static fn (string $line): bool => $line !== 'owned');
    $check(
        count(array_keys($ownerSaid, 'owned', true)) === 1 && count($ownerRefused) === 3 && array_filter($ownerRefused, static fn (string $line): bool => !str_starts_with($line, 'refused: another aamio')) === [],
        'four runtimes started on one home at once leave one owner, and the other three say who has it',
        implode(' | ', $ownerSaid)
    );

    // An owner that ends without letting go: the lock goes with its process,
    // and the pid file it left says it held the lock, so no pid is asked about.
    $crashedHome = $ownerRoot . DIRECTORY_SEPARATOR . 'crashed';
    $ownerCrasher = $ownerRoot . DIRECTORY_SEPARATOR . 'crasher.php';
    file_put_contents($ownerCrasher, "<?php\ndeclare(strict_types=1);\nrequire " . $ownerBootstrap . ";\n\$runtime = new Aamio\\Runtime(\$argv[1], 'https://fake.test', ['crashed'], false, static function (string \$line): void {\n});\necho \"held\\n\";\nexit(0);\n");
    [, $crashedOut, $crashedErr] = $cliRun(array_merge($childPhp, [$ownerCrasher, $crashedHome]));
    $crashedLeft = json_decode((string) @file_get_contents($crashedHome . DIRECTORY_SEPARATOR . 'lock'), true);
    $asked = [];
    $taker = (new ReflectionClass(Runtime::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Runtime::class, 'home'))->setValue($taker, $crashedHome);
    (new ReflectionProperty(Runtime::class, 'host'))->setValue($taker, 'https://fake.test');
    $takerRefused = '';
    try {
        // Where no pid could be asked about at all, the lock still says enough.
        (new ReflectionMethod(Runtime::class, 'takeLock'))->invoke($taker, static function (int $pid) use (&$asked): ?bool {
            $asked[] = $pid;

            return null;
        });
    } catch (RuntimeException $error) {
        $takerRefused = $error->getMessage();
    }
    $takerNow = json_decode((string) @file_get_contents($crashedHome . DIRECTORY_SEPARATOR . 'lock'), true);
    $check(
        trim($crashedOut) === 'held' && ($crashedLeft['os_lock'] ?? null) === true && $takerRefused === '' && $asked === [] && ($takerNow['pid'] ?? null) === getmypid() && ($takerNow['os_lock'] ?? null) === true,
        'an owner that ended without letting go is taken over without asking about its pid',
        json_encode([$crashedOut, $crashedErr, $crashedLeft, $takerRefused, $asked])
    );
    $taker->close();
    $check(!file_exists($crashedHome . DIRECTORY_SEPARATOR . 'lock'), 'and closing lets go of the pid file and the lock');

    // A pid file from before owner.lock names a live owner that holds no lock.
    $olderHome = $ownerRoot . DIRECTORY_SEPARATOR . 'older';
    mkdir($olderHome, 0700, true);
    file_put_contents($olderHome . DIRECTORY_SEPARATOR . 'lock', json_encode(['pid' => 999999999, 'at' => time(), 'host' => 'https://fake.test']));
    $older = (new ReflectionClass(Runtime::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Runtime::class, 'home'))->setValue($older, $olderHome);
    (new ReflectionProperty(Runtime::class, 'host'))->setValue($older, 'https://fake.test');
    $olderRefused = '';
    try {
        (new ReflectionMethod(Runtime::class, 'takeLock'))->invoke($older, static fn (int $pid): ?bool => true);
    } catch (RuntimeException $error) {
        $olderRefused = $error->getMessage();
    }
    $older->close();
    $afterOlder = new Runtime($ownerRoot . DIRECTORY_SEPARATOR . 'older-free', 'https://fake.test', ['older-free'], false, static function (string $line): void {
    });
    $afterOlder->close();
    $check(str_contains($olderRefused, 'another aamio (pid 999999999) is using'), 'a pid file from before owner.lock still keeps a runtime out', $olderRefused);
}
