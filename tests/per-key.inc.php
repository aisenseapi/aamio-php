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
$check($pkCode === 'invalid_gate' && str_contains($pkSaid, 'allow: ["bob"] over MCP, or --allow bob on the command line'), 'and with a partner, the advice names one from the address book', $pkSaid);
[$pkCode] = $pkAsk(['*']);
$check($pkCode === 'unknown_partner', 'and the star the service would have given is no partner here');
$pkRuntime->close();

// A name with a space or a quote was put into the advice unquoted, and was not
// one argument or not JSON (a review of 14 November 2026).
$pkWhole = [];
foreach (['Anne Hansen', 'Anne "A"', 'back\\slash', 'a,b', 'bob'] as $pkName) {
    $pkSaid = Runtime::perKeyFix([$pkName]);
    $pkListed = explode(' over MCP', explode('allow: ', $pkSaid, 2)[1] ?? '', 2)[0];
    $pkWhole[] = json_decode($pkListed, true) === [$pkName] && ($pkName === 'bob' ? str_contains($pkSaid, '--allow bob on the command line') : !str_contains($pkSaid, '--allow ' . $pkName));
}
$check($pkWhole === [true, true, true, true, true], 'the advice keeps a name whole: JSON for MCP, and a command line only where no shell changes the name');
