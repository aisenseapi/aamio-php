<?php

declare(strict_types=1);

/*
 * The card of earshot and qrcast against card_vectors.json, the file aamio-python and aamio-js are tested against as
 * well: tests/card_vectors.json in aamio-python, made from the reference there.
 *
 * A card heard and a card seen are one thing: the same text from the same input, and the same reading of the same text,
 * in both add-ons and all three runtimes. A change to the card is a change to that file, and this copy follows it.
 *
 * Included by tests/run.php, with $check in scope.
 */

require_once dirname(__DIR__) . '/addons/earshot/autoload.php';
require_once dirname(__DIR__) . '/addons/qrcast/autoload.php';

echo "the card: the shared vectors\n";

(static function () use ($check): void {
    $vectors = json_decode((string) file_get_contents(__DIR__ . '/card_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    $cards = [
        'earshot' => [\Aamio\Earshot\Card::class, \Aamio\Earshot\Refused::class, \Aamio\Earshot\NotUnderstood::class],
        'qrcast' => [\Aamio\Qrcast\Card::class, \Aamio\Qrcast\Refused::class, \Aamio\Qrcast\NotUnderstood::class],
    ];
    $python = dirname(__DIR__, 2) . '/aamio-python/tests/card_vectors.json';
    $check(!is_file($python) || file_get_contents($python) === file_get_contents(__DIR__ . '/card_vectors.json'), 'the vectors are the file in aamio-python, byte for byte');
    foreach ($cards as $module => [$card, $refusal, $why]) {
        $wrong = [];
        foreach ($vectors['make'] as $case) {
            try {
                $text = $card::make($case['address'], $case['name'], $case['expires'], $case['via'], $case['key']);
                $ok = isset($case['card']) && $text === $case['card'] && strlen($text) === $case['bytes'] && $case['bytes'] <= $vectors['max_bytes'];
            } catch (\Throwable $error) {
                $ok = isset($case['refused']) && $error instanceof $refusal && $error->reason === $case['refused'];
            }
            if (!$ok) {
                $wrong[] = $case['about'];
            }
        }
        $check($wrong === [], $module . ': make gives each card, or refuses it for the reason, as the vectors say (' . count($vectors['make']) . ' cases)', implode('; ', $wrong));
        $wrong = [];
        foreach ($vectors['read'] as $case) {
            try {
                $got = $card::read($case['text']);
                $ok = isset($case['card']) && $got === $case['card'];
            } catch (\Throwable $error) {
                $ok = isset($case['not_understood']) && $error instanceof $why && $error->reason === $case['not_understood'];
            }
            if (!$ok) {
                $wrong[] = $case['about'];
            }
        }
        $check($wrong === [], $module . ': read gives each card, or does not understand it for the reason, as the vectors say (' . count($vectors['read']) . ' cases)', implode('; ', $wrong));
    }
    $made = array_column(array_filter($vectors['make'], static fn (array $case): bool => isset($case['card'])), 'card');
    $refused = array_unique(array_column(array_filter($vectors['make'], static fn (array $case): bool => isset($case['refused'])), 'refused'));
    $check(array_filter($made, static fn (string $text): bool => str_contains($text, 'v=bt:00:00:5E:00:53:01')) !== []
        && array_filter($made, static fn (string $text): bool => str_contains($text, 'v=lan:192.168.2.22:47474,internet')) !== []
        && array_diff(['invalid_via', 'key_needed', 'card_too_long', 'invalid_key', 'a_key_not_a_public_key'], $refused) === []
        && max(array_column(array_filter($vectors['make'], static fn (array $case): bool => isset($case['card'])), 'bytes')) === $vectors['max_bytes'] && $vectors['max_bytes'] === 128,
        'the vectors cover where a medium is reached, both ways, every refusal, and the longest card');
})();
