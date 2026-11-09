<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * The two calls to aisenseapi.com, for whoever asks for the aisense engine: a card to a code, and a picture back to text.
 *
 *     POST https://aisenseapi.com/services/v1/qrcode_encode    JSON in, JSON with a PNG out
 *     POST https://aisenseapi.com/services/v1/qrcode_decode    a picture in, as multipart/form-data, JSON out
 *
 * Both are free and need no key. The card and the picture go to AI SENSE over
 * HTTPS. aisense makes its code at level H, blue and softened, with two modules
 * of quiet zone, and reads a picture with zbarimg: every code it finds, QR codes
 * and barcodes alike, one to a line. It answers 400 both when a picture holds
 * no code and when it cannot read the picture at all, so its sentence "No QR
 * code or barcode was found" tells the two apart, and a 422, as audio_decode
 * answers for no message, is taken for no code too; 413 for a picture over 10 MiB
 * or 25 megapixels, 415 for a format it does not read, and 429 or 503 with
 * Retry-After when it cannot serve now. A picture goes as multipart: JSON
 * bodies stop at 256 KB there. The same as aamio-python's aamio_qrcast.service.
 */
final class Service
{
    public const BASE = 'https://aisenseapi.com/services/v1';
    public const MAX_IMAGE_BYTES = 10485760;
    /** A property, so a test can lower it. */
    public static int $maxImageBytes = self::MAX_IMAGE_BYTES;
    public const MAX_PIXELS = 25000000;
    public const MAX_JSON_BYTES = 1048576;
    public const TIMEOUT = 30;
    public const NOTHING = 'No QR code or barcode was found';
    public const TYPES = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /**
     * The PNG of aisense's code for a card. $post is for tests.
     *
     * @param null|callable(string, string, string, int): array{0: int, 1: array<string, string>, 2: string} $post
     */
    public static function encode(string $card, ?callable $post = null): string
    {
        $body = (string) json_encode(['data' => $card], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $headers, $payload] = ($post ?? [self::class, 'post'])('/qrcode_encode', $body, 'application/json', self::MAX_JSON_BYTES);
        if ($status !== 200) {
            throw self::failure($status, $headers, $payload, 'made', 'service_refused');
        }
        $said = self::json($payload);
        $png = is_string($said['qrcode_image'] ?? null) ? base64_decode($said['qrcode_image'], true) : false;
        if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new Unreachable('service_answered_something_else', 'aisenseapi.com answered with something other than the PNG it documents, so nothing was made. Try again later, or use the local engine.', null, true);
        }

        return $png;
    }

    /**
     * Every code aisense read in a picture, one text each. NothingSeen when it found none.
     *
     * @param null|callable(string, string, string, int): array{0: int, 1: array<string, string>, 2: string} $post
     * @return list<string>
     */
    public static function decode(string $picture, string $kind, ?callable $post = null): array
    {
        $edge = 'aamio-qrcast-' . bin2hex(random_bytes(12));
        $body = '--' . $edge . "\r\n" . 'Content-Disposition: form-data; name="qrcode_image"; filename="picture.' . $kind . '"' . "\r\n"
            . 'Content-Type: ' . (self::TYPES[$kind] ?? 'application/octet-stream') . "\r\n\r\n" . $picture . "\r\n--" . $edge . "--\r\n";
        [$status, $headers, $payload] = ($post ?? [self::class, 'post'])('/qrcode_decode', $body, 'multipart/form-data; boundary=' . $edge, self::MAX_JSON_BYTES);
        if ($status === 400 || $status === 422) {
            [$error, $fix] = self::words($payload);
            // 422 is how audio_decode says it heard no message, and what qrcode_decode may say one day.
            if ($status === 422 || ($error !== null && str_starts_with($error, self::NOTHING))) {
                throw new NothingSeen('aisense found no code in the picture. ' . ($fix ?? 'Send a sharper, larger or straighter picture of the code.'));
            }
        }
        if ($status !== 200) {
            throw self::failure($status, $headers, $payload, 'read', 'not_readable');
        }
        $said = self::json($payload);
        if (!is_string($said['qrcode_content'] ?? null)) {
            throw new Unreachable('service_answered_something_else', 'aisenseapi.com answered with something other than the JSON it documents, so nothing was read. Try again later, or use the local engine.', null, true);
        }
        $texts = [];
        foreach (explode("\n", $said['qrcode_content']) as $line) {
            $line = rtrim($line, "\r");
            if ($line !== '') {
                $texts[] = $line;
            }
        }

        return $texts;
    }

    /** @return ?array<string, mixed> aisense's JSON, read from the first brace: PHP warnings can come before it */
    private static function json(string $payload): ?array
    {
        $start = strpos($payload, '{');
        $said = $start === false ? null : json_decode(substr($payload, $start), true);

        return is_array($said) ? $said : null;
    }

    /** @return array{0: ?string, 1: ?string} aisense's own error and fix, when its answer carries them */
    private static function words(string $payload): array
    {
        $said = self::json($payload) ?? [];

        return [is_string($said['error'] ?? null) ? $said['error'] : null, is_string($said['fix'] ?? null) ? $said['fix'] : null];
    }

    /** @param array<string, string> $headers */
    private static function failure(int $status, array $headers, string $payload, string $done, string $refused): \RuntimeException
    {
        $retry = $headers['retry-after'] ?? null;
        $later = $retry !== null ? ' Try again after ' . $retry . ' seconds.' : ' Try again later.';
        if ($status === 429) {
            return new Unreachable('rate_limited', 'aisenseapi.com counts requests per address and day, and this address has used its share for now.' . $later, $retry, true);
        }
        if ($status === 503) {
            return new Unreachable('service_busy', 'aisenseapi.com cannot serve this right now.' . $later, $retry, true);
        }
        if ($status >= 400 && $status < 500) {
            [$error, $fix] = self::words($payload);

            return new Refused($refused, trim('aisenseapi.com would not take it: ' . ($error ?? 'HTTP ' . $status . '.') . ' ' . ($fix ?? '')));
        }

        return new Unreachable('service_unreachable', 'aisenseapi.com answered HTTP ' . $status . ', so nothing was ' . $done . '.' . $later, null, true);
    }

    /**
     * One POST over HTTPS with curl, the answer capped at $limit bytes.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function post(string $path, string $body, string $type, int $limit): array
    {
        if (!function_exists('curl_init')) {
            throw new Unreachable('no_curl', 'This PHP has no curl, which the aisense engine needs. Enable the curl extension in php.ini, or use the local engine.');
        }
        $headers = [];
        $received = '';
        $tooMuch = false;
        $handle = curl_init(self::BASE . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'User-Agent: aamio-qrcast/' . Process::VERSION, 'Expect:'],
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
            throw new Unreachable('service_answered_something_else', 'aisenseapi.com answered with more than it documents, so nothing was used. Try again later.', null, true);
        }
        if ($done === false || $errno !== 0) {
            if ($errno === 60 || $errno === 77 || str_contains($error, 'certificate')) {
                throw new Unreachable('service_unreachable', 'The certificate of aisenseapi.com could not be verified on this machine. If the site opens in a browser, PHP may lack the root: curl.cainfo or openssl.cafile in php.ini can point it at a CA bundle. Verification is never switched off.');
            }
            // A status line came before the error, a timeout or a connection cut
            // in the body: it had the request, and that is said, not hidden.
            if ($status > 0) {
                throw new Unreachable('service_unreachable', 'aisenseapi.com answered, and the answer broke off: ' . ($error !== '' ? $error : 'curl error ' . $errno) . '. Try again, or use the local engine.', null, true);
            }
            throw new Unreachable('service_unreachable', 'aisenseapi.com could not be reached: ' . ($error !== '' ? $error : 'curl error ' . $errno) . '. Check the network and try again, or use the local engine.');
        }

        return [$status, $headers, $received];
    }
}
