<?php

declare(strict_types=1);

/*
 * The pace the live tests keep, checked without a service.
 *
 * The service allows one address thirty opens and closes of threads a minute.
 * On 28 September 2026 tests/live.php and tests/first-exchange.php made 32
 * between them, and the second one failed on the 429 and not on the code.
 * live-pace.inc.php holds them to twenty a window, counted in a file the live
 * tests of aamio-python keep too. What is checked here is the counting: a
 * clock that is moved by hand, and a sleep that moves it.
 *
 * Included by tests/runtime.php with $root and $check in scope.
 */

use Aamio\Http;

require_once __DIR__ . '/live-pace.inc.php';

echo "the live tests wait their turn\n";

$paceDir = $root . DIRECTORY_SEPARATOR . 'live-pace';
mkdir($paceDir, 0700, true);

/** A pace over its own file, with time that passes only while something sleeps. */
$paceAt = static function (string $name, int $limit = LivePace::LIMIT) use ($paceDir): array {
    $time = new stdClass();
    $time->now = 1790000000.0;
    $time->slept = [];
    $pace = new LivePace($limit, LivePace::WINDOW, $paceDir . DIRECTORY_SEPARATOR . $name, static fn (): float => $time->now, static function (float $seconds) use ($time): void {
        $time->slept[] = $seconds;
        $time->now += $seconds;
    });

    return [$pace, $time];
};

[$pace, $time] = $paceAt('twenty.json');
$began = $time->now;
$free = true;
for ($n = 0; $n < 20; $n++) {
    $free = $free && $pace->take() === 0.0;
    $time->now += 1.0;
}
$waited = $pace->take();
$check($free && count($time->slept) === 1 && abs($waited - 41.05) < 0.001 && $time->now - $began >= 61.0 && $pace->taken === 21, 'twenty go through, and the next waits for the first to leave the window', json_encode([$free, $time->slept, $waited]));

[$pace, $time] = $paceAt('window.json');
$went = [];
for ($n = 0; $n < 90; $n++) {
    $pace->take();
    $went[] = $time->now;
    $time->now += $n % 7 ? 0.4 : 3.0;
}
$most = 0;
foreach ($went as $index => $first) {
    $most = max($most, count(array_filter(array_slice($went, $index), static fn (float $stamp): bool => $stamp - $first < 60.0)));
}
$check($most <= 20, 'no window of sixty seconds holds more than the limit', $most . ' inside one minute');

[$first, $time] = $paceAt('shared.json');
$second = new LivePace(LivePace::LIMIT, LivePace::WINDOW, $first->path, static fn (): float => $time->now, static function (float $seconds) use ($time): void {
    $time->now += $seconds;
});
$free = true;
for ($n = 0; $n < 12; $n++) {
    $free = $free && $first->take() === 0.0;
}
for ($n = 0; $n < 8; $n++) {
    $free = $free && $second->take() === 0.0;
}
$check($free && $second->take() > 60.0, 'a second run counts what the first one took, as the service does');

[$pace, $time] = $paceAt('from-python.json');
// As json.dump writes it: fractions and whole seconds, mixed.
file_put_contents($pace->path, '[' . implode(', ', array_merge([$time->now - 100, $time->now - 30, '1789999970.5'], array_fill(0, 17, $time->now - 5))) . ']');
$room = $pace->take();
$full = $pace->take();
$kept = json_decode((string) file_get_contents($pace->path), true);
$check($room === 0.0 && $full > 0.0 && is_array($kept) && array_is_list($kept) && array_filter($kept, static fn (mixed $stamp): bool => !is_int($stamp) && !is_float($stamp)) === [] && array_filter($kept, static fn (mixed $stamp): bool => $time->now - $stamp >= 61.0) === [], 'the file is a list of seconds and nothing else, so the Python tests can keep it too', json_encode([$room, $full, $kept]));

$odd = true;
foreach (['', 'not json', '{}', '{"stamps": [1]}', '[true, null, "12", {}]', 'null'] as $index => $found) {
    [$pace, $time] = $paceAt('odd-' . $index . '.json');
    file_put_contents($pace->path, $found);
    for ($n = 0; $n < 20; $n++) {
        $odd = $odd && $pace->take() === 0.0;
    }
    $odd = $odd && count((array) json_decode((string) file_get_contents($pace->path), true)) === 20 && $pace->take() > 0.0;
}
$check($odd, 'a file that is not a list of seconds is an empty one');

[$pace, $time] = $paceAt('no such folder' . DIRECTORY_SEPARATOR . 'ledger.json');
$free = true;
for ($n = 0; $n < 20; $n++) {
    $free = $free && $pace->take() === 0.0;
}
$check($free && $pace->take() > 60.0 && !file_exists($paceDir . DIRECTORY_SEPARATOR . 'no such folder'), 'where the file cannot be kept the count is kept in this process');

[$pace, $time] = $paceAt('left-behind.json');
mkdir($pace->path . '.lock');
touch($pace->path . '.lock', time() - 120);
clearstatcache();
$check($pace->take() === 0.0 && !file_exists($pace->path . '.lock') && count((array) json_decode((string) file_get_contents($pace->path), true)) === 1, 'a lock left by a run that was stopped is taken over, and let go of again');

$wrong = [];
foreach ([
    ['PUT', 'https://aamio.at/abcdefghij234567abcd', true],
    ['DELETE', 'https://aamio.at/abcdefghij234567abcd', true],
    ['POST', 'https://aamio.at/abcdefghij234567abcd', false],
    ['GET', 'https://aamio.at/abcdefghij234567abcd/after/0/wait/20', false],
    ['GET', 'https://aamio.at/abcdefghij234567abcd/receipt', false],
    ['PUT', 'https://aamio.at/p/abcdefghij234567abcdabcdefghij234567abcd', false],
    ['DELETE', 'https://aamio.at/p/abcdefghij234567abcdabcdefghij234567abcd', false],
    ['DELETE', 'https://board.aamio.at/abcdefghij234567abcd', false],
    ['PUT', 'https://aamio.at.example/abcdefghij234567abcd', false],
    ['PUT', 'http://127.0.0.1:8080/abcdefghij234567abcd', false],
    ['PUT', 'https://aamio.at/ABCDEFGHIJ234567ABCD', false],
    ['PUT', 'https://aamio.at/abcdefghij234567abc', false],
] as [$method, $url, $waits]) {
    if (LivePace::counted($method, $url, 'https://aamio.at') !== $waits || LivePace::counted($method, $url, 'https://aamio.at/') !== $waits) {
        $wrong[] = $method . ' ' . $url;
    }
}
$check($wrong === [], 'only opens and closes of threads at the service under test wait', implode(', ', $wrong));

[$pace, $time] = $paceAt('forwarded.json', 1);
$seen = [];
$through = LivePace::forwarder('https://aamio.at', $pace, static function (string $method, string $url, ?string $body, array $headers, int $timeout) use (&$seen): array {
    $seen[] = [$method, $url, $body, $headers, $timeout];

    return [201, ['w' => 'x'], ['etag' => '1']];
});
$answers = [
    $through('PUT', 'https://aamio.at/abcdefghij234567abcd', null, ['X-Read' => 'k'], 9),
    $through('GET', 'https://aamio.at/abcdefghij234567abcd/after/0/wait/20', null, [], 65),
    $through('DELETE', 'https://aamio.at/abcdefghij234567abcd', null, ['X-Read' => 'k']),
];
$check(
    $answers === array_fill(0, 3, [201, ['w' => 'x'], ['etag' => '1']])
    && $seen === [
        ['PUT', 'https://aamio.at/abcdefghij234567abcd', null, ['X-Read' => 'k'], 9],
        ['GET', 'https://aamio.at/abcdefghij234567abcd/after/0/wait/20', null, [], 65],
        ['DELETE', 'https://aamio.at/abcdefghij234567abcd', null, ['X-Read' => 'k'], 40],
    ]
    && $pace->taken === 2 && count($time->slept) === 1,
    'the request is handed on as it came, timeout and all, and only the open and the close took a turn',
    json_encode([$seen, $pace->taken, $time->slept])
);

// The timeout reaches whatever stands in for the network, or a call handed on
// would go out with the default where the caller asked for longer.
$standing = Http::$override;
$handed = null;
Http::$override = static function (string $method, string $url, ?string $body = null, array $headers = [], int $timeout = -1) use (&$handed): array {
    $handed = $timeout;

    return [200, [], []];
};
try {
    Http::call('GET', 'https://fake.test/health', null, [], 65);
} finally {
    Http::$override = $standing;
}
$check($handed === 65, 'Http::call hands its timeout to what stands in for the network', (string) json_encode($handed));

$unpaced = [];
foreach (['live.php', 'first-exchange.php'] as $script) {
    $text = (string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $script);
    if (!str_contains($text, "require __DIR__ . '/live-pace.inc.php';") || !str_contains($text, 'LivePace::install($host);')) {
        $unpaced[] = $script;
    }
}
$check($unpaced === [], 'both scripts that write to the service wait their turn', implode(', ', $unpaced));
