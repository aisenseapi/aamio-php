<?php

declare(strict_types=1);

/*
 * What a carrier refuses is said where the user sees it, and never answered
 * over the medium (contract 3, A7). Until 22 November 2026 every refusal went
 * into a list that only tests read, also a message refused because the session
 * it came in had expired with a restart, so that it was not stored (A6.8). A
 * refusal from a verified sender is said once for each partner, session and
 * code, one in an expired session goes to attention, and what was turned away
 * before a sender was known is counted and said at most once a minute.
 * Included from carry.inc.php, after the scenarios.
 */

use Aamio\Carrier;
use Aamio\Carry;
use Aamio\CarryState;
use Aamio\Codec;
use Aamio\Runtime;
use Aamio\TransportProcess;

echo "carry: refusals said\n";
$refusalProfile = CarryState::profile('sealed');
$refusalNow = 1794700000;
$refusalClock = new class () {
    public float $now = 1000.0;

    public function __invoke(): float
    {
        return $this->now;
    }
};
$receiverOf = static function (CarryState $state) use ($carryPk, $refusalProfile, $refusalNow, $refusalClock): array {
    $process = new TransportProcess('stub', '0.1.0', ['stub'], null, []);
    $process->properties = ['max_frame_up' => 4096, 'max_frame_down' => 4096];
    $lines = new \ArrayObject();
    $notes = new \ArrayObject();
    $carrier = new Carrier($state, $process, $refusalProfile, [$carryPk('A')], clock: $refusalClock, wall: static fn (): int => $refusalNow,
        nameOf: static fn (string $pk): string => $pk === $carryPk('A') ? 'alice' : substr(bin2hex($pk), 0, 8),
        log: static function (string $line) use ($lines): void {
            $lines[] = $line;
        },
        attention: static function (string $state, string $text) use ($notes): void {
            $notes[] = [$state, $text];
        });

    return [$carrier, $lines, $notes];
};
// Frames as if the transport had heard them, given to the carrier's own receiving side.
$hear = static function (Carrier $carrier, string ...$frames): void {
    foreach ($frames as $frame) {
        (fn () => $this->received(null, $frame))->call($carrier);
    }
};
$whole = static fn (string $envelope): array => Carry::split($envelope, 4096);
$queued = static fn (Carrier $carrier): array => (fn () => $this->queue)->call($carrier);
$askedAndChallenged = static function () use ($carryKeys, $carryPk, $refusalProfile, $refusalNow): array {
    $atA = new CarryState($carryKeys('A'));
    $atB = new CarryState($carryKeys('B'));
    [$session, $ask] = $atA->ask($carryPk('B'), $refusalProfile, 0.0);
    [, $challenge] = $atB->deliver($ask, [$carryPk('A')], $refusalProfile, 0.0, $refusalNow);
    [, $opening] = $atA->deliver($challenge, [$carryPk('B')], $refusalProfile, 0.0, $refusalNow);

    return [$atA, $atB, $session, $opening];
};

// A message in a session that expired with a restart: said in attention once, and nothing answered.
[$atA, $atB, $session, $opening] = $askedAndChallenged();
$openedFirst = $atB->deliver($opening, [$carryPk('A')], $refusalProfile, 0.0, $refusalNow)[0] === 'accepted';
$atB->restart();
[$carrier, $lines, $notes] = $receiverOf($atB);
$envelope = $atA->send($carryPk('B'), $session, 'hei', $refusalProfile);
$hear($carrier, ...$whole($envelope));
$first = (array) $notes;
$said = $first[0][1] ?? '';
$check($openedFirst && $carrier->refused === ['carry_session_expired'] && array_column($first, 0) === ['session_expired']
    && str_contains($said, 'alice') && str_contains($said, 'not stored') && str_contains($said, 'sent again') && str_contains($said, 'A6.8')
    && $queued($carrier) === [] && $carrier->inbox() === [],
    'a message in a session that expired with a restart goes to attention, and nothing is answered over the medium');
$hear($carrier, ...$whole($envelope));
$hear($carrier, ...$whole($atA->send($carryPk('B'), $session, 'igjen', $refusalProfile)));
$check($carrier->refused === array_fill(0, 3, 'carry_session_expired') && count($notes) === 1,
    'a copy, and another message in the same session, are not said again');

// Another refusal from a known partner: logged once, with its kind and code.
[, $atB, , $opening] = $askedAndChallenged();
$atB->restart();
[$carrier, $lines, $notes] = $receiverOf($atB);
$hear($carrier, ...$whole($opening));
$hear($carrier, ...$whole($opening));
$said = array_values(array_filter((array) $lines, static fn (string $line): bool => str_contains($line, 'carry_nonce_unknown')));
$check($carrier->refused === ['carry_nonce_unknown', 'carry_nonce_unknown'] && count($said) === 1
    && str_contains($said[0], 'an open from alice') && str_contains($said[0], 'not answered over the medium') && str_contains($said[0], 'transport stub')
    && count($notes) === 0 && $queued($carrier) === [],
    'another refusal from a known partner is logged once, with its kind and code');

// Turned away before a sender was known: counted, and said at most once a minute.
$stranger = new CarryState($carryKeys('C'));
[$carrier, $lines, $notes] = $receiverOf(new CarryState($carryKeys('B')));
$unverifiedSaid = static fn (): array => array_values(array_filter((array) $lines, static fn (string $line): bool => str_contains($line, 'before a sender was known')));
$hear($carrier, ...$whole($stranger->ask($carryPk('B'), $refusalProfile, 0.0)[1]));
$once = $unverifiedSaid() === ['transport stub: turned away before a sender was known, said at most once a minute: carry_unknown_sender 1'];
for ($n = 0; $n < 3; $n++) {
    $hear($carrier, ...$whole($stranger->ask($carryPk('B'), $refusalProfile, 0.0)[1]));
}
$hear($carrier, 'x');
$heldBack = count($unverifiedSaid()) === 1;
$refusalClock->now += 61;
(fn () => $this->sayUnverified())->call($carrier);
$later = $unverifiedSaid();
(fn () => $this->sayUnverified())->call($carrier);
$check($once && $heldBack && count($later) === 2
    && $later[1] === 'transport stub: turned away before a sender was known, said at most once a minute: carry_unknown_sender 3, frame_bad_length 1'
    && count($unverifiedSaid()) === 2 && count($notes) === 0 && !str_contains(implode(' ', (array) $lines), 'alice'),
    'what was turned away before a sender was known is counted and said at most once a minute');

// The list of codes keeps only the newest.
[$carrier, $lines] = $receiverOf(new CarryState($carryKeys('B')));
$hear($carrier, ...array_fill(0, Carrier::REFUSALS_KEPT + 5, 'x'));
$check($carrier->refused === array_fill(0, Carrier::REFUSALS_KEPT, 'frame_bad_length')
    && count(array_filter((array) $lines, static fn (string $line): bool => str_contains($line, 'before a sender was known'))) === 1,
    'the list of refusal codes keeps only the newest, as a long aamio serve kept every code for as long as it ran');

// alice, then carol, then alice in her other session: alice's text waited there already, and it replaced what waited, so
// carol's was gone before anyone read it (the review of 23 November 2026). Through the coupling the transport service
// gives its carriers, into the runtime's attention, where a read takes it once.
$abaHome = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-refusals-' . bin2hex(random_bytes(6));
$abaLines = new \ArrayObject();
$abaRuntime = new Runtime($abaHome, 'http://127.0.0.1:9', null, true, static function (string $line) use ($abaLines): void {
    $abaLines[] = $line;
});
try {
    $abaRuntime->partnerAdd('alice', $carryKeys('A')->public);
    $abaRuntime->partnerAdd('carol', $carryKeys('C')->public);
    $abaKnown = [$carryPk('A'), $carryPk('C')];
    $abaB = new CarryState($carryKeys('B'));
    $abaOpened = static function (CarryState $sender) use ($abaB, $abaKnown, $carryPk, $refusalProfile, $refusalNow): string {
        [$session, $ask] = $sender->ask($carryPk('B'), $refusalProfile, 0.0);
        [, $challenge] = $abaB->deliver($ask, $abaKnown, $refusalProfile, 0.0, $refusalNow);
        [, $opening] = $sender->deliver($challenge, [$carryPk('B')], $refusalProfile, 0.0, $refusalNow);
        if ($abaB->deliver($opening, $abaKnown, $refusalProfile, 0.0, $refusalNow)[0] !== 'accepted') {
            throw new \RuntimeException('the open was not taken');
        }

        return $session;
    };
    $abaA = new CarryState($carryKeys('A'));
    $abaC = new CarryState($carryKeys('C'));
    [$abaFirst, $abaSecond, $abaCarols] = [$abaOpened($abaA), $abaOpened($abaA), $abaOpened($abaC)];
    $abaB->restart();
    $abaWire = new TransportProcess('stub', '0.1.0', ['stub'], null, []);
    $abaWire->properties = ['max_frame_up' => 4096, 'max_frame_down' => 4096];
    $abaCarrier = new Carrier($abaB, $abaWire, $refusalProfile, $abaKnown, clock: $refusalClock, wall: static fn (): int => $refusalNow,
        nameOf: static fn (string $pk): string => $abaRuntime->partnerByKey(Codec::b64url($pk))['name'] ?? '?',
        log: static function (string $line) use ($abaLines): void {
            $abaLines[] = $line;
        },
        attention: $abaRuntime->transportService()->attentionFor('stub'));
    $hear($abaCarrier, ...$whole($abaA->send($carryPk('B'), $abaFirst, '1', $refusalProfile)));
    $hear($abaCarrier, ...$whole($abaC->send($carryPk('B'), $abaCarols, '2', $refusalProfile)));
    $hear($abaCarrier, ...$whole($abaA->send($carryPk('B'), $abaSecond, '3', $refusalProfile)));
    $abaTaken = $abaRuntime->attentionTaken();
    $abaSaid = $abaTaken[0]['what'] ?? '';
    $abaLast = (string) ($abaLines[count($abaLines) - 1] ?? '');
    $abaThenEmpty = $abaRuntime->attentionTaken() === [];
    $abaThird = $abaOpened($abaA);
    $abaB->restart();
    $hear($abaCarrier, ...$whole($abaA->send($carryPk('B'), $abaThird, '4', $refusalProfile)));
    $abaAgain = $abaRuntime->attentionTaken();
    $check($abaCarrier->refused === array_fill(0, 4, 'carry_session_expired') && count($abaTaken) === 1
        && $abaTaken[0]['channel'] === 'stub' && $abaTaken[0]['state'] === 'session_expired'
        && substr_count($abaSaid, 'a message from alice') === 1 && substr_count($abaSaid, 'a message from carol') === 1
        && str_starts_with($abaLast, 'transport stub: a message from alice') && !str_contains($abaLast, 'carol') && $abaThenEmpty
        && count($abaAgain) === 1 && str_contains($abaAgain[0]['what'], 'a message from alice') && !str_contains($abaAgain[0]['what'], 'carol'),
        'a refusal said again keeps what another partner said unread, through the coupling a running carrier is given');
} finally {
    $abaRuntime->close();
    $removeTree($abaHome);
}
