<?php

declare(strict_types=1);

namespace Aamio;

/**
 * An error that carries the code the three runtimes give the same failure.
 *
 * error_code on the command line and over MCP is this code, the same in
 * aamio-php, aamio-python and the Node runtime: unknown_channel, never the
 * name of a class. An error without one is said by the short name of its
 * class, as before (a check of 13 November 2026).
 */
interface Coded
{
    public function errorCode(): string;
}
