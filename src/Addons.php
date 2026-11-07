<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Add-ons: what a runtime can do beyond the core, installed one by one.
 *
 * The core is the rendezvous: keys, inbox, presence, the board, receipts. Other
 * things are add-ons, each a Composer package of its own of type aamio-addon that
 * depends on this one and has an `aamio-addon.php` at its root returning an
 * Addon. Composer says which packages of that type are installed, so installing
 * one is the user's choice and the core stays what it was.
 *
 * The core owns the names. Two add-ons with one short name, or two tools with
 * one name, stop the start and say so, rather than let the order they were found
 * in decide which one runs. An add-on whose file does not return an Addon stops
 * the start too: a broken install is not a quiet absence. For a checkout that is
 * not installed, AAMIO_ADDONS names the `aamio-addon.php` files to load, comma
 * separated; installed add-ons are found by themselves.
 */
final class Addons
{
    public const TYPE = 'aamio-addon';
    public const FILE = 'aamio-addon.php';

    /** @var list<Addon>|null */
    private static ?array $loaded = null;
    private static ?string $key = null;

    /** @return list<Addon> */
    public static function loaded(): array
    {
        $key = (string) getenv('AAMIO_ADDONS');
        if (self::$loaded !== null && self::$key === $key) {
            return self::$loaded;
        }
        $files = [];
        if (class_exists(\Composer\InstalledVersions::class) && method_exists(\Composer\InstalledVersions::class, 'getInstalledPackagesByType')) {
            foreach (\Composer\InstalledVersions::getInstalledPackagesByType(self::TYPE) as $package) {
                $path = \Composer\InstalledVersions::getInstallPath($package);
                if ($path !== null) {
                    $files[] = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
                }
            }
        }
        foreach (explode(',', $key) as $named) {
            if (trim($named) !== '') {
                $files[] = trim($named);
            }
        }
        $found = [];
        $seen = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false) {
                throw new \RuntimeException('the add-on file ' . $file . ' is not there, so it could not be loaded. Reinstall the add-on, or uninstall it to go on without it.');
            }
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $addon = (static fn () => require $real)();
            if (!$addon instanceof Addon) {
                throw new \RuntimeException($file . ' cannot be used as an add-on: it does not return an Aamio\\Addon. Reinstall it, or uninstall it to go on without it.');
            }
            foreach ($found as $other) {
                if ($other->name() === $addon->name()) {
                    throw new \RuntimeException('two add-ons are called ' . $addon->name() . ': ' . get_class($other) . ' and ' . get_class($addon) . '. Uninstall one, or the order they were found in would decide which one runs.');
                }
            }
            $found[] = $addon;
        }
        // The tools too, here and not first when a server lists them: `aamio addon
        // list` and `aamio serve` have to agree on whether this installation works.
        self::tools(McpServer::coreTools(), $found);
        self::$loaded = $found;
        self::$key = $key;

        return $found;
    }

    public static function byName(string $name): ?Addon
    {
        foreach (self::loaded() as $addon) {
            if ($addon->name() === $name) {
                return $addon;
            }
        }

        return null;
    }

    /** What aamio addon list answers: each add-on, its version, its commands and its tools. */
    public static function describe(): array
    {
        $rows = [];
        foreach (self::loaded() as $addon) {
            $rows[] = ['name' => $addon->name(), 'version' => $addon->version(), 'class' => get_class($addon), 'commands' => array_keys($addon->commands()), 'mcp_tools' => array_column($addon->tools(), 'name')];
        }
        $answer = ['addons' => $rows];
        if ($rows === []) {
            $answer['note'] = 'No add-on is installed. An add-on is a package of its own, installed beside aamio: composer require aisenseapi/aamio-domain-keys for Domain Keys.';
        }

        return $answer;
    }

    /**
     * Every tool the add-ons bring, refused when a name is taken already.
     *
     * @param list<array> $core the core's tools
     * @param list<Addon>|null $among the add-ons to take them from; the loaded ones when null
     * @return list<array>
     */
    public static function tools(array $core, ?array $among = null): array
    {
        $taken = [];
        foreach ($core as $tool) {
            $taken[$tool['name']] = 'the core';
        }
        $found = [];
        foreach ($among ?? self::loaded() as $addon) {
            foreach ($addon->tools() as $tool) {
                $name = (string) ($tool['name'] ?? '');
                if (isset($taken[$name])) {
                    throw new \RuntimeException('the tool ' . $name . ' is offered by ' . $taken[$name] . ' and by ' . get_class($addon) . '. Uninstall one, or the order they were found in would decide which one runs.');
                }
                $taken[$name] = get_class($addon);
                $found[] = $tool;
            }
        }

        return $found;
    }

    /** One tool call, to the add-on that has the tool, or null when none does. */
    public static function dispatch(Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array
    {
        foreach (self::loaded() as $addon) {
            if (in_array($name, array_column($addon->tools(), 'name'), true)) {
                return $addon->dispatch($runtime, $name, $arguments, $ask);
            }
        }

        return null;
    }

    /** Forget what was loaded, for a test that changes AAMIO_ADDONS or the files behind it. */
    public static function reset(): void
    {
        self::$loaded = null;
        self::$key = null;
    }
}
