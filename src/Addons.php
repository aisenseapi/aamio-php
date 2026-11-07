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
 * running any of it, with the command that enables it. The choice is read in
 * the home a runtime runs in, so a server built on a runtime for another home
 * does not see it. An add-on that runs here runs in this process, with the key
 * and the home: enabling says which code runs, it is not a boundary around what
 * that code can do.
 *
 * The core owns the names. Two add-ons with one short name, or two tools with
 * one name, stop the start and say so, rather than let the order they were found
 * in decide which one runs. An add-on whose file does not return an Addon, or
 * whose commands and tools do not build, stops the start too: a broken install
 * is not a quiet absence, and nothing of the kind is recorded as enabled.
 * `aamio addon disable NAME` takes a broken one out again.
 */
final class Addons
{
    public const TYPE = 'aamio-addon';
    public const FILE = 'aamio-addon.php';
    public const ENABLED_FILE = 'addons.json';
    public const RESERVED = ['list', 'enable', 'disable'];
    public const RESTART = 'A local MCP server already running keeps the tools it started with: start it again to see the change.';

    /** How long a change waits for another aamio that is changing the same list, in seconds. */
    public static float $lockWait = 10.0;

    /** @var array<string, array{0: list<Addon>, 1: array<string, Addon>}> what is loaded, by AAMIO_ADDONS and home: two runtimes on two homes in one process each see their own home's choice */
    private static array $cache = [];
    private static ?string $home = null;
    /** @var list<array{package: string, version: ?string, path: string}>|null */
    private static ?array $installedOverride = null;

    /** The home the command line was given, before anything is loaded: the enabled list is kept in it. */
    public static function useHome(?string $home): void
    {
        self::$home = $home;
        self::$cache = [];
    }

    public static function home(?string $given = null): string
    {
        return $given ?? self::$home ?? Runtime::homeDir();
    }

    // ------------------------------------------------------------------ enabled

    public static function enabledFile(?string $home = null): string
    {
        return self::home($home) . DIRECTORY_SEPARATOR . self::ENABLED_FILE;
    }

    /** @return list<string> the packages enabled in this home, in the order they were enabled, as the file spells them */
    public static function enabled(?string $home = null): array
    {
        $path = self::enabledFile($home);
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

    /**
     * The enabled list's lock, one per home, held from reading the list to writing it back.
     *
     * The operating system holds it, as it holds a home's owner lock, so two
     * commands at once, from this runtime or from aamio-python on the same
     * home, take turns rather than each writing over the other's change.
     *
     * @return resource
     */
    private static function lock(?string $home)
    {
        $folder = self::home($home);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new \RuntimeException('the home ' . $folder . ' could not be made, so nothing changed.');
        }
        $path = self::enabledFile($home) . '.lock';
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException($path . ' could not be opened: ' . (error_get_last()['message'] ?? 'no reason given') . '. Nothing changed; restore file access, or use a home on a local disk.');
        }
        $deadline = microtime(true) + self::$lockWait;
        while (!@flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new \RuntimeException(self::enabledFile($home) . ' is being changed by another aamio, which did not finish in ' . (int) self::$lockWait . ' seconds. Nothing changed; try again.');
            }
            usleep(50000);
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function unlock($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Writes the list, under the lock the caller holds: a file of this process's own first, then one rename.
     *
     * @param list<string> $names
     */
    private static function writeEnabled(?string $home, array $names): void
    {
        $path = self::enabledFile($home);
        $tmp = $path . '.' . getmypid() . '.tmp';
        $json = json_encode(['enabled' => $names], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException($path . ' could not be written, so nothing changed.');
        }
        for ($attempt = 0; !@rename($tmp, $path); $attempt++) {
            // Windows: a reader holds the file for a moment.
            if ($attempt >= 19) {
                @unlink($tmp);
                throw new \RuntimeException($path . ' could not be replaced: another program holds it. Nothing changed; try again.');
            }
            usleep(50000);
        }
        self::$cache = [];
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
        self::$cache = [];
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

    /** What the hooks produce, tried before the add-on is used or recorded: its commands are a map of name to help, and its tools have names. */
    private static function wellFormed(Addon $addon, string $fix): void
    {
        try {
            $commands = $addon->commands();
            $tools = $addon->tools();
        } catch (\Throwable $failure) {
            throw new \RuntimeException('the add-on ' . get_class($addon) . ' could not build its commands or tools: ' . get_class($failure) . ': ' . $failure->getMessage() . '. Reinstall it, or ' . $fix . ' to go on without it.');
        }
        foreach ($commands as $name => $help) {
            if (!is_string($name) || $name === '' || !is_string($help)) {
                throw new \RuntimeException(get_class($addon) . ' cannot be used as an add-on: commands() is not a map of command name to help. Reinstall it, or ' . $fix . ' to go on without it.');
            }
        }
        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null) || $tool['name'] === '') {
                throw new \RuntimeException(get_class($addon) . ' cannot be used as an add-on: a tool of its has no name. Reinstall it, or ' . $fix . ' to go on without it.');
            }
        }
    }

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
            self::wellFormed($addon, $fix);
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

    /** @return array{0: list<Addon>, 1: array<string, Addon>} */
    private static function loadedFor(?string $home): array
    {
        $env = (string) getenv('AAMIO_ADDONS');
        $key = $env . "\0" . self::home($home);
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = self::load($env, self::enabled($home));
        }

        return self::$cache[$key];
    }

    /** @return list<Addon> every add-on this installation runs in this home: the enabled ones and what AAMIO_ADDONS names, checked once */
    public static function loaded(?string $home = null): array
    {
        return self::loadedFor($home)[0];
    }

    public static function byName(string $name, ?string $home = null): ?Addon
    {
        foreach (self::loaded($home) as $addon) {
            if ($addon->name() === $name) {
                return $addon;
            }
        }

        return null;
    }

    // ---------------------------------------------------------- enable, disable

    /** Lets an installed add-on run here, once it has been loaded clean: a broken one is refused, not recorded. */
    public static function enable(string $which, ?string $home = null): array
    {
        $package = self::pick($which);
        $lock = self::lock($home);
        try {
            $names = self::enabled($home);
            if (!in_array(strtolower($package), array_map('strtolower', $names), true)) {
                self::load((string) getenv('AAMIO_ADDONS'), array_merge($names, [$package]));
                self::writeEnabled($home, array_merge($names, [$package]));
            }
        } finally {
            self::unlock($lock);
        }
        $answer = self::describe($home);
        $answer['note'] = $package . " runs here from now on, in aamio's own process and with its key and home: it can do what aamio itself can. " . self::RESTART;

        return $answer;
    }

    /** Takes an add-on out of the enabled list, by short name or by package, loading nothing to do it. */
    public static function disable(string $which, ?string $home = null): array
    {
        $packages = [strtolower($which)];
        foreach (self::installed() as $row) {
            if ($row['name'] === $which) {
                $packages[] = strtolower($row['package']);
            }
        }
        $lock = self::lock($home);
        try {
            $names = self::enabled($home);
            $kept = array_values(array_filter($names, static fn (string $name): bool => !in_array(strtolower($name), $packages, true)));
            if (count($kept) !== count($names)) {
                self::writeEnabled($home, $kept);
            }
        } finally {
            self::unlock($lock);
        }
        $answer = self::describe($home);
        $notes = [count($kept) === count($names) ? $which . ' was not enabled; nothing on disk changed.' : $which . ' is disabled. ' . self::RESTART];
        // What AAMIO_ADDONS names runs whatever the file says, and goes on running.
        foreach ($answer['addons'] as $row) {
            $meant = in_array($which, [$row['name'], $row['class'] ?? null], true) || (($row['package'] ?? null) !== null && strtolower($row['package']) === strtolower($which));
            if (($row['named_in'] ?? null) === 'AAMIO_ADDONS' && $meant) {
                $notes[] = $row['name'] . ' still runs: AAMIO_ADDONS names its file. Unset AAMIO_ADDONS, or take the file out of it, to stop it.';
            }
        }
        $answer['note'] = implode(' ', $notes);

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
    public static function describe(?string $home = null): array
    {
        $error = null;
        try {
            [$running, $byFile] = self::loadedFor($home);
        } catch (\RuntimeException $failure) {
            $running = [];
            $byFile = [];
            $error = $failure->getMessage();
        }
        $wanted = self::enabled($home);
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
     * Every tool the add-ons bring, in the given home, refused when a name is taken already.
     *
     * @param list<array> $core the core's tools
     * @param list<Addon>|null $among the add-ons to take them from; the loaded ones when null
     * @return list<array>
     */
    public static function tools(array $core, ?array $among = null, ?string $home = null): array
    {
        $taken = [];
        foreach ($core as $tool) {
            $taken[$tool['name']] = 'the core';
        }
        $found = [];
        foreach ($among ?? self::loaded($home) as $addon) {
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

    /** One tool call, to the add-on that has the tool in the runtime's home, or null when none does. */
    public static function dispatch(Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array
    {
        foreach (self::loaded($runtime->home) as $addon) {
            if (in_array($name, array_column($addon->tools(), 'name'), true)) {
                return $addon->dispatch($runtime, $name, $arguments, $ask);
            }
        }

        return null;
    }

    /** Forget what was loaded, for a test that changes AAMIO_ADDONS, the home, or the files behind them. */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
