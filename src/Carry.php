<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The carrier envelope aamio-carry-v1, for transports under contract 3.
 *
 * The specification is docs/aamio-tillegg-kontrakt-3-2026-11-15.md in the
 * service repository, with its vectors beside it; tests/carry_v1_vectors.json
 * is a copy. Bytes in and bytes out, with no state: the sessions, counters and
 * receipts that decide whether a message is taken are in CarryState.
 *
 * Readable is signed and seen by everyone in range; sealed has a new ephemeral
 * key for every envelope, with the sender and the signature inside, and is the
 * default. A reader refuses with the code of the first check that fails, in
 * the order of section A9, so every client says the same about the same bytes.
 */
final class Carry
{
    public const DOMAIN = "aamio-carry-v1\n";
    public const NONCE_LABEL = "aamio-carry-v1 nonce\n";
    public const ESK_LABEL = "aamio-carry-v1 esk\n";
    public const CHALLENGE_LABEL = "aamio-carry-v1 challenge\n";
    public const MAGIC = 'AMC1';
    public const FRAME_MAGIC = 'AF';
    public const FRAME_HEADER = 9;

    public const READABLE = 1;
    public const SEALED = 2;
    public const MESSAGE = 1;
    public const RECEIPT = 2;
    public const ASK = 3;
    public const CHALLENGE = 4;
    public const OPEN = 5;
    public const CLOSE = 6;
    public const KINDS = [1, 2, 3, 4, 5, 6];
    public const BOUND = 0x01;
    public const STORED = 1;
    public const PROCESSED = 2;

    public const READABLE_HEAD = 33;
    public const SEALED_OUTER = 43;
    public const SEALED_SIGNED_OUTER = 41;
    public const INNER_HEAD = 24;
    public const SIGNATURE = 64;
    public const MAC = 16;
    public const RECEIPT_BODY = 45;
    public const NONCE_BYTES = 16;
    public const MAX_CONTENT = 65431;
    public const CANDIDATES = 4;

    /** The group order L and the prime p, little-endian. */
    private const L_LE = 'edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010';
    private const P_LE = 'edffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f';

    /** Appendix 1: Ed25519 encodings of points of small order, compared with bit 255 cleared. */
    private const SMALL_ORDER = [
        '0000000000000000000000000000000000000000000000000000000000000000',
        '0100000000000000000000000000000000000000000000000000000000000000',
        '26e8958fc2b227b045c3f489f2ef98f0d5dfac05d3c63339b13802886d53fc05',
        'c7176a703d4dd84fba3c0b760d10670f2a2053fa2c39ccc64ec7fd7792ac037a',
        'ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
        'edffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
        'eeffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
    ];

    /** The first 4 bytes of SHA-256 over a key: aamio's hash prefix, as bytes. */
    public static function hint(string $pk): string
    {
        return substr(hash('sha256', $pk, true), 0, 4);
    }

    /**
     * The challenge as it goes into the signature and the ephemeral key: its
     * length first, 0x00 for none. Without the length, a 16-byte challenge before
     * a bound header could be the same bytes as an unbound header that begins
     * with them (a review of 15 November 2026).
     */
    public static function bind(?string $binding): string
    {
        return $binding === null ? "\x00" : chr(strlen($binding)) . $binding;
    }

    public static function curvePublic(string $pk): string
    {
        return sodium_crypto_sign_ed25519_pk_to_curve25519($pk);
    }

    public static function boxNonce(string $epk, string $recipientXpk): string
    {
        return substr(hash('sha512', self::NONCE_LABEL . $epk . $recipientXpk, true), 0, 24);
    }

    /** N for the challenge numbered $number that $keys give: the count never repeats, so N is new even where rnd does. */
    public static function challengeNonce(Keys $keys, string $rnd, string $initiatorPk, string $session, int $number): string
    {
        return substr(hash('sha512', self::CHALLENGE_LABEL . $keys->seed() . $rnd . $initiatorPk . $session . pack('J', $number), true), 0, self::NONCE_BYTES);
    }

    public static function receiptBody(int $receiptKind, string $session, int $counter, string $envelope): string
    {
        return chr($receiptKind) . $session . pack('N', $counter) . hash('sha256', $envelope, true);
    }

    private static function checkWritable(int $kind, int $flags, string $content, ?string $binding): void
    {
        if (strlen($content) > self::MAX_CONTENT) {
            throw new CarryRefused('carry_too_large', sprintf('%d bytes of content, and the most is %d', strlen($content), self::MAX_CONTENT));
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('unknown kind ' . $kind);
        }
        if (!in_array($flags, $kind === self::MESSAGE ? [0, self::BOUND] : [0], true)) {
            throw new \InvalidArgumentException(sprintf('flags %d are not allowed for kind %d', $flags, $kind));
        }
        if (($binding !== null) !== (bool) ($flags & self::BOUND)) {
            throw new \InvalidArgumentException('a bound message needs the challenge, and only a bound message has one');
        }
        if ($binding !== null && strlen($binding) !== self::NONCE_BYTES) {
            throw new \InvalidArgumentException('the challenge is 16 bytes');
        }
    }

    /**
     * One envelope from $keys to $recipientPk, readable or sealed, as A3 and A4
     * say. $rnd is only for the vectors: a sealed envelope otherwise takes 32 new
     * random bytes, and its ephemeral key is derived from them, the sender's seed
     * and everything signed, so two envelopes that sign different bytes never
     * share a key.
     */
    public static function write(Keys $keys, string $recipientPk, int $mode, int $kind, int $flags, string $session, int $counter, int $deadline, string $content, ?string $binding = null, ?string $rnd = null): string
    {
        self::checkWritable($kind, $flags, $content, $binding);
        if (strlen($session) !== 8) {
            throw new \InvalidArgumentException('a session is 8 bytes');
        }
        $fields = $session . pack('N', $counter) . pack('N', $deadline) . pack('n', strlen($content)) . $content;
        $me = $keys->publicRaw();
        $prefix = self::DOMAIN . $me . $recipientPk . self::bind($binding);
        if ($mode === self::READABLE) {
            $head = self::MAGIC . chr(self::READABLE) . chr($kind) . chr($flags) . self::hint($recipientPk) . self::hint($me) . $fields;

            return $head . $keys->signRaw($prefix . $head);
        }
        if ($mode !== self::SEALED) {
            throw new \InvalidArgumentException('unknown mode ' . $mode);
        }
        $innerHead = chr($kind) . chr($flags) . self::hint($me) . $fields;
        $rnd ??= random_bytes(32);
        $esk = substr(hash('sha512', self::ESK_LABEL . $keys->seed() . $rnd . $recipientPk . self::bind($binding) . $innerHead, true), 0, 32);
        $epk = sodium_crypto_scalarmult_base($esk);
        $outer = self::MAGIC . chr(self::SEALED) . self::hint($recipientPk) . $epk;
        $inner = $innerHead . $keys->signRaw($prefix . $outer . $innerHead);
        $recipientXpk = self::curvePublic($recipientPk);
        $ciphertext = sodium_crypto_box($inner, self::boxNonce($epk, $recipientXpk), sodium_crypto_box_keypair_from_secretkey_and_publickey($esk, $recipientXpk));
        sodium_memzero($esk);

        return $outer . pack('n', strlen($ciphertext)) . $ciphertext;
    }

    /** The frames of A8 for a medium whose largest frame is $frameLimit bytes. */
    public static function split(string $envelope, int $frameLimit): array
    {
        $part = $frameLimit - self::FRAME_HEADER;
        if ($part < 1) {
            throw new CarryRefused('frame_too_small', sprintf('a frame of %d bytes has no room after its 9-byte header', $frameLimit));
        }
        $count = intdiv(strlen($envelope) + $part - 1, $part);
        if ($count > 255) {
            throw new CarryRefused('carry_too_large', sprintf('%d bytes need %d frames of %d bytes, and the most is 255', strlen($envelope), $count, $frameLimit));
        }
        $tag = substr(hash('sha256', $envelope, true), 0, 4);
        $frames = [];
        for ($index = 0; $index < $count; $index++) {
            $frames[] = self::FRAME_MAGIC . "\x00" . $tag . chr($index) . chr($count) . substr($envelope, $index * $part, $part);
        }

        return $frames;
    }

    /** The frames of one message, in any order, as one envelope; or the first code of A8. */
    public static function reassemble(array $frames): string
    {
        $group = [];
        $tag = null;
        $count = null;
        foreach ($frames as $frame) {
            if (strlen($frame) < self::FRAME_HEADER + 1) {
                throw new CarryRefused('frame_bad_length');
            }
            if (substr($frame, 0, 2) !== self::FRAME_MAGIC) {
                throw new CarryRefused('frame_bad_magic');
            }
            if (ord($frame[2]) !== 0) {
                throw new CarryRefused('frame_unknown_scheme');
            }
            $thisTag = substr($frame, 3, 4);
            $index = ord($frame[7]);
            $thisCount = ord($frame[8]);
            if ($thisCount < 1 || $index >= $thisCount) {
                throw new CarryRefused('frame_bad_index');
            }
            if ($tag === null) {
                $tag = $thisTag;
                $count = $thisCount;
            } elseif ($thisTag !== $tag) {
                throw new CarryRefused('frame_other_message');
            } elseif ($thisCount !== $count) {
                throw new CarryRefused('frame_count_mismatch');
            }
            $part = substr($frame, self::FRAME_HEADER);
            if (isset($group[$index]) && $group[$index] !== $part) {
                throw new CarryRefused('frame_conflict');
            }
            $group[$index] = $part;
        }
        if ($tag === null || count($group) !== $count) {
            throw new CarryRefused('frame_incomplete');
        }
        ksort($group);
        $envelope = implode('', $group);
        if (substr(hash('sha256', $envelope, true), 0, 4) !== $tag) {
            throw new CarryRefused('frame_tag_mismatch');
        }

        return $envelope;
    }

    /** Whether the little-endian number $a is below the little-endian number $b, both 32 bytes. */
    private static function below(string $a, string $b): bool
    {
        for ($i = 31; $i >= 0; $i--) {
            if (ord($a[$i]) !== ord($b[$i])) {
                return ord($a[$i]) < ord($b[$i]);
            }
        }

        return false;
    }

    private static function smallOrder(string $encoded): bool
    {
        $masked = substr($encoded, 0, 31) . chr(ord($encoded[31]) & 0x7F);

        return in_array(bin2hex($masked), self::SMALL_ORDER, true);
    }

    /**
     * A9, step 9: the key canonical and not of small order, S below L, R not of
     * small order, then the verification itself. libsodium makes all five checks;
     * they are made here as well, so the rule does not rest on the library.
     */
    public static function strictVerify(string $pk, string $data, string $signature): bool
    {
        if (strlen($pk) !== 32 || strlen($signature) !== self::SIGNATURE) {
            return false;
        }
        $masked = substr($pk, 0, 31) . chr(ord($pk[31]) & 0x7F);
        if (!self::below($masked, hex2bin(self::P_LE)) || self::smallOrder($pk)) {
            return false;
        }
        if (!self::below(substr($signature, 32), hex2bin(self::L_LE)) || self::smallOrder(substr($signature, 0, 32))) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $data, $pk);
        } catch (\SodiumException) {
            return false;
        }
    }

    private static function kindAndFlags(int $kind, int $flags): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new CarryRefused('carry_unknown_kind');
        }
        if (($flags & ~($kind === self::MESSAGE ? self::BOUND : 0)) !== 0) {
            throw new CarryRefused('carry_reserved_flags');
        }
    }

    private static function checkContent(int $kind, int $counter, int $deadline, string $content, string $session): void
    {
        if ($kind === self::MESSAGE) {
            if ($counter < 1) {
                throw new CarryRefused('carry_bad_content', 'a message counts from 1');
            }

            return;
        }
        if ($counter !== 0 || $deadline !== 0) {
            throw new CarryRefused('carry_bad_content', 'only a message has a counter or a deadline');
        }
        if ($kind === self::RECEIPT) {
            if (strlen($content) !== self::RECEIPT_BODY || !in_array(ord($content[0]), [self::STORED, self::PROCESSED], true) || substr($content, 1, 8) !== $session) {
                throw new CarryRefused('carry_bad_content', 'a receipt is 45 bytes about a message in its own session');
            }
        } elseif ($kind === self::CHALLENGE || $kind === self::OPEN) {
            if (strlen($content) !== self::NONCE_BYTES) {
                throw new CarryRefused('carry_bad_content', 'a challenge and an open carry the 16-byte nonce');
            }
        } elseif ($content !== '') {
            throw new CarryRefused('carry_bad_content', 'an ask and a close carry nothing');
        }
    }

    /**
     * The checks of A9, in that order: what was read, or CarryRefused with the
     * first code. $known is the address book's raw keys bound to this transport,
     * in its order. $bindingFor($pk, $session) gives the challenge a bound
     * message from that key in that session is signed over, or null.
     *
     * @return array{mode:int, kind:int, flags:int, sender:string, session:string, counter:int, deadline:int, content:string}
     */
    public static function read(string $envelope, Keys $keys, array $known, ?callable $bindingFor = null): array
    {
        if (strlen($envelope) < 5 || substr($envelope, 0, 4) !== self::MAGIC) {
            throw new CarryRefused('carry_bad_magic');
        }
        $mode = ord($envelope[4]);
        $me = $keys->publicRaw();
        if ($mode === self::READABLE) {
            if (strlen($envelope) < self::READABLE_HEAD + self::SIGNATURE) {
                throw new CarryRefused('carry_bad_length');
            }
            $kind = ord($envelope[5]);
            $flags = ord($envelope[6]);
            $recipientHint = substr($envelope, 7, 4);
            $senderHint = substr($envelope, 11, 4);
            $fields = substr($envelope, 15, 18);
            $n = unpack('n', substr($envelope, 31, 2))[1];
            if ($n > self::MAX_CONTENT || strlen($envelope) !== self::READABLE_HEAD + $n + self::SIGNATURE) {
                throw new CarryRefused('carry_bad_length');
            }
            self::kindAndFlags($kind, $flags);
            if ($recipientHint !== self::hint($me)) {
                throw new CarryRefused('carry_not_for_me');
            }
            $content = substr($envelope, self::READABLE_HEAD, $n);
            $signature = substr($envelope, self::READABLE_HEAD + $n);
            $signed = substr($envelope, 0, self::READABLE_HEAD + $n);
        } elseif ($mode === self::SEALED) {
            if (strlen($envelope) < self::SEALED_OUTER) {
                throw new CarryRefused('carry_bad_length');
            }
            $recipientHint = substr($envelope, 5, 4);
            $epk = substr($envelope, 9, 32);
            $m = unpack('n', substr($envelope, 41, 2))[1];
            if (strlen($envelope) !== self::SEALED_OUTER + $m || $m < self::MAC + self::INNER_HEAD + self::SIGNATURE) {
                throw new CarryRefused('carry_bad_length');
            }
            if ($recipientHint !== self::hint($me)) {
                throw new CarryRefused('carry_not_for_me');
            }
            // A key of small order gives a shared secret of zeros. libsodium
            // refuses it and some libraries do not, so it is refused here first.
            if ($keys->sharedWith($epk) === str_repeat("\0", 32)) {
                throw new CarryRefused('carry_open_failed');
            }
            $inner = $keys->openFrom(substr($envelope, self::SEALED_OUTER), self::boxNonce($epk, $keys->curvePublicRaw()), $epk);
            if ($inner === null) {
                throw new CarryRefused('carry_open_failed');
            }
            $kind = ord($inner[0]);
            $flags = ord($inner[1]);
            $senderHint = substr($inner, 2, 4);
            $fields = substr($inner, 6, 18);
            $n = unpack('n', substr($inner, 22, 2))[1];
            if ($n > self::MAX_CONTENT || strlen($inner) !== self::INNER_HEAD + $n + self::SIGNATURE) {
                throw new CarryRefused('carry_bad_length');
            }
            self::kindAndFlags($kind, $flags);
            $content = substr($inner, self::INNER_HEAD, $n);
            $signature = substr($inner, self::INNER_HEAD + $n);
            $signed = substr($envelope, 0, self::SEALED_SIGNED_OUTER) . substr($inner, 0, self::INNER_HEAD + $n);
        } else {
            throw new CarryRefused('carry_unknown_mode');
        }

        $session = substr($fields, 0, 8);
        $counter = unpack('N', substr($fields, 8, 4))[1];
        $deadline = unpack('N', substr($fields, 12, 4))[1];
        $candidates = array_slice(array_values(array_filter($known, static fn (string $pk): bool => self::hint($pk) === $senderHint)), 0, self::CANDIDATES);
        if ($candidates === []) {
            throw new CarryRefused('carry_unknown_sender');
        }
        $pairs = [];
        foreach ($candidates as $pk) {
            if ($flags & self::BOUND) {
                $binding = $bindingFor === null ? null : $bindingFor($pk, $session);
                if ($binding !== null) {
                    $pairs[] = [$pk, $binding];
                }
            } else {
                $pairs[] = [$pk, null];
            }
        }
        if ($pairs === []) {
            throw new CarryRefused('carry_session_unknown');
        }
        $sender = null;
        foreach ($pairs as [$pk, $binding]) {
            if (self::strictVerify($pk, self::DOMAIN . $pk . $me . self::bind($binding) . $signed, $signature)) {
                $sender = $pk;
                break;
            }
        }
        if ($sender === null) {
            throw new CarryRefused('carry_bad_signature');
        }
        self::checkContent($kind, $counter, $deadline, $content, $session);

        return ['mode' => $mode, 'kind' => $kind, 'flags' => $flags, 'sender' => $sender, 'session' => $session, 'counter' => $counter,
                'deadline' => $deadline, 'content' => $content];
    }
}
