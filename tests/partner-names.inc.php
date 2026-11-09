<?php

declare(strict_types=1);

/*
 * A partner is never a guess between names that differ only in case.
 *
 * Finding 1 of the review of 9 November 2026, found in the Node runtime and
 * present here as well: a name was matched without regard to case and the
 * first partner in the book won, a key given was turned into its name and
 * looked up again, and the first partner presence answered for was taken
 * whichever was asked for. With Ada and ADA in the book, a message meant for
 * ADA could be sealed to Ada's key, or written to the inbox of the one who
 * could not open it. Included by tests/runtime.php, with $fake, $root, $quiet
 * and $check in scope.
 */

use Aamio\AmbiguousPartner;
use Aamio\Runtime;

echo "a partner is never a guess between names that differ only in case\n";

$named = static function (string $tag) use ($root, $quiet): Runtime {
    $home = $root . DIRECTORY_SEPARATOR . 'names-' . $tag;
    mkdir($home, 0700, true);

    return new Runtime($home, 'https://fake.test', [$tag], false, $quiet);
};
$sender = $named('s');
$lower = $named('ada-lower');
$upper = $named('ada-upper');
foreach ([$lower, $upper] as $target) {
    $target->partnerAdd('s', $sender->keys->public);
    $target->ensureInbox();
}
// An existing book with both, as an older client could leave it.
$sender->partners = [['name' => 'Ada', 'key' => $lower->keys->public], ['name' => 'ADA', 'key' => $upper->keys->public]];
$recipient = new ReflectionMethod($sender, 'recipient');
$inboxOf = static fn (Runtime $runtime): string => $runtime->channels['inbox']->w;
$caught = static function (callable $try): ?\Throwable {
    try {
        $try();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
};

foreach (['the book\'s order', 'the other order'] as $round => $order) {
    if ($round === 1) {
        // Presence answers in the order its records were made: make them again the other way round.
        $records = $fake->presence;
        $fake->presence = array_reverse($records, true);
    }
    $check($recipient->invoke($sender, $upper->keys->public) === [$inboxOf($upper), $upper->keys->public, 'ADA'], 'a key given is the key the message goes to, with presence in ' . $order);
    $check($recipient->invoke($sender, 'ADA') === [$inboxOf($upper), $upper->keys->public, 'ADA'] && $recipient->invoke($sender, 'Ada') === [$inboxOf($lower), $lower->keys->public, 'Ada'], 'and an exact name reaches that partner, with presence in ' . $order);
}
$guess = $caught(static fn () => $recipient->invoke($sender, 'ada'));
$check($guess instanceof AmbiguousPartner && str_contains($guess->getMessage(), 'Ada, ADA'), 'a name that matches both apart from case is an error, never a guess');

$book = $named('book');
$book->partnerAdd('Ada', $lower->keys->public);
$refused = $caught(static fn () => $book->partnerAdd('ada', $upper->keys->public));
$check($refused instanceof AmbiguousPartner && $book->partners === [['name' => 'Ada', 'key' => $lower->keys->public]], 'a new name that differs only in case from another partner is refused, and nothing is added');
$book->partnerAdd('Ada', $upper->keys->public);
$check($book->partners === [['name' => 'Ada', 'key' => $upper->keys->public]], 'the exact name replaces its key');
$check($book->partnerByName('ADA') === ['name' => 'Ada', 'key' => $upper->keys->public], 'and a single partner is still found whatever the case');
foreach ([$sender, $lower, $upper, $book] as $runtime) {
    $runtime->close();
}
