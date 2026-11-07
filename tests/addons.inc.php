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

use Aamio\Addons;
use Aamio\Channel;
use Aamio\McpServer;
use Aamio\Runtime;

echo "add-ons\n";

(static function () use ($root, $check, $childPhp, $cliRun): void {
    $folder = $root . DIRECTORY_SEPARATOR . 'addons';
    mkdir($folder, 0700, true);
    $demo = static function (string $file, string $name = 'demo', string $tool = 'aamio_addon_demo_ping') use ($folder): string {
        $class = 'Demo' . substr(md5($file . $name . $tool), 0, 8);
        // Declared once: the registry requires the file again after every reset.
        file_put_contents($folder . DIRECTORY_SEPARATOR . $file, '<?php
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
');

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
    [$code, , $err] = $cli(['addon', 'list'], $root . DIRECTORY_SEPARATOR . 'addons-cli');
    $check($code === 1 && str_contains($err, 'gone.php is not there'), 'nor is a file that is not there');

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
