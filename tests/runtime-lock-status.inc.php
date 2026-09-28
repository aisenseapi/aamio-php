<?php

declare(strict_types=1);

/* Included by runtime.php. Local process probes only; no network or real home. */

echo "a process inspection that fails cannot release somebody else's lock\n";

$lockParse = new ReflectionMethod(\Aamio\Runtime::class, 'windowsPidAlive');
$lockRun = new ReflectionMethod(\Aamio\Runtime::class, 'processStatusCommand');
$lockTake = new ReflectionMethod(\Aamio\Runtime::class, 'takeLock');
$lockPid = new ReflectionMethod(\Aamio\Runtime::class, 'pidAlive');
$lockTarget = 999999999;
$lockSelf = '"php.exe","' . getmypid() . '","Console","1","12,000 K"' . "\r\n";
$lockOther = '"owner.exe","' . $lockTarget . '","Console","1","1,024 K"' . "\r\n";
$lockCases = [
    'live owner' => [[0, $lockSelf . $lockOther, ''], true],
    'proven absent owner' => [[0, $lockSelf, ''], false],
    'access denied with exit 1' => [[1, '', 'Access denied'], null],
    'exit 1 despite plausible output' => [[1, $lockSelf, ''], null],
    'unexpected empty output' => [[0, '', ''], null],
    'malformed output' => [[0, $lockSelf . 'not a CSV process row', ''], null],
    'localized no-match text' => [[0, 'INFO: No tasks are running which match the specified criteria.', ''], null],
    'missing positive control' => [[0, $lockOther, ''], null],
    'duplicate process row' => [[0, $lockSelf . $lockSelf, ''], null],
    'partial warning on stderr' => [[0, $lockSelf, 'some processes could not be listed'], null],
    'timeout or failed launch' => [null, null],
];
$lockHomeRoot = $root . DIRECTORY_SEPARATOR . 'lock-status';
mkdir($lockHomeRoot, 0700, true);
foreach ($lockCases as $label => [$answer, $expected]) {
    $actual = $lockParse->invoke(null, $lockTarget, $answer);
    $check($actual === $expected, 'process status: ' . $label);
    $folder = $lockHomeRoot . DIRECTORY_SEPARATOR . count((array) glob($lockHomeRoot . DIRECTORY_SEPARATOR . '*'));
    mkdir($folder, 0700);
    $originalLock = json_encode(['pid' => $lockTarget, 'at' => time(), 'host' => 'https://fake.test']);
    $partners = '[{"name":"kept","key":"not read by the lock check"}]';
    file_put_contents($folder . DIRECTORY_SEPARATOR . 'lock', $originalLock);
    file_put_contents($folder . DIRECTORY_SEPARATOR . 'partners.json', $partners);
    $runtime = (new ReflectionClass(\Aamio\Runtime::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(\Aamio\Runtime::class, 'home'))->setValue($runtime, $folder);
    (new ReflectionProperty(\Aamio\Runtime::class, 'host'))->setValue($runtime, 'https://fake.test');
    $refusal = '';
    try {
        $lockTake->invoke($runtime, static fn (int $pid): ?bool => $lockParse->invoke(null, $pid, $answer));
    } catch (\RuntimeException $error) {
        $refusal = $error->getMessage();
    }
    $lockNow = (string) file_get_contents($folder . DIRECTORY_SEPARATOR . 'lock');
    if ($expected === false) {
        $check($refusal === '' && (json_decode($lockNow, true)['pid'] ?? null) === getmypid(), 'a proven absent owner can still be replaced');
    } else {
        $check($refusal !== '' && $lockNow === $originalLock && file_get_contents($folder . DIRECTORY_SEPARATOR . 'partners.json') === $partners && ($expected === true || str_contains($refusal, 'could not determine')), $label . ' leaves the existing lock and partners untouched');
    }
    $runtime->close();
}
$check($lockPid->invoke(null, 0) === null && $lockPid->invoke(null, -1) === null, 'an invalid pid is unknown, never a process group query');

// An isolated child's namespaced functions stand in for a restricted /proc;
// the host process and its real operating-system functions are unchanged.
$lockProcProbe = 'namespace Aamio { '
    . 'function function_exists($name) { return $name === "posix_kill" ? false : \\function_exists($name); } '
    . 'function is_dir($path) { return $path === "/proc"; } '
    . '} namespace { require $argv[1]; '
    . '$probe = new \\ReflectionMethod(\\Aamio\\Runtime::class, "pidAlive"); '
    . 'echo json_encode($probe->invoke(null, 999999999)); }';
$lockResult = $lockRun->invoke(null, [PHP_BINARY, '-n', '-r', $lockProcProbe, __DIR__ . '/bootstrap.php']);
$check($lockResult === [0, 'null', ''], 'without POSIX inspection a pid absent from a restricted /proc stays unknown');

// Real child processes exercise exit handling and the timeout, including on
// Windows where a pipe read can block despite stream_set_blocking(false).
$lockResult = $lockRun->invoke(null, [PHP_BINARY, '-n', '-r', 'echo "out"; fwrite(STDERR, "err"); exit(1);']);
$check($lockResult === [1, 'out', 'err'], 'the process runner preserves exit status and separates stdout from stderr');
$lockResult = $lockRun->invoke(null, [PHP_BINARY, '-n', '-r', 'echo base64_decode($argv[1]);', base64_encode($lockSelf)]);
$check($lockParse->invoke(null, $lockTarget, $lockResult) === false, 'successful complete output can prove the target absent');
$lockResult = $lockRun->invoke(null, [PHP_BINARY, '-n', '-r', 'echo base64_decode($argv[1]); exit(1);', base64_encode($lockSelf)]);
$check($lockParse->invoke(null, $lockTarget, $lockResult) === null, 'a real exit 1 cannot make plausible process output authoritative');
$lockStarted = microtime(true);
$lockResult = $lockRun->invoke(null, [PHP_BINARY, '-n', '-r', 'usleep(5000000);'], 0.2);
$check($lockResult === null && microtime(true) - $lockStarted < 2.0, 'a real slow child is stopped at the deadline rather than waited out');
$lockResult = $lockRun->invoke(null, [PHP_BINARY, '-n', '-r', 'echo str_repeat("x", 1048577);']);
$check($lockResult === null, 'oversized process output stays inconclusive');
$lockResult = $lockRun->invoke(null, [$lockHomeRoot . DIRECTORY_SEPARATOR . 'missing-executable']);
$check($lockParse->invoke(null, $lockTarget, $lockResult) === null, 'a command that cannot start stays inconclusive');

unset($lockParse, $lockRun, $lockTake, $lockPid, $lockCases, $lockResult, $runtime);
