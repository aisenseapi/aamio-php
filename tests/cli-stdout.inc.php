<?php

declare(strict_types=1);

/*
 * What the command line prints on stdout is JSON and nothing else, whatever PHP
 * has to say while it runs.
 *
 * C1 of the follow-up review of 25 September 2026: Http called curl_close(),
 * which has done nothing since PHP 8.0 and is deprecated from 8.5, and on 8.5
 * the notice was printed on stdout ahead of the JSON, so `aamio doctor` exited
 * 0 with an answer no parser would take. The call is gone, and the command line
 * sends diagnostics to stderr. Here a deprecation is raised in a real run with
 * every notice switched on, after the command line has started, as the
 * transport's was: once against a service that answers and once against a
 * port where nothing listens, so both ways out of the transport are covered.
 *
 * Included by tests/runtime.php, with $root and $check in scope.
 */

echo "the command line prints JSON on stdout and diagnostics on stderr\n";

// Calls, as PHP reads the code: a comment that names the function is not one.
$closeCalls = [];
foreach (array_merge((array) glob(dirname(__DIR__) . '/src/*.php'), [dirname(__DIR__) . '/bin/aamio']) as $source) {
    foreach (token_get_all((string) file_get_contents((string) $source)) as $token) {
        if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'curl_close') {
            $closeCalls[] = basename((string) $source) . ':' . $token[2];
        }
    }
}
$check($closeCalls === [], 'nothing here calls curl_close(), which has done nothing since PHP 8.0 and is deprecated from 8.5', implode(', ', $closeCalls));

/** A child from a list of arguments, with stdout, stderr and the exit apart. */
$cliRun = static function (array $command): array {
    $errFile = (string) tempnam(sys_get_temp_dir(), 'aamio-cli-');
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
    if (!is_resource($process)) {
        @unlink($errFile);

        return [-1, '', 'could not start ' . $command[0]];
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    $err = (string) @file_get_contents($errFile);
    @unlink($errFile);

    return [$status, $out, $err];
};

// The PHP a child gets: this one, with what the command line needs on its
// command line where the child's own configuration lacks it, and nothing
// loaded twice, which warns.
[, $loaded] = $cliRun([PHP_BINARY, '-r', 'echo strtolower(implode(",", get_loaded_extensions()));']);
$childPhp = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir')];
foreach (['sodium', 'curl'] as $extension) {
    if (!in_array($extension, explode(',', trim($loaded)), true)) {
        $childPhp = array_merge($childPhp, ['-d', 'extension=' . $extension]);
    }
}
[, $able] = $cliRun(array_merge($childPhp, ['-r', 'echo function_exists("curl_init") && function_exists("sodium_crypto_box_seal") ? "yes" : "no";']));

if (trim($able) !== 'yes') {
    echo "  skip  the command line's stdout under visible notices: a child PHP here has no curl or no sodium\n";
} else {
    $cliDir = $root . DIRECTORY_SEPARATOR . 'cli-stdout';
    mkdir($cliDir, 0700, true);
    // As aamio-python and the Node runtime print theirs. It printed the help.
    [$versionStatus, $versionOut, $versionErr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--version']));
    $check($versionStatus === 0 && $versionOut === 'aamio ' . \Aamio\Http::VERSION . "\n", '--version prints aamio and the version, and nothing else', json_encode([$versionStatus, $versionOut, $versionErr]));
    // What a command could not do is its answer: JSON on stdout with error and
    // error_code, and exit 1, as aamio-python and the Node runtime answer it.
    // It was a line on stderr (a check of 13 November 2026). Nothing goes out:
    // the host is a port where nothing listens.
    $failedHome = $cliDir . DIRECTORY_SEPARATOR . 'home-failed';
    $failing = static fn (string ...$words): array => $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $failedHome, '--host', 'http://127.0.0.1:9'], $words));
    [$closeStatus, $closeOut, $closeErr] = $failing('channel', 'close', 'no-such-channel');
    $closed = json_decode($closeOut, true);
    $check($closeStatus === 1 && is_array($closed) && array_keys($closed) === ['error', 'error_code'] && $closed['error'] === 'no such channel: no-such-channel' && $closed['error_code'] === 'InvalidArgumentException', 'a command that cannot do what it was asked answers with JSON on stdout, error and error_code, and exit 1', json_encode([$closeStatus, $closeOut, $closeErr]));
    [$keyStatus, $keyOut, $keyErr] = $failing('partner', 'add', 'bob', 'not-a-key');
    $refused = json_decode($keyOut, true);
    $check($keyStatus === 1 && is_array($refused) && isset($refused['error'], $refused['error_code']), 'and so does an argument that is wrong for the command, as a key that is no key', json_encode([$keyStatus, $keyOut, $keyErr]));
    // trace of a counterpart nothing is recorded about is the one said on
    // stderr, with nothing on stdout, in all three alike.
    [$traceStatus, $traceOut, $traceErr] = $failing('trace', 'nobody-at-all');
    $check($traceStatus === 1 && $traceOut === '' && str_contains($traceErr, 'nothing sent to or received from nobody-at-all is recorded here'), 'trace of a counterpart nothing is recorded about says so on stderr, as the other two say it', json_encode([$traceStatus, $traceOut, $traceErr]));
    // serve's stdout is the protocol's alone. A server that cannot start, here
    // with no service to open its inbox at, says why on stderr, where the host
    // keeps its log, and nothing the host would read as a message. Its input
    // is closed, so a server that did start would end at once, and say 0.
    $serveErrFile = (string) tempnam(sys_get_temp_dir(), 'aamio-serve-');
    $serving = proc_open(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $cliDir . DIRECTORY_SEPARATOR . 'home-serve', '--host', 'http://127.0.0.1:9', 'serve']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $serveErrFile, 'w']], $servePipes);
    $serveStatus = -1;
    $serveOut = '';
    if (is_resource($serving)) {
        fclose($servePipes[0]);
        $serveOut = (string) stream_get_contents($servePipes[1]);
        fclose($servePipes[1]);
        $serveStatus = proc_close($serving);
    }
    $serveErr = (string) @file_get_contents($serveErrFile);
    @unlink($serveErrFile);
    $check($serveStatus === 1 && $serveOut === '' && str_contains($serveErr, 'could not open inbox'), "serve that cannot start says why on stderr, and nothing on stdout, which is the protocol's", json_encode([$serveStatus, $serveOut, substr($serveErr, -300)]));
    // Raised after the command line has started, as the transport's notice was.
    $late = $cliDir . DIRECTORY_SEPARATOR . 'late-notice.php';
    file_put_contents($late, "<?php\nregister_shutdown_function(static function (): void {\n    trigger_error('aamio-test: a deprecation raised during the run', E_USER_DEPRECATED);\n});\n");
    // A service that answers /health and the descriptor with JSON.
    $router = $cliDir . DIRECTORY_SEPARATOR . 'router.php';
    file_put_contents($router, "<?php\nheader('Content-Type: application/json');\necho json_encode(['status' => 'ok', 'service' => 'aamio', 'version' => '0.0.0-test']);\n");
    $freePort = static function (): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    };
    $port = $freePort();
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', $cliDir . DIRECTORY_SEPARATOR . 'server.out', 'w'], 2 => ['file', $cliDir . DIRECTORY_SEPARATOR . 'server.err', 'w']], $serverPipes);
    $up = false;
    for ($i = 0; $i < 100 && !$up; $i++) {
        $up = @file_get_contents('http://127.0.0.1:' . $port . '/health') !== false;
        if (!$up) {
            usleep(50000);
        }
    }
    $doctor = static fn (string $home, string $host): array => $cliRun(array_merge($childPhp, ['-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'auto_prepend_file=' . $late, dirname(__DIR__) . '/bin/aamio', '--home', $home, '--host', $host, 'doctor']));
    $strict = static function (string $text): ?array {
        try {
            $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    };
    [$answeredStatus, $answeredOut, $answeredErr] = $doctor($cliDir . DIRECTORY_SEPARATOR . 'home-answered', 'http://127.0.0.1:' . $port);
    $nobody = $freePort();
    [$silentStatus, $silentOut, $silentErr] = $doctor($cliDir . DIRECTORY_SEPARATOR . 'home-silent', 'http://127.0.0.1:' . $nobody);
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $answered = $strict($answeredOut);
    $silent = $strict($silentOut);
    $check($up && $answeredStatus === 0 && $answered !== null && ($answered['service']['reachable'] ?? null) === true && str_contains($answeredErr, 'aamio-test: a deprecation raised during the run'), 'with a service that answers, stdout is JSON and nothing else, and the notice raised during the run is on stderr', substr($answeredOut, 0, 120) . ' | ' . substr($answeredErr, 0, 120));
    $check($silentStatus === 0 && $silent !== null && ($silent['service']['reachable'] ?? null) === false && str_contains($silentErr, 'aamio-test: a deprecation raised during the run'), 'and with nothing listening, the same: the failure is in the JSON and the notice on stderr', substr($silentOut, 0, 120) . ' | ' . substr($silentErr, 0, 120));
}
