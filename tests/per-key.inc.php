<?php

declare(strict_types=1);

/*
 * per_key without allow, answered here with a name from the address book. The
 * service's advice for such a gate is allow ["*"], a list of keys. Here allow
 * takes partner names, and "*" was answered unknown_partner (a review of 13
 * November 2026). The service is not asked: nothing is opened at the fake.
 *
 * Included by tests/runtime.php, with $fake, $root and $check in scope.
 */

use Aamio\Keys;
use Aamio\Runtime;

echo "per_key without allow\n";

$pkHome = $root . DIRECTORY_SEPARATOR . 'per-key';
mkdir($pkHome, 0700, true);
$pkRuntime = new Runtime($pkHome, 'https://fake.test', null, false, static function (string $line): void {
});
$pkThreads = count($fake->threads);
$pkAsk = static function (?array $allow) use ($pkRuntime): array {
    try {
        $pkRuntime->openChannel('deal', 600, $allow, ['require' => ['per_key' => 5]]);
    } catch (\Throwable $error) {
        return [$error instanceof \Aamio\Coded ? $error->errorCode() : null, $error->getMessage()];
    }

    return [null, 'opened'];
};
[$pkCode, $pkSaid] = $pkAsk(null);
$check($pkCode === 'invalid_gate' && str_contains($pkSaid, 'aamio partner add NAME KEY') && !str_contains($pkSaid, '*') && count($fake->threads) === $pkThreads, 'per_key without allow is answered here, with how to add a partner first, and the service is not asked', $pkSaid);
$pkRuntime->partnerAdd('bob', Keys::generate()->public);
[$pkCode, $pkSaid] = $pkAsk([]);
$check($pkCode === 'invalid_gate' && str_contains($pkSaid, 'allow: ["bob"] over MCP, or --allow=bob on the command line'), 'and with a partner, the advice names one from the address book', $pkSaid);
[$pkCode] = $pkAsk(['*']);
$check($pkCode === 'unknown_partner', 'and the star the service would have given is no partner here');
$pkRuntime->close();

// A name with a space or a quote was put into the advice unquoted, and was not
// one argument or not JSON (a review of 14 November 2026).
$pkWhole = [];
foreach (['Anne Hansen', 'Anne "A"', 'back\\slash', 'a,b', 'bob'] as $pkName) {
    $pkSaid = Runtime::perKeyFix([$pkName]);
    $pkListed = explode(' over MCP', explode('allow: ', $pkSaid, 2)[1] ?? '', 2)[0];
    $pkWhole[] = json_decode($pkListed, true) === [$pkName] && ($pkName === 'bob' ? str_contains($pkSaid, '--allow=bob on the command line') : !str_contains($pkSaid, '--allow ' . $pkName) && !str_contains($pkSaid, '--allow=' . $pkName));
}
$check($pkWhole === [true, true, true, true, true], 'the advice keeps a name whole: JSON for MCP, and a command line only where no shell changes the name');

// A name that starts with a hyphen was read as an option: --allow --bob
// answered "--allow needs a value" (a review of 14 November 2026). As
// --allow=NAME the parser takes it whole, and the partner is looked up.
$pkParsed = [];
$pkCliHome = $root . DIRECTORY_SEPARATOR . 'per-key-cli';
foreach (['-anne', '--bob', '--home', 'bob'] as $pkName) {
    [, $pkOut, $pkErr] = $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $pkCliHome, '--host', 'http://127.0.0.1:9', 'channel', 'open', 'deal', '--allow=' . $pkName, '--gate', '{"require": {"per_key": 5}}']));
    $pkParsed[$pkName] = str_contains(Runtime::perKeyFix([$pkName]), '--allow=' . $pkName . ' on the command line') && (json_decode((string) $pkOut, true) ?? []) === ['error' => 'unknown partner: ' . $pkName, 'error_code' => 'unknown_partner'] ? 'ok' : $pkOut . $pkErr;
}
$check($pkParsed === ['-anne' => 'ok', '--bob' => 'ok', '--home' => 'ok', 'bob' => 'ok'], 'and the command line in the advice is one the parser takes, a name that starts with a hyphen too', json_encode($pkParsed));
