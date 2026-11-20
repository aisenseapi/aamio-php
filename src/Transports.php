<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Transports for one home (contract 3, B): the profiles, the bindings to partners, and the commands of aamio transport.
 *
 * The home keeps, under transports/: profiles.json, the security profiles of B5; bindings.json, for each partner's key
 * an ordered list of a transport and a profile; and carry.json, the carrier state of aamio-carry-v1-tilstand. A
 * partner without a binding goes over HTTPS as before; a partner with one goes over its transport only, and never over
 * HTTPS instead. Only the process that owns the home runs a transport (B2): aamio serve for as long as it serves, and a
 * send or a read that waits, on the command line, for as long as it runs. TransportService runs them. The same as
 * aamio-python's transports.py, over the same files.
 *
 * Two files beside them are hints, and never stop a send: here.json, where each transport said it is reached, for cards
 * that say it; and addresses.json, where each bound partner is reached on each transport, as the user gave it to bind or
 * as a fresh envelope from the partner showed it.
 */
final class Transports
{
    public const PROFILE_VERSION = 1;
    public const DEFAULT_PROFILE = 'sealed';
    public const CRASH_WINDOW = 600;
    public const CRASHES_MOST = 3;
    /** How long a start of a moment waits for a transport's first ready, to learn where it is reached (B2). */
    public const LEARN_SECONDS = 10;
    /** Where an address a partner is reached at came from: given to bind, or shown by a fresh envelope. */
    public const ADDRESS_FROM = ['you', 'heard'];
    /** What a carried message is filed under when the partner it came from is no longer bound to a transport. */
    public const UNBOUND_CHANNEL = 'carry';
    /** How often a running carrier's list of whom it takes messages from is read again. */
    public const KNOWN_REFRESH = 2.0;
    /** The window a profile for a slow medium is told to give, at the least. */
    public const CHALLENGE_WINDOW_SLOW = 300;

    /** @var list<float> how long a transport that stopped waits before it is started again; a property, so a test can shorten it */
    public static array $restartAfter = [1.0, 5.0, 30.0];
    /** How long a send waits for a challenge and its receipt, in seconds; a property, so a test can shorten it. */
    public static int $waitDefault = 30;

    public static function profileRecord(string $name, string $mode = 'sealed', int $lifetime = CarryState::LIFETIME_DEFAULT, int $window = CarryState::CHALLENGE_WINDOW_DEFAULT, int $maxContent = Carry::MAX_CONTENT): array
    {
        // A profile as B5 writes it: with a challenge, on a medium both ways, the clock trusted.
        return ['name' => $name, 'version' => self::PROFILE_VERSION, 'mode' => $mode, 'challenge' => true, 'accept_unchallenged' => false, 'lifetime' => $lifetime,
            'challenge_window' => $window, 'clock_trusted' => true, 'max_content' => $maxContent, 'radio' => null];
    }

    /** The profile the core holds to (B5), from a record in profiles.json, or CodedInvalidArgument when it is not one. */
    public static function checked(mixed $record): array
    {
        if (!is_array($record) || ($record['version'] ?? null) !== self::PROFILE_VERSION) {
            throw new CodedInvalidArgument('transport_profile_unknown', 'the profile ' . (is_array($record) ? json_encode($record['name'] ?? null) : 'null') . ' is of a version this aamio does not know, so nothing is sent with it');
        }
        if (($record['radio'] ?? null) !== null) {
            throw new CodedInvalidArgument('transport_profile_incomplete', 'the profile ' . json_encode($record['name'] ?? null) . ' has radio settings, and v1 has no radio transport');
        }
        if (!is_string($record['name'] ?? null) || preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/', $record['name']) !== 1) {
            throw new CodedInvalidArgument('transport_profile_invalid', "a profile's name is 1 to 32 characters of a-z, 0-9, dash and underscore");
        }
        foreach (['challenge', 'accept_unchallenged', 'clock_trusted'] as $field) {
            if (!is_bool($record[$field] ?? null)) {
                throw new CodedInvalidArgument('transport_profile_invalid', 'the profile ' . $record['name'] . ' has a field of the wrong kind');
            }
        }
        foreach (['lifetime', 'challenge_window', 'max_content'] as $field) {
            if (!is_int($record[$field] ?? null)) {
                throw new CodedInvalidArgument('transport_profile_invalid', 'the profile ' . $record['name'] . ' has a field of the wrong kind');
            }
        }
        try {
            return CarryState::profile((string) ($record['mode'] ?? ''), $record['challenge'], $record['accept_unchallenged'], $record['lifetime'],
                $record['challenge_window'], $record['clock_trusted'], $record['max_content']);
        } catch (\InvalidArgumentException $error) {
            throw new CodedInvalidArgument('transport_profile_invalid', 'the profile ' . $record['name'] . ' cannot be used: ' . $error->getMessage());
        }
    }

    /** B2: the transport's folder, outside the home and private to the user, one per home and transport. */
    public static function workingFolder(string $home, string $name, bool $make = true): string
    {
        $absolute = realpath($home) ?: $home;
        $h = substr(hash('sha256', $absolute), 0, 8);
        $userHome = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        if (PHP_OS_FAMILY === 'Windows') {
            $base = (getenv('LOCALAPPDATA') ?: $userHome . '\\AppData\\Local') . '\\aamio-transports';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $base = $userHome . '/Library/Application Support/aamio-transports';
        } else {
            $base = (getenv('XDG_STATE_HOME') ?: $userHome . '/.local/state') . '/aamio-transports';
        }
        $path = $base . DIRECTORY_SEPARATOR . $name . '-' . $h;
        if ($make && !is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new CodedRuntime('transport_down', $path . ' could not be made, so the transport ' . $name . ' has no working folder. Check the disk and the permissions there.');
        }

        return $path;
    }

    /** B2: contract 2's short list, AAMIO_ADDON=3 and, unless $declared is false, what the manifest declares, made safe as contract 2 makes it. */
    public static function environmentFor(AddonTransport $manifest, ?array $base = null, bool $declared = true): array
    {
        $env = TransportProcess::environment($declared ? $manifest->environment : [], $base);

        return AddonProcess::childEnvironment($env, PHP_OS_FAMILY === 'Windows');
    }

    /**
     * What enable asks of a transport before anything is recorded: it starts, describes itself as its manifest says, and
     * stops. hello says probe, and a transport then takes nothing of its medium in use (B3). It runs with the short list
     * and none of the variables its manifest asks for, as contract 2's probe does.
     */
    public static function probe(AddonTransport $manifest, string $home): void
    {
        $folder = self::workingFolder($home, $manifest->name);
        $heard = [];
        $process = new TransportProcess($manifest->name, $manifest->version, $manifest->startCommand(), $folder, self::environmentFor($manifest, null, false), null,
            static function (string $line) use (&$heard): void {
                $heard[] = $line;
            });
        try {
            $process->start(Http::VERSION, 'php', $folder, true);
        } catch (TransportFailed $error) {
            $said = $heard === [] ? '' : ' It said: ' . end($heard);
            throw new \RuntimeException('the transport ' . $manifest->name . ' did not start and describe itself as its manifest says: ' . $error->getMessage() . '.' . $said . ' Nothing was recorded; reinstall it, or ' . $manifest->fix . ' to go on without it.');
        } finally {
            $process->stop();
        }
        if ($process->medium !== $manifest->medium) {
            throw new \RuntimeException('the transport ' . $manifest->name . ' describes itself as one over ' . json_encode($process->medium) . ', and its manifest says ' . $manifest->medium . '. Nothing was recorded; reinstall it, or ' . $manifest->fix . ' to go on without it.');
        }
    }

    // ------------------------------------------------------------ the commands --

    private static function partner(Runtime $runtime, string $which): array
    {
        $partner = Codec::isKey($which) ? $runtime->partnerByKey($which) : $runtime->partnerByName($which);
        if ($partner === null) {
            throw new CodedInvalidArgument('unknown_partner', 'unknown partner: ' . $which . '. A transport carries messages between partners: aamio partner add NAME KEY first');
        }

        return $partner;
    }

    private static function service(Runtime $runtime): TransportService
    {
        $service = $runtime->transportService();
        if ($service === null) {
            throw new CodedRuntime('transport_down', 'this runtime has no home of its own, so it has no transports');
        }

        return $service;
    }

    /** aamio transport list: the transports enabled here, the profiles, which partner goes over which, and what was carried. */
    public static function listing(Runtime $runtime): array
    {
        $service = self::service($runtime);
        $enabled = $service->manifests();
        $stored = $service->config->profiles();
        $kept = $service->config->here();
        $addresses = $service->config->addresses();
        $rows = [];
        foreach ($service->config->bindings() as $key => $routes) {
            $partner = $runtime->partnerByKey((string) $key);
            $row = ['partner' => $partner['name'] ?? null, 'key' => (string) $key, 'in_address_book' => $partner !== null, 'routes' => $routes];
            $reached = [];
            foreach ($addresses[(string) $key] ?? [] as $transport => $entry) {
                if (array_filter($routes, static fn (array $route): bool => $route['transport'] === (string) $transport) !== []) {
                    $reached[(string) $transport] = $entry;
                }
            }
            if ($reached !== []) {
                // Where the partner is reached on each transport: given to bind ("you") or shown by a fresh envelope ("heard").
                $row['addresses'] = $reached;
            }
            $rows[] = $row;
        }
        $transports = [];
        foreach ($enabled as $name => $manifest) {
            $partners = [];
            foreach ($rows as $row) {
                if ($row['in_address_book'] && array_filter($row['routes'], static fn (array $route): bool => $route['transport'] === $name) !== []) {
                    $partners[] = $row['partner'] ?? $row['key'];
                }
            }
            $item = ['name' => $name, 'version' => $manifest->version, 'medium' => $manifest->medium, 'permissions' => $manifest->permissions, 'partners' => $partners];
            $said = $kept[$name] ?? null;
            if ($said !== null && $said['medium'] === $manifest->medium) {
                $item['here'] = $said['here'];
                $item['here_at'] = $said['at'];
            } else {
                $item['here'] = null;
                $item['here_fix'] = 'aamio transport here ' . $name . ' starts it for a moment and says where it is reached, for a card';
            }
            $transports[] = $item;
        }
        $profiles = [];
        if (!isset($stored[self::DEFAULT_PROFILE])) {
            $profiles[] = self::profileRecord(self::DEFAULT_PROFILE) + ['stored' => false];
        }
        foreach ($stored as $record) {
            $profiles[] = $record + ['stored' => true];
        }
        $answer = ['transports' => $transports, 'profiles' => $profiles, 'bindings' => $rows, 'outbox' => $service->outbox(), 'pending' => count($service->carry()->pending())];
        $missing = [];
        foreach ($rows as $row) {
            foreach ($row['in_address_book'] ? $row['routes'] : [] as $route) {
                if (!isset($enabled[$route['transport']])) {
                    $missing[$route['transport']] = true;
                }
            }
        }
        $missing = array_keys($missing);
        sort($missing);
        if ($missing !== []) {
            $answer['note'] = implode(', ', $missing) . (count($missing) === 1 ? ' is' : ' are') . ' bound to a partner and not enabled here: aamio addon enable ' . $missing[0] . '.';
        } elseif ($transports === []) {
            $answer['note'] = 'No transport is enabled here. A transport is an add-on: install one, as aisenseapi/aamio-lan, and aamio addon enable lan.';
        }

        return $answer;
    }

    public static function profileAdd(Runtime $runtime, string $name, bool $readable = false, ?int $lifetime = null, ?int $window = null, ?int $maxContent = null): array
    {
        $record = self::profileRecord($name, $readable ? 'readable' : 'sealed', $lifetime ?? CarryState::LIFETIME_DEFAULT, $window ?? CarryState::CHALLENGE_WINDOW_DEFAULT, $maxContent ?? Carry::MAX_CONTENT);
        self::service($runtime)->config->addProfile($record);
        $note = $readable
            ? 'A readable profile signs and does not seal: everyone who hears the medium can read what goes under it. Bind it only where that is meant.'
            : 'Sealed: only the partner can read what goes under it, and everyone who hears the medium sees that it goes and how big it is.';

        return ['profile' => $record, 'note' => $note];
    }

    public static function profileRemove(Runtime $runtime, string $name): array
    {
        self::service($runtime)->config->removeProfile($name);

        return ['removed' => $name];
    }

    /** aamio transport here NAME: where an enabled transport is reached, from the running one or a start of a moment. */
    public static function here(Runtime $runtime, string $name): array
    {
        $service = self::service($runtime);
        $manifests = $service->manifests();
        if (!isset($manifests[$name])) {
            throw new CodedRuntime('transport_unknown', 'no enabled transport is called ' . $name . '. aamio addon list shows what is installed, and aamio addon enable ' . $name . ' lets it run here');
        }
        [$found, $how] = $service->learnHere($name);
        $medium = $manifests[$name]->medium;
        if ($how === 'stopping') {
            return ['transport' => $name, 'medium' => $medium, 'here' => null,
                'note' => 'This runtime is letting go of the home, so ' . $name . ' was not started and nothing was learned. Ask again from the aamio that owns it now.'];
        }
        if ($how === 'starting') {
            return ['transport' => $name, 'medium' => $medium, 'here' => null,
                'note' => $name . ' is starting in this process and has not said it is ready yet: ask again in a moment.'];
        }
        if ($how === 'not_ready') {
            return ['transport' => $name, 'medium' => $medium, 'here' => null,
                'note' => $name . ' did not say it was ready within ' . self::LEARN_SECONDS . ' seconds, as when its medium is off or another program holds it, so nothing was learned. '
                    . 'The lines it wrote above say why.'];
        }
        // Nothing said and an empty list say the same: a card names the medium alone, and there is no address to name.
        if ($found === null || $found === []) {
            return ['transport' => $name, 'medium' => $medium, 'here' => $found,
                'note' => $name . ' says nothing of where it is reached: a version from before here (contract 3, B3), or a medium without addresses. '
                    . 'A card can name the medium ' . $medium . ' without an address.'];
        }
        $started = $how === 'running' ? 'It runs, and said so itself.' : 'It was started for a moment to say this, and stopped again.';

        return ['transport' => $name, 'medium' => $medium, 'here' => $found,
            'note' => $started . ' ' . implode(', ', $found) . ' is kept in the home for cards: over MCP an Earshot card that names ' . $medium . ' carries it by itself, and on the command line '
                . 'aamio addon earshot card ADDRESS --via ' . $found[0] . ' --key KEY does. A partner binds it with aamio transport bind NAME TRANSPORT --address ' . $found[0] . '.'];
    }

    /** A partner bound to a transport, or the address it is reached at there, for a partner bound to it already. */
    public static function bind(Runtime $runtime, string $which, string $transport, ?string $profile = null, ?string $address = null): array
    {
        $partner = self::partner($runtime, $which);
        $service = self::service($runtime);
        $manifests = $service->manifests();
        if (!isset($manifests[$transport])) {
            throw new CodedRuntime('transport_unknown', 'no enabled transport is called ' . $transport . '. aamio addon list shows what is installed, and aamio addon enable ' . $transport . ' lets it run here');
        }
        $medium = $manifests[$transport]->medium;
        if ($address !== null && TransportProcess::hereOf([$address], $medium) === null) {
            throw new CodedInvalidArgument('transport_address_invalid', 'an address on ' . $transport . ' is ' . $medium . ': and then where the partner is reached, as its card or aamio transport here on its machine '
                . 'says it, at most 128 characters of printable ASCII without spaces');
        }
        $bound = null;
        foreach ($service->config->routes($partner['key']) as $route) {
            if ($route['transport'] === $transport) {
                $bound = $route;
                break;
            }
        }
        if ($bound !== null && ($address === null || ($profile !== null && $profile !== $bound['profile']))) {
            throw new CodedInvalidArgument('transport_already_bound', $transport . ' is bound to that partner already' . ($address !== null
                ? ', under the profile ' . $bound['profile'] . ': aamio transport unbind first to bind it under another'
                : '. With --address, bind says where the partner is reached on it'));
        }
        $routes = $bound !== null ? $service->config->routes($partner['key']) : $service->config->bind($partner['key'], $transport, $profile ?? self::DEFAULT_PROFILE);
        $answer = ['partner' => $partner['name'], 'key' => $partner['key'], 'routes' => $routes];
        if ($address !== null) {
            $service->config->setAddress($partner['key'], $transport, $address, 'you', time());
            $answer['address'] = $address;
        }
        if ($bound !== null) {
            $answer['note'] = 'The first ask to ' . $partner['name'] . ' on ' . $transport . ' goes to ' . $address . ' from now on; when it stops answering there, every second ask goes to nobody in particular, '
                . 'and a fresh envelope from ' . $partner['name'] . ' moves the address to where it came from.';

            return $answer;
        }
        $reached = $address !== null ? ' The first ask goes to ' . $address . '; when it stops answering there, every second ask goes to nobody in particular, and a fresh envelope from ' . $partner['name']
            . ' moves the address to where it came from.' : '';
        $first = $routes[0]['transport'];
        $answer['note'] = 'Messages to ' . $partner['name'] . ' go over ' . $first . ' from now on, and never over HTTPS instead, and ' . $first . ' takes messages from ' . $partner['name'] . '.' . $reached . ' '
            . $partner['name'] . ' binds your key to ' . $transport . ' the same way. Only the process that owns this home runs the transport: aamio serve, or aamio send and aamio read --wait while they run.';

        return $answer;
    }

    public static function unbind(Runtime $runtime, string $which, ?string $transport = null): array
    {
        $partner = self::partner($runtime, $which);
        $routes = self::service($runtime)->config->unbind($partner['key'], $transport);

        return ['partner' => $partner['name'], 'key' => $partner['key'], 'routes' => $routes,
            'note' => $routes !== [] ? 'Messages to ' . $partner['name'] . ' go over ' . $routes[0]['transport'] . '.'
                : 'Messages to ' . $partner['name'] . ' go over HTTPS again. What came over a transport and was not read is still read.'];
    }
}
