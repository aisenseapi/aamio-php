<?php

declare(strict_types=1);

namespace Aamio;

/** What was given is wrong, with the code the other two runtimes give it. */
final class CodedInvalidArgument extends \InvalidArgumentException implements Coded
{
    public function __construct(private readonly string $errorCode, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
