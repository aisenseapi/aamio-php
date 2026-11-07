<?php

declare(strict_types=1);

/*
 * The connection and the page a read of a domain key list sees, for the tests of
 * the read and of the runtime around it. Everything above the socket is the real
 * thing.
 */

final class FakeDomainConnection
{
    public string $sent = '';
    public bool $closed = false;
    public int $reads = 0;

    /**
     * @param list<string|\Throwable> $chunks what each read gets
     * @param (\Closure():void)|null $onReceive
     */
    public function __construct(public array $chunks, private ?\Closure $onReceive = null)
    {
    }

    public function send(string $bytes, float $left): void
    {
        assert($left > 0);
        $this->sent .= $bytes;
    }

    public function receive(float $left): string
    {
        assert($left > 0);
        $this->reads++;
        if ($this->onReceive !== null) {
            ($this->onReceive)();
        }
        if ($this->chunks === []) {
            return '';
        }
        $chunk = array_shift($this->chunks);
        if ($chunk instanceof \Throwable) {
            throw $chunk;
        }
        if (strlen($chunk) > 4096) {
            array_unshift($this->chunks, substr($chunk, 4096));

            return substr($chunk, 0, 4096);
        }

        return $chunk;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

/** A response as it comes over the wire. */
function fakeDomainHttp(int $status = 200, ?array $headers = null, string $body = ''): string
{
    $headers ??= ['Content-Type' => 'application/json; charset=utf-8'];
    $head = 'HTTP/1.1 ' . $status . ' ' . ([200 => 'OK', 404 => 'Not Found'][$status] ?? 'X') . "\r\n";
    foreach ($headers as $name => $value) {
        $head .= $name . ': ' . $value . "\r\n";
    }

    return $head . "\r\n" . $body;
}
