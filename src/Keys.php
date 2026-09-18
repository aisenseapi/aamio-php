<?php

declare(strict_types=1);

namespace Aamio;

/**
 * One Ed25519 identity: signs what leaves, seals to partners, opens what
 * arrives. The X25519 pair used for sealing is derived from the Ed25519 pair
 * the way libsodium does it, so a box sealed here opens in aamio-js and
 * aamio-python, and theirs open here.
 */
final class Keys
{
    public const ENVELOPE = 'nacl.box.v1';

    private string $signSecret;   // 64 bytes: seed || public
    private string $curveSecret;  // 32 bytes
    public readonly string $public;      // base64url, 43 characters
    public readonly string $hash;        // sha256 hex over the raw 32 bytes
    public readonly string $hashPrefix;  // the first 8

    private function __construct(string $seed)
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException('a seed is 32 bytes');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $this->signSecret = sodium_crypto_sign_secretkey($pair);
        $publicRaw = sodium_crypto_sign_publickey($pair);
        $this->curveSecret = sodium_crypto_sign_ed25519_sk_to_curve25519($this->signSecret);
        $this->public = Codec::b64url($publicRaw);
        $this->hash = hash('sha256', $publicRaw);
        $this->hashPrefix = substr($this->hash, 0, 8);
    }

    public static function generate(): self
    {
        return new self(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }

    public static function fromSeed(string $seed): self
    {
        return new self($seed);
    }

    public static function fromSeedHex(string $hex): self
    {
        return new self(hex2bin($hex) ?: '');
    }

    /** The 32 seed bytes, for keeping under a file with mode 600. Never anywhere else. */
    public function seed(): string
    {
        return substr($this->signSecret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
    }

    // ------------------------------------------------------------- signing --

    /** A detached signature over the exact string, base64url. */
    public function sign(string $input): string
    {
        return Codec::b64url(sodium_crypto_sign_detached($input, $this->signSecret));
    }

    /**
     * One message as the service returned it, checked here:
     * ['verified' => bool, 'why_not' => ?string, 'sha256' => ?string].
     *
     * verified in an answer is the service's word, and the trust model says an
     * operator cannot forge a signature. That only holds for a reader that
     * checks: the body is hashed, the hash compared with the one beside it, and
     * the signature verified over the address being read. why_not is null for a
     * message that verified and for an ordinary unsigned one, and a sentence
     * when something that should have held did not.
     */
    public static function checkMessage(string $w, array $message): array
    {
        $body = $message['body'] ?? null;
        if (!is_string($body)) {
            return ['verified' => false, 'why_not' => 'the message has no body to check', 'sha256' => null];
        }
        $digest = Codec::sha256hex($body);
        if (($message['sha256'] ?? null) !== $digest) {
            return ['verified' => false, 'why_not' => 'the body does not hash to the sha256 the service gave with it, so these are not the bytes that were stored', 'sha256' => $digest];
        }
        $from = $message['from'] ?? null;
        $signature = $message['sig'] ?? null;
        $claimed = !empty($message['verified']);
        if (!is_string($from) || $from === '' || !is_string($signature) || $signature === '') {
            return ['verified' => false, 'why_not' => $claimed ? 'the service calls it verified and gave no key or signature to check' : null, 'sha256' => $digest];
        }
        if (self::verify($from, $signature, self::threadSigningInput($w, $body))) {
            return ['verified' => true, 'why_not' => null, 'sha256' => $digest];
        }

        return ['verified' => false, 'why_not' => 'the signature does not check out for this key, this address and these bytes' . ($claimed ? ', though the service said it did' : ''), 'sha256' => $digest];
    }

    public static function verify(string $key, string $signature, string $input): bool
    {
        if (!Codec::isKey($key)) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(Codec::unb64url($signature), $input, Codec::unb64url($key));
        } catch (\SodiumException) {
            return false;
        }
    }

    /** What a thread write is signed over. */
    public static function threadSigningInput(string $w, string $body): string
    {
        return "aamio-v1\n" . $w . "\n" . Codec::sha256hex($body);
    }

    public static function presenceSigningInput(string $key, string $body): string
    {
        return "aamio-presence-v1\n" . $key . "\n" . Codec::sha256hex($body);
    }

    public static function presenceDeleteSigningInput(string $key, string $body): string
    {
        return "aamio-presence-delete-v1\n" . $key . "\n" . Codec::sha256hex($body);
    }

    public static function boardSigningInput(string $key, string $body): string
    {
        return "aamio-board-v1\n" . $key . "\n" . Codec::sha256hex($body);
    }

    public static function boardDeleteSigningInput(string $id, string $body): string
    {
        return "aamio-board-delete-v1\n" . $id . "\n" . Codec::sha256hex($body);
    }

    // ------------------------------------------------------------- sealing --

    /** The X25519 public key derived from an Ed25519 public key, raw 32 bytes. */
    public static function curvePublic(string $key): string
    {
        if (!Codec::isKey($key)) {
            throw new \InvalidArgumentException('not a base64url Ed25519 public key of 32 bytes');
        }

        return sodium_crypto_sign_ed25519_pk_to_curve25519(Codec::unb64url($key));
    }

    public static function hashPrefixOf(string $key): string
    {
        return substr(hash('sha256', Codec::unb64url($key)), 0, 8);
    }

    /** The envelope, as JSON text, sealed to the recipient's key. The recipient opens it with our public key. */
    public function seal(string $recipientKey, string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($this->curveSecret, self::curvePublic($recipientKey));
        $ciphertext = sodium_crypto_box($plaintext, $nonce, $pair);

        return Codec::json(['e2ee' => self::ENVELOPE, 'to' => self::hashPrefixOf($recipientKey), 'nonce' => Codec::b64url($nonce), 'ct' => Codec::b64url($ciphertext)]);
    }

    /** Whether a body claims to be an envelope. */
    public static function isEnvelope(mixed $body): bool
    {
        if (is_string($body)) {
            $body = json_decode($body, true);
        }

        return is_array($body) && ($body['e2ee'] ?? null) === self::ENVELOPE && isset($body['nonce'], $body['ct']);
    }

    /** Opens an envelope sealed to us by the holder of senderKey. Throws when it does not open. */
    public function open(string $senderKey, string $envelopeText): string
    {
        $envelope = json_decode($envelopeText, true);
        if (!self::isEnvelope($envelope)) {
            throw new \InvalidArgumentException('not an envelope');
        }
        if (($envelope['to'] ?? null) !== $this->hashPrefix) {
            throw new \InvalidArgumentException('this envelope is sealed to ' . ($envelope['to'] ?? '?') . ', not to ' . $this->hashPrefix);
        }
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($this->curveSecret, self::curvePublic($senderKey));
        $plaintext = sodium_crypto_box_open(Codec::unb64url((string) $envelope['ct']), Codec::unb64url((string) $envelope['nonce']), $pair);
        if ($plaintext === false) {
            throw new \RuntimeException('the envelope does not open with this key pair');
        }

        return $plaintext;
    }
}
