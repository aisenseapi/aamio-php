<?php

declare(strict_types=1);

namespace Aamio;

/**
 * One HTTP call, one answer: [status, body, headers]. The body is decoded when
 * the answer is JSON and left as text otherwise; a network failure is status 0
 * with the reason in the body, because "no answer" is an outcome a caller must
 * see and never confuse with a refusal.
 */
final class Http
{
    public const VERSION = '0.3.3';
    public const USER_AGENT = 'aamio-php/' . self::VERSION;

    /**
     * A stand-in for the network, for tests: a callable taking (method, url,
     * body, headers) and returning [status, body, headers]. Null in production.
     * @var callable|null
     */
    public static $override = null;

    /** @return array{0:int,1:mixed,2:array<string,string>} */
    public static function call(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 40): array
    {
        if (self::$override !== null) {
            return (self::$override)($method, $url, $body, $headers);
        }
        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $name . ': ' . $value;
            }
        }
        $lines[] = 'Accept: application/json';
        $lines[] = 'User-Agent: ' . self::USER_AGENT;
        $responseHeaders = [];
        // A PHP without a CA bundle configured, common on Windows, would fail
        // every TLS handshake with "unable to get local issuer certificate".
        // The operating system's own store is the fallback; verification is
        // never turned off.
        if (ini_get('curl.cainfo') === '' && ini_get('openssl.cafile') === '' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($handle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($handle);
        if ($text === false) {
            $reason = curl_error($handle);
            curl_close($handle);

            return [0, ['error' => 'no answer: ' . $reason, 'fix' => 'The request may have landed. Keep the bytes, mark the send unknown, and retry only when somebody has decided it is safe to.'], []];
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = json_decode((string) $text, true);

        return [$status, json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $text, $responseHeaders];
    }
}
