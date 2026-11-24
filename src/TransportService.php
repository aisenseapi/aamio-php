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
 * one no partner in the address book is bound to any more is stopped, and one bound meanwhile is started; every
 * transport stops with the owner. The same as aamio-python's transports.Service.
 *
 * Where things are reached is kept in the home as hints (TransportConfig): where a transport says it is reached, for
 * cards, and where a partner is reached on it, as bind was given it or a fresh envelope showed it. A carrier starts with
 * what the home keeps of its partners, and takes in what changes there while it runs.
 */
final class TransportService
{
    public readonly TransportConfig $config;
    private ?CarryState $state = null;
    /** @var array<string, array{manifest: ?AddonTransport, process: ?TransportProcess, carrier: ?Carrier, crashes: list<float>, next: float, down: ?string}> */
    public array $running = [];
    /** Whether start has been called and stop has not since: pumped from then on, also while no transport runs for a time. */
    public bool $started = false;
    /** stop has been called: the service stops once, with its owner, and nothing starts after it, not even for a moment. */
    private bool $stopped = false;
    /**
     * @var array<string, TransportProcess> a start of a moment under way, by transport name: one at a time per transport,
     * never beside its ordinary start, and none after the stop (a review of 20 November 2026)
     */
    public array $learning = [];
    /**
     * What a transport said of its medium the last time a start of a moment did not come up, by name: the reason
     * transport here gives. A transport says it as status down on the protocol, not on standard error, and here said only
     * that "the lines it wrote above" said why, with none written (a check of 22 November 2026).
     *
     * @var array<string, ?string>
     */
    public array $downSaid = [];
    /**
     * @var array<string, TransportProcess> an ordinary start under way, by transport name: known before its process can
     * call back, so a stop from the log stops it too (a review of 21 November 2026)
     */
    public array $starting = [];
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

    /** Whether something came over a transport and waits for a read, whatever is bound now: an unbind leaves it to be read. */
    public function hasPending(): bool
    {
        return file_exists($this->config->statePath()) && $this->carry()->pending() !== [];
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
        if ($this->stopped) {
            // Stopped with its owner, and for good: a send on a runtime that has let go of its home starts nothing in it.
            return $this;
        }
        $this->add($this->wanted());
        $this->startDue();
        // Not when a callback inside the start stopped the service: started is false from the stop on.
        $this->started = !$this->stopped;

        return $this;
    }

    /**
     * Transports to run, each once, with the manifests read only when one is new.
     *
     * @param list<string> $names
     */
    private function add(array $names): void
    {
        $manifests = null;
        foreach ($names as $name) {
            if (isset($this->running[$name])) {
                continue;
            }
            $manifests ??= $this->manifests();
            $this->running[$name] = ['manifest' => $manifests[$name] ?? null, 'process' => null, 'carrier' => null, 'crashes' => [], 'next' => 0.0, 'down' => null];
            if (!isset($manifests[$name])) {
                $this->running[$name]['down'] = $name . ' is bound to a partner and is not an enabled transport here: aamio addon enable ' . $name;
                $this->runtime->logged('transport ' . $name . ': ' . $this->running[$name]['down']);
            }
        }
    }

    /**
     * Who each running carrier takes messages from, read again; a transport no partner is bound to any more is stopped,
     * so it no longer holds its port and is not started again after a crash, and one bound meanwhile is started (the
     * follow-up check of 15 November 2026).
     */
    private function refresh(): void
    {
        try {
            $wanted = $this->wanted();
            $known = [];
            foreach ($wanted as $name) {
                $known[$name] = $this->knownFor($name);
            }
        } catch (\RuntimeException $error) {
            // Whom to take messages from is not known while the bindings cannot be read: no one, then, and nothing stopped.
            foreach ($this->running as $entry) {
                if ($entry['carrier'] !== null) {
                    $entry['carrier']->known = [];
                }
            }
            $this->runtime->logged('transports: ' . $error->getMessage());

            return;
        }
        foreach ($this->running as $name => $entry) {
            if (in_array((string) $name, $wanted, true)) {
                continue;
            }
            unset($this->running[$name]);
            if ($entry['process'] !== null) {
                $entry['process']->stop();
            }
            $this->runtime->logged('transport ' . $name . ': stopped, since no partner in the address book is bound to it any more');
        }
        $this->add($wanted);
        $addresses = $this->config->addresses();
        foreach ($this->running as $name => $entry) {
            if ($entry['carrier'] !== null) {
                $entry['carrier']->known = $known[$name] ?? [];
                // Given to bind meanwhile, or learned by another owner of this home: what the home keeps is the newest.
                $entry['carrier']->addresses = $this->addressesFor((string) $name, $addresses) + $entry['carrier']->addresses;
            }
        }
    }

    // ------------------------------------------------------------ where things are reached --

    /**
     * Where each partner bound to the transport is reached on it, as the home keeps it.
     *
     * @param array<string, array<string, array{address: string, from: string, at: int}>>|null $addresses
     * @return array<string, string> bin2hex of the partner's key => address
     */
    private function addressesFor(string $name, ?array $addresses = null): array
    {
        // Only for the partners bound to it: a hint left behind for one no longer bound, or no longer in the address book, is never used.
        $bound = array_flip(array_map(static fn (string $pk): string => Codec::b64url($pk), $this->knownFor($name)));
        $found = [];
        foreach ($addresses ?? $this->config->addresses() as $key => $by) {
            if (isset($by[$name]) && isset($bound[(string) $key])) {
                $found[bin2hex(Codec::unb64url((string) $key))] = $by[$name]['address'];
            }
        }

        return $found;
    }

    /** A fresh envelope from the partner came from $address: kept, so the next owner of the home asks there first. */
    private function heardFrom(string $name, string $pk, string $address): void
    {
        try {
            $this->config->setAddress(Codec::b64url($pk), $name, $address, 'heard', time());
        } catch (\RuntimeException $error) {
            $this->runtime->logged('transport ' . $name . ': where a partner was heard from could not be kept: ' . $error->getMessage());
        }
    }

    /**
     * The transport said where it is reached, or said nothing of it (an empty list): kept for cards, as an add-on that
     * needs here gets it, with the version that said it.
     *
     * @param list<string> $here
     */
    private function keepHere(string $name, string $medium, array $here, ?string $version = null): void
    {
        try {
            $this->config->setHere($name, $medium, $here, time(), $version);
        } catch (\RuntimeException $error) {
            $this->runtime->logged('transport ' . $name . ': where it is reached could not be kept: ' . $error->getMessage());
        }
    }

    /** A partner as the user knows it: its name, or the start of its key. */
    private function nameOf(string $pk): string
    {
        $key = Codec::b64url($pk);

        return $this->runtime->partnerByKey($key)['name'] ?? 'the key ' . substr($key, 0, 8);
    }

    /**
     * What a carrier on this transport has to tell the user once, as attention under the transport's name and the state it
     * gives, added to what waits there unread under the same state. The one a running carrier is given, and the one a test
     * can give its own.
     */
    public function attentionFor(string $name): \Closure
    {
        return fn (string $state, string $text) => $this->runtime->noteTransport($name, $state, $text, true);
    }

    /**
     * Where an enabled transport is reached: [addresses or null, how it is known], how being running, starting, learned,
     * not_ready or stopping.
     *
     * The running one says it on its ready. One that does not run is started for a moment, as B2 allows for this alone,
     * with a hello like any other, and stopped at its first ready: the ready says where it is reached, or that it says
     * nothing of it. Never after the stop: an owner that has let go of the home starts nothing in it and keeps nothing
     * there.
     *
     * This runtime has no threads, so a lookup never overlaps another one, the ordinary start or the stop, as two lookups
     * did in aamio-js (a review of 20 November 2026). Only a call from a callback, as the log, comes while a lookup or the
     * ordinary start runs, and it follows the same rules: it starts nothing beside the start under way (a review of
     * 21 November 2026).
     *
     * @return array{0: ?list<string>, 1: string}
     */
    public function learnHere(string $name, float $seconds = Transports::LEARN_SECONDS): array
    {
        $manifest = $this->manifests()[$name] ?? null;
        if ($manifest === null) {
            throw new CodedRuntime('transport_unknown', 'no enabled transport is called ' . $name . '. aamio addon list shows what is installed, and aamio addon enable ' . $name . ' lets it run here');
        }
        if ($this->stopped) {
            // Its owner has let go of the home, or is letting go of it: nothing starts any more, not even for a moment.
            return [null, 'stopping'];
        }
        $process = $this->running[$name]['process'] ?? null;
        if ($process !== null) {
            // Never a second one beside it: the two would fight over the medium, as a port or a channel.
            return [$process->here, $process->readied ? 'running' : 'starting'];
        }
        if (isset($this->learning[$name]) || isset($this->starting[$name])) {
            // One start of a moment per transport, and none beside the ordinary start while that one starts it. A second
            // lookup comes from inside the first here, as from the log, and cannot wait for it: one from the log of the
            // ordinary start started a second child (a review of 21 November 2026).
            return [null, 'starting'];
        }
        $folder = Transports::workingFolder($this->runtime->home, $name);
        $process = new TransportProcess($name, $manifest->version, $manifest->startCommand(), $folder, Transports::environmentFor($manifest, $this->environ), null,
            fn (string $line) => $this->runtime->logged($line));
        $this->learning[$name] = $process;
        try {
            $process->start(Http::VERSION, 'php', $folder);
            $until = self::now() + $seconds;
            $said = null;
            while (!$process->readied && !$this->stopped && self::now() < $until) {
                foreach ($process->poll(0.1) as $event) {
                    if ($event[0] === 'status' && $event[1] === 'down' && is_string($event[2] ?? null) && trim($event[2]) !== '') {
                        // Characters, not bytes, and without mbstring, which this client does not ask for.
                        $said = preg_match('/^.{1,' . Transports::DOWN_SAID_MOST . '}/us', trim($event[2]), $cut) === 1 ? $cut[0] : $said;
                    }
                }
            }
        } catch (TransportFailed $error) {
            if ($this->stopped) {
                // Ended by the stop, from a callback while it started: no failure of the transport.
                return [null, 'stopping'];
            }
            throw new CodedRuntime('transport_down', $name . ' did not start to say where it is reached: ' . $error->getMessage());
        } finally {
            $process->stop();
            unset($this->learning[$name]);
        }
        if ($this->stopped) {
            // Stopped while it started: an owner that has let go of the home writes nothing into it.
            return [null, 'stopping'];
        }
        if (!$process->readied) {
            $this->downSaid[$name] = $said;

            return [null, 'not_ready'];
        }
        unset($this->downSaid[$name]);
        // Said nothing is kept too, as an empty list, so a transport over a medium without addresses is not started again
        // for every card: the microphone of a transport over sound opened each time (the review of 19 November 2026).
        $this->keepHere($name, $manifest->medium, $process->here ?? [], $manifest->version);

        return [$process->here, 'learned'];
    }

    /**
     * What an add-on that needs here gets: each enabled transport's medium and where it is reached, as the running one
     * says it, else as the home keeps it from this version of the transport, else learned now by a start of a moment, at
     * most once per version. Never a partner's.
     *
     * A transport that says nothing of it is there with an empty list: the medium is this machine's, and it has no
     * address on it, as sound or Bluetooth LE, so a card names the medium alone and says nothing is wrong. One that could
     * not say now, as when its medium is off, is left out (the review of 19 November 2026).
     *
     * @return list<array{transport: string, medium: string, here: list<string>, at: int}>
     */
    public function hereForAddons(): array
    {
        $kept = $this->config->here();
        $out = [];
        foreach ($this->manifests() as $name => $manifest) {
            $name = (string) $name;
            $process = $this->running[$name]['process'] ?? null;
            $running = $process !== null && $process->readied ? ($process->here ?? []) : null;
            $said = $kept[$name] ?? null;
            if ($running !== null) {
                [$here, $at] = [$running, time()];
            } elseif ($said !== null && $said['medium'] === $manifest->medium && ($said['version'] === null || $said['version'] === $manifest->version)) {
                [$here, $at] = [$said['here'], $said['at']];
            } else {
                try {
                    [$here, $how] = $this->learnHere($name);
                } catch (CodedRuntime $error) {
                    $this->runtime->logged('transport ' . $name . ': ' . $error->getMessage());
                    [$here, $how] = [null, 'failed'];
                }
                if ($how === 'learned' || $how === 'running') {
                    // It said ready: what it said of where it is reached, or nothing, which is an empty list.
                    $here ??= [];
                }
                $at = time();
            }
            if ($here !== null) {
                $out[] = ['transport' => $name, 'medium' => $manifest->medium, 'here' => array_values($here), 'at' => $at];
            }
        }

        return $out;
    }

    /**
     * What an add-on that needs media gets: each enabled transport's name and medium, as its manifest says them, ordered
     * by name. From the manifests alone, and nothing is started: a reader of a card asks only which media this machine
     * has, and a transport over Bluetooth LE announces this machine as it starts (19 November 2026).
     *
     * @return list<array{transport: string, medium: string}>
     */
    public function mediaForAddons(): array
    {
        $manifests = $this->manifests();
        ksort($manifests, SORT_STRING);
        $out = [];
        foreach ($manifests as $name => $manifest) {
            $out[] = ['transport' => (string) $name, 'medium' => $manifest->medium];
        }

        return $out;
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

    /**
     * Every transport that is due started. A callback from inside a start, as the log, can stop the service: the start is
     * known to the stop before it begins, and after each callback the service checks that it still owns the transport.
     * A close from the log stopped nothing that was starting, and the start then took the process back into an entry the
     * stop had emptied (a review of 21 November 2026).
     */
    private function startDue(): void
    {
        $now = self::now();
        // Each entry as it is now, not as it was when the round began: a round called from a callback inside this one can
        // have started a transport meanwhile, and a copy taken at the start would start it a second time.
        foreach (array_keys($this->running) as $name) {
            $name = (string) $name;
            if ($this->stopped) {
                return;
            }
            $entry = $this->running[$name] ?? null;
            if ($entry === null) {
                continue;
            }
            if ($entry['carrier'] !== null || $entry['manifest'] === null || $entry['down'] !== null || $now < $entry['next']) {
                continue;
            }
            if (isset($this->learning[$name]) || isset($this->starting[$name])) {
                // Started for a moment just now, to say where it is reached, or being started by a round this one was
                // called from, as from the log: the one under way ends first.
                continue;
            }
            $manifest = $entry['manifest'];
            $folder = Transports::workingFolder($this->runtime->home, $name);
            $process = new TransportProcess($name, $manifest->version, $manifest->startCommand(), $folder, Transports::environmentFor($manifest, $this->environ), null,
                fn (string $line) => $this->runtime->logged($line));
            $this->starting[$name] = $process;
            try {
                $process->start(Http::VERSION, 'php', $folder);
                if (!$this->owns($name)) {
                    // Stopped, or no longer wanted, from a callback while it started: the next round sees which.
                    $process->stop();
                    continue;
                }
                $carrier = new Carrier($this->carry(), $process, fn (string $pk): array => $this->profileFor($pk), $this->knownFor($name),
                    note: fn (string $text) => $this->runtime->notSentAgain($name, $text), addresses: $this->addressesFor($name),
                    heard: fn (string $pk, string $address) => $this->heardFrom($name, $pk, $address), nameOf: fn (string $pk): string => $this->nameOf($pk),
                    onHere: fn (array $here) => $this->keepHere($name, $manifest->medium, $here, $manifest->version),
                    log: fn (string $line) => $this->runtime->logged($line),
                    attention: $this->attentionFor($name));
                if (!$this->owns($name)) {
                    $process->stop();
                    continue;
                }
                $this->running[$name]['carrier'] = $carrier;
                $this->running[$name]['process'] = $process;
            } catch (TransportFailed $error) {
                $process->stop();
                if ($this->owns($name)) {
                    $this->crashed($name, $error);
                }
                continue;
            } catch (\RuntimeException | \InvalidArgumentException $error) {
                // Not the transport: the carrier state is closed after a write failed, or a profile cannot be read. It
                // stays down with the reason rather than start again every second.
                $process->stop();
                if ($this->owns($name)) {
                    $this->running[$name]['down'] = rtrim($error->getMessage(), '.') . '. It stays down until that is put right and aamio starts again';
                    $this->runtime->logged('transport ' . $name . ': down: ' . $this->running[$name]['down']);
                }
                continue;
            } finally {
                unset($this->starting[$name]);
            }
            $this->runtime->logged('transport ' . $name . ': up');
            if (!$this->owns($name)) {
                continue;
            }
            $this->checkWindows($name, $carrier);
        }
    }

    /** Whether the service still runs and still has this transport: a callback can have stopped it, or unbound it. */
    private function owns(string $name): bool
    {
        return !$this->stopped && isset($this->running[$name]);
    }

    /**
     * Say it when a partner's profile gives a challenge less time than this medium needs for it and its open.
     *
     * Over sound a challenge and its open take close to a minute in the air alone, and two with the waits for a turn, and
     * the profile sealed gives 60 seconds: most opens would come too late and be turned down, few sessions would open,
     * and sends would end in carry_not_sent or without a receipt, with nothing to say why.
     */
    private function checkWindows(string $name, Carrier $carrier): void
    {
        $need = $carrier->handshakeSeconds();
        if ($need <= 0) {
            return;
        }
        try {
            $bindings = $this->config->bindings();
        } catch (\RuntimeException) {
            return;
        }
        foreach ($bindings as $key => $routes) {
            $route = null;
            foreach ($routes as $candidate) {
                if (($candidate['transport'] ?? null) === $name) {
                    $route = $candidate;
                    break;
                }
            }
            $partner = $this->runtime->partnerByKey((string) $key);
            if ($route === null || $partner === null) {
                continue;
            }
            try {
                $window = Transports::checked($this->config->profile($route['profile']))['challenge_window'];
            } catch (\RuntimeException | \InvalidArgumentException) {
                continue;
            }
            if ($window >= $need) {
                continue;
            }
            // Long enough for an open to have three tries, in whole minutes, as long as a profile can give at the most.
            $longer = min(max(Transports::CHALLENGE_WINDOW_SLOW, (int) (ceil($carrier->windowForOpens() / 60) * 60)), 600);
            $this->runtime->noteTransport($name, 'window_too_short', 'the profile ' . $route['profile'] . ' gives a challenge ' . $window
                . ' seconds to be answered, and on ' . $name . ' a challenge and its open take up to ' . (int) $carrier->airtimeOfHandshake()
                . ' seconds in the air, more with the waits for a turn: most sessions with ' . $partner['name'] . ' cannot open, and a send to '
                . $partner['name'] . ' ends in carry_not_sent or gets no receipt. A window of ' . $longer . ' seconds gives an open three tries: '
                . 'aamio transport profile add ' . $route['profile'] . '-slow --window ' . $longer . ' --lifetime 3600, then aamio transport unbind '
                . $partner['name'] . ' and aamio transport bind ' . $partner['name'] . ' ' . $name . ' --profile ' . $route['profile'] . '-slow');
        }
    }

    private function crashed(string $name, \Throwable $error): void
    {
        if ($this->stopped || !isset($this->running[$name])) {
            // Stopped, or no longer wanted, from a callback meanwhile: nothing of it is left to count.
            return;
        }
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

    /** One round: what is bound read again when that is due, every carrier pumped, and transports that are due started again. */
    public function pump(float $timeout = 0.02): void
    {
        if ($this->stopped) {
            // No round after the stop: one could add a transport and start it with nobody left to stop it.
            return;
        }
        $now = self::now();
        if ($now - $this->knownAt >= Transports::KNOWN_REFRESH) {
            $this->knownAt = $now;
            $this->refresh();
        }
        foreach ($this->running as $name => $entry) {
            if ($this->stopped) {
                // Stopped from a callback in this round, as the log of a carrier.
                return;
            }
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
        // From here on nothing is added or started, not even for a moment (a review of 20 November 2026).
        $this->stopped = true;
        $running = $this->running;
        $this->running = [];
        $this->started = false;
        foreach ($running as $entry) {
            if ($entry['process'] !== null) {
                $entry['process']->stop();
            }
        }
        // A start under way, ordinary or of a moment, can only be here when this stop came from a callback inside it:
        // its process is stopped now, before the owner lets go of the home, and the start sees that it is (a review of
        // 21 November 2026).
        foreach ([...array_values($this->starting), ...array_values($this->learning)] as $process) {
            $process->stop();
        }
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
     * $content to $pk over its first transport, in an open session or in a new one: its mark, the sha256 of its envelope
     * and how far it came, and for the trace its size and whether it went sealed. The sha256 is the one the partner's
     * runtime reads it under and names as seen once it has read it.
     *
     * With $stretch, the wait is as long as the medium needs as well, once its carrier is up and has said its rate: a
     * session and a message over sound take minutes. waited says how long it was.
     *
     * status is confirmed when the receipt came, handed_over when every frame of a try was given to the medium and no
     * receipt came within $wait, queued when the message waits in the outbox for room, and unknown when its tries are
     * used without a receipt. A partner that gives no challenge within $wait gets nothing: carry_not_sent.
     */
    public function send(string $pk, string $content, ?int $wait = null, bool $stretch = false): array
    {
        $wait ??= Transports::$waitDefault;
        $route = $this->routeOf($pk);
        if ($route === null) {
            throw new CodedRuntime('transport_unbound', 'that partner has no transport');
        }
        $name = $route['transport'];
        $began = self::now();
        $until = $began + $wait;
        $this->start();
        if ($stretch && $this->wait(fn (): bool => $this->carrierOf($name) !== null, $until)) {
            $carrier = $this->carrierOf($name);
            if ($carrier !== null) {
                $until = max($until, $began + $carrier->patience(strlen($content)));
            }
        }
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
                // An ask under way, as one an earlier send made before it gave up, is answered all the same: a new one
                // beside it only adds frames, and on a half-duplex medium they take each other's turns.
                if (!$carrier->asking($pk)) {
                    $carrier->open($pk);
                }
                $asked = $carrier;
            }

            return false;
        };
        if (!$this->wait($opened, $until)) {
            $hint = $stretch ? '' : $this->notSentHint($name, $pk, $content, $until - $began);
            throw new CodedRuntime('carry_not_sent', 'the partner did not answer on ' . $name . ' within ' . (int) ($until - $began) . ' seconds, so nothing was sent.' . $hint
                . ' Its aamio has to be within reach of ' . $name . ' and running, as aamio serve or aamio read --wait, with this key bound to ' . $name . $this->transportSaid($name, $began));
        }
        $carrier = $this->carrierOf($name);
        try {
            $key = $carrier->send($pk, (string) $this->session($carrier, $pk), $content);
        } catch (CarryRefused $refused) {
            throw new CodedInvalidArgument($refused->carryCode, $refused->getMessage() . '. Nothing was sent.');
        }
        // What went, as the outbox keeps it: the envelope, and its sha256.
        $written = $this->carry()->durable['outbox'][$key];
        $envelope = (string) hex2bin((string) $written['envelope']);
        $state = $this->carry();
        $this->wait(static fn (): bool => $state->outcome($key) !== 'waiting', $until);
        $outcome = $state->outcome($key);
        if ($outcome === 'waiting') {
            $current = $this->running[$name]['carrier'] ?? null;
            $outcome = $current !== null && $current->handedOver($key) ? 'handed_over' : 'queued';
        }

        return ['via' => $name, 'profile' => $route['profile'], 'mark' => $key, 'status' => $outcome, 'sha256' => $written['sha256'],
            'bytes' => strlen($envelope), 'sealed' => ord($envelope[strlen(Carry::MAGIC)]) === Carry::SEALED, 'waited' => (int) ($until - $began)];
    }

    /**
     * What the transport said of the latest frame it could not send since the send began, for carry_not_sent, or an empty
     * string. It knows the medium: a Bluetooth address nobody answers on, a network that is down.
     */
    private function transportSaid(string $name, float $since): string
    {
        $said = ($this->running[$name]['process'] ?? null)?->lastError;
        if ($said === null || $said[0] < $since || $said[2] === null || $said[2] === '') {
            return '';
        }

        return '. ' . $name . ' said of the last frame it could not send: ' . rtrim($said[2], '.');
    }

    /**
     * What carry_not_sent adds when the wait was shorter than a send takes on the medium, as over MCP, where it is the work
     * budget: the ask goes on in the background, and a send after its answer came uses the session it opened. Empty
     * otherwise.
     */
    private function notSentHint(string $name, string $pk, string $content, float $waited): string
    {
        try {
            $carrier = $this->carrierOf($name);
        } catch (CodedRuntime) {
            return '';
        }
        if ($carrier === null || $waited >= $carrier->patience(strlen($content))) {
            return '';
        }
        $due = $carrier->askDue($pk);

        return $due === null ? '' : ' The ask for a session is still under way on ' . $name . ', and its answer is due in about ' . max(5, (int) $due)
            . ' seconds: send again then, and that send uses the session it opens.';
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
