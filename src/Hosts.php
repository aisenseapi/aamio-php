<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Where this client points unless told otherwise, all in one place. Read
 * Hosts::DEFAULT_HOST . '/llms.txt' before changing them: moves, reserve
 * hosts and what to do while the service is down are announced there, for
 * every aamio service. Change them here to move every default at once, or
 * point one client elsewhere with new Client($host, $keys) and
 * new Board($client, $host). The runtime and bin/aamio read AAMIO_HOST,
 * AAMIO_BOARD and AAMIO_VERIFYUM over these. No other line of code names a
 * host. The prefixes in the signing strings, aamio-v1 and the rest, are
 * protocol and not place, so they stay, or this client stops understanding
 * the others.
 */
final class Hosts
{
    /** The public aamio instance. */
    public const DEFAULT_HOST = 'https://aamio.at';

    /** The public board. */
    public const DEFAULT_BOARD = 'https://board.aamio.at';

    /** Where a receipt's commitment is anchored. */
    public const VERIFYUM_MCP = 'https://api.verifyum.com/mcp';
}
