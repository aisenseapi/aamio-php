<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;


/**
 * One TLS connection to an address that was checked, for one read of a domain
 * key list. The name goes into the certificate check and the server name, and
 * is not looked up here.
 *
 * A stream of its own and not cURL, which the rest of this client uses: cURL
 * takes a proxy from the environment, reads a response before it can be looked
 * at, and treats a body that is shorter or longer than announced in ways of its
 * own. This reads the bytes as they come and counts them, as aamio-python does.
 */
final class Connection
{
    /** @param resource $stream */
    private function __construct(private $stream, private $clock)
    {
    }

    /**
     * One limit for the connection and the handshake together, read again
     * before each step, so each step gets what the one before it left. $port is
     * for the local tests of this class: a read goes to 443 and nowhere else.
     *
     * @throws Refused
     */
    public static function open(string $address, string $domain, float $remaining, ?callable $clock = null, int $port = Fetch::PORT): self
    {
        $clock ??= static fn (): float => hrtime(true) / 1e9;
        if (!extension_loaded('openssl')) {
            throw new Refused('tls_unavailable', null, 'unreachable');
        }
        $limit = $clock() + $remaining;
        $target = 'tcp://' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . ':' . $port;
        // No cafile: where php.ini names none, OpenSSL uses the system's paths,
        // and on Windows PHP asks the operating system's own store.
        $context = stream_context_create(['ssl' => ['peer_name' => $domain, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'disable_compression' => true]]);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, max($limit - $clock(), 0.001), STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new Refused($clock() >= $limit - 0.01 ? 'timeout' : 'connect', null, 'unreachable');
        }
        $connection = new self($stream, $clock);
        stream_set_blocking($stream, false);
        try {
            while (true) {
                $done = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if ($done === true) {
                    return $connection;
                }
                if ($done === false) {
                    throw new Refused('tls', null, 'unreachable');
                }
                $connection->wait($limit, true);
            }
        } catch (\Throwable $error) {
            $connection->close();
            throw $error;
        }
    }

    /** Waits until the stream can be used or the time is up. */
    private function wait(float $limit, bool $writing = false): void
    {
        $left = $limit - ($this->clock)();
        if ($left <= 0) {
            throw new Refused('timeout', null, 'unreachable');
        }
        $read = [$this->stream];
        $write = $writing ? [$this->stream] : null;
        $except = null;
        // Short slices: a handshake can want either direction, and what OpenSSL
        // has buffered does not always show up here.
        $slice = min($left, 0.05);
        if (@stream_select($read, $write, $except, 0, (int) ceil($slice * 1000000)) === false) {
            throw new Refused('network', null, 'unreachable');
        }
    }

    /** @throws Refused */
    public function send(string $bytes, float $left): void
    {
        $limit = ($this->clock)() + $left;
        while ($bytes !== '') {
            $written = @fwrite($this->stream, $bytes);
            if ($written === false) {
                throw new Refused('network', null, 'unreachable');
            }
            if ($written > 0) {
                $bytes = (string) substr($bytes, $written);
                continue;
            }
            $this->wait($limit, true);
        }
    }

    /**
     * What has arrived, up to 4096 bytes, or '' when the other side has closed.
     *
     * @throws Refused
     */
    public function receive(float $left): string
    {
        $limit = ($this->clock)() + $left;
        while (true) {
            $chunk = @fread($this->stream, 4096);
            if (is_string($chunk) && $chunk !== '') {
                return $chunk;
            }
            if (feof($this->stream)) {
                return '';
            }
            $this->wait($limit);
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }
}
