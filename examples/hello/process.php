<?php

declare(strict_types=1);

// The process aamio runs for each call: one JSON request on standard input, one
// JSON answer on standard output. The whole contract is at
// https://aamio.at/docs/addons.
$request = json_decode((string) stream_get_contents(STDIN), true) ?: [];
if (($request['kind'] ?? null) === 'probe') {
    echo json_encode(['result' => ['ok' => true, 'name' => 'hello', 'version' => '0.1.0', 'aamio_addon' => 2], 'exit_code' => 0]);
    exit(0);
}
$to = $request['arguments']['to'] ?? null;
echo json_encode(['result' => ['hello' => is_string($to) && $to !== '' ? $to : 'world'], 'exit_code' => 0, 'is_error' => false]);
