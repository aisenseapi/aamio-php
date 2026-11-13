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
    // error_code is the code the three runtimes share, never the name of a class.
    $check($closeStatus === 1 && is_array($closed) && array_keys($closed) === ['error', 'error_code'] && $closed['error'] === 'no such channel: no-such-channel' && $closed['error_code'] === 'unknown_channel', 'a command that cannot do what it was asked answers with JSON on stdout, error and the shared error_code, and exit 1', json_encode([$closeStatus, $closeOut, $closeErr]));
    // A partner's name or key and send --re are the command's answer, as JSON
    // with 1, as in the other two, and no home is made for them.
    $KEY = str_repeat('w', 42) . 'A';
    foreach ([[['partner', 'add', 'bob', 'not-a-key'], 'invalid_key'], [['partner', 'add', str_repeat('x', 65), $KEY], 'invalid_name'], [['partner', 'add', "a\tb", $KEY], 'invalid_name'], [['send', 'bob', 'hi', '--re', 'xyz'], 'invalid_re']] as [$words, $code]) {
        $unmade = $cliDir . DIRECTORY_SEPARATOR . 'home-answer-' . $code . '-' . count($words);
        [$answerStatus, $answerOut, $answerErr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $unmade, '--host', 'http://127.0.0.1:9'], $words));
        $told = json_decode($answerOut, true);
        $check($answerStatus === 1 && is_array($told) && ($told['error_code'] ?? null) === $code && !file_exists($unmade), 'and so is ' . implode(' ', array_slice($words, 0, 2)) . ' with ' . $code . ', before any home is made', json_encode([$answerStatus, $answerOut, $answerErr]));
    }
    // What can be seen to be wrong from the command line alone is refused
    // before the home is opened or made, on stderr with 2, as aamio-python and
    // the Node runtime refuse it. It was refused after the home was made, with
    // 1, and a number that was no number was taken as 0.
    foreach ([['read', '--limit', '0'], ['read', '--limit', 'x'], ['read', '--max-bytes', '100'], ['read', '--frob', '1'], ['lookup', '--wait', 'x'], ['trace', '--limit', '51'],
        ['send', 'bob'], ['send', 'bob', 'hi', '--data', '{not json'], ['board', 'answer', 'post', 'text', '--data', '{not json'], ['channel', 'open', 'room', '--gate', '{not json'],
        ['channel', 'open', 'room', '--gate', '[]'], ['channel', 'open', '../x'], ['channel', 'open', 'room', '--ttl', '5'], ['board', 'post', 'maybe', 't', 'x'],
        ['board', 'post', 'need', 't', 'x', '--ttl', '5'], ['board', 'find', '--min-work-bits', '99'], ['board', 'channel', $KEY, '--reply-to', 'bad'], ['board', 'channel', $KEY, '--note', 'hi'],
        ['board', 'replies', '--wait', '99'], ['scope', 'new', 'bad/name'], ['scope', 'add', 'team'], ['scope', 'share', 'team', 'bob'], ['scope', 'frob'], ['archive', 'keep', '--all'],
        ['archive', 'status', '--max-mb', '5'], ['archive', 'keep', '--max-mb', 'inf'], ['init', '--archive', 'sometimes'], ['frobnicate']] as $at => $words) {
        $unmade = $cliDir . DIRECTORY_SEPARATOR . 'home-usage-' . $at;
        [$usageStatus, $usageOut, $usageErr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $unmade, '--host', 'http://127.0.0.1:9'], $words));
        $check($usageStatus === 2 && $usageOut === '' && trim($usageErr) !== '' && !file_exists($unmade), implode(' ', $words) . ' is refused before the home, on stderr with 2', json_encode([$usageStatus, $usageOut, $usageErr]));
    }
    // A name is counted in characters, as in the other two.
    \Aamio\Runtime::checkPartner(str_repeat("\u{1F600}", 64), $KEY);
    try {
        \Aamio\Runtime::checkPartner(str_repeat("\u{1F600}", 65), $KEY);
        $longName = null;
    } catch (\Aamio\CodedInvalidArgument $wrong) {
        $longName = $wrong->errorCode();
    }
    $check($longName === 'invalid_name', 'a partner\'s name of 64 characters outside the basic plane is taken, and one of 65 is not', (string) $longName);
    $check(\Aamio\Runtime::errorCode(new \RuntimeException('x')) === 'RuntimeException' && \Aamio\Runtime::errorCode(new \Aamio\CodedRuntime('not_saved', 'x')) === 'not_saved', 'an error without a shared code is said by its class');
    // Over MCP a failure carries the same code, as in the other two.
    $mcpHome = $cliDir . DIRECTORY_SEPARATOR . 'home-mcp-codes';
    $codesRuntime = new \Aamio\Runtime($mcpHome, 'http://127.0.0.1:9', null, false);
    try {
        $codesServer = new \Aamio\McpServer($codesRuntime);
        $codesServer->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
        $codeOf = static fn (string $tool, array $arguments): mixed => $codesServer->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])['result']['structuredContent']['error_code'] ?? null;
        // What the schema takes and the runtime does not is the runtime's to name.
        $codes = [$codeOf('aamio_close_channel', ['label' => 'nope']), $codeOf('aamio_open_channel', ['label' => '../x', 'ttl' => 600])];
        $check($codes === ['unknown_channel', 'invalid_label'], 'over MCP a failure carries the code the command line says', json_encode($codes));
        // Arguments that do not fit the tool's schema are the call's answer, as
        // in the other two, where a model reads what is wrong. A name spelt
        // wrong was passed over here, and a value of another type went on to
        // the runtime.
        $answerOf = static fn (string $tool, mixed $arguments): ?array => $codesServer->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
        foreach ([
            ['aamio_send', ['to' => 'b', 'txt' => 'typo'], 'aamio_send takes no argument called txt'],
            ['aamio_close_channel', [], 'aamio_close_channel needs label'],
            ['aamio_open_channel', ['label' => 'room', 'ttl' => 5], 'ttl is not what aamio_open_channel takes: '],
            ['aamio_open_channel', ['label' => 'room', 'ttl' => '600'], 'ttl is not what aamio_open_channel takes: '],
            ['aamio_open_channel', ['label' => 'room', 'ttl' => 600, 'gate' => [1]], 'gate is not what aamio_open_channel takes: '],
            ['aamio_open_channel', ['label' => '', 'ttl' => 600], 'label is not what aamio_open_channel takes: '],
            ['aamio_send', ['to' => 'b', 're' => str_repeat('a', 64) . "\n"], 're is not what aamio_send takes: '],
            ['aamio_read', ['limit' => 999], 'limit is not what aamio_read takes: '],
            ['aamio_receipt', ['anchor' => 'yes'], 'anchor is not what aamio_receipt takes: '],
            ['aamio_outbox_forget', ['id' => ''], 'id is not what aamio_outbox_forget takes: '],
        ] as [$tool, $arguments, $said]) {
            $result = $answerOf($tool, $arguments)['result'] ?? [];
            $check(($result['isError'] ?? null) === true && ($result['structuredContent']['error_code'] ?? null) === 'invalid_arguments' && str_starts_with((string) ($result['structuredContent']['error'] ?? ''), $said) && str_contains((string) ($result['structuredContent']['fix'] ?? ''), 'inputSchema'), 'over MCP ' . $tool . ' ' . json_encode($arguments) . ' is the call\'s answer, invalid_arguments', json_encode($result['structuredContent'] ?? null));
        }
        $listed = $answerOf('aamio_send', ['b', 'x']);
        $check(($listed['error'] ?? null) === ['code' => -32602, 'message' => 'arguments must be an object'] && !isset($listed['result']), 'arguments that are no object are a request that cannot be read', json_encode($listed));
    } finally {
        $codesRuntime->close();
    }
    // trace of a counterpart nothing is recorded about is the one said on
    // stderr, with nothing on stdout, in all three alike.
    [$traceStatus, $traceOut, $traceErr] = $failing('trace', 'nobody-at-all');
    $check($traceStatus === 1 && $traceOut === '' && str_contains($traceErr, 'nothing sent to or received from nobody-at-all is recorded here'), 'trace of a counterpart nothing is recorded about says so on stderr, as the other two say it', json_encode([$traceStatus, $traceOut, $traceErr]));
    // serve starts without the service, as the Node runtime's does, says on
    // stderr that the inbox could not be opened yet, and answers on stdout,
    // which is the protocol's alone. It stopped with the reason, and a host
    // that started it while the network was down showed a server that had
    // failed. initialize is answered, and its input then ends.
    $serveErrFile = (string) tempnam(sys_get_temp_dir(), 'aamio-serve-');
    $serving = proc_open(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $cliDir . DIRECTORY_SEPARATOR . 'home-serve', '--host', 'http://127.0.0.1:9', 'serve']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $serveErrFile, 'w']], $servePipes);
    $serveStatus = -1;
    $serveOut = '';
    if (is_resource($serving)) {
        fwrite($servePipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '0']]]) . "\n");
        fclose($servePipes[0]);
        $serveOut = (string) stream_get_contents($servePipes[1]);
        fclose($servePipes[1]);
        $serveStatus = proc_close($serving);
    }
    $serveErr = (string) @file_get_contents($serveErrFile);
    @unlink($serveErrFile);
    $served = array_map(static fn (string $line): mixed => json_decode($line, true), array_values(array_filter(explode("\n", $serveOut), static fn (string $line): bool => trim($line) !== '')));
    $protocol = $served !== [] && array_filter($served, static fn (mixed $line): bool => !is_array($line) || ($line['jsonrpc'] ?? null) !== '2.0') === [];
    $answered = array_values(array_map(static fn (array $line): mixed => $line['id'] ?? null, array_filter($served, static fn (mixed $line): bool => is_array($line) && isset($line['result']))));
    $check($serveStatus === 0 && $protocol && $answered === [1] && str_contains($serveErr, 'the inbox could not be opened yet'), "serve without the service starts, answers on stdout, which is the protocol's, and says on stderr that the inbox could not be opened yet", json_encode([$serveStatus, substr($serveOut, 0, 200), substr($serveErr, -300)]));
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
