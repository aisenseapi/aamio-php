<?php

declare(strict_types=1);

namespace Aamio;

/**
 * profiles.json and bindings.json under transports/ in the home, read whole and written atomically.
 *
 * The same files as aamio-python's transports.Config writes, so a home moves between the runtimes: profiles.json is
 * {"version": 1, "profiles": {name: record}} and bindings.json {"version": 1, "partners": {key: [{transport,
 * profile}]}}, the key a partner's public key as the address book has it. A map is written as a JSON object also when
 * it is empty, as the other two read it.
 *
 * Two files beside them are hints, and never stop a send: here.json {"version": 1, "transports": {name: {medium, here,
 * at, version}}}, where each transport said it is reached, for cards that say it; and addresses.json {"version": 1,
 * "partners": {key: {transport: {address, from, at}}}}, where each bound partner is reached on each transport, as the
 * user gave it to bind ("you") or as a fresh envelope from the partner showed it ("heard"). They are files of their own
 * because a core of 0.4.0 refuses a binding with a field it does not know, and the same home is read by all three
 * runtimes, of any version. One that cannot be read is read as empty.
 */
final class TransportConfig
{
    public readonly string $dir;

    public function __construct(public readonly string $home)
    {
        $this->dir = $home . DIRECTORY_SEPARATOR . 'transports';
    }

    /** @return array<string, mixed> the map under $field, or [] when the file is not there */
    private function read(string $name, string $field): array
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            return [];
        }
        $text = @file_get_contents($path);
        // As objects first, to tell a map from a list as the other runtimes do, then as arrays to use.
        $shape = is_string($text) ? json_decode($text, false) : null;
        if (!$shape instanceof \stdClass || ($shape->version ?? null) !== 1 || !(($shape->{$field} ?? null) instanceof \stdClass)) {
            throw new CodedRuntime('transport_config_unreadable', $path . ' could not be read. Which partners go over a transport is not known while it cannot, so nothing is sent to a partner until it is repaired or moved away');
        }
        $value = json_decode((string) $text, true);

        return is_array($value[$field] ?? null) ? $value[$field] : [];
    }

    private function write(string $name, string $field, array $value): void
    {
        Storage::makePrivateDir($this->dir);
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        $tmp = $path . '.tmp';
        $mask = umask(0077);
        $written = false;
        try {
            $handle = @fopen($tmp, 'wb');
            if ($handle !== false) {
                $written = @fwrite($handle, json_encode(['version' => 1, $field => self::asMap($value)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                Storage::sync($handle);
                fclose($handle);
            }
        } finally {
            umask($mask);
        }
        if ($written === false || !@rename($tmp, $path)) {
            throw new CodedRuntime('not_saved', $path . ' could not be written, so it is as it was. Free the disk, or give this user write access to ' . $this->dir . ', and do it again.');
        }
    }

    /** A map as a JSON object, an empty one too, with its records as objects. */
    private static function asMap(array $value): \stdClass
    {
        $map = new \stdClass();
        foreach ($value as $key => $entry) {
            $map->{(string) $key} = is_array($entry) && !array_is_list($entry) ? (object) $entry : $entry;
        }

        return $map;
    }

    /** @return array<string, array> name => record */
    public function profiles(): array
    {
        return $this->read('profiles.json', 'profiles');
    }

    /** @return array<string, list<array{transport: string, profile: string}>> */
    public function bindings(): array
    {
        $found = $this->read('bindings.json', 'partners');
        foreach ($found as $routes) {
            $whole = is_array($routes) && $routes !== [] && array_is_list($routes);
            foreach ($whole ? $routes : [] as $route) {
                $keys = is_array($route) ? array_keys($route) : [];
                sort($keys);
                if ($keys !== ['profile', 'transport'] || !is_string($route['transport']) || !is_string($route['profile'])) {
                    $whole = false;
                }
            }
            if (!$whole) {
                throw new CodedRuntime('transport_config_unreadable', $this->dir . DIRECTORY_SEPARATOR . 'bindings.json has a binding that is not a list of transport and profile. Nothing is sent to a partner until it is repaired or moved away');
            }
        }

        return $found;
    }

    public function profile(string $name): array
    {
        $found = $this->profiles()[$name] ?? null;
        if ($found === null && $name === Transports::DEFAULT_PROFILE) {
            return Transports::profileRecord(Transports::DEFAULT_PROFILE);
        }
        if ($found === null) {
            throw new CodedRuntime('transport_profile_unknown', 'no profile called ' . $name . ': aamio transport profile add ' . $name);
        }

        return $found;
    }

    private function inUse(string $name): bool
    {
        foreach ($this->bindings() as $routes) {
            foreach ($routes as $route) {
                if ($route['profile'] === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    public function addProfile(array $record): void
    {
        Transports::checked($record);
        $profiles = $this->profiles();
        if (isset($profiles[$record['name']]) && $this->inUse($record['name']) && $profiles[$record['name']] != $record) {
            // A change under a bound partner changes how its messages are checked while they are under way.
            throw new CodedInvalidArgument('transport_profile_in_use', 'the profile ' . $record['name'] . ' is bound to a partner: aamio transport unbind first, or add a profile of another name');
        }
        $profiles[$record['name']] = $record;
        $this->write('profiles.json', 'profiles', $profiles);
    }

    public function removeProfile(string $name): void
    {
        if ($this->inUse($name)) {
            throw new CodedInvalidArgument('transport_profile_in_use', 'the profile ' . $name . ' is bound to a partner: aamio transport unbind first');
        }
        $profiles = $this->profiles();
        if (!isset($profiles[$name])) {
            throw new CodedRuntime('transport_profile_unknown', 'no profile called ' . $name);
        }
        unset($profiles[$name]);
        $this->write('profiles.json', 'profiles', $profiles);
    }

    /** @return list<array{transport: string, profile: string}> the partner's routes after the binding */
    public function bind(string $key, string $transport, string $profile): array
    {
        $record = $this->profile($profile);
        Transports::checked($record);
        if (!isset($this->profiles()[$profile])) {
            $this->addProfile($record);
        }
        $bindings = $this->bindings();
        $routes = $bindings[$key] ?? [];
        foreach ($routes as $route) {
            if ($route['transport'] === $transport) {
                throw new CodedInvalidArgument('transport_already_bound', $transport . ' is bound to that partner already');
            }
        }
        $routes[] = ['transport' => $transport, 'profile' => $profile];
        $bindings[$key] = $routes;
        $this->write('bindings.json', 'partners', $bindings);

        return $routes;
    }

    /** @return list<array{transport: string, profile: string}> what is left of the partner's routes */
    public function unbind(string $key, ?string $transport = null): array
    {
        $bindings = $this->bindings();
        $named = $transport === null || array_filter($bindings[$key] ?? [], static fn (array $route): bool => $route['transport'] === $transport) !== [];
        if (!isset($bindings[$key]) || !$named) {
            throw new CodedRuntime('transport_unbound', 'that partner is not bound to ' . ($transport ?? 'a transport'));
        }
        $routes = $transport === null ? [] : array_values(array_filter($bindings[$key], static fn (array $route): bool => $route['transport'] !== $transport));
        if ($routes !== []) {
            $bindings[$key] = $routes;
        } else {
            unset($bindings[$key]);
        }
        $this->write('bindings.json', 'partners', $bindings);
        try {
            $this->dropAddresses($key, $transport);
        } catch (\RuntimeException) {
            // A hint left for a partner no longer bound is never used: addresses are looked up by binding.
        }

        return $routes;
    }

    /** @return list<array{transport: string, profile: string}> */
    public function routes(string $key): array
    {
        return $this->bindings()[$key] ?? [];
    }

    // ------------------------------------------------------------ hints --

    /** @return array<string, mixed> a hint file's field, or [] when it is not there or cannot be read: where something is reached never stops a send */
    private function readHint(string $name, string $field): array
    {
        try {
            return $this->read($name, $field);
        } catch (CodedRuntime) {
            return [];
        }
    }

    /** Whether a value read from JSON was an object there: a list with members was not, and an empty one cannot be told apart. */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Where each transport said it is reached, the last time it said, and the version that said it. An empty here is a
     * transport that said nothing of it: kept, so one whose medium has no addresses, as sound, is not started again for
     * every card. version is null in a file without it.
     *
     * @return array<string, array{medium: string, here: list<string>, at: int, version: ?string}>
     */
    public function here(): array
    {
        $found = [];
        foreach ($this->readHint('here.json', 'transports') as $name => $entry) {
            if (self::isMap($entry) && is_string($entry['medium'] ?? null) && is_int($entry['at'] ?? null)
                && TransportProcess::hereOf($entry['here'] ?? null, $entry['medium']) !== null) {
                $found[(string) $name] = ['medium' => $entry['medium'], 'here' => $entry['here'], 'at' => $entry['at'], 'version' => is_string($entry['version'] ?? null) ? $entry['version'] : null];
            }
        }

        return $found;
    }

    /** @param list<string> $here */
    public function setHere(string $name, string $medium, array $here, int $at, ?string $version = null): void
    {
        $known = $this->readHint('here.json', 'transports');
        $known[$name] = ['medium' => $medium, 'here' => array_values($here), 'at' => $at];
        if ($version !== null) {
            $known[$name]['version'] = $version;
        }
        $this->write('here.json', 'transports', $known);
    }

    /**
     * Where each bound partner is reached on each transport.
     *
     * @return array<string, array<string, array{address: string, from: string, at: int}>> partner key => transport => where
     */
    public function addresses(): array
    {
        $found = [];
        foreach ($this->readHint('addresses.json', 'partners') as $key => $by) {
            if (!self::isMap($by)) {
                continue;
            }
            foreach ($by as $transport => $entry) {
                if (self::isMap($entry) && is_string($entry['address'] ?? null) && in_array($entry['from'] ?? null, Transports::ADDRESS_FROM, true) && is_int($entry['at'] ?? null)
                    && TransportProcess::hereOf([$entry['address']], explode(':', $entry['address'], 2)[0]) !== null) {
                    $found[(string) $key][(string) $transport] = ['address' => $entry['address'], 'from' => $entry['from'], 'at' => $entry['at']];
                }
            }
        }

        return $found;
    }

    public function setAddress(string $key, string $transport, string $address, string $source, int $at): void
    {
        $known = $this->readHint('addresses.json', 'partners');
        $by = self::isMap($known[$key] ?? null) ? $known[$key] : [];
        $by[$transport] = ['address' => $address, 'from' => $source, 'at' => $at];
        $known[$key] = $by;
        $this->write('addresses.json', 'partners', $known);
    }

    /** Where a partner is reached, dropped for one transport or for all. */
    public function dropAddresses(string $key, ?string $transport = null): void
    {
        $known = $this->readHint('addresses.json', 'partners');
        $by = $known[$key] ?? null;
        if (!self::isMap($by)) {
            return;
        }
        if ($transport === null) {
            unset($known[$key]);
        } else {
            if (!array_key_exists($transport, $by)) {
                return;
            }
            unset($by[$transport]);
            if ($by === []) {
                unset($known[$key]);
            } else {
                $known[$key] = $by;
            }
        }
        $this->write('addresses.json', 'partners', $known);
    }

    public function statePath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'carry.json';
    }
}
