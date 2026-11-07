<?php

declare(strict_types=1);

namespace Aamio;

/**
 * What an add-on is to the core: a short name, commands under `aamio addon NAME`,
 * and MCP tools beside the core's. An add-on is a Composer package of type
 * aamio-addon whose `aamio-addon.php` returns one of these, so that installing it
 * is the user's choice and `composer require aisenseapi/aamio` stays what it was.
 * The same shape as aamio-python's add-ons.
 */
interface Addon
{
    /** The short name: the command under aamio addon, and the middle of its tool names. */
    public function name(): string;

    public function version(): string;

    /**
     * The commands under aamio addon NAME, each with one line of help, for
     * `aamio addon list` and `aamio addon NAME --help`.
     *
     * @return array<string, string>
     */
    public function commands(): array;

    /**
     * A command that needs no home, run before any runtime is made or locked:
     * an exit code, or null for a command that needs the runtime. $args are
     * the words after aamio addon NAME, $opts the --options.
     */
    public function early(array $args, array $opts): ?int;

    /** A command, with the runtime: an exit code. */
    public function command(array $args, array $opts, Runtime $runtime): int;

    /** @return list<array> the MCP tools, in the shape mcp-tools.json has */
    public function tools(): array;

    /** One tool call, or null for a tool that is not this add-on's. $ask is how a tool asks the user, and null where it cannot. */
    public function dispatch(Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array;
}
