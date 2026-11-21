<?php

declare(strict_types=1);

namespace Aamio;

/**
 * A readable message carries something this home holds that reads a scope, a
 * thread or the home itself.
 *
 * $found says what, as "the key of scope team", never the value: the error is
 * shown, logged and handed to a model, and the value goes nowhere with it.
 * $hosted is true when the inbox was opened through the hosted endpoint.
 */
final class SecretInReadable extends \InvalidArgumentException implements Coded
{
    /** @param list<string> $found */
    public function __construct(string $message, public readonly array $found, public readonly string $w, public readonly bool $hosted)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'secret_in_readable';
    }
}
