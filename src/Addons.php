<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Add-ons: what a runtime can do beyond the core, installed one by one and enabled by name.
 *
 * The core is the rendezvous: keys, inbox, presence, the board, receipts. Other
 * things are add-ons, each a Composer package of its own of type aamio-addon that
 * depends on this one, says its short name in composer.json under
 * extra.aamio-addon.name, and has an `aamio-addon.php` at its root returning an
 * Addon. Composer says which packages of that type are installed, so installing
 * one is the user's choice and the core stays what it was.
 *
 * Installed is not enabled. The core requires an add-on's file only when the
 * user has named it: with `aamio addon enable NAME`, kept in addons.json in the
 * home, or in AAMIO_ADDONS for a checkout that is not installed. What is
 * installed and not enabled is listed from the package's metadata, without
 * running any of it, with the command that enables it. An add-on that runs here
 * runs in this process, with the key and the home: enabling says which code
 * runs, it is not a boundary around what that code can do.
 *
 * The core owns the names. Two add-ons with one short name, or two tools with
 * one name, stop the start and say so, rather than let the order they were found
 * in decide which one runs. An add-on whose file does not return an Addon stops
 * the start too: a broken install is not a quiet absence. `aamio addon disable
 * NAME` takes a broken one out again.
 */
final class Addons
{
    public const TYPE = 'aamio-addon';
    public const FILE = 'aamio-addon.php';
    public const ENABLED_FILE = 'addons.json';
    public const RESERVED = ['list', 'enable', 'disable'];

    /** @var list<Addon>|null */
    private static ?array $loaded = null;
    private static ?string $key = null;
    private static ?string $home = null;
    /** @var array<string, Addon> the loaded add-ons by the real path of their file */
    private static array $byFile = [];
    /** @var list<array{package: string, version: ?string, path: string}>|null */
    private static ?array $installedOverride = null;

    /** The home the command line was given, before anything is loaded: the enabled list is kept in it. */
    public static function useHome(?string $home): void
    {
        self::$home = $home;
        self::$loaded = null;
    }

    public static function home(): string
    {
        return self::$home ?? Runtime::homeDir();
    }

    // ------------------------------------------------------------------ enabled

    public static function enabledFile(): string
    {
        return self::home() . DIRECTORY_SEPARATOR . self::ENABLED_FILE;
    }

    /** @return list<string> the packages enabled in this home, in the order they were enabled, as the file spells them */
    public static function enabled(): array
    {
        $path = self::enabledFile();
        if (!is_file($path)) {
            return [];
        }
        $text = @file_get_contents($path);
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException($path . ' could not be read as JSON. Fix or remove the file; it lists the add-ons enabled here, one package name each under "enabled".');
        }
        $names = $data['enabled'] ?? null;
        if (!is_array($names) || $names !== array_values($names) || array_filter($names, static fn ($name): bool => !is_string($name)) !== []) {
            throw new \RuntimeException($path . ' is not a list of enabled add-ons. Fix or remove the file; it has one package name each under "enabled".');
        }

        return array_values($names);
    }

    /** @param list<string> $names */
    private static function writeEnabled(array $names): void
    {
        $home = self::home();
        if (!is_dir($home) && !@mkdir($home, 0700, true) && !is_dir($home)) {
            throw new \RuntimeException('the home ' . $home . ' could not be made, so nothing changed.');
        }
        $path = self::enabledFile();
        $json = json_encode(['enabled' => $names], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($path . '.tmp', $json) === false || !@rename($path . '.tmp', $path)) {
            @unlink($path . '.tmp');
            throw new \RuntimeException($path . ' could not be written, so nothing changed.');
        }
        self::$loaded = null;
    }

    // ---------------------------------------------------------------- installed

    /**
     * For tests: these installed packages, instead of asking Composer; null asks Composer again.
     *
     * @param list<array{package: string, version: ?string, path: string}>|null $packages
     */
    public static function useInstalled(?array $packages): void
    {
        self::$installedOverride = $packages;
        self::$loaded = null;
    }

    /**
     * Every add-on installed beside aamio, from its metadata and without running any of it.
     *
     * @return list<array{package: string, version: ?string, name: ?string, file: string}>
     */
    public static function installed(): array
    {
        $packages = self::$installedOverride;
        if ($packages === null) {
            $packages = [];
            if (class_exists(\Composer\InstalledVersions::class) && method_exists(\Composer\InstalledVersions::class, 'getInstalledPackagesByType')) {
                foreach (\Composer\InstalledVersions::getInstalledPackagesByType(self::TYPE) as $package) {
                    $path = \Composer\InstalledVersions::getInstallPath($package);
                    if ($path !== null) {
                        $packages[] = ['package' => $package, 'version' => \Composer\InstalledVersions::getPrettyVersion($package), 'path' => $path];
                    }
                }
            }
        }
        $rows = [];
        foreach ($packages as $package) {
            $dir = rtrim($package['path'], '/\\');
            $rows[] = ['package' => $package['package'], 'version' => $package['version'], 'name' => self::declaredName($dir), 'file' => $dir . DIRECTORY_SEPARATOR . self::FILE];
        }

        return $rows;
    }

    /** The short name a package says in its composer.json under extra.aamio-addon.name: read, never run. */
    private static function declaredName(string $dir): ?string
    {
        $text = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'composer.json');
        $data = is_string($text) ? json_decode($text, true) : null;
        $name = is_array($data) ? ($data['extra']['aamio-addon']['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @param list<string> $wanted */
    private static function isEnabled(array $row, array $wanted): bool
    {
        return in_array(strtolower($row['package']), array_map('strtolower', $wanted), true);
    }

    /** The installed add-on `$which` names, by short name or by package: its package, refused when none or two fit. */
    public static function pick(string $which): string
    {
        $packages = [];
        foreach (self::installed() as $row) {
            if ($row['name'] === $which || strtolower($row['package']) === strtolower($which)) {
                $packages[$row['package']] = true;
            }
        }
        $packages = array_keys($packages);
        sort($packages);
        if ($packages === []) {
            throw new \RuntimeException('no installed add-on is called ' . $which . '. aamio addon list says which are installed; an add-on is installed first, as composer require aisenseapi/aamio-domain-keys for Domain Keys.');
        }
        if (count($packages) > 1) {
            throw new \RuntimeException('two installed add-ons are called ' . $which . ': ' . implode(' and ', $packages) . '. Enable one by its package name.');
        }

        return $packages[0];
    }

    // ------------------------------------------------------------------- loaded

    /**
     * The add-ons of the enabled packages and of what AAMIO_ADDONS names, each checked; nothing cached.
     *
     * @param list<string> $wanted
     * @return array{0: list<Addon>, 1: array<string, Addon>}
     */
    private static function load(string $env, array $wanted): array
    {
        $files = [];
        foreach (self::installed() as $row) {
            if (self::isEnabled($row, $wanted)) {
                $files[] = [$row['file'], $row['name'], $row['package'], 'aamio addon disable ' . $row['package']];
            }
        }
        foreach (explode(',', $env) as $named) {
            if (trim($named) !== '') {
                $files[] = [trim($named), null, null, 'uninstall it'];
            }
        }
        $found = [];
        $byFile = [];
        foreach ($files as [$file, $declared, $package, $fix]) {
            $real = realpath($file);
            if ($real === false) {
                throw new \RuntimeException('the add-on file ' . $file . ' is not there, so it could not be loaded. Reinstall the add-on, or ' . $fix . ' to go on without it.');
            }
            if (isset($byFile[$real])) {
                continue;
            }
            try {
                $addon = (static fn () => require $real)();
            } catch (\Throwable $failure) {
                throw new \RuntimeException('the add-on ' . ($package ?? $file) . ' could not be loaded: ' . get_class($failure) . ': ' . $failure->getMessage() . '. Reinstall it, or ' . $fix . ' to go on without it.');
            }
            if (!$addon instanceof Addon) {
                throw new \RuntimeException($file . ' cannot be used as an add-on: it does not return an Aamio\\Addon. Reinstall it, or ' . $fix . ' to go on without it.');
            }
            if ($declared !== null && $declared !== $addon->name()) {
                throw new \RuntimeException('the add-on ' . $package . ' is called ' . $declared . ' in its composer.json and ' . $addon->name() . ' in its code. Reinstall it, or ' . $fix . ' to go on without it.');
            }
            if (in_array($addon->name(), self::RESERVED, true)) {
                throw new \RuntimeException(get_class($addon) . ' cannot be called ' . $addon->name() . ': that is a command of aamio addon itself. To go on without it: ' . $fix . '.');
            }
            foreach ($found as $other) {
                if ($other->name() === $addon->name()) {
                    throw new \RuntimeException('two add-ons are called ' . $addon->name() . ': ' . get_class($other) . ' and ' . get_class($addon) . '. Uninstall one, or the order they were found in would decide which one runs.');
                }
            }
            $found[] = $addon;
            $byFile[$real] = $addon;
        }
        // The tools too, here and not first when a server lists them: `aamio addon
        // list` and `aamio serve` have to agree on whether this installation works.
        self::tools(McpServer::coreTools(), $found);

        return [$found, $byFile];
    }

    /** @return list<Addon> every add-on this installation runs: the enabled ones and what AAMIO_ADDONS names, checked once */
    public static function loaded(): array
    {
        $env = (string) getenv('AAMIO_ADDONS');
        $key = $env . "\0" . self::home();
        if (self::$loaded !== null && self::$key === $key) {
            return self::$loaded;
        }
        [$found, $byFile] = self::load($env, self::enabled());
        self::$loaded = $found;
        self::$byFile = $byFile;
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

    // ---------------------------------------------------------- enable, disable

    /** Lets an installed add-on run here, once it has been loaded clean: a broken one is refused, not recorded. */
    public static function enable(string $which): array
    {
        $package = self::pick($which);
        $names = self::enabled();
        if (!in_array(strtolower($package), array_map('strtolower', $names), true)) {
            self::load((string) getenv('AAMIO_ADDONS'), array_merge($names, [$package]));
            self::writeEnabled(array_merge($names, [$package]));
        }
        $answer = self::describe();
        $answer['note'] = $package . " runs here from now on, in aamio's own process and with its key and home: it can do what aamio itself can.";

        return $answer;
    }

    /** Takes an add-on out of the enabled list, by short name or by package, loading nothing to do it. */
    public static function disable(string $which): array
    {
        $packages = [strtolower($which)];
        foreach (self::installed() as $row) {
            if ($row['name'] === $which) {
                $packages[] = strtolower($row['package']);
            }
        }
        $names = self::enabled();
        $kept = array_values(array_filter($names, static fn (string $name): bool => !in_array(strtolower($name), $packages, true)));
        if (count($kept) !== count($names)) {
            self::writeEnabled($kept);
        }
        $answer = self::describe();
        if (count($kept) === count($names)) {
            $answer['note'] = $which . ' was not enabled; nothing changed.';
        }

        return $answer;
    }

    // ---------------------------------------------------------------- describe

    private static function running(Addon $addon): array
    {
        return ['version' => $addon->version(), 'class' => get_class($addon), 'commands' => array_keys($addon->commands()), 'mcp_tools' => array_column($addon->tools(), 'name')];
    }

    /**
     * What aamio addon list answers: every add-on installed or named, whether it runs here, and for one that runs its commands and tools.
     *
     * When an enabled add-on cannot be loaded, the list still answers, from the
     * metadata, with the error and the command that takes the add-on out: a
     * broken add-on must not hide itself or the others.
     */
    public static function describe(): array
    {
        $error = null;
        try {
            $running = self::loaded();
            $byFile = self::$byFile;
        } catch (\RuntimeException $failure) {
            $running = [];
            $byFile = [];
            $error = $failure->getMessage();
        }
        $wanted = self::enabled();
        $installed = self::installed();
        $rows = [];
        $seen = [];
        foreach ($installed as $row) {
            $entry = ['name' => $row['name'], 'version' => $row['version'], 'package' => $row['package'], 'enabled' => self::isEnabled($row, $wanted)];
            $real = realpath($row['file']);
            $addon = $real !== false ? ($byFile[$real] ?? null) : null;
            if (!$entry['enabled'] && $addon !== null) {
                // Installed, and running because AAMIO_ADDONS names the same file:
                // one row, saying how it came to run.
                $entry['enabled'] = true;
                $entry['named_in'] = 'AAMIO_ADDONS';
            }
            if ($entry['enabled']) {
                if ($addon !== null) {
                    $seen[spl_object_id($addon)] = true;
                    $entry = array_merge($entry, self::running($addon));
                } else {
                    $entry['loaded'] = false;
                }
            } else {
                $same = array_filter($installed, static fn (array $other): bool => $other['name'] === $row['name'] && $other['package'] !== $row['package']);
                $entry['fix'] = 'aamio addon enable ' . ($same !== [] || $row['name'] === null ? $row['package'] : $row['name']);
            }
            $rows[] = $entry;
        }
        foreach ($running as $addon) {
            if (!isset($seen[spl_object_id($addon)])) {
                $rows[] = ['name' => $addon->name(), 'package' => null, 'enabled' => true, 'named_in' => 'AAMIO_ADDONS'] + self::running($addon);
            }
        }
        $answer = ['addons' => $rows];
        $known = array_map(static fn (array $row): string => strtolower($row['package']), $installed);
        $missing = array_values(array_filter($wanted, static fn (string $name): bool => !in_array(strtolower($name), $known, true)));
        if ($missing !== []) {
            $answer['enabled_but_not_installed'] = $missing;
        }
        if ($error !== null) {
            $answer['error'] = $error;
        }
        if ($rows === [] && $error === null) {
            $answer['note'] = 'No add-on is installed. An add-on is a package of its own, installed beside aamio and then enabled by name: composer require aisenseapi/aamio-domain-keys and aamio addon enable domain for Domain Keys.';
        }

        return $answer;
    }

    // ------------------------------------------------------------------- tools

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

    /** Forget what was loaded, for a test that changes AAMIO_ADDONS, the home, or the files behind them. */
    public static function reset(): void
    {
        self::$loaded = null;
        self::$key = null;
        self::$byFile = [];
    }
}
