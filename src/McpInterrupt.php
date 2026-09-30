<?php

declare(strict_types=1);

namespace Aamio;

/**
 * A tool call that ends without a result of its own. Either the user has to be
 * asked first, and over 2026-07-28 the question is the call's result
 * (input_required, in $result), or the call was cancelled, or the input ended,
 * while the user was being asked, and nothing answers it ($result null).
 *
 * Not a RuntimeException, so a tool's own error handling lets it through.
 *
 * @internal
 */
final class McpInterrupt extends \Exception
{
    public function __construct(public readonly ?array $result, string $why)
    {
        parent::__construct($why);
    }
}
