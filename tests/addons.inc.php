<?php

declare(strict_types=1);

/*
 * Add-ons: found by themselves, named once, and never a quiet absence.
 *
 * The core finds an add-on through Composer, or through AAMIO_ADDONS for a
 * checkout that is not installed, puts its commands under aamio addon NAME and
 * its tools beside the core's. What is tried here is the registry around that:
 * nothing installed is said plainly, two add-ons with one name or one tool name
 * stop the start with both names, an add-on file that returns no add-on stops it
 * with the reason, and the three ways into the runtime an add-on has. The same
 * checks as aamio-python's tests/test_addons.py.
 *
 * Included by tests/runtime.php, with $root, $check, $childPhp and $cliRun in scope.
 */

use Aamio\AddonProcess;
use Aamio\Addons;
use Aamio\Channel;
use Aamio\McpServer;
use Aamio\Runtime;

echo "add-ons\n";

/** The PHP of a demo add-on: ping with the runtime, dry without a home, one tool that answers pong. Declared once: the registry requires the file again after every reset. */
$addonClass = static fn (string $class, string $name, string $tool): string => '
if (!class_exists(' . $class . '::class, false)) {
final class ' . $class . ' implements \Aamio\Addon {
    public function name(): string { return ' . var_export($name, true) . '; }
    public function version(): string { return "0.0.1"; }
    public function commands(): array { return ["ping" => "ping: answers pong", "dry" => "dry: needs no home"]; }
    public function early(array $args, array $opts): ?int { if (($args[0] ?? null) === "dry") { fwrite(STDOUT, "{\"dry\":true}\n"); return 7; } return null; }
    public function command(array $args, array $opts, \Aamio\Runtime $runtime): int { fwrite(STDOUT, "{\"pong\":true,\"home\":\"held\"}\n"); return 0; }
    public function tools(): array { return [["name" => ' . var_export($tool, true) . ', "description" => "answers pong", "inputSchema" => ["type" => "object", "properties" => new \stdClass(), "additionalProperties" => false]]]; }
    public function dispatch(\Aamio\Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array { return $name === ' . var_export($tool, true) . ' ? \Aamio\McpServer::resultOf(["pong" => true]) : null; }
}
}
return new ' . $class . '();
';

(static function () use ($root, $check, $childPhp, $cliRun, $addonClass): void {
    $folder = $root . DIRECTORY_SEPARATOR . 'addons';
    mkdir($folder, 0700, true);
    $demo = static function (string $file, string $name = 'demo', string $tool = 'aamio_addon_demo_ping') use ($folder, $addonClass): string {
        $class = 'Demo' . substr(md5($file . $name . $tool), 0, 8);
        file_put_contents($folder . DIRECTORY_SEPARATOR . $file, '<?php' . $addonClass($class, $name, $tool));

        return $folder . DIRECTORY_SEPARATOR . $file;
    };
    $use = static function (string ...$files): void {
        putenv('AAMIO_ADDONS=' . implode(',', $files));
        Addons::reset();
    };
    $quiet = static function (string $line): void {
    };
    $cli = static fn (array $words, ?string $home = null): array => $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', (string) $home, '--no-archive'], $words));

    $use();
    $said = Addons::describe();
    $check($said['addons'] === [] && str_contains($said['note'], 'No add-on is installed') && str_contains($said['note'], 'aamio-domain-keys'), 'nothing installed is said, and how to get one');
    $runtime = new Runtime($root . DIRECTORY_SEPARATOR . 'addons-home', 'https://fake.test', null, false, $quiet);
    $server = new McpServer($runtime);
    $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $check(count($listed['result']['tools']) === 24, 'and the core is whole: twenty-four tools');
    [$code, , $err] = $cli(['addon', 'domain', 'list'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 2 && str_contains($err, 'no add-on is called domain'), 'an add-on that is not installed is not a command');
    [$code, $out] = $cli(['addon', 'list'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 0 && json_decode($out, true)['addons'] === [], 'aamio addon list, with nothing installed');

    $one = $demo('demo.php');
    $use($one, $one);
    $said = Addons::describe();
    $check(count($said['addons']) === 1 && $said['addons'][0]['name'] === 'demo' && $said['addons'][0]['commands'] === ['ping', 'dry'] && $said['addons'][0]['mcp_tools'] === ['aamio_addon_demo_ping'] && !isset($said['note']), 'an add-on is listed once, with its commands and tools, though named twice');
    $server = new McpServer($runtime);
    $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $check(count($listed['result']['tools']) === 25 && $listed['result']['tools'][24]['name'] === 'aamio_addon_demo_ping', 'its tool is listed beside the core\'s');
    $called = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_demo_ping', 'arguments' => []]]);
    $check(($called['result']['structuredContent']['pong'] ?? null) === true && $called['result']['isError'] === false, 'and called through the server');
    $unknown = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_nothing', 'arguments' => []]]);
    $check(($unknown['error']['code'] ?? null) === -32602, 'a tool nobody has is still unknown');
    [$code, $out] = $cli(['addon', 'demo', 'ping'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 0 && json_decode($out, true) === ['pong' => true, 'home' => 'held'], 'its commands run under aamio addon NAME, with the runtime');
    [$code, $out] = $cli(['addon', 'demo', 'dry'], $root . DIRECTORY_SEPARATOR . 'addons-nowhere');
    $check($code === 7 && json_decode($out, true) === ['dry' => true] && !is_dir($root . DIRECTORY_SEPARATOR . 'addons-nowhere'), 'early: before any home is made or locked');
    [$code, $out] = $cli(['addon', 'list'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 0 && (json_decode($out, true)['addons'][0]['name'] ?? null) === 'demo', 'and aamio addon list names it from a process of its own');

    $other = $demo('other.php', 'demo', 'aamio_addon_other_ping');
    $use($one, $other);
    $threw = null;
    try {
        Addons::loaded();
    } catch (\RuntimeException $error) {
        $threw = $error->getMessage();
    }
    $check($threw !== null && str_contains($threw, 'two add-ons are called demo') && str_contains($threw, 'Uninstall one'), 'two add-ons with one name stop the start with both names');
    [$code, , $err] = $cli(['whoami'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 1 && str_contains($err, 'two add-ons are called demo'), 'and every command stops, not only the add-on\'s');

    $taken = $demo('taken.php', 'other', 'aamio_addon_demo_ping');
    $use($one, $taken);
    $threw = null;
    try {
        Addons::loaded();
    } catch (\RuntimeException $error) {
        $threw = $error->getMessage();
    }
    $check($threw !== null && str_contains($threw, 'aamio_addon_demo_ping') && str_contains($threw, 'Uninstall one'), 'a tool name taken already stops the start');
    $shadow = $demo('shadow.php', 'shadow', 'aamio_whoami');
    $use($shadow);
    $threw = null;
    try {
        Addons::loaded();
    } catch (\RuntimeException $error) {
        $threw = $error->getMessage();
    }
    $check($threw !== null && str_contains($threw, 'aamio_whoami') && str_contains($threw, 'the core'), 'nor may an add-on stand in front of the core\'s tools');

    file_put_contents($folder . DIRECTORY_SEPARATOR . 'half.php', "<?php\nreturn 'not an add-on';\n");
    $use($folder . DIRECTORY_SEPARATOR . 'half.php');
    $threw = null;
    try {
        Addons::loaded();
    } catch (\RuntimeException $error) {
        $threw = $error->getMessage();
    }
    $check($threw !== null && str_contains($threw, 'does not return an Aamio\\Addon') && str_contains($threw, 'Reinstall it, or uninstall it'), 'a file that returns no add-on is not a quiet absence');
    $use($folder . DIRECTORY_SEPARATOR . 'gone.php');
    [$code, $out] = $cli(['addon', 'list'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 1 && str_contains((string) (json_decode($out, true)['error'] ?? ''), 'gone.php is not there') && !isset(json_decode($out, true)['note']), 'nor is a file that is not there: the list answers with the error, and does not also say nothing is installed');

    $use();
    $check($runtime->homeFile('mine.json') === $root . DIRECTORY_SEPARATOR . 'addons-home' . DIRECTORY_SEPARATOR . 'mine.json', 'homeFile is a path in this home');
    $runtime->saveJson('mine.json', ['kept' => true]);
    $check(json_decode((string) file_get_contents($runtime->homeFile('mine.json')), true) === ['kept' => true], 'saveJson writes it');
    $one = new Channel('one', str_repeat('a', 26), str_repeat('b', 20), 10 ** 10);
    $two = new Channel('two', str_repeat('c', 26), str_repeat('d', 20), 10 ** 10);
    $runtime->channels['one'] = $one;
    $runtime->channels['two'] = $two;
    $one->observed[1] = ['seq' => 1, 'sha256' => str_repeat('e', 64), 'verified' => true, 'from_key' => 'K1'];
    $one->observed[2] = ['seq' => 2, 'sha256' => str_repeat('e', 64), 'verified' => false, 'from_key' => 'K2'];
    $two->observed[1] = ['seq' => 1, 'sha256' => str_repeat('e', 64), 'verified' => true, 'from_key' => 'K1'];
    $two->observed[2] = ['seq' => 2, 'sha256' => str_repeat('e', 64), 'verified' => true, 'from_key' => 'K3'];
    $two->observed[3] = ['seq' => 3, 'sha256' => str_repeat('f', 64), 'verified' => true, 'from_key' => 'K4'];
    $check($runtime->verifiedSigners(str_repeat('e', 64)) === ['K1', 'K3'] && $runtime->verifiedSigners(str_repeat('f', 64)) === ['K4'] && $runtime->verifiedSigners(str_repeat('0', 64)) === [], 'verifiedSigners: verified only, once each, across every channel');
    $runtime->close();
})();

// Installed is not enabled: an add-on Composer knows is listed from its
// metadata, and nothing of it runs until `aamio addon enable` names it, by
// package, in the home the command was given. The same checks as
// aamio-python's tests/test_addons.py.
(static function () use ($root, $check, $childPhp, $cliRun, $addonClass): void {
    $folder = $root . DIRECTORY_SEPARATOR . 'installed';
    $home = $root . DIRECTORY_SEPARATOR . 'enable-home';
    mkdir($folder, 0700, true);
    // A package as Composer leaves it: a folder with composer.json and the
    // add-on's file, which writes a marker when it runs.
    $pkg = static function (string $dir, string $package, ?string $declared, string $name, string $tool = 'aamio_addon_demo_ping') use ($folder, $addonClass): array {
        $path = $folder . DIRECTORY_SEPARATOR . $dir;
        @mkdir($path, 0700, true);
        $class = 'Installed' . substr(md5($dir . $name . $tool), 0, 8);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'composer.json', json_encode(['name' => $package, 'type' => 'aamio-addon'] + ($declared === null ? [] : ['extra' => ['aamio-addon' => ['name' => $declared]]])));
        file_put_contents($path . DIRECTORY_SEPARATOR . 'aamio-addon.php', "<?php\nfile_put_contents(__DIR__ . '/ran', '1');\n" . $addonClass($class, $name, $tool));

        return ['package' => $package, 'version' => '0.0.1', 'path' => $path];
    };
    $enabledFile = static fn (): mixed => is_file($home . '/addons.json') ? json_decode((string) file_get_contents($home . '/addons.json'), true) : null;
    $refused = static function (callable $try): ?string {
        try {
            $try();
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }

        return null;
    };
    putenv('AAMIO_ADDONS');
    Addons::useHome($home);

    $one = $pkg('demo', 'acme/demo', 'demo', 'demo');
    Addons::useInstalled([$one]);
    $said = Addons::describe();
    $check($said['addons'] === [['name' => 'demo', 'version' => '0.0.1', 'package' => 'acme/demo', 'enabled' => false, 'contract' => 1, 'fix' => 'aamio addon enable demo']] && !isset($said['note']), 'installed is listed from its metadata, not enabled');
    $check(!is_file($one['path'] . '/ran') && Addons::byName('demo') === null && Addons::tools(McpServer::coreTools()) === [], 'and nothing of it ran: the core alone');

    $said = Addons::enable('demo');
    $check($said['addons'][0]['enabled'] === true && $said['addons'][0]['commands'] === ['ping', 'dry'] && $said['addons'][0]['mcp_tools'] === ['aamio_addon_demo_ping'], 'enable lets it run');
    $check(str_contains($said['note'], 'acme/demo runs here from now on') && str_contains($said['note'], 'with its key and home'), 'and says what was agreed to');
    $check($enabledFile() === ['enabled' => ['acme/demo']] && is_file($one['path'] . '/ran') && count(Addons::tools(McpServer::coreTools())) === 1, 'recorded by package, loaded, its tool beside the core\'s');
    Addons::enable('ACME/demo');
    $check($enabledFile() === ['enabled' => ['acme/demo']], 'by package too, and once is once');
    $said = Addons::disable('demo');
    $check($said['addons'][0]['enabled'] === false && $enabledFile() === ['enabled' => []] && Addons::tools(McpServer::coreTools()) === [], 'disable takes it out');
    $check(str_contains(Addons::disable('demo')['note'] ?? '', 'demo was not enabled'), 'disabling what is not enabled says so');

    Addons::enable('demo');
    Addons::useHome($root . DIRECTORY_SEPARATOR . 'enable-home-b');
    $check(Addons::describe()['addons'][0]['enabled'] === false && !is_dir($root . DIRECTORY_SEPARATOR . 'enable-home-b'), 'enabled in one home, not in another, and listing made no home');
    Addons::useHome($home);
    Addons::disable('demo');

    $two = $pkg('other', 'acme/other', 'demo', 'demo', 'aamio_addon_other_ping');
    Addons::useInstalled([$one, $two]);
    $threw = $refused(static fn () => Addons::enable('demo'));
    $check($threw !== null && str_contains($threw, 'two installed add-ons are called demo: acme/demo and acme/other') && str_contains($threw, 'by its package name'), 'two with one name: the choice is by package');
    $check(array_column(Addons::describe()['addons'], 'fix') === ['aamio addon enable acme/demo', 'aamio addon enable acme/other'], 'each by its package, since the name says nothing');
    $said = Addons::enable('acme/demo');
    $check(array_map(static fn (array $row): array => [$row['package'], $row['enabled']], $said['addons']) === [['acme/demo', true], ['acme/other', false]] && count(Addons::tools(McpServer::coreTools())) === 1, 'the one chosen runs; the other, with the same name, does not inherit the choice');
    $threw = $refused(static fn () => Addons::enable('nothing'));
    $check($threw !== null && str_contains($threw, 'no installed add-on is called nothing') && str_contains($threw, 'aamio addon list'), 'enabling what is not installed is refused');
    Addons::disable('acme/demo');

    $broken = $pkg('broken', 'acme/broken', 'broken', 'broken');
    file_put_contents($broken['path'] . '/aamio-addon.php', "<?php\nthrow new \\RuntimeException('no such library');\n");
    $wrong = $pkg('wrong', 'acme/wrong', 'right', 'demo');
    $listed = $pkg('listed', 'acme/listed', 'list', 'list', 'aamio_addon_list_ping');
    Addons::useInstalled([$one, $broken, $wrong, $listed]);
    $threw = $refused(static fn () => Addons::enable('broken'));
    $check($threw !== null && str_contains($threw, 'the add-on acme/broken could not be loaded: RuntimeException: no such library') && str_contains($threw, 'aamio addon disable acme/broken'), 'a broken add-on is refused');
    $threw = $refused(static fn () => Addons::enable('right'));
    $check($threw !== null && str_contains($threw, 'the add-on acme/wrong is called right in its composer.json and demo in its code'), 'so is one whose metadata and code disagree on the name');
    $threw = $refused(static fn () => Addons::enable('acme/listed'));
    $check($threw !== null && str_contains($threw, 'cannot be called list: that is a command of aamio addon itself'), 'and one with a name aamio addon uses itself');
    $check($enabledFile() === ['enabled' => []] && Addons::tools(McpServer::coreTools()) === [], 'none was recorded');

    Addons::useInstalled([$one]);
    Addons::enable('demo');
    file_put_contents($one['path'] . '/aamio-addon.php', "<?php\nthrow new \\RuntimeException('no such library');\n");
    Addons::reset();
    $threw = $refused(static fn () => Addons::loaded());
    $check($threw !== null && str_contains($threw, 'acme/demo could not be loaded') && str_contains($threw, 'aamio addon disable acme/demo'), 'a broken enabled add-on stops the start and says how to go on');
    $said = Addons::describe();
    $check($said['addons'][0]['enabled'] === true && $said['addons'][0]['loaded'] === false && str_contains($said['error'], 'acme/demo could not be loaded') && !isset($said['note']), 'the list still answers, from the metadata');
    $said = Addons::disable('demo');
    $check($said['addons'][0]['enabled'] === false && !isset($said['error']) && Addons::tools(McpServer::coreTools()) === [], 'and disable takes it out without loading it');

    file_put_contents($home . '/addons.json', '{"enabled": ["acme/gone"]}');
    Addons::reset();
    $said = Addons::describe();
    $check(($said['enabled_but_not_installed'] ?? null) === ['acme/gone'] && !isset($said['error']), 'enabled once, uninstalled since: said, and not a failure');
    Addons::disable('acme/gone');
    $check($enabledFile() === ['enabled' => []], 'and taken out');
    file_put_contents($home . '/addons.json', '{not json');
    Addons::reset();
    $threw = $refused(static fn () => Addons::loaded());
    $check($threw !== null && str_contains($threw, 'addons.json could not be read as JSON') && str_contains($threw, 'Fix or remove the file'), 'an unreadable file is said');
    file_put_contents($home . '/addons.json', '{"enabled": "acme/demo"}');
    Addons::reset();
    $threw = $refused(static fn () => Addons::loaded());
    $check($threw !== null && str_contains($threw, 'is not a list of enabled add-ons'), 'so is a file of the wrong shape');
    unlink($home . '/addons.json');

    $fresh = $pkg('fresh', 'acme/fresh', 'demo', 'demo');
    Addons::useInstalled([$fresh]);
    putenv('AAMIO_ADDONS=' . $fresh['path'] . DIRECTORY_SEPARATOR . 'aamio-addon.php');
    Addons::reset();
    $said = Addons::describe();
    $check(count($said['addons']) === 1 && $said['addons'][0]['enabled'] === true && ($said['addons'][0]['named_in'] ?? null) === 'AAMIO_ADDONS' && $said['addons'][0]['package'] === 'acme/fresh' && $said['addons'][0]['commands'] === ['ping', 'dry'], 'a checkout that is also installed: running because it was named, said once');
    putenv('AAMIO_ADDONS');

    // Composer's own answer, through the three calls the registry makes. Where
    // Composer itself is not in a process, a stand-in answers from a list: set
    // here, or read from AAMIO_TEST_INSTALLED by a child that prepends the file.
    $standIn = $folder . DIRECTORY_SEPARATOR . 'InstalledVersions.php';
    file_put_contents($standIn, '<?php
namespace Composer;
if (!class_exists(InstalledVersions::class, false)) {
    final class InstalledVersions {
        public static array $packages = [];
        public static function getInstalledPackagesByType(string $type): array { return array_keys(array_filter(self::$packages, static fn (array $p): bool => $p["type"] === $type)); }
        public static function getInstallPath(string $name): ?string { return self::$packages[$name]["path"] ?? null; }
        public static function getPrettyVersion(string $name): ?string { return self::$packages[$name]["version"] ?? null; }
    }
    (static function (): void {
        $json = getenv("AAMIO_TEST_INSTALLED");
        if (is_string($json) && $json !== "") {
            InstalledVersions::$packages = json_decode($json, true) ?: [];
        }
    })();
}
');
    if (!class_exists(\Composer\InstalledVersions::class)) {
        require $standIn;
        \Composer\InstalledVersions::$packages = ['acme/fresh' => ['type' => 'aamio-addon', 'path' => $fresh['path'], 'version' => 'v0.0.1'], 'acme/lib' => ['type' => 'library', 'path' => $folder, 'version' => '1.0.0']];
        Addons::useInstalled(null);
        Addons::reset();
        $rows = Addons::installed();
        $check(count($rows) === 1 && $rows[0]['package'] === 'acme/fresh' && $rows[0]['name'] === 'demo' && $rows[0]['version'] === '0.0.1' && $rows[0]['file'] === $fresh['path'] . DIRECTORY_SEPARATOR . 'aamio-addon.php', 'Composer\'s answer is read through getInstalledPackagesByType, getInstallPath and getPrettyVersion, and the tag\'s v comes off the version');
        \Composer\InstalledVersions::$packages = [];
    }

    // The command line, from a process of its own: it reads and writes the home it was given.
    $cli = static fn (array $words): array => $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $home, '--no-archive'], $words));
    [$code, , $err] = $cli(['addon', 'enable', 'nothing']);
    $check($code === 2 && str_contains($err, 'no installed add-on is called nothing'), 'aamio addon enable refuses what is not installed');
    [$code, , $err] = $cli(['addon', 'enable']);
    $check($code === 2 && str_contains($err, 'aamio addon enable NAME'), 'and says how it is used');
    [$code, $out] = $cli(['addon', 'disable', 'acme/gone']);
    $check($code === 0 && str_contains(json_decode($out, true)['note'] ?? '', 'was not enabled'), 'aamio addon disable answers before anything is loaded');
    file_put_contents($home . '/addons.json', '{"enabled": ["acme/gone"]}');
    [$code, $out] = $cli(['addon', 'list']);
    $check($code === 0 && (json_decode($out, true)['enabled_but_not_installed'] ?? null) === ['acme/gone'], 'the command line reads the home it was given');
    [$code, $out] = $cli(['addon', 'disable', 'acme/gone']);
    $check($code === 0 && $enabledFile() === ['enabled' => []], 'and writes it');
    file_put_contents($home . '/addons.json', '{not json');
    [$code, , $err] = $cli(['whoami']);
    $check($code === 1 && str_contains($err, 'addons.json could not be read as JSON'), 'and an unreadable file stops every command with the reason');
    unlink($home . '/addons.json');

    // The review of the enable step: a server on another home, help, two
    // changes at once, what does not build, and what the environment holds.
    $quiet = static function (string $line): void {
    };
    $pa = $pkg('cli-demo', 'acme/cli-demo', 'demo', 'demo');
    $pb = $pkg('cli-other', 'acme/cli-other', 'other', 'other', 'aamio_addon_other_ping');
    Addons::useInstalled([$pa, $pb]);
    Addons::useHome($home);
    Addons::reset();

    Addons::enable('demo');
    $homeB = $root . DIRECTORY_SEPARATOR . 'enable-home-b2';
    $rtA = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $rtB = new Runtime($homeB, 'https://fake.test', null, false, $quiet);
    $serverA = new McpServer($rtA);
    $serverB = new McpServer($rtB);
    $listing = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
    $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_demo_ping', 'arguments' => []]];
    $ok = true;
    for ($i = 0; $i < 2; $i++) {
        $ok = $ok && count($serverB->handle($listing)['result']['tools']) === 24 && ($serverA->handle($listing)['result']['tools'][24]['name'] ?? null) === 'aamio_addon_demo_ping';
    }
    $check($ok, 'a server on home b, where nothing is enabled, offers the core alone, whatever home the command line had; the one on a offers the add-on, in alternation');
    $check(($serverB->handle($call)['error']['code'] ?? null) === -32602 && ($serverA->handle($call)['result']['structuredContent']['pong'] ?? null) === true, 'and a call through b is refused as a tool nobody has, while a answers');
    $rtA->close();
    $rtB->close();

    $child = array_merge($childPhp, ['-d', 'auto_prepend_file=' . $standIn]);
    putenv('AAMIO_TEST_INSTALLED=' . json_encode(['acme/cli-demo' => ['type' => 'aamio-addon', 'path' => $pa['path'], 'version' => 'v0.0.1'], 'acme/cli-other' => ['type' => 'aamio-addon', 'path' => $pb['path'], 'version' => 'v0.0.1']]));
    $cliI = static fn (array $words): array => $cliRun(array_merge($child, [dirname(__DIR__) . '/bin/aamio', '--home', $home, '--no-archive'], $words));
    $before = (string) file_get_contents($home . '/addons.json');
    [$code, $out] = $cliI(['addon', 'disable', 'demo', '--help']);
    $check($code === 0 && str_contains($out, 'aamio addon disable NAME') && file_get_contents($home . '/addons.json') === $before, 'help is help, not a disable, and the bytes are the same');
    [$code, , $err] = $cliI(['addon', 'disable', 'demo', 'extra']);
    $check($code === 2 && str_contains($err, 'aamio addon disable NAME') && file_get_contents($home . '/addons.json') === $before, 'a word too many refuses the change');
    [$code, , $err] = $cliI(['addon', 'disable', 'demo', '--bogus']);
    $check($code === 2 && str_contains($err, 'unknown option --bogus') && file_get_contents($home . '/addons.json') === $before, 'so does an option that does not belong');
    [$code, $out] = $cliI(['addon', 'enable', 'other', '--help']);
    $check($code === 0 && str_contains($out, 'aamio addon enable NAME') && file_get_contents($home . '/addons.json') === $before, 'the same for enable');
    [$code, , $err] = $cliI(['addon', 'enable', 'other', '--bogus']);
    $check($code === 2 && file_get_contents($home . '/addons.json') === $before, 'and for its options');
    [$code, $out] = $cliI(['addon', 'list']);
    $check($code === 0 && (json_decode($out, true)['addons'][0]['enabled'] ?? null) === true, 'the child sees what Composer, standing in, says is installed');

    $concurrently = static function (array ...$commands) use ($child, $home): array {
        $running = [];
        foreach ($commands as $words) {
            $pipes = [];
            $process = proc_open(array_merge($child, [dirname(__DIR__) . '/bin/aamio', '--home', $home, '--no-archive'], $words), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $running[] = [$process, $pipes];
        }
        $results = [];
        foreach ($running as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $results[] = [proc_close($process), $err];
        }

        return $results;
    };
    $ok = true;
    $why = '';
    for ($round = 0; $round < 3 && $ok; $round++) {
        Addons::disable('demo');
        Addons::disable('other');
        $results = $concurrently(['addon', 'enable', 'demo'], ['addon', 'enable', 'other']);
        Addons::reset();
        $now = Addons::enabled();
        sort($now);
        $ok = $results[0][0] === 0 && $results[1][0] === 0 && $now === ['acme/cli-demo', 'acme/cli-other'];
        $why = json_encode([$results, $now]);
    }
    $check($ok, 'two changes at once both stand: each took its turn', $why);

    $held = fopen($home . '/addons.json.lock', 'c+b');
    flock($held, LOCK_EX);
    Addons::$lockWait = 0.3;
    $threw = $refused(static fn () => Addons::enable('demo'));
    Addons::$lockWait = 10.0;
    flock($held, LOCK_UN);
    fclose($held);
    $check($threw !== null && str_contains($threw, 'addons.json is being changed by another aamio') && str_contains($threw, 'Nothing changed'), 'a change that waits too long for another says so and changes nothing');

    $throws = $pkg('throws', 'acme/throws', 'throws', 'throws', 'aamio_addon_throws_ping');
    file_put_contents($throws['path'] . DIRECTORY_SEPARATOR . 'aamio-addon.php', '<?php
if (!class_exists(ThrowsInCommands::class, false)) {
final class ThrowsInCommands implements \Aamio\Addon {
    public function name(): string { return "throws"; }
    public function version(): string { return "0.0.1"; }
    public function commands(): array { throw new \LogicException("no commands today"); }
    public function early(array $args, array $opts): ?int { return null; }
    public function command(array $args, array $opts, \Aamio\Runtime $runtime): int { return 0; }
    public function tools(): array { return []; }
    public function dispatch(\Aamio\Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array { return null; }
}
}
return new ThrowsInCommands();
');
    $nameless = $pkg('nameless', 'acme/nameless', 'nameless', 'nameless', '');
    Addons::useInstalled([$pa, $pb, $throws, $nameless]);
    Addons::disable('demo');
    Addons::disable('other');
    $before = $enabledFile();
    $threw = $refused(static fn () => Addons::enable('throws'));
    $check($threw !== null && str_contains($threw, 'could not build its commands or tools: LogicException: no commands today') && str_contains($threw, 'aamio addon disable acme/throws'), 'an add-on whose commands do not build is refused');
    $threw = $refused(static fn () => Addons::enable('nameless'));
    $check($threw !== null && str_contains($threw, 'a tool of its has no name'), 'so is one with a tool without a name');
    $check($enabledFile() === $before && Addons::tools(McpServer::coreTools()) === [], 'and nothing was recorded');

    putenv('AAMIO_ADDONS=' . $pa['path'] . DIRECTORY_SEPARATOR . 'aamio-addon.php');
    Addons::reset();
    $said = Addons::disable('demo');
    $check(str_contains($said['note'], 'demo was not enabled') && str_contains($said['note'], 'demo still runs: AAMIO_ADDONS names its file') && str_contains($said['note'], 'Unset AAMIO_ADDONS'), 'disabling what AAMIO_ADDONS holds says the variable still holds it');
    $check($said['addons'][0]['enabled'] === true && ($said['addons'][0]['named_in'] ?? null) === 'AAMIO_ADDONS', 'and it does run');
    putenv('AAMIO_ADDONS');
    Addons::reset();
    $said = Addons::enable('demo');
    $check(str_contains($said['note'], 'start it again'), 'a running server keeps its tools: said on enable');
    $said = Addons::disable('demo');
    $check(str_contains($said['note'], 'demo is disabled') && str_contains($said['note'], 'start it again'), 'and on disable');

    // `--home` without a folder is not the default home: refused before anything is written.
    Addons::enable('demo');
    $before = (string) file_get_contents($home . '/addons.json');
    $defaultHome = $root . DIRECTORY_SEPARATOR . 'default-home';
    putenv('AAMIO_HOME=' . $defaultHome);
    [$code, , $err] = $cliRun(array_merge($child, [dirname(__DIR__) . '/bin/aamio', '--no-archive', 'addon', 'disable', 'demo', '--home']));
    $check($code === 2 && str_contains($err, '--home needs a value') && file_get_contents($home . '/addons.json') === $before && !is_dir($defaultHome), '--home without a folder refuses the change, and the default home is not touched');
    [$code, , $err] = $cliRun(array_merge($child, [dirname(__DIR__) . '/bin/aamio', '--host', '--no-archive', 'addon', 'list']));
    $check($code === 2 && str_contains($err, '--host needs a value'), 'the same for --host, before any command');
    putenv('AAMIO_HOME');
    Addons::disable('demo');
    putenv('AAMIO_TEST_INSTALLED');

    Addons::useInstalled(null);
    Addons::useHome(null);
    Addons::reset();
})();

// ------------------------------------------------------------- contract 2 --
//
// An add-on in a process of its own: the manifest says what it offers, the
// core reads it without running anything, and each call is a child process
// with one request and one answer. The same checks as aamio-python's
// tests/test_addons.py, contract 2.
(static function () use ($root, $check, $childPhp, $cliRun): void {
    $folder = $root . DIRECTORY_SEPARATOR . 'contract2';
    $home = $folder . DIRECTORY_SEPARATOR . 'home';
    mkdir($folder, 0700, true);
    $quiet = static function (string $line): void {
    };
    $manifestFor = static function (string $name, int $contract = 2, string $version = '0.0.2', int $deadline = 2, ?array $environment = null): array {
        return ($environment === null ? [] : ['environment' => $environment]) + [
            'aamio_addon' => $contract,
            'name' => $name,
            'version' => $version,
            'process' => ['php' => 'process.php'],
            'deadline_seconds' => $deadline,
            'hosts' => ['legacy_file' => 'old-hosts.json'],
            'commands' => [
                'ping' => ['help' => 'answers pong', 'arguments' => [['name' => '--word', 'help' => 'a word to carry']]],
                'check' => ['help' => 'a website and a partner', 'arguments' => [['name' => 'site', 'help' => 'the website', 'host' => true], ['name' => '--partner', 'help' => 'a name in the address book', 'required' => true, 'subject' => 'partner']]],
                'file' => ['help' => "the runtime's own key", 'needs' => ['own_key']],
                'local' => ['help' => 'no home needed', 'runtime' => false, 'arguments' => [['name' => 'path']]],
                'sleep' => ['help' => 'never answers'],
                'crash' => ['help' => 'dies'],
                'garbage' => ['help' => 'talks nonsense'],
                'bytes' => ['help' => 'raw bytes'],
                'fail' => ['help' => 'a refusal'],
                'flood' => ['help' => 'writes without end'],
                'noisy' => ['help' => 'complains without end'],
                'spill' => ['help' => 'a big answer, at once'],
                'grumble' => ['help' => 'too much on standard error, at once'],
                'mutter' => ['help' => 'exactly the limit on standard error'],
            ],
            'tools' => [
                ['name' => 'aamio_addon_' . $name . '_ping', 'description' => 'answers pong', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                ['name' => 'aamio_addon_' . $name . '_check', 'description' => 'a website and a subject', 'inputSchema' => ['type' => 'object', 'properties' => ['site' => ['type' => 'string'], 'partner' => ['type' => 'string'], 'message' => ['type' => 'string']], 'required' => ['site'], 'additionalProperties' => false], 'host' => 'site', 'subject' => ['partner' => 'partner', 'message' => 'message']],
                ['name' => 'aamio_addon_' . $name . '_sleep', 'description' => 'never answers', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                ['name' => 'aamio_addon_' . $name . '_flood', 'description' => 'writes without end', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                ['name' => 'aamio_addon_' . $name . '_noisy', 'description' => 'complains without end', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                ['name' => 'aamio_addon_' . $name . '_spill', 'description' => 'a big answer, at once', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                ['name' => 'aamio_addon_' . $name . '_grumble', 'description' => 'too much on standard error, at once', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                ['name' => 'aamio_addon_' . $name . '_mutter', 'description' => 'exactly the limit on standard error', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
            ],
        ];
    };
    /** A package as Composer leaves it: composer.json, the manifest, and a process that answers the protocol and leaves a marker of what it was asked. */
    $pkg = static function (string $dir, string $package, string $name, ?array $manifest = null, string $version = '0.0.2') use ($folder, $manifestFor): array {
        $path = $folder . DIRECTORY_SEPARATOR . $dir;
        @mkdir($path, 0700, true);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'composer.json', json_encode(['name' => $package, 'type' => 'aamio-addon', 'extra' => ['aamio-addon' => ['name' => $name, 'contract' => 2]]]));
        file_put_contents($path . DIRECTORY_SEPARATOR . 'aamio-addon.json', json_encode($manifest ?? $manifestFor($name, 2, $version)));
        file_put_contents($path . DIRECTORY_SEPARATOR . 'process.php', '<?php
$request = json_decode((string) stream_get_contents(STDIN), true);
file_put_contents(__DIR__ . "/process.ran", $request["kind"] . " " . $request["name"] . "\n", FILE_APPEND);
if ($request["kind"] === "probe") {
    if (getenv("AAMIO_TEST_PASSTHRU") !== false) {
        echo json_encode(["result" => ["ok" => false, "reason" => "addon_failed", "fix" => "the probe was given AAMIO_TEST_PASSTHRU, which only a call may get"]]);
        exit(0);
    }
    echo json_encode(["result" => ["ok" => true, "name" => ' . var_export($name, true) . ', "version" => ' . var_export($version, true) . ', "aamio_addon" => 2]]);
    exit(0);
}
$parts = explode("_", $request["name"]);
$short = end($parts);
if ($short === "sleep") { sleep(30); }
if ($short === "crash") { exit(3); }
if ($short === "garbage") { echo "not json"; exit(0); }
if ($short === "bytes") { echo json_encode(["raw" => base64_encode("raw\n")]); exit(0); }
if ($short === "fail") { echo json_encode(["result" => ["status" => "no", "fix" => "x"], "exit_code" => 3, "is_error" => true, "stderr" => "a note from the add-on"]); exit(3); }
if ($short === "flood") { echo str_repeat("x", 5000); flush(); sleep(5); exit(0); }
if ($short === "noisy") { fwrite(STDERR, str_repeat("y", 5000)); sleep(5); exit(0); }
if ($short === "spill") { echo str_repeat("x", 5000); exit(0); }
if ($short === "grumble") { fwrite(STDERR, str_repeat("y", 5000)); echo json_encode(["result" => ["pong" => true]]); exit(0); }
if ($short === "mutter") { fwrite(STDERR, str_repeat("y", 500)); echo json_encode(["result" => ["pong" => true]]); exit(0); }
$env = static fn (string $key) => getenv($key) === false ? null : getenv($key);
echo json_encode(["result" => ["pong" => true, "name" => $request["name"], "arguments" => $request["arguments"], "subject" => $request["subject"], "own_key" => $request["own_key"], "state_dir" => $request["state_dir"], "home_env" => $env("AAMIO_HOME"), "addon_env" => $env("AAMIO_ADDON"), "secret_env" => $env("AAMIO_TEST_SECRET"), "passthru_env" => $env("AAMIO_TEST_PASSTHRU"), "path_env" => getenv("PATH") !== false]]);
');

        return ['package' => $package, 'version' => $version, 'path' => $path];
    };
    $ran = static fn (string $dir): string => is_file($folder . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'process.ran') ? (string) file_get_contents($folder . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'process.ran') : '';
    $enabledFile = static fn (): mixed => is_file($home . '/addons.json') ? json_decode((string) file_get_contents($home . '/addons.json'), true) : null;
    $refused = static function (callable $try): ?string {
        try {
            $try();
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }

        return null;
    };
    putenv('AAMIO_ADDONS');
    putenv('AAMIO_TEST_SECRET=not for the add-on');
    Addons::useHome($home);
    Addons::reset();
    AddonProcess::resetLimits();

    $two = $pkg('demo2', 'acme/demo2', 'demo2');
    Addons::useInstalled([$two]);
    $said = Addons::describe();
    $check($said['addons'] === [['name' => 'demo2', 'version' => '0.0.2', 'package' => 'acme/demo2', 'enabled' => false, 'contract' => 2, 'fix' => 'aamio addon enable demo2']] && $ran('demo2') === '', 'a contract-2 add-on is listed from its manifest, and nothing of it ran');
    $check(Addons::tools(McpServer::coreTools()) === [], 'and the local MCP has the core alone');

    $said = Addons::enable('demo2');
    $row = $said['addons'][0];
    $commands = $row['commands'];
    sort($commands);
    $check($ran('demo2') === "probe probe\n", 'enable asked the process who it is, and nothing else of it ran');
    $check($row['enabled'] === true && $row['contract'] === 2 && $commands === ['allow', 'bytes', 'check', 'crash', 'fail', 'file', 'flood', 'forget', 'garbage', 'grumble', 'list', 'local', 'mutter', 'noisy', 'ping', 'sleep', 'spill'] && $row['mcp_tools'] === ['aamio_addon_demo2_ping', 'aamio_addon_demo2_check', 'aamio_addon_demo2_sleep', 'aamio_addon_demo2_flood', 'aamio_addon_demo2_noisy', 'aamio_addon_demo2_spill', 'aamio_addon_demo2_grumble', 'aamio_addon_demo2_mutter'], "the manifest's commands and the three the core gives an add-on with hosts, and its tools");
    $check(str_contains($said['note'], 'a process of its own') && str_contains($said['note'], "never the home's key") && $enabledFile() === ['enabled' => ['acme/demo2']], 'the note says what it means, and the choice is recorded by package');
    $again = Addons::enable('demo2');
    $check(str_contains($again['note'], 'nothing of it ran now') && !str_contains($again['note'], 'just now') && $ran('demo2') === "probe probe\n", 'enabling it again runs no probe, and says so');

    // The command line, from a process of its own, with Composer standing in.
    $standIn = $folder . DIRECTORY_SEPARATOR . 'InstalledVersions.php';
    file_put_contents($standIn, '<?php
namespace Composer;
if (!class_exists(InstalledVersions::class, false)) {
    final class InstalledVersions {
        public static array $packages = [];
        public static function getInstalledPackagesByType(string $type): array { return array_keys(array_filter(self::$packages, static fn (array $p): bool => $p["type"] === $type)); }
        public static function getInstallPath(string $name): ?string { return self::$packages[$name]["path"] ?? null; }
        public static function getPrettyVersion(string $name): ?string { return self::$packages[$name]["version"] ?? null; }
    }
    (static function (): void {
        $json = getenv("AAMIO_TEST_INSTALLED");
        if (is_string($json) && $json !== "") {
            InstalledVersions::$packages = json_decode($json, true) ?: [];
        }
    })();
}
');
    $child = array_merge($childPhp, ['-d', 'auto_prepend_file=' . $standIn]);
    putenv('AAMIO_TEST_INSTALLED=' . json_encode(['acme/demo2' => ['type' => 'aamio-addon', 'path' => $two['path'], 'version' => '0.0.2']]));
    $cli = static fn (array $words, ?string $where = null): array => $cliRun(array_merge($child, [dirname(__DIR__) . '/bin/aamio', '--home', $where ?? $home, '--host', 'https://fake.test', '--no-archive'], $words));

    [$code, $out] = $cli(['addon', 'demo2', '--help']);
    $check($code === 0 && str_contains($out, 'ping') && str_contains($out, 'allow') && $ran('demo2') === "probe probe\n", 'help comes from the manifest');
    [$code, $out] = $cli(['addon', 'demo2', 'ping', '--word', 'hi']);
    $got = json_decode($out, true) ?? [];
    $check($code === 0 && ($got['pong'] ?? null) === true && ($got['arguments'] ?? null) === ['word' => 'hi'] && array_key_exists('subject', $got) && $got['subject'] === null && array_key_exists('own_key', $got) && $got['own_key'] === null, 'a command goes through the process with its arguments');
    $check(($got['state_dir'] ?? null) === $home . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . 'demo2' && is_dir((string) ($got['state_dir'] ?? '')), 'a directory of its own under the home');
    $check(array_key_exists('home_env', $got) && $got['home_env'] === null && ($got['addon_env'] ?? null) === '2' && array_key_exists('secret_env', $got) && $got['secret_env'] === null && ($got['path_env'] ?? null) === true, "the process sees no AAMIO_HOME and no token from the user's environment; the path it gets");
    $check($ran('demo2') === "probe probe\ncommand ping\n", 'and the process was asked exactly that');
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $own = $runtime->keys->public;
    $runtime->close();
    [$code, $out] = $cli(['addon', 'demo2', 'file']);
    $check($code === 0 && (json_decode($out, true)['own_key'] ?? null) === $own, 'needs own_key gives the public key, and nothing else of the key');
    [$code, $out, $err] = $cli(['addon', 'demo2', 'fail']);
    $check($code === 3 && json_decode($out, true) === ['status' => 'no', 'fix' => 'x'] && str_contains($err, 'a note from the add-on'), "the add-on's result, code and note come back as they are");
    [$code, $out] = $cli(['addon', 'demo2', 'bytes']);
    $check($code === 0 && $out === "raw\n", 'raw bytes reach standard output as they are');
    [$code, $out] = $cli(['addon', 'demo2', 'local', 'somewhere']);
    $got = json_decode($out, true) ?? [];
    $check($code === 0 && array_key_exists('state_dir', $got) && $got['state_dir'] === null && ($got['arguments'] ?? null) === ['path' => 'somewhere'], 'a command that needs no home runs in the process too, with no directory');

    $started = microtime(true);
    [$code, $out] = $cli(['addon', 'demo2', 'sleep']);
    $said = json_decode($out, true) ?? [];
    $check($code === 5 && ($said['status'] ?? null) === 'unreachable' && ($said['reason'] ?? null) === 'addon_timeout' && str_contains($said['fix'] ?? '', '2 seconds') && ($said['addon'] ?? null) === 'demo2' && microtime(true) - $started < 15, 'a process that never answers is stopped at the deadline');
    [$code, $out] = $cli(['addon', 'demo2', 'crash']);
    $said = json_decode($out, true) ?? [];
    $check($code === 5 && ($said['reason'] ?? null) === 'addon_failed' && str_contains($said['fix'] ?? '', 'code 3'), 'one that crashes is an unreachable answer with the code');
    [$code, $out] = $cli(['addon', 'demo2', 'garbage']);
    $check($code === 5 && (json_decode($out, true)['reason'] ?? null) === 'addon_failed', 'and so is one that answers something else');
    // The acceptance from the review of 8 November: a tool that hangs is stopped
    // at its deadline, and the same server answers the next request.
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $server = new McpServer($runtime);
    $reply = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_demo2_sleep', 'arguments' => []]]);
    $check(($reply['result']['isError'] ?? null) === true && ($reply['result']['structuredContent']['reason'] ?? null) === 'addon_timeout', 'over MCP a tool that hangs is stopped at the deadline');
    $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
    $pong = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_demo2_ping', 'arguments' => []]]);
    $check(count($listed['result']['tools']) === 32 && ($pong['result']['isError'] ?? null) === false && ($pong['result']['structuredContent']['pong'] ?? null) === true, 'and the same server answers the next request');
    $runtime->close();

    // The limits hold while the process runs: a stream past either stops it, and the next call goes through.
    AddonProcess::$maxAnswerBytes = 1000;
    AddonProcess::$maxStderrBytes = 500;
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $started = microtime(true);
    $flood = Addons::dispatch($runtime, 'aamio_addon_demo2_flood', [], null)['structuredContent'] ?? [];
    $check(($flood['reason'] ?? null) === 'addon_failed' && str_contains($flood['fix'] ?? '', 'bytes an answer may be') && microtime(true) - $started < 4, 'a process that writes past the answer limit is stopped at the size, not at the deadline');
    $started = microtime(true);
    $noisy = Addons::dispatch($runtime, 'aamio_addon_demo2_noisy', [], null)['structuredContent'] ?? [];
    $check(($noisy['reason'] ?? null) === 'addon_failed' && str_contains($noisy['fix'] ?? '', 'standard error') && microtime(true) - $started < 4, 'and so is one that writes past the limit on standard error');
    // The same limits when the process has exited before the next look.
    $spill = Addons::dispatch($runtime, 'aamio_addon_demo2_spill', [], null)['structuredContent'] ?? [];
    $check(($spill['reason'] ?? null) === 'addon_failed' && str_contains($spill['fix'] ?? '', 'bytes an answer may be'), 'an answer past the limit from a process that exited at once is refused the same');
    $grumble = Addons::dispatch($runtime, 'aamio_addon_demo2_grumble', [], null)['structuredContent'] ?? [];
    $check(($grumble['reason'] ?? null) === 'addon_failed' && str_contains($grumble['fix'] ?? '', 'standard error'), 'and so is a valid answer beside too much standard error');
    $mutter = Addons::dispatch($runtime, 'aamio_addon_demo2_mutter', [], null)['structuredContent'] ?? [];
    $check(($mutter['pong'] ?? null) === true, 'exactly the limit on standard error passes');
    $pongAgain = Addons::dispatch($runtime, 'aamio_addon_demo2_ping', [], null)['structuredContent'] ?? [];
    $check(($pongAgain['pong'] ?? null) === true, 'and the next call goes through');
    $runtime->close();
    AddonProcess::$maxAnswerBytes = AddonProcess::MAX_ANSWER_BYTES;
    AddonProcess::$maxStderrBytes = AddonProcess::MAX_STDERR_BYTES;

    // The environment: only what the manifest declares, on top of the short list.
    $passthru = $pkg('demo-env', 'acme/demo-env', 'demoenv', $manifestFor('demoenv', 2, '0.0.2', 2, ['AAMIO_TEST_PASSTHRU']));
    $bad = $pkg('demo-bad', 'acme/demo-bad', 'demobad', $manifestFor('demobad', 2, '0.0.2', 2, ['AAMIO_HOME']));
    Addons::useInstalled([$two, $passthru, $bad]);
    Addons::reset();
    putenv('AAMIO_TEST_PASSTHRU=yes please');
    $passive = null;
    foreach (Addons::describe()['addons'] as $candidate) {
        if ($candidate['name'] === 'demoenv') {
            $passive = $candidate;
        }
    }
    $check(($passive['enabled'] ?? null) === false && ($passive['environment'] ?? null) === ['AAMIO_TEST_PASSTHRU'] && $ran('demo-env') === '', 'the list says which variables an add-on asks for before it is enabled, running nothing');
    // The fake process refuses the probe when it is given the variable: the probe gets the short list alone.
    $said = ['addons' => []];
    $threw = $refused(static function () use (&$said): void {
        $said = Addons::enable('demoenv');
    });
    $check($threw === null && $ran('demo-env') === "probe probe\n" && str_contains($said['note'] ?? '', 'AAMIO_TEST_PASSTHRU, which its manifest asks for'), 'the probe ran without it, and the note names it' . ($threw === null ? '' : ': ' . $threw));
    $rowEnv = null;
    foreach ($said['addons'] as $candidate) {
        if ($candidate['name'] === 'demoenv') {
            $rowEnv = $candidate;
        }
    }
    $check(($rowEnv['environment'] ?? null) === ['AAMIO_TEST_PASSTHRU'], 'the list says which variables the add-on asked for');
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $got = Addons::dispatch($runtime, 'aamio_addon_demoenv_ping', [], null)['structuredContent'] ?? [];
    $runtime->close();
    $check(($got['passthru_env'] ?? null) === 'yes please' && array_key_exists('secret_env', $got) && $got['secret_env'] === null, 'what the manifest declares comes through; the rest of the user\'s environment does not');
    putenv('AAMIO_TEST_PASSTHRU');
    $threw = $refused(static fn () => Addons::enable('demobad'));
    $check($threw !== null && str_contains($threw, 'AAMIO_HOME') && str_contains($threw, 'no add-on gets'), 'a manifest may not ask for the home');
    Addons::disable('demoenv');
    Addons::useInstalled([$two]);
    Addons::reset();

    // Hosts: the core's consent, kept per add-on and beside its directory.
    [$code, $out] = $cli(['addon', 'demo2', 'check', 'Example.ORG', '--partner', 'bob']);
    $said = json_decode($out, true) ?? [];
    $check($code === 2 && ($said['reason'] ?? null) === 'host_not_allowed' && str_contains($said['fix'] ?? '', 'aamio addon demo2 allow example.org') && str_contains($said['fix'] ?? '', 'aamio_addon_demo2_check asks them') && !str_contains($ran('demo2'), 'check'), 'a website not on the list is refused before the process starts');
    $key = str_repeat('A', 43);
    $check($cli(['partner', 'add', 'bob', $key])[0] === 0, 'a partner for the checks');
    [$code, $out] = $cli(['addon', 'demo2', 'allow', 'Example.ORG']);
    $said = json_decode($out, true) ?? [];
    $check($code === 0 && ($said['host'] ?? null) === 'example.org' && ($said['added'] ?? null) === true && ($said['hosts'] ?? null) === ['example.org'] && str_contains($said['note'] ?? '', 'Nothing was read now'), 'allow puts a website on the list, in its exact form');
    $check(json_decode((string) file_get_contents($home . '/addons/demo2.hosts.json'), true) === ['example.org'], "kept beside the add-on's directory, not in it");
    [$code, $out] = $cli(['addon', 'demo2', 'check', 'EXAMPLE.org', '--partner', 'bob']);
    $got = json_decode($out, true) ?? [];
    $check($code === 0 && ($got['arguments'] ?? null) === ['site' => 'example.org', 'partner' => 'bob'] && ($got['subject'] ?? null) === ['key' => $key, 'subject_source' => 'partner', 'partner' => 'bob', 'signature_checked_locally' => null], 'the core resolved the partner to a key; the add-on got the key and never the book');
    [$code, $out] = $cli(['addon', 'demo2', 'check', 'example.org', '--partner', 'nobody']);
    $check($code === 2 && (json_decode($out, true)['reason'] ?? null) === 'partner_unavailable', 'a partner nobody has is refused by the core');
    [$code, $out] = $cli(['addon', 'demo2', 'allow', 'localhost']);
    $check($code === 2 && (json_decode($out, true)['reason'] ?? null) === 'invalid_host', 'only a plain host goes on the list');
    [$code, $out] = $cli(['addon', 'demo2', 'forget', 'example.org']);
    $check($code === 0 && (json_decode($out, true)['removed'] ?? null) === true && json_decode($cli(['addon', 'demo2', 'list'])[1], true) === ['hosts' => []], 'forget takes it off, and list says so');
    $legacyHome = $folder . DIRECTORY_SEPARATOR . 'legacy-home';
    mkdir($legacyHome, 0700, true);
    file_put_contents($legacyHome . '/old-hosts.json', json_encode(['legacy.example']));
    file_put_contents($legacyHome . '/addons.json', json_encode(['enabled' => ['acme/demo2']]));
    $check(json_decode($cli(['addon', 'demo2', 'list'], $legacyHome)[1], true) === ['hosts' => ['legacy.example']] && is_file($legacyHome . '/addons/demo2.hosts.json'), 'the list an earlier version kept is read once');
    file_put_contents($legacyHome . '/old-hosts.json', json_encode(['other.example']));
    $check(json_decode($cli(['addon', 'demo2', 'list'], $legacyHome)[1], true) === ['hosts' => ['legacy.example']], "and from then on the list is the core's");

    // Over MCP the user is asked for the website, in words this runtime holds.
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $manifest = Addons::byName('demo2', $home);
    $arguments = ['site' => 'example.org', 'partner' => 'bob'];
    $refusedCall = Addons::dispatch($runtime, 'aamio_addon_demo2_check', $arguments, null);
    $check(($refusedCall['isError'] ?? null) === true && ($refusedCall['structuredContent']['reason'] ?? null) === 'host_not_allowed' && str_contains($refusedCall['structuredContent']['fix'] ?? '', 'stop this server'), 'without a form the way by hand is said');
    $asked = [];
    $declined = Addons::dispatch($runtime, 'aamio_addon_demo2_check', $arguments, static function (array $question, string $about, string $tool, string $key) use (&$asked): array {
        $asked[] = [$question, $about, $tool, $key];

        return ['answer' => ['action' => 'decline']];
    });
    $check(($declined['structuredContent']['reason'] ?? null) === 'host_declined' && ($asked[0][2] ?? null) === 'aamio_addon_demo2_check' && ($asked[0][3] ?? null) === AddonProcess::HOST_QUESTION, 'a user who declines adds nothing');
    $check(str_contains($asked[0][0]['message'] ?? '', 'your partner bob') && !str_contains($asked[0][0]['message'] ?? '', 'example.org'), 'the user is told who, and types the website themselves');
    $other = Addons::dispatch($runtime, 'aamio_addon_demo2_check', $arguments, static fn (): array => ['answer' => ['action' => 'accept', 'content' => ['domain' => 'examp1e.org']]]);
    $check(($other['structuredContent']['reason'] ?? null) === 'host_not_confirmed' && AddonProcess::allowedHosts($runtime, $manifest) === [], 'a lookalike stops at what the user types');
    $done = Addons::dispatch($runtime, 'aamio_addon_demo2_check', $arguments, static fn (): array => ['answer' => ['action' => 'accept', 'content' => ['domain' => 'Example.org']]]);
    $got = $done['structuredContent'] ?? [];
    $check(($done['isError'] ?? null) === false && ($got['arguments'] ?? null) === ['site' => 'example.org', 'partner' => 'bob'] && ($got['subject']['key'] ?? null) === $key && AddonProcess::allowedHosts($runtime, $manifest) === ['example.org'], "the user's yes lets the process run, and is kept");
    $both = Addons::dispatch($runtime, 'aamio_addon_demo2_check', $arguments + ['message' => str_repeat('a', 64)], null);
    $check(($both['structuredContent']['reason'] ?? null) === 'choose_subject', 'exactly one subject');
    $extra = Addons::dispatch($runtime, 'aamio_addon_demo2_check', $arguments + ['key' => 'x'], null);
    $check(($extra['isError'] ?? null) === true && ($extra['structuredContent']['given'] ?? null) === ['key'], 'a key beside the rest is refused, not passed over');
    $runtime->close();

    // A contract this aamio does not know is said, and can be taken out.
    Addons::disable('demo2');
    $three = $pkg('demo3', 'acme/demo3', 'demo3', $manifestFor('demo3', 3));
    Addons::useInstalled([$three]);
    Addons::reset();
    $row = Addons::describe()['addons'][0];
    $check($row['enabled'] === false && str_contains($row['error'] ?? '', 'contract 3') && str_contains($row['error'] ?? '', 'knows 1 and 2') && !isset($row['contract']), 'a manifest for a contract this aamio does not know is the row\'s error');
    $threw = $refused(static fn () => Addons::enable('demo3'));
    $check($threw !== null && str_contains($threw, 'contract 3') && $ran('demo3') === '' && $enabledFile() === ['enabled' => []], 'enable refuses it, and nothing ran');
    file_put_contents($home . '/addons.json', json_encode(['enabled' => ['acme/demo3']]));
    Addons::reset();
    $check(str_contains(Addons::describe()['error'] ?? '', 'contract 3'), 'enabled by an older aamio, say: the list says what is wrong');
    Addons::disable('demo3');
    $check($enabledFile() === ['enabled' => []] && $ran('demo3') === '', 'and disable works, running nothing');

    // A process that is not the add-on the manifest says is refused before anything is recorded.
    $nine = $pkg('demo9', 'acme/demo9', 'demo9', $manifestFor('demo9', 2, '9.9.9'), '0.0.2');
    Addons::useInstalled([$nine]);
    Addons::reset();
    $threw = $refused(static fn () => Addons::enable('demo9'));
    $check($threw !== null && str_contains($threw, 'answered the probe as') && str_contains($threw, '9.9.9') && $enabledFile() === ['enabled' => []] && $ran('demo9') === "probe probe\n", 'a probe that names another version refuses the enable');

    // composer.json that says contract 2 for a package without a manifest.
    $claims = $folder . DIRECTORY_SEPARATOR . 'claims';
    mkdir($claims, 0700, true);
    file_put_contents($claims . '/composer.json', json_encode(['name' => 'acme/claims', 'type' => 'aamio-addon', 'extra' => ['aamio-addon' => ['name' => 'claims', 'contract' => 2]]]));
    file_put_contents($claims . '/aamio-addon.php', "<?php\nreturn 'never read';\n");
    Addons::useInstalled([['package' => 'acme/claims', 'version' => '0.0.1', 'path' => $claims]]);
    Addons::reset();
    $threw = $refused(static fn () => Addons::enable('claims'));
    $check($threw !== null && str_contains($threw, 'contract 2') && str_contains($threw, 'aamio-addon.json') && $enabledFile() === ['enabled' => []], 'a package that claims contract 2 without a manifest is refused');

    // The files of a call live in a directory of their own, private from the start, and are gone afterwards.
    Addons::useInstalled([$two]);
    Addons::reset();
    $before = glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'aamio-addon-*') ?: [];
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    Addons::dispatch($runtime, 'aamio_addon_demo2_ping', [], null);
    $runtime->close();
    $after = glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'aamio-addon-*') ?: [];
    $check(count($after) <= count($before), 'and nothing of the call is left in the temporary directory');
    if (DIRECTORY_SEPARATOR === '/') {
        $probe = rtrim(sys_get_temp_dir(), '/') . '/aamio-addon-perms-' . getmypid();
        $mask = umask(0077);
        mkdir($probe, 0700);
        file_put_contents($probe . '/in', '{}');
        umask($mask);
        $check((fileperms($probe) & 0777) === 0700 && (fileperms($probe . '/in') & 0777) === 0600, 'a directory and a file made under the umask the call sets are private');
        @unlink($probe . '/in');
        @rmdir($probe);
    }

    putenv('AAMIO_TEST_INSTALLED');
    putenv('AAMIO_TEST_SECRET');
    Addons::useInstalled(null);
    Addons::useHome(null);
    Addons::reset();
    AddonProcess::resetLimits();
})();

// The example add-on hello, as https://aamio.at/docs/addons shows it: a manifest and a dozen lines of process, named by AAMIO_ADDONS.
(static function () use ($root, $check, $childPhp, $cliRun): void {
    $home = $root . DIRECTORY_SEPARATOR . 'hello-home';
    putenv('AAMIO_ADDONS=' . dirname(__DIR__) . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'hello');
    Addons::useInstalled(null);
    Addons::useHome($home);
    Addons::reset();
    AddonProcess::resetLimits();
    $quiet = static function (string $line): void {
    };
    $row = Addons::describe()['addons'][0] ?? [];
    $check(($row['name'] ?? null) === 'hello' && ($row['contract'] ?? null) === 2 && ($row['commands'] ?? null) === ['wave'] && ($row['mcp_tools'] ?? null) === ['aamio_addon_hello_wave'], 'the example add-on hello is listed from its manifest');
    $cli = static fn (array $words): array => $cliRun(array_merge($childPhp, [dirname(__DIR__) . '/bin/aamio', '--home', $home, '--host', 'https://fake.test', '--no-archive'], $words));
    [$code, $out] = $cli(['addon', 'hello', 'wave', '--to', 'Kari']);
    $check($code === 0 && json_decode($out, true) === ['hello' => 'Kari'], 'its command runs through its process');
    [$code, $out] = $cli(['addon', 'hello', 'wave']);
    $check($code === 0 && json_decode($out, true) === ['hello' => 'world'], 'with the default when the option is left out');
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $reply = (new McpServer($runtime))->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_hello_wave', 'arguments' => ['to' => 'Ola']]]);
    $runtime->close();
    $check(($reply['result']['isError'] ?? null) === false && ($reply['result']['structuredContent'] ?? null) === ['hello' => 'Ola'], 'and its tool over MCP');
    putenv('AAMIO_ADDONS');
    Addons::useHome(null);
    Addons::reset();
})();
