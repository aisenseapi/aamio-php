<?php

declare(strict_types=1);

namespace Aamio;

/**
 * What a contract-2 add-on offers, as its manifest aamio-addon.json says it.
 *
 * It stands where a contract-1 add-on stands in the registry: the same
 * interface, with the commands and tools read from the manifest and every call
 * carried out by AddonProcess in a process of the add-on's own. Reading it runs
 * none of the add-on's code. The manifest's rules are in AddonProcess and on
 * https://aamio.at/docs/addons.
 */
final class AddonManifest implements Addon
{
    public const CONTRACT = 2;

    public readonly string $name;
    public readonly string $version;
    public readonly string $script;
    public readonly int $deadline;
    public readonly ?string $description;
    /** @var list<string> */
    public readonly array $environment;
    /** @var array<string, mixed>|null */
    public readonly ?array $hosts;
    /** @var array<string, array<string, mixed>> */
    public readonly array $commands;
    /** @var list<array<string, mixed>> */
    public readonly array $toolSpecs;
    /** @var list<array<string, mixed>> */
    private array $tools;

    /** @param array<string, mixed> $data */
    public function __construct(public readonly ?string $package, public readonly string $dir, public readonly string $path, array $data, public readonly string $fix)
    {
        // A closure, not an arrow function: PHP 8.1.0 to 8.1.2, as Ubuntu 22.04 ships, refuse never on an arrow function (GH-7900).
        $wrong = function (string $what) use ($fix): never {
            throw new \RuntimeException($this->label() . ' cannot be used as an add-on: ' . $what . '. Reinstall it, or ' . $fix . ' to go on without it.');
        };
        $name = $data['name'] ?? null;
        // To the very end: with $ a name that ends in a line break was taken, and it names files in the home.
        if (!is_string($name) || preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/', $name) !== 1) {
            $wrong('its manifest has no name of 1 to 32 characters of a-z, 0-9, dash and underscore');
        }
        if (in_array($name, AddonProcess::RESERVED, true)) {
            throw new \RuntimeException($this->label() . ' cannot be called ' . $name . ': that is a command of aamio addon itself. To go on without it: ' . $fix . '.');
        }
        // The short name is the name of the add-on's list of websites and of
        // its own directory in the home. On Windows a file of a device's name
        // is the device: a list written to nul.hosts.json is written nowhere.
        if (preg_match('/\A(?:con|prn|aux|nul|com[0-9]|lpt[0-9])\z/', $name) === 1) {
            $wrong('its manifest\'s name, ' . $name . ', is one Windows keeps for a device, and it names the add-on\'s files in the home');
        }
        $this->name = $name;
        $version = $data['version'] ?? null;
        if (!is_string($version) || $version === '') {
            $wrong('its manifest has no version');
        }
        $this->version = $version;
        $process = $data['process'] ?? null;
        $script = is_array($process) ? ($process['php'] ?? null) : null;
        if (!is_string($script) || $script === '' || str_contains($script, '..') || str_starts_with($script, '/') || str_starts_with($script, '\\') || preg_match('/^[A-Za-z]:/', $script) === 1) {
            $wrong('its manifest names no PHP file of its own to run under process.php');
        }
        $this->script = $script;
        $deadline = $data['deadline_seconds'] ?? AddonProcess::DEADLINE_DEFAULT;
        if (!is_int($deadline) || $deadline < 1 || $deadline > AddonProcess::DEADLINE_MAX) {
            $wrong('deadline_seconds in its manifest is not a whole number from 1 to ' . AddonProcess::DEADLINE_MAX);
        }
        $this->deadline = $deadline;
        $description = $data['description'] ?? null;
        if ($description !== null && !is_string($description)) {
            $wrong('description in its manifest is not text');
        }
        $this->description = $description;
        $environment = $data['environment'] ?? [];
        if (!is_array($environment) || array_filter($environment, static fn ($name): bool => !is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $name) !== 1) !== []) {
            $wrong('environment in its manifest is not a list of variable names in A-Z, 0-9 and underscore');
        }
        $never = array_values(array_intersect($environment, AddonProcess::NEVER_PASSED));
        if ($never !== []) {
            $wrong('environment in its manifest asks for ' . implode(', ', $never) . ', which no add-on gets');
        }
        $this->environment = array_values($environment);
        $hosts = $data['hosts'] ?? null;
        if ($hosts === true) {
            $hosts = [];
        }
        if ($hosts !== null && $hosts !== false) {
            if (!is_array($hosts)) {
                $wrong('hosts in its manifest is neither true nor an object');
            }
            $legacy = $hosts['legacy_file'] ?? null;
            if ($legacy !== null && (!is_string($legacy) || $legacy === '' || basename($legacy) !== $legacy)) {
                $wrong('hosts.legacy_file in its manifest is not a plain file name');
            }
            $this->hosts = $hosts;
        } else {
            $this->hosts = null;
        }
        $commands = $data['commands'] ?? [];
        if (!is_array($commands)) {
            $wrong('commands in its manifest is not an object');
        }
        foreach ($commands as $command => $spec) {
            if (!is_string($command) || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $command) !== 1) {
                $wrong('the command ' . json_encode($command) . ' in its manifest is not a name of a-z, 0-9 and dashes');
            }
            if ($this->hosts !== null && in_array($command, AddonProcess::HOST_COMMANDS, true)) {
                $wrong('its manifest has a command ' . $command . ', which aamio provides for an add-on with hosts');
            }
            if (!is_array($spec)) {
                $wrong('the command ' . $command . ' in its manifest is not an object');
            }
            if (!is_string($spec['help'] ?? '')) {
                $wrong('the help of the command ' . $command . ' is not text');
            }
            if (!is_bool($spec['runtime'] ?? true)) {
                $wrong('runtime on the command ' . $command . ' is not true or false');
            }
            $needs = $spec['needs'] ?? [];
            if (!is_array($needs) || array_filter($needs, static fn ($need): bool => $need !== 'own_key') !== []) {
                $wrong('needs on the command ' . $command . ' names something other than own_key');
            }
            if ($needs !== [] && ($spec['runtime'] ?? true) === false) {
                $wrong('the command ' . $command . ' needs own_key and says it runs without a runtime');
            }
            $this->checkArguments($command, $spec, $wrong);
        }
        $this->commands = $commands;
        $tools = $data['tools'] ?? [];
        if (!is_array($tools) || array_keys($tools) !== range(0, count($tools) - 1) && $tools !== []) {
            $wrong('tools in its manifest is not a list');
        }
        $seen = [];
        foreach ($tools as $spec) {
            if (!is_array($spec) || !is_string($spec['name'] ?? null) || $spec['name'] === '') {
                $wrong('a tool of its has no name');
            }
            if (isset($seen[$spec['name']])) {
                $wrong('its manifest has two tools called ' . $spec['name']);
            }
            $seen[$spec['name']] = true;
            if (!is_string($spec['description'] ?? null) || $spec['description'] === '') {
                $wrong('the tool ' . $spec['name'] . ' has no description');
            }
            $schema = $spec['inputSchema'] ?? null;
            if (!is_array($schema) || ($schema['type'] ?? null) !== 'object' || !is_array($schema['properties'] ?? [])) {
                $wrong('the tool ' . $spec['name'] . ' has no inputSchema of type object');
            }
            if (isset($schema['oneOf']) || isset($schema['anyOf'])) {
                $wrong('the tool ' . $spec['name'] . ' has oneOf or anyOf at the top of its schema, which hosts refuse');
            }
            $fields = array_map('strval', array_keys($schema['properties'] ?? []));
            $host = $spec['host'] ?? null;
            if ($host !== null && (!is_string($host) || !in_array($host, $fields, true))) {
                $wrong('host on the tool ' . $spec['name'] . ' names no field of its schema');
            }
            if ($host !== null && $this->hosts === null) {
                $wrong('the tool ' . $spec['name'] . ' has a host field, and the manifest has no hosts');
            }
            $subject = $spec['subject'] ?? null;
            if ($subject !== null) {
                if (!is_array($subject) || $subject === []) {
                    $wrong('subject on the tool ' . $spec['name'] . ' is not an object of partner or message to a field of its schema');
                }
                foreach ($subject as $role => $field) {
                    if (!in_array($role, ['partner', 'message'], true) || !is_string($field) || !in_array($field, $fields, true)) {
                        $wrong('subject on the tool ' . $spec['name'] . ' is not an object of partner or message to a field of its schema');
                    }
                }
            }
            $needs = $spec['needs'] ?? [];
            if (!is_array($needs) || array_filter($needs, static fn ($need): bool => $need !== 'own_key') !== []) {
                $wrong('needs on the tool ' . $spec['name'] . ' names something other than own_key');
            }
            if (isset($spec['annotations']) && !is_array($spec['annotations'])) {
                $wrong('annotations on the tool ' . $spec['name'] . ' is not an object');
            }
        }
        $this->toolSpecs = array_values($tools);
        $this->tools = array_map(static function (array $spec): array {
            $tool = ['name' => $spec['name'], 'description' => $spec['description'], 'inputSchema' => $spec['inputSchema']];
            if (isset($spec['annotations'])) {
                $tool['annotations'] = $spec['annotations'];
            }

            return $tool;
        }, $this->toolSpecs);
    }

    /** @param array<string, mixed> $spec */
    private function checkArguments(string $command, array $spec, callable $wrong): void
    {
        $arguments = $spec['arguments'] ?? [];
        if (!is_array($arguments)) {
            $wrong('the arguments of the command ' . $command . ' are not a list');
        }
        $hosts = 0;
        $subjects = 0;
        $dests = [];
        foreach ($arguments as $argument) {
            if (!is_array($argument) || !is_string($argument['name'] ?? null)) {
                $wrong('an argument of the command ' . $command . ' has no name');
            }
            $name = $argument['name'];
            if (str_starts_with($name, '-')) {
                if (preg_match('/^--[a-z][a-z0-9-]{0,31}$/', $name) !== 1) {
                    $wrong('the option ' . $name . ' of the command ' . $command . ' is not --name in a-z, 0-9 and dashes');
                }
            } elseif (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) !== 1) {
                $wrong('the argument ' . $name . ' of the command ' . $command . ' is not a name in a-z, 0-9, dash and underscore');
            } elseif (array_key_exists('required', $argument)) {
                $wrong('the argument ' . $name . ' of the command ' . $command . ' is positional, and required is for options');
            }
            $dest = AddonProcess::dest($name);
            if (isset($dests[$dest])) {
                $wrong('the command ' . $command . ' has two arguments that would be called ' . $dest);
            }
            $dests[$dest] = true;
            if (!is_string($argument['help'] ?? '') || !is_bool($argument['required'] ?? false) || !is_bool($argument['host'] ?? false)) {
                $wrong('the argument ' . $name . ' of the command ' . $command . ' has help, required or host of the wrong kind');
            }
            if (($argument['host'] ?? false) === true) {
                $hosts++;
                if ($this->hosts === null) {
                    $wrong('the argument ' . $name . ' of the command ' . $command . ' is a host, and the manifest has no hosts');
                }
                if (($spec['runtime'] ?? true) === false) {
                    $wrong('the argument ' . $name . ' of the command ' . $command . ' is a host, which needs the runtime the command says it does without');
                }
            }
            if (array_key_exists('subject', $argument)) {
                $subjects++;
                if (!in_array($argument['subject'], ['partner', 'message'], true)) {
                    $wrong('subject on the argument ' . $name . ' of the command ' . $command . ' is neither partner nor message');
                }
                if (($spec['runtime'] ?? true) === false) {
                    $wrong('the argument ' . $name . ' of the command ' . $command . ' is a subject, which needs the runtime the command says it does without');
                }
            }
        }
        if ($hosts > 1 || $subjects > 1) {
            $wrong('the command ' . $command . ' has more than one host or more than one subject argument');
        }
    }

    /** The add-on in messages: its package, or its directory when AAMIO_ADDONS named it. */
    public function label(): string
    {
        return $this->package ?? $this->dir;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, string> the manifest's commands, and the three aamio gives an add-on with hosts */
    public function commands(): array
    {
        $commands = [];
        foreach ($this->commands as $name => $spec) {
            $commands[$name] = AddonProcess::usageLine($name, $spec);
        }
        if ($this->hosts !== null) {
            $commands['allow'] = 'allow HOST: put a website on this runtime\'s list for this add-on, from your own records';
            $commands['forget'] = 'forget HOST: take a website off that list';
            $commands['list'] = 'list: the websites this runtime may read for this add-on';
        }

        return $commands;
    }

    public function early(array $args, array $opts): ?int
    {
        return AddonProcess::early($this, $args, $opts);
    }

    public function command(array $args, array $opts, Runtime $runtime): int
    {
        return AddonProcess::command($this, $args, $opts, $runtime);
    }

    /** @return list<array<string, mixed>> */
    public function tools(): array
    {
        return $this->tools;
    }

    public function dispatch(Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array
    {
        return AddonProcess::dispatch($this, $runtime, $name, $arguments, $ask);
    }
}
