<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The transports one owner runs, with one carrier each, and what goes in and out through them.
 *
 * Only the process that owns the home runs them (B2). This runtime has no threads, so nothing pumps the carriers
 * behind the caller's back: whoever waits pumps. A send waits for its challenge and its receipt by pumping, a read
 * that waits pumps between its short waits on the inbox, and aamio serve pumps while it waits for the next line. A
 * transport that stops is started again after 1, 5 and 30 seconds, and left down after three stops in ten minutes;
 * every transport stops with the owner. The same as aamio-python's transports.Service.
 */
final class TransportService
{
    public readonly TransportConfig $config;
    private ?CarryState $state = null;
    /** @var array<string, array{manifest: ?AddonTransport, process: ?TransportProcess, carrier: ?Carrier, crashes: list<float>, next: float, down: ?string}> */
    public array $running = [];
    /** The environment a transport's variables are read from: getenv(), unless a test or an embedder says otherwise. */
    public ?array $environ = null;
    private float $knownAt = 0.0;

    public function __construct(private Runtime $runtime)
    {
        $this->config = new TransportConfig($runtime->home);
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    // ------------------------------------------------------------ what is there --

    /** @return array<string, AddonTransport> the transports enabled in this home, by name */
    public function manifests(): array
    {
        $found = [];
        foreach (Addons::loaded($this->runtime->home) as $addon) {
            if ($addon instanceof AddonTransport) {
                $found[$addon->name] = $addon;
            }
        }

        return $found;
    }

    public function carry(): CarryState
    {
        return $this->state ??= new CarryState($this->runtime->keys, $this->config->statePath());
    }

    /** Whether some partner in the address book is bound to a transport; transport_config_unreadable when that cannot be known. */
    public function bound(): bool
    {
        foreach (array_keys($this->config->bindings()) as $key) {
            if ($this->runtime->partnerByKey((string) $key) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every transport a partner in the address book is bound to, in the order first named */
    public function wanted(): array
    {
        $names = [];
        foreach ($this->config->bindings() as $key => $routes) {
            if ($this->runtime->partnerByKey((string) $key) === null) {
                continue;
            }
            foreach ($routes as $route) {
                if (!in_array($route['transport'], $names, true)) {
                    $names[] = $route['transport'];
                }
            }
        }

        return $names;
    }

    /** @return list<string> the keys a transport takes messages from: partners in the address book bound to it, and no one who has left the book */
    public function knownFor(string $transport): array
    {
        $keys = [];
        foreach ($this->config->bindings() as $key => $routes) {
            if ($this->runtime->partnerByKey((string) $key) !== null && array_filter($routes, static fn (array $route): bool => $route['transport'] === $transport) !== []) {
                $keys[] = Codec::unb64url((string) $key);
            }
        }

        return $keys;
    }

    public function routeOf(string $pk): ?array
    {
        return $this->config->routes(Codec::b64url($pk))[0] ?? null;
    }

    public function profileFor(string $pk): array
    {
        $route = $this->routeOf($pk);

        return Transports::checked($this->config->profile($route['profile'] ?? Transports::DEFAULT_PROFILE));
    }

    // ------------------------------------------------------------ running --

    /** Every transport that is bound and enabled, started. */
    public function start(): self
    {
        $manifests = $this->manifests();
        foreach ($this->wanted() as $name) {
            if (!isset($this->running[$name])) {
                $this->running[$name] = ['manifest' => $manifests[$name] ?? null, 'process' => null, 'carrier' => null, 'crashes' => [], 'next' => 0.0, 'down' => null];
                if (!isset($manifests[$name])) {
                    $this->running[$name]['down'] = $name . ' is bound to a partner and is not an enabled transport here: aamio addon enable ' . $name;
                    $this->runtime->logged('transport ' . $name . ': ' . $this->running[$name]['down']);
                }
            }
        }
        $this->startDue();

        return $this;
    }

    public function isRunning(): bool
    {
        foreach ($this->running as $entry) {
            if ($entry['carrier'] !== null || ($entry['down'] === null && $entry['manifest'] !== null)) {
                return true;
            }
        }

        return false;
    }

    private function startDue(): void
    {
        $now = self::now();
        foreach ($this->running as $name => $entry) {
            if ($entry['carrier'] !== null || $entry['manifest'] === null || $entry['down'] !== null || $now < $entry['next']) {
                continue;
            }
            $manifest = $entry['manifest'];
            $folder = Transports::workingFolder($this->runtime->home, $name);
            $process = new TransportProcess($name, $manifest->version, $manifest->startCommand(), $folder, Transports::environmentFor($manifest, $this->environ), null,
                fn (string $line) => $this->runtime->logged($line));
            try {
                $process->start(Http::VERSION, 'php', $folder);
                $this->running[$name]['carrier'] = new Carrier($this->carry(), $process, fn (string $pk): array => $this->profileFor($pk), $this->knownFor($name));
                $this->running[$name]['process'] = $process;
                $this->runtime->logged('transport ' . $name . ': up');
            } catch (TransportFailed $error) {
                $process->stop();
                $this->crashed($name, $error);
            }
        }
    }

    private function crashed(string $name, \Throwable $error): void
    {
        $entry = $this->running[$name];
        if ($entry['process'] !== null) {
            $entry['process']->stop();
        }
        $now = self::now();
        $crashes = array_values(array_filter($entry['crashes'], static fn (float $at): bool => $now - $at < Transports::CRASH_WINDOW));
        $crashes[] = $now;
        $this->running[$name]['process'] = null;
        $this->running[$name]['carrier'] = null;
        $this->running[$name]['crashes'] = $crashes;
        if (count($crashes) >= Transports::CRASHES_MOST) {
            $this->running[$name]['down'] = 'it stopped ' . Transports::CRASHES_MOST . ' times in ' . intdiv(Transports::CRASH_WINDOW, 60) . ' minutes, last: ' . $error->getMessage() . '. It stays down until aamio starts again';
            $this->runtime->logged('transport ' . $name . ': down: ' . $this->running[$name]['down']);

            return;
        }
        $after = Transports::$restartAfter[count($crashes) - 1];
        $this->running[$name]['next'] = $now + $after;
        $this->runtime->logged('transport ' . $name . ': down: ' . $error->getMessage() . ', started again in ' . $after . ' seconds');
    }

    /** One round: every carrier pumped, and transports that are due started again. */
    public function pump(float $timeout = 0.02): void
    {
        $now = self::now();
        if ($now - $this->knownAt >= Transports::KNOWN_REFRESH) {
            $this->knownAt = $now;
            foreach ($this->running as $name => $entry) {
                if ($entry['carrier'] !== null) {
                    try {
                        $entry['carrier']->known = $this->knownFor($name);
                    } catch (\RuntimeException $error) {
                        // Whom to take messages from is not known while the bindings cannot be read: no one, then.
                        $entry['carrier']->known = [];
                        $this->runtime->logged('transport ' . $name . ': ' . $error->getMessage());
                    }
                }
            }
        }
        foreach ($this->running as $name => $entry) {
            if ($entry['carrier'] === null) {
                continue;
            }
            try {
                $entry['carrier']->pump($timeout);
            } catch (TransportFailed $failed) {
                $this->crashed($name, $failed);
            }
        }
        $this->startDue();
    }

    /** Until $condition holds or $until passes on the monotonic clock, pumping meanwhile. */
    public function wait(callable $condition, float $until): bool
    {
        while (true) {
            if ($condition()) {
                return true;
            }
            if (self::now() >= $until) {
                return false;
            }
            $this->pump(0.05);
            if ($this->running === [] || !$this->isRunning()) {
                usleep(50000);
            }
        }
    }

    /**
     * Before a stop: what the carriers have queued is given to the transports and answered, for a moment at most. A read
     * on the command line that got a message returns at once, and the receipt for it was queued in the same round.
     */
    public function drain(float $seconds = 1.0): void
    {
        if (array_filter($this->running, static fn (array $entry): bool => $entry['carrier'] !== null) === []) {
            return;
        }
        $this->wait(function (): bool {
            foreach ($this->running as $entry) {
                if ($entry['carrier'] !== null && $entry['carrier']->busy()) {
                    return false;
                }
            }

            return true;
        }, self::now() + $seconds);
    }

    public function stop(): void
    {
        foreach ($this->running as $entry) {
            if ($entry['process'] !== null) {
                $entry['process']->stop();
            }
        }
        $this->running = [];
    }

    /** @return array<string, string> */
    public function status(): array
    {
        $rows = [];
        foreach ($this->running as $name => $entry) {
            $rows[$name] = $entry['carrier'] !== null ? 'up' : 'down: ' . ($entry['down'] ?? 'waiting to start again');
        }

        return $rows;
    }

    /** The carrier of a transport now, or null while it waits to start again; transport_down when it will not run here. */
    private function carrierOf(string $name): ?Carrier
    {
        $entry = $this->running[$name] ?? null;
        if ($entry === null || $entry['down'] !== null) {
            throw new CodedRuntime('transport_down', $name . ' cannot carry the message now: ' . ($entry['down'] ?? 'it is not running in this process') . '. Nothing was sent.');
        }

        return $entry['carrier'];
    }

    // ------------------------------------------------------------ in and out --

    /** The newest session of this party's that still has room in its lifetime, or null. */
    private function session(Carrier $carrier, string $pk): ?string
    {
        $profile = $this->profileFor($pk);

        return $carrier->usableSession($pk, $profile['lifetime'] - $profile['challenge_window']);
    }

    /**
     * $content to $pk over its first transport, in an open session or in a new one: its mark and how far it came.
     *
     * status is confirmed when the receipt came, handed_over when every frame of a try was given to the medium and no
     * receipt came within $wait, queued when the message waits in the outbox for room, and unknown when its tries are
     * used without a receipt. A partner that gives no challenge within $wait gets nothing: carry_not_sent.
     */
    public function send(string $pk, string $content, ?int $wait = null): array
    {
        $wait ??= Transports::$waitDefault;
        $route = $this->routeOf($pk);
        if ($route === null) {
            throw new CodedRuntime('transport_unbound', 'that partner has no transport');
        }
        $name = $route['transport'];
        $until = self::now() + $wait;
        $this->start();
        $asked = null;
        $opened = function () use ($name, $pk, &$asked): bool {
            // A carrier that started again after a crash knows nothing of the ask, so it asks again.
            $carrier = $this->carrierOf($name);
            if ($carrier === null) {
                return false;
            }
            if ($this->session($carrier, $pk) !== null) {
                return true;
            }
            if ($asked !== $carrier) {
                $carrier->open($pk);
                $asked = $carrier;
            }

            return false;
        };
        if (!$this->wait($opened, $until)) {
            throw new CodedRuntime('carry_not_sent', 'the partner did not answer on ' . $name . ' within ' . $wait . ' seconds, so nothing was sent. Its aamio has to run on the same network, as aamio serve or aamio read --wait, with this key bound to ' . $name);
        }
        $carrier = $this->carrierOf($name);
        try {
            $key = $carrier->send($pk, (string) $this->session($carrier, $pk), $content);
        } catch (CarryRefused $refused) {
            throw new CodedInvalidArgument($refused->carryCode, $refused->getMessage() . '. Nothing was sent.');
        }
        $state = $this->carry();
        $this->wait(static fn (): bool => $state->outcome($key) !== 'waiting', $until);
        $outcome = $state->outcome($key);
        if ($outcome === 'waiting') {
            $current = $this->running[$name]['carrier'] ?? null;
            $outcome = $current !== null && $current->handedOver($key) ? 'handed_over' : 'queued';
        }

        return ['via' => $name, 'profile' => $route['profile'], 'mark' => $key, 'status' => $outcome];
    }

    /** @return list<array{mark: string, outcome: string}> what was sent over a transport, as aamio transport list shows it, oldest first */
    public function outbox(): array
    {
        $state = $this->carry();
        $rows = [];
        foreach (array_keys($state->durable['outbox']) as $key) {
            $rows[] = ['mark' => (string) $key, 'outcome' => $state->outcome((string) $key)];
        }

        return $rows;
    }

    /**
     * What came over a transport and the program has not taken, in read's shape and in the order it came. Each says the
     * transport and profile its partner is bound to now, and whether it came sealed and when, as the state kept it then.
     *
     * @param array<string, true> $skip marks to leave out
     */
    public function entries(array $skip = []): array
    {
        $state = $this->carry();
        try {
            $bindings = $this->config->bindings();
        } catch (\RuntimeException) {
            $bindings = [];
        }
        $out = [];
        foreach ($state->pending() as [$key, $pk, , , $content]) {
            if (isset($skip[$key])) {
                continue;
            }
            $kept = $state->kept($key);
            $from = Codec::b64url($pk);
            $route = ($bindings[$from] ?? [])[0] ?? ['transport' => Transports::UNBOUND_CHANNEL, 'profile' => null];
            $partner = $this->runtime->partnerByKey($from);
            [$body, $meta] = Runtime::contentOf($content, $kept['sealed']);
            $out[] = ['channel' => $route['transport'], 'w' => null, 'seq' => null, 'at' => $kept['at'], 'verified' => true, 'from_key' => $from,
                'known_contact' => $partner !== null, 'sender' => $partner['name'] ?? 'unknown key', 'sha256' => $kept['sha256'], 'replay' => false,
                'body' => $body, 'via' => $route['transport'], 'profile' => $route['profile'], 'mark' => $key] + $meta;
        }

        return $out;
    }

    /** @param list<string> $keys the program has these: the state keeps only their marks from now on */
    public function taken(array $keys): void
    {
        $state = $this->carry();
        foreach ($keys as $key) {
            $state->handedOver($key);
        }
    }
}
