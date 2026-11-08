<?php

declare(strict_types=1);

namespace Aamio\Sound;

/**
 * The two calls to aisenseapi.com: a card to sound, and sound back to bytes.
 *
 *     POST https://aisenseapi.com/services/v1/audio_encode          JSON in, WAV out
 *     POST https://aisenseapi.com/services/v1/audio_decode/MODE     WAV in, JSON out
 *
 * Both are free and need no key. What is encoded and what is recorded go to AI
 * SENSE over HTTPS, and are not kept there, as the pages for the two endpoints
 * say. aisense answers 422 when a recording holds no intact message, 400 when a
 * file is not one it reads, and 429 or 503 with Retry-After when it cannot
 * serve now. An answer that is not what the endpoint documents is unreachable
 * here, never guessed at. The same as aamio-python's aamio_sound.service.
 */
final class Service
{
    public const BASE = 'https://aisenseapi.com/services/v1';
    public const MODES = ['audible', 'high', 'ultrasound'];
    public const DECODE_MODES = ['auto', 'audible', 'high', 'ultrasound'];
    /** What aisense reads: at most 40 seconds and 8 MiB of PCM16 WAV. A property, so a test can lower it. */
    public const MAX_RECORDING_BYTES = 8388608;
    public static int $maxRecordingBytes = self::MAX_RECORDING_BYTES;
    /** The longest message aisense makes is about 17 seconds, 1.6 MB of WAV. */
    public const MAX_SOUND_BYTES = 4194304;
    public const MAX_JSON_BYTES = 65536;
    public const TIMEOUT = 30;

    public static function isWav(string $data): bool
    {
        return strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WAVE';
    }

    /**
     * The WAV for a card, made by aisense. $post is for tests.
     *
     * @param null|callable(string, string, string, int): array{0: int, 1: array<string, string>, 2: string} $post
     */
    public static function encode(string $card, string $mode, ?callable $post = null): string
    {
        $body = (string) json_encode(['data' => $card, 'input_encoding' => 'utf8', 'mode' => $mode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $headers, $payload] = ($post ?? [self::class, 'post'])('/audio_encode', $body, 'application/json', self::MAX_SOUND_BYTES);
        if ($status !== 200) {
            throw self::failure($status, $headers, $payload, 'played', 'service_refused');
        }
        if (!self::isWav($payload) || strlen($payload) > self::MAX_SOUND_BYTES) {
            throw new Unreachable('service_answered_something_else', 'aisenseapi.com answered with something other than the WAV file it documents, so nothing was played. Try again later.');
        }

        return $payload;
    }

    /**
     * What aisense read in a WAV: data_base64, text, protocol, corrected_bits and mode. NothingHeard when it read no message.
     *
     * @param null|callable(string, string, string, int): array{0: int, 1: array<string, string>, 2: string} $post
     * @return array<string, mixed>
     */
    public static function decode(string $sound, string $mode, ?callable $post = null): array
    {
        [$status, $headers, $payload] = ($post ?? [self::class, 'post'])('/audio_decode/' . $mode, $sound, 'audio/wav', self::MAX_JSON_BYTES);
        if ($status === 422) {
            [$error, $fix] = self::words($payload);
            $said = $error !== null ? ': ' . strtolower(substr($error, 0, 1)) . rtrim(substr($error, 1), '.') : '';
            throw new NothingHeard('No complete message was heard' . $said . '. ' . ($fix ?? 'Record the whole sound, close to the speaker, in a quiet room, and try audible mode.'));
        }
        if ($status !== 200) {
            throw self::failure($status, $headers, $payload, 'read', 'not_readable');
        }
        $said = json_decode($payload, true);
        if (!is_array($said) || !is_string($said['data_base64'] ?? null)) {
            throw new Unreachable('service_answered_something_else', 'aisenseapi.com answered with something other than the JSON it documents, so nothing was read. Try again later.');
        }

        return $said;
    }

    /** @return array{0: ?string, 1: ?string} aisense's own error and fix, when its answer carries them */
    private static function words(string $payload): array
    {
        $said = json_decode($payload, true);
        if (!is_array($said)) {
            return [null, null];
        }

        return [is_string($said['error'] ?? null) ? $said['error'] : null, is_string($said['fix'] ?? null) ? $said['fix'] : null];
    }

    /** @param array<string, string> $headers */
    private static function failure(int $status, array $headers, string $payload, string $done, string $refused): \RuntimeException
    {
        $retry = $headers['retry-after'] ?? null;
        $later = $retry !== null ? ' Try again after ' . $retry . ' seconds.' : ' Try again later.';
        if ($status === 429) {
            return new Unreachable('rate_limited', 'aisenseapi.com counts requests per address and day, and this address has used its share for now.' . $later, $retry);
        }
        if ($status === 503) {
            return new Unreachable('service_busy', 'aisenseapi.com cannot serve this right now.' . $later, $retry);
        }
        if ($status >= 400 && $status < 500) {
            [$error, $fix] = self::words($payload);

            return new Refused($refused, trim('aisenseapi.com would not take it: ' . ($error ?? 'HTTP ' . $status . '.') . ' ' . ($fix ?? '')));
        }

        return new Unreachable('service_unreachable', 'aisenseapi.com answered HTTP ' . $status . ', so nothing was ' . $done . '.' . $later);
    }

    /**
     * One POST over HTTPS with curl, the answer capped at $limit bytes.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function post(string $path, string $body, string $type, int $limit): array
    {
        if (!function_exists('curl_init')) {
            throw new Unreachable('no_curl', 'This PHP has no curl, which the add-on needs to reach aisenseapi.com. Enable the curl extension in php.ini.');
        }
        $headers = [];
        $received = '';
        $tooMuch = false;
        $handle = curl_init(self::BASE . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'User-Agent: aamio-sound/' . Process::VERSION, 'Expect:'],
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received, &$tooMuch, $limit): int {
                $received .= $chunk;
                if (strlen($received) > $limit) {
                    $tooMuch = true;

                    return -1;
                }

                return strlen($chunk);
            },
        ]);
        $done = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        unset($handle);
        if ($tooMuch) {
            throw new Unreachable('service_answered_something_else', 'aisenseapi.com answered with more than it documents, so nothing was used. Try again later.');
        }
        if ($done === false || $errno !== 0) {
            if ($errno === 60 || $errno === 77 || str_contains($error, 'certificate')) {
                throw new Unreachable('service_unreachable', 'The certificate of aisenseapi.com could not be verified on this machine. If the site opens in a browser, PHP may lack the root: curl.cainfo or openssl.cafile in php.ini can point it at a CA bundle. Verification is never switched off.');
            }
            throw new Unreachable('service_unreachable', 'aisenseapi.com, which makes and reads the sound, could not be reached: ' . ($error !== '' ? $error : 'curl error ' . $errno) . '. Check the network and try again.');
        }

        return [$status, $headers, $received];
    }
}
