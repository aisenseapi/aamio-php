<?php

declare(strict_types=1);

namespace Aamio;

/**
 * What a contract-3 add-on is: a transport the owner of the home starts and speaks to in lines (contract 3, part B).
 *
 * It stands where a contract-1 add-on stands in the registry, as AddonManifest does, and has no commands and no tools
 * of its own: what it offers is a medium, used through aamio transport. Reading it runs none of its code. The same
 * rules as TransportManifest in aamio-python.
 */
final class AddonTransport implements Addon
{
    public const CONTRACT = 3;
    /** What a transport may say it uses (B1): shown before enable, never enforced, the ground for consent. */
    public const PERMISSIONS = ['network_local', 'network_internet', 'bluetooth', 'serial', 'microphone', 'speaker', 'camera', 'screen', 'radio'];
    public const PLATFORMS = ['linux', 'darwin', 'win32'];

    public readonly string $name;
    public readonly string $version;
    public readonly ?string $description;
    public readonly string $medium;
    /** The PHP file of the package that is the transport, from the package's root. */
    public readonly string $script;
    /** @var list<string> */
    public readonly array $platforms;
    /** @var list<string> */
    public readonly array $permissions;
    /** @var list<string> */
    public readonly array $environment;

    /** @param array<string, mixed> $data */
    public function __construct(public readonly ?string $package, public readonly string $dir, public readonly string $path, array $data, public readonly string $fix)
    {
        // A closure, not an arrow function: PHP 8.1.0 to 8.1.2, as Ubuntu 22.04 ships, refuse never on an arrow function (GH-7900).
        $wrong = function (string $what) use ($fix): never {
            throw new \RuntimeException($this->label() . ' cannot be used as a transport: ' . $what . '. Reinstall it, or ' . $fix . ' to go on without it.');
        };
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/', $name) !== 1) {
            $wrong('its manifest has no name of 1 to 32 characters of a-z, 0-9, dash and underscore');
        }
        if (in_array($name, AddonProcess::RESERVED, true)) {
            throw new \RuntimeException($this->label() . ' cannot be called ' . $name . ': that is a command of aamio addon itself. To go on without it: ' . $fix . '.');
        }
        if (preg_match('/\A(?:con|prn|aux|nul|com[0-9]|lpt[0-9])\z/', $name) === 1) {
            $wrong('its manifest\'s name, ' . $name . ', is one Windows keeps for a device, and it names the transport\'s working folder');
        }
        $this->name = $name;
        $version = $data['version'] ?? null;
        if (!is_string($version) || $version === '') {
            $wrong('its manifest has no version');
        }
        $this->version = $version;
        foreach (['process', 'commands', 'tools', 'hosts'] as $field) {
            if (array_key_exists($field, $data)) {
                $wrong('its manifest has ' . $field . ', which is contract 2\'s: a transport has transport and nothing of that kind');
            }
        }
        $description = $data['description'] ?? null;
        if ($description !== null && !is_string($description)) {
            $wrong('description in its manifest is not text');
        }
        $this->description = $description;
        $transport = $data['transport'] ?? null;
        if (!is_array($transport) || array_is_list($transport)) {
            $wrong('its manifest has no transport object');
        }
        $medium = $transport['medium'] ?? null;
        if (!is_string($medium) || preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/', $medium) !== 1) {
            $wrong('transport.medium in its manifest is not a name of a-z, 0-9, dash and underscore');
        }
        $this->medium = $medium;
        $start = $transport['start'] ?? null;
        if (!is_array($start) || ($start !== [] && array_is_list($start))) {
            $wrong('transport.start in its manifest is not an object');
        }
        $script = $start['php'] ?? null;
        // exec, a program inside the package, is for a transport in another language; this aamio starts one of its own.
        if (!is_string($script) || $script === '' || str_contains($script, '..') || str_starts_with($script, '/') || str_starts_with($script, '\\') || preg_match('/^[A-Za-z]:/', $script) === 1) {
            $wrong('transport.start.php in its manifest names no PHP file of its own, and this aamio starts a transport with its own interpreter');
        }
        $this->script = $script;
        $platforms = $transport['platforms'] ?? null;
        if (!is_array($platforms) || $platforms === [] || !array_is_list($platforms) || array_diff($platforms, self::PLATFORMS) !== []) {
            $wrong('transport.platforms in its manifest is not a list of ' . implode(', ', self::PLATFORMS));
        }
        $this->platforms = $platforms;
        $permissions = $transport['permissions'] ?? null;
        if (!is_array($permissions) || !array_is_list($permissions) || array_filter($permissions, static fn ($p): bool => !in_array($p, self::PERMISSIONS, true)) !== []) {
            $wrong('transport.permissions in its manifest is not a list of ' . implode(', ', self::PERMISSIONS));
        }
        $this->permissions = $permissions;
        $environment = $data['environment'] ?? [];
        if (!is_array($environment) || array_filter($environment, static fn ($name): bool => !is_string($name) || preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $name) !== 1) !== []) {
            $wrong('environment in its manifest is not a list of variable names in A-Z, 0-9 and underscore');
        }
        $never = array_values(array_intersect($environment, AddonProcess::NEVER_PASSED));
        if ($never !== []) {
            $wrong('environment in its manifest asks for ' . implode(', ', $never) . ', which no add-on gets');
        }
        $this->environment = array_values($environment);
    }

    public function label(): string
    {
        return $this->package ?? $this->dir;
    }

    /** This platform as a manifest names it: linux, darwin or win32. */
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'win32',
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    public function runsHere(?string $platform = null): bool
    {
        return in_array($platform ?? self::platform(), $this->platforms, true);
    }

    /** @return list<string> PHP_BINARY and the transport's file, as contract 2 starts a process: nothing looked up in PATH */
    public function startCommand(): array
    {
        return [PHP_BINARY, $this->dir . DIRECTORY_SEPARATOR . $this->script];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, string> a transport has no commands of its own: aamio transport is where it is used */
    public function commands(): array
    {
        return [];
    }

    public function early(array $args, array $opts): ?int
    {
        return null;
    }

    public function command(array $args, array $opts, Runtime $runtime): int
    {
        fwrite(STDOUT, json_encode(['name' => $this->name, 'version' => $this->version, 'contract' => self::CONTRACT, 'medium' => $this->medium, 'permissions' => $this->permissions,
            'note' => $this->name . ' is a transport. aamio transport list shows it with the partners bound to it; aamio transport bind PARTNER ' . $this->name . ' sends to a partner over it.'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return 0;
    }

    /** @return list<array> */
    public function tools(): array
    {
        return [];
    }

    public function dispatch(Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array
    {
        return null;
    }

    /** What enable says of a transport: who starts it, where it runs, what it gets, and what it says it uses. */
    public function enableNote(string $package, string $folder): string
    {
        $asked = $this->environment !== [] ? ' and ' . implode(', ', $this->environment) . ', which its manifest asks for' : '';
        $uses = $this->permissions !== [] ? implode(', ', $this->permissions) : 'nothing it names';

        return $package . ' is a transport here from now on, over ' . $this->medium . '. Only the process that owns this home starts it: aamio serve, and aamio send or '
            . 'aamio read --wait while they run. It runs in a process of its own, in ' . $folder . ', outside the home, and gets the frames to send and gives '
            . "back the frames it heard: never the home's key, the home or the address book. Its environment is the short list of contract 2" . $asked . '. '
            . 'It says it uses ' . $uses . '; aamio cannot hold it to that, and it runs with your own permissions. Nothing goes over it until a partner is '
            . 'bound to it: aamio transport bind PARTNER ' . $this->name . '.';
    }
}
