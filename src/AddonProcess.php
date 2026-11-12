<?php

declare(strict_types=1);

namespace Aamio;

/**
 * Add-ons on contract 2: a manifest the core reads without running the add-on,
 * and a process of the add-on's own per call.
 *
 * An add-on on contract 1 is a file required into this process with the runtime
 * in its hands (Addons). On contract 2 the package carries aamio-addon.json,
 * which says what the add-on offers, and a PHP file the core runs as a child
 * process for each call: one JSON request on its standard input, one JSON answer
 * on its standard output, and a deadline. The core keeps what is the core's: the
 * key, the home, the address book, the messages this process verified, and the
 * user's consent to the websites an add-on may read. The add-on gets the call's
 * arguments, a subject the core resolved to a key, a directory of its own, and a
 * short list of environment variables rather than the user's. It is not a
 * sandbox: the process runs as the same user, with the user's own file
 * permissions, and reads what the user reads. What it gives is that the core
 * never sends the home's key, only the public key a call asks for, that nothing
 * an add-on answers changes state here, that an add-on that hangs or crashes
 * does not take the runtime with it, and that list and help run none of the
 * add-on's code: enable starts it once, before the choice is recorded, to see
 * that it answers as its manifest says. It is for add-ons the user trusts. The
 * same as aamio-python's addon_process.py, and written up on
 * https://aamio.at/docs/addons.
 */
final class AddonProcess
{
    public const MANIFEST_FILE = 'aamio-addon.json';
    public const KNOWN_CONTRACTS = [1, 2];
    public const DEADLINE_DEFAULT = 30;
    public const DEADLINE_MAX = 120;
    public const PROBE_DEADLINE = 20;
    public const MAX_AT_ONCE = 2;
    public const MAX_PER_WINDOW = 20;
    public const WINDOW_SECONDS = 60.0;
    /** The most an answer may be, and the most standard error may carry: a process that streams past either is stopped while it runs, not read to the end. Properties, so a test can lower them. */
    public const MAX_ANSWER_BYTES = 1000000;
    public const MAX_STDERR_BYTES = 65536;
    public static int $maxAnswerBytes = self::MAX_ANSWER_BYTES;
    public static int $maxStderrBytes = self::MAX_STDERR_BYTES;
    /** How long a stopped process gets to go away before it is given up on. */
    public const STOP_WAIT = 5.0;
    /** Names an add-on may not ask for in its environment list. */
    public const NEVER_PASSED = ['AAMIO_HOME', 'AAMIO_ADDONS', 'AAMIO_ADDON'];
    /** The consent list lives beside the add-on's own directory, not in it: the add-on's process must not be able to widen what it may read. */
    public const HOSTS_SUFFIX = '.hosts.json';
    public const HOST_COMMANDS = ['allow', 'forget', 'list'];
    /** Commands of aamio addon itself, which no add-on may be called. */
    public const RESERVED = ['list', 'enable', 'disable', 'run'];
    /** The question a tool asks, under this name in inputRequests, for a website that is not on the user's list. */
    public const HOST_QUESTION = 'aamio_addon_host';
    /** The global options of the command line, which an add-on's command never sees as its own. */
    public const GLOBAL_OPTIONS = ['home', 'host', 'tags', 'no-archive', 'anchor', 'help'];
    /**
     * What the process gets of the environment: what the interpreter, the path, a
     * temporary directory, the locale, TLS roots, a proxy and PHP's own ini need,
     * and nothing else. A token in the user's environment is not the add-on's to read,
     * unless its manifest names it, and then aamio addon list says so before it is enabled.
     */
    public const ENVIRONMENT = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'TMPDIR',
        'HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA',
        'LANG', 'LANGUAGE', 'LC_ALL', 'LC_CTYPE', 'TZ',
        'PHPRC', 'PHP_INI_SCAN_DIR', 'COMPOSER_HOME', 'COMPOSER_VENDOR_DIR',
        'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE',
        'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY',
    ];

    /** @var array<string, array{at_once: int, started: list<float>}> */
    private static array $limits = [];

    // ------------------------------------------------------------------ manifest

    /**
     * The add-on's manifest, read without running it, or null for a package that
     * has none and so is on contract 1. composer.json may say the contract under
     * extra.aamio-addon.contract; 2 without a manifest, or a number this aamio does
     * not know, is refused with the reason.
     */
    public static function readManifest(string $dir, ?string $package, string $fix): ?AddonManifest
    {
        $dir = rtrim($dir, '/\\');
        $path = $dir . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $declared = self::declaredContract($dir);
        $label = $package ?? $dir;
        if (!is_file($path)) {
            if ($declared !== null && $declared !== 1) {
                throw new \RuntimeException('the add-on ' . $label . ' says it is written for contract ' . json_encode($declared) . ' of aamio\'s add-ons and carries no ' . self::MANIFEST_FILE . ', which contract 2 and later have. This aamio knows ' . implode(' and ', self::KNOWN_CONTRACTS) . '. Reinstall it, or ' . $fix . ' to go on without it.');
            }

            return null;
        }
        $text = @file_get_contents($path);
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('the add-on ' . $label . ' has a manifest, ' . $path . ', that cannot be read as JSON. Reinstall it, or ' . $fix . ' to go on without it.');
        }
        $number = $data['aamio_addon'] ?? null;
        if ($number === 2) {
            return new AddonManifest($package, $dir, $path, $data, $fix);
        }
        throw new \RuntimeException('the add-on ' . $label . ' is written for contract ' . json_encode($number) . ' of aamio\'s add-ons, and this aamio (' . Http::VERSION . ') knows ' . implode(' and ', self::KNOWN_CONTRACTS) . '. Upgrade aamio, or install a version of the add-on for this one; ' . $fix . ' goes on without it.');
    }

    /** What composer.json says under extra.aamio-addon.contract, or null when it says nothing. */
    public static function declaredContract(string $dir): mixed
    {
        $text = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'composer.json');
        $data = is_string($text) ? json_decode($text, true) : null;

        return is_array($data) ? ($data['extra']['aamio-addon']['contract'] ?? null) : null;
    }

    public static function dest(string $name): string
    {
        return str_replace('-', '_', ltrim($name, '-'));
    }

    /** @param array<string, mixed> $spec */
    public static function usageLine(string $name, array $spec): string
    {
        $words = [$name];
        foreach ($spec['arguments'] ?? [] as $argument) {
            $word = str_starts_with($argument['name'], '-') ? $argument['name'] . ' ' . strtoupper(self::dest($argument['name'])) : strtoupper($argument['name']);
            $words[] = ($argument['required'] ?? false) || !str_starts_with($argument['name'], '-') ? $word : '[' . $word . ']';
        }

        return implode(' ', $words) . ': ' . ($spec['help'] ?? '');
    }

    public static function usage(AddonManifest $manifest): string
    {
        $lines = ['aamio addon ' . $manifest->name . ' ' . implode(' | ', array_keys($manifest->commands()))];
        foreach ($manifest->commands() as $line) {
            $lines[] = '  ' . $line;
        }
        if ($manifest->description !== null) {
            $lines[] = '';
            $lines[] = $manifest->description;
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------- hosts

    /**
     * The exact host a list is read from, in lowercase, or AddonRefused.
     *
     * Plain ASCII names of two labels or more. No port, path, IP literal, trailing
     * dot, underscore or space is trimmed into shape: anything but a host name is
     * refused. www.example.org and example.org are different places.
     */
    public static function normaliseHost(mixed $value): string
    {
        $refused = new AddonRefused('invalid_host', 'A website is a plain host name of two labels or more, as example.org, with no https://, port or path. www.example.org and example.org are different places. Internationalised names are not read yet.');
        if (!is_string($value) || preg_match('/^[\x00-\x7F]*$/', $value) !== 1) {
            throw $refused;
        }
        $host = strtolower($value);
        $labels = explode('.', $host);
        if (strlen($host) < 1 || strlen($host) > 253 || count($labels) < 2) {
            throw $refused;
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1 || str_starts_with($label, 'xn--')) {
                throw $refused;
            }
        }
        if (preg_match('/^[0-9]+$/', $labels[count($labels) - 1]) === 1 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw $refused;
        }

        return $host;
    }

    public static function isSameHost(mixed $typed, string $host): bool
    {
        try {
            return self::normaliseHost($typed) === $host;
        } catch (AddonRefused) {
            return false;
        }
    }

    public static function hostsFile(Runtime $runtime, AddonManifest $manifest): string
    {
        return $runtime->home . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . $manifest->name . self::HOSTS_SUFFIX;
    }

    private static function unreadable(string $path, AddonManifest $manifest): AddonRefused
    {
        return new AddonRefused('hosts_unreadable', $path . ' could not be read as a list of websites, so no website is read. Repair the file, or move it away and add the websites again with aamio addon ' . $manifest->name . ' allow.');
    }

    /** @return list<string>|null the rows, or null when the file is not there */
    private static function readHosts(string $path, AddonManifest $manifest): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $text = @file_get_contents($path);
        $rows = is_string($text) ? json_decode(Storage::pastMark($text), true) : null;
        if (!is_array($rows) || array_keys($rows) !== range(0, count($rows) - 1) && $rows !== []) {
            throw self::unreadable($path, $manifest);
        }
        foreach ($rows as $row) {
            try {
                if (self::normaliseHost($row) !== $row) {
                    throw self::unreadable($path, $manifest);
                }
            } catch (AddonRefused) {
                throw self::unreadable($path, $manifest);
            }
        }

        return array_values($rows);
    }

    /** @param list<string> $rows */
    private static function writeHosts(Runtime $runtime, AddonManifest $manifest, array $rows): void
    {
        $folder = $runtime->home . DIRECTORY_SEPARATOR . 'addons';
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new AddonRefused('hosts_unwritable', self::hostsFile($runtime, $manifest) . ' could not be written, so the list is as it was. Check the disk and the home\'s permissions.');
        }
        try {
            $runtime->saveJson('addons' . DIRECTORY_SEPARATOR . $manifest->name . self::HOSTS_SUFFIX, array_values($rows));
        } catch (\Throwable) {
            throw new AddonRefused('hosts_unwritable', self::hostsFile($runtime, $manifest) . ' could not be written, so the list is as it was. Check the disk and the home\'s permissions.');
        }
    }

    /**
     * The websites the user has agreed to for this add-on, from the file as it is
     * now. A file that cannot be read is refused, never an empty list. When the
     * list does not exist yet and the manifest names a file an earlier version
     * kept in the home, that file is read once and becomes the list.
     *
     * @return list<string>
     */
    public static function allowedHosts(Runtime $runtime, AddonManifest $manifest): array
    {
        $rows = self::readHosts(self::hostsFile($runtime, $manifest), $manifest);
        if ($rows !== null) {
            return $rows;
        }
        $legacy = $manifest->hosts['legacy_file'] ?? null;
        if (is_string($legacy) && $legacy !== '') {
            $rows = self::readHosts($runtime->home . DIRECTORY_SEPARATOR . $legacy, $manifest);
            if ($rows !== null && $rows !== []) {
                self::writeHosts($runtime, $manifest, $rows);

                return $rows;
            }
        }

        return [];
    }

    /** @return array{host: string, added: bool, hosts: list<string>} */
    public static function allowHost(Runtime $runtime, AddonManifest $manifest, mixed $value): array
    {
        $host = self::normaliseHost($value);
        $rows = self::allowedHosts($runtime, $manifest);
        $added = !in_array($host, $rows, true);
        if ($added) {
            $rows[] = $host;
            self::writeHosts($runtime, $manifest, $rows);
        }

        return ['host' => $host, 'added' => $added, 'hosts' => $rows];
    }

    /** @return array{host: string, removed: bool, hosts: list<string>} */
    public static function forgetHost(Runtime $runtime, AddonManifest $manifest, mixed $value): array
    {
        $host = self::normaliseHost($value);
        $rows = self::allowedHosts($runtime, $manifest);
        $removed = in_array($host, $rows, true);
        $kept = array_values(array_filter($rows, static fn (string $row): bool => $row !== $host));
        if ($removed) {
            self::writeHosts($runtime, $manifest, $kept);
        }

        return ['host' => $host, 'removed' => $removed, 'hosts' => $kept];
    }

    public static function notAllowed(AddonManifest $manifest, string $host): AddonRefused
    {
        $tool = null;
        foreach ($manifest->toolSpecs as $spec) {
            if (isset($spec['host'])) {
                $tool = $spec['name'];
                break;
            }
        }

        return new AddonRefused('host_not_allowed', 'This runtime reads only websites the user has put on its list, and this one is not there. The user adds it with aamio addon ' . $manifest->name . ' allow ' . $host . ', from their own records and never from the message being checked.' . ($tool !== null ? ' Over MCP ' . $tool . ' asks them.' : ''));
    }

    // ------------------------------------------------------------------ subject

    /**
     * The key to check and where it came from, resolved by the core: a partner in
     * the address book, or the signer of a message this process verified.
     *
     * @return array<string, mixed>
     */
    public static function subjectFor(Runtime $runtime, string $source, mixed $value): array
    {
        if ($source === 'partner') {
            if (!is_string($value) || $value === '') {
                throw new AddonRefused('invalid_partner', 'partner is a name in the address book.');
            }
            $row = $runtime->partnerByName($value);
            if ($row === null || !is_string($row['key'] ?? null) || preg_match('/^[A-Za-z0-9_-]{43}$/', $row['key']) !== 1) {
                throw new AddonRefused('partner_unavailable', 'No partner by that name with a usable key is in the address book. aamio_partners or aamio partner list shows who is.');
            }

            return ['key' => $row['key'], 'subject_source' => 'partner', 'partner' => $row['name'], 'signature_checked_locally' => null];
        }
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new AddonRefused('invalid_message_hash', 'message is the sha256 of a message as read shows it: 64 lowercase hexadecimal characters.');
        }
        $keys = $runtime->verifiedSigners($value);
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{43}$/', $key) !== 1) {
                throw new AddonRefused('invalid_local_observation', 'The message is recorded here without a usable signing key, so it cannot be checked.');
            }
        }
        if ($keys === []) {
            throw new AddonRefused('source_unavailable', 'This process holds no verified message with that hash. Only a message this process read itself and whose signature checked out can be used: an unsigned one has no key to check, and what an earlier process read is not kept and cannot be read again. Check a later message from the same sender, or the partner by name.');
        }
        if (count($keys) !== 1) {
            throw new AddonRefused('ambiguous_signer', 'Different keys signed the same bytes, so the hash does not say which one to check. Check the partner by name.');
        }

        return ['key' => $keys[0], 'subject_source' => 'message', 'message_sha256' => $value, 'signature_checked_locally' => true];
    }

    /** @return array<string, mixed> */
    public static function refusal(AddonRefused $refused, ?string $host = null, ?array $subject = null): array
    {
        $answer = ['status' => $refused->status, 'reason' => $refused->reason, 'fix' => $refused->fix];
        if ($host !== null) {
            $answer['host'] = $host;
        }
        if ($subject !== null) {
            foreach ($subject as $field => $value) {
                if ($field !== 'key' && $value !== null) {
                    $answer[$field] = $value;
                }
            }
        }

        return $answer;
    }

    // ------------------------------------------------------------------ process

    /** A place for one call of this add-on, or the reason there is none. */
    private static function take(string $name): ?string
    {
        $now = microtime(true);
        $limits = self::$limits[$name] ?? ['at_once' => 0, 'started' => []];
        $limits['started'] = array_values(array_filter($limits['started'], static fn (float $when): bool => $now - $when <= self::WINDOW_SECONDS));
        if ($limits['at_once'] >= self::MAX_AT_ONCE) {
            self::$limits[$name] = $limits;

            return 'addon_busy';
        }
        if (count($limits['started']) >= self::MAX_PER_WINDOW) {
            self::$limits[$name] = $limits;

            return 'addon_rate';
        }
        $limits['at_once']++;
        $limits['started'][] = $now;
        self::$limits[$name] = $limits;

        return null;
    }

    private static function giveBack(string $name): void
    {
        if (isset(self::$limits[$name])) {
            self::$limits[$name]['at_once'] = max(0, self::$limits[$name]['at_once'] - 1);
        }
    }

    /** Forget the limits, for tests. */
    public static function resetLimits(): void
    {
        self::$limits = [];
    }

    /** @return array<string, mixed> */
    private static function failure(AddonManifest $manifest, string $reason, string $fix): array
    {
        return ['result' => ['status' => 'unreachable', 'reason' => $reason, 'fix' => $fix, 'addon' => $manifest->name], 'exit_code' => 5, 'is_error' => true];
    }

    /**
     * One call to the add-on's process: the request on its standard input, its
     * answer from standard output, within the deadline. The streams are files of
     * the call's own, so this works the same on every platform, and the deadline
     * is kept by watching the process. $stderr is given what the process wrote to
     * standard error, or null drops it. The answer is always an array with result
     * or raw, exit_code and is_error.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function run(AddonManifest $manifest, string $kind, string $name, array $arguments, ?array $subject = null, ?string $ownKey = null, ?string $stateDir = null, ?callable $stderr = null, ?int $deadline = null, bool $declared = true): array
    {
        $deadline ??= $manifest->deadline;
        $request = [
            'aamio_addon' => 2,
            'kind' => $kind,
            'name' => $name,
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
            'subject' => $subject,
            'own_key' => $ownKey,
            'state_dir' => $stateDir,
            'deadline_seconds' => $deadline,
            'aamio' => ['version' => Http::VERSION, 'runtime' => 'php'],
        ];
        $script = $manifest->dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $manifest->script);
        $stop = 'Reinstall it, or aamio addon disable ' . $manifest->name . ' to go on without it.';
        if (!is_file($script)) {
            return self::failure($manifest, 'addon_failed', 'the process of the add-on ' . $manifest->name . ' is not there: ' . $script . '. ' . $stop);
        }
        // The request, the answer and standard error go through files in a
        // directory of this call's own, private from the start: a pipe would
        // block a request the process does not read, select does not work on
        // pipes everywhere, and the files' sizes are watched while it runs.
        $folder = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'aamio-addon-' . bin2hex(random_bytes(8));
        $files = ['in' => $folder . DIRECTORY_SEPARATOR . 'in', 'out' => $folder . DIRECTORY_SEPARATOR . 'out', 'err' => $folder . DIRECTORY_SEPARATOR . 'err'];
        $clean = static function () use ($folder, $files): void {
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($folder);
        };
        $mask = umask(0077);
        try {
            $made = @mkdir($folder, 0700) && file_put_contents($files['in'], json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false && file_put_contents($files['out'], '') !== false && file_put_contents($files['err'], '') !== false;
        } finally {
            umask($mask);
        }
        if (!$made) {
            $clean();

            return self::failure($manifest, 'addon_failed', 'the request to the add-on ' . $manifest->name . ' could not be written to a private directory of its own. Check the temporary directory.');
        }
        // What the process gets of the environment: the short list, and what
        // its manifest declared it reads, when set and when this is a call and
        // not the probe at enable. Never the home or the rest.
        $environment = ['AAMIO_ADDON' => '2'];
        foreach (getenv() as $key => $value) {
            if (in_array(strtoupper((string) $key), self::ENVIRONMENT, true) || ($declared && in_array((string) $key, $manifest->environment, true))) {
                $environment[$key] = $value;
            }
        }
        $pipes = [];
        $process = @proc_open([PHP_BINARY, $script], [0 => ['file', $files['in'], 'r'], 1 => ['file', $files['out'], 'w'], 2 => ['file', $files['err'], 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            $clean();

            return self::failure($manifest, 'addon_failed', 'the process of the add-on ' . $manifest->name . ' could not be started. ' . $stop);
        }
        $until = microtime(true) + $deadline;
        $stopped = null;
        $exit = null;
        while (true) {
            $status = proc_get_status($process);
            if ($status['running'] !== true) {
                $exit = $status['exitcode'];
                break;
            }
            clearstatcache(true, $files['out']);
            clearstatcache(true, $files['err']);
            if ((int) @filesize($files['out']) > self::$maxAnswerBytes) {
                $stopped = ['addon_failed', 'the add-on ' . $manifest->name . ' wrote more than the ' . self::$maxAnswerBytes . ' bytes an answer may be, and was stopped. ' . $stop];
            } elseif ((int) @filesize($files['err']) > self::$maxStderrBytes) {
                $stopped = ['addon_failed', 'the add-on ' . $manifest->name . ' wrote more than ' . self::$maxStderrBytes . ' bytes to standard error, and was stopped. ' . $stop];
            } elseif (microtime(true) > $until) {
                $stopped = ['addon_timeout', 'the add-on ' . $manifest->name . ' did not answer within ' . $deadline . ' seconds and was stopped. Nothing else here was affected. Try again, or run the command by hand to see what it does.'];
            }
            if ($stopped !== null) {
                self::stop($process);
                break;
            }
            usleep(20000);
        }
        proc_close($process);
        if ($stopped !== null) {
            $clean();

            return self::failure($manifest, $stopped[0], $stopped[1]);
        }
        $out = (string) @file_get_contents($files['out'], false, null, 0, self::$maxAnswerBytes + 1);
        $err = (string) @file_get_contents($files['err'], false, null, 0, self::$maxStderrBytes + 1);
        $clean();
        // The same limits whether the process was slow or had exited before the
        // next look: past either, the answer is refused and nothing is relayed.
        if (strlen($out) > self::$maxAnswerBytes) {
            return self::failure($manifest, 'addon_failed', 'the add-on ' . $manifest->name . ' answered with more than the ' . self::$maxAnswerBytes . ' bytes an answer may be. ' . $stop);
        }
        if (strlen($err) > self::$maxStderrBytes) {
            return self::failure($manifest, 'addon_failed', 'the add-on ' . $manifest->name . ' wrote more than ' . self::$maxStderrBytes . ' bytes to standard error. ' . $stop);
        }
        if ($stderr !== null && $err !== '') {
            $stderr($err);
        }
        $answer = json_decode($out, true);
        if (!is_array($answer) || array_is_list($answer) && $answer !== []) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $err)), static fn (string $line): bool => $line !== ''));
            $said = $lines !== [] ? ': ' . substr($lines[count($lines) - 1], 0, 200) : '';

            return self::failure($manifest, 'addon_failed', 'the add-on ' . $manifest->name . ' exited with code ' . json_encode($exit) . ' and gave no answer this aamio can read' . $said . '. ' . $stop);
        }
        $code = $answer['exit_code'] ?? ($exit === 0 ? 0 : $exit);
        if (!is_int($code) || $code < 0 || $code > 255) {
            $code = 1;
        }
        $normal = ['exit_code' => $code, 'is_error' => (bool) ($answer['is_error'] ?? ($code !== 0))];
        if (is_string($answer['raw'] ?? null)) {
            $raw = base64_decode($answer['raw'], true);
            if ($raw === false) {
                return self::failure($manifest, 'addon_failed', 'the add-on ' . $manifest->name . ' answered with raw bytes that are not base64. Reinstall it, or aamio addon disable ' . $manifest->name . ' to go on without it.');
            }
            $normal['raw'] = $raw;
        } else {
            $normal['result'] = $answer['result'] ?? null;
        }
        if (is_string($answer['stderr'] ?? null) && $answer['stderr'] !== '' && $stderr !== null) {
            $stderr(str_ends_with($answer['stderr'], "\n") ? $answer['stderr'] : $answer['stderr'] . "\n");
        }

        return $normal;
    }

    /** Kill the process and give it a moment to go away, so the files it holds can be removed; after that it is left to the system. */
    private static function stop($process): void
    {
        proc_terminate($process, 9);
        $until = microtime(true) + self::STOP_WAIT;
        while (microtime(true) < $until && proc_get_status($process)['running'] === true) {
            usleep(20000);
        }
        if (proc_get_status($process)['running'] === true) {
            proc_terminate($process);
        }
    }

    /**
     * What enable asks before anything is recorded: the process starts, speaks
     * the protocol, and is the add-on the manifest says. It runs with the short
     * list of environment variables and none the manifest asks for: the user
     * sees those names in aamio addon list before anything of the add-on has
     * run, and the first call is what gets them.
     */
    public static function probe(AddonManifest $manifest): void
    {
        $answer = self::run($manifest, 'probe', 'probe', [], null, null, null, null, min($manifest->deadline, self::PROBE_DEADLINE), false);
        $said = $answer['result'] ?? null;
        if (!is_array($said) || ($said['ok'] ?? null) !== true) {
            $reason = is_array($said) ? ($said['reason'] ?? null) : null;
            $detail = is_array($said) && in_array($reason, ['addon_timeout', 'addon_failed'], true) ? (string) ($said['fix'] ?? '') : 'it answered ' . substr(json_encode($said), 0, 200);
            throw new \RuntimeException('the add-on ' . $manifest->name . ' did not answer the probe: ' . $detail);
        }
        if (($said['aamio_addon'] ?? null) !== 2 || ($said['name'] ?? null) !== $manifest->name || ($said['version'] ?? null) !== $manifest->version) {
            throw new \RuntimeException('the add-on ' . $manifest->name . ' answered the probe as ' . json_encode($said['name'] ?? null) . ' ' . json_encode($said['version'] ?? null) . ' on contract ' . json_encode($said['aamio_addon'] ?? null) . ', and its manifest says ' . $manifest->name . ' ' . $manifest->version . ' on contract 2. Reinstall it, or ' . $manifest->fix . ' to go on without it.');
        }
    }

    /**
     * What enable says of an add-on on contract 2: the same words as aamio-python and https://aamio.at/docs/addons.
     * $probed is whether the probe ran now; it does not for an add-on that was enabled already.
     *
     * @param list<string> $environment
     */
    public static function enableNote(string $package, array $environment, bool $probed = true): string
    {
        $asked = $environment === [] ? '' : ', and ' . implode(', ', $environment) . ', which its manifest asks for';
        $ran = $probed
            ? 'enable started it once just now, before the choice was recorded, to see that it answers as its manifest says.'
            : 'It was enabled already, and nothing of it ran now.';

        return $package . ' runs here from now on, in a process of its own for each call. ' . $ran . ' '
            . 'A call gets its arguments, a key aamio resolved for it, the runtime\'s public key where a '
            . 'command asks for it, a directory of its own under the home, and a short list of environment variables: the path, a temporary directory, '
            . 'the locale, the interpreter\'s own settings, TLS roots and the proxy variables, which can carry credentials of their own' . $asked . ', and never the '
            . 'home\'s key or the rest of your environment. Nothing it answers changes state in aamio. It runs with your own file permissions.';
    }

    /** A directory of the add-on's own under the home, made on first use, private to the user. */
    public static function stateDir(Runtime $runtime, AddonManifest $manifest): string
    {
        $path = $runtime->home . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . $manifest->name;
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }

        return $path;
    }

    /**
     * The add-on's own directory for this call, or the answer that says it
     * could not be made. A home whose disk is full, or with a file in the way
     * of addons/, stops the call before the process starts, as an answer like
     * any other. The call used to go on, and the add-on was told of a
     * directory that was not there (a check of 12 November 2026, found while
     * the Node runtime's add-ons were built).
     *
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private static function ownDirectory(Runtime $runtime, AddonManifest $manifest): array
    {
        $path = self::stateDir($runtime, $manifest);
        if (is_dir($path)) {
            return [$path, null];
        }

        return [null, self::failure($manifest, 'addon_failed', 'the add-on ' . $manifest->name . ' has no directory of its own: addons/' . $manifest->name . ' in the home could not be made. Nothing of the add-on ran. Check the disk and the home\'s permissions.')];
    }

    // ------------------------------------------------------------- the command line

    private static function out(mixed $value): void
    {
        fwrite(STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * The command and its arguments from the words and options the command line
     * gave, bound by the manifest, or a usage error.
     *
     * @param list<string> $args the words after aamio addon NAME
     * @param array<string, mixed> $opts
     * @return array{0: string, 1: array<string, mixed>}|int
     */
    private static function bind(AddonManifest $manifest, array $args, array $opts): array|int
    {
        $name = (string) ($args[0] ?? '');
        $spec = $manifest->commands[$name] ?? null;
        if ($spec === null) {
            fwrite(STDERR, self::usage($manifest) . "\n");

            return 2;
        }
        $usage = 'aamio addon ' . $manifest->name . ' ' . self::usageLine($name, $spec);
        $known = self::GLOBAL_OPTIONS;
        $arguments = [];
        $positional = array_slice($args, 1);
        foreach ($spec['arguments'] ?? [] as $argument) {
            $dest = self::dest($argument['name']);
            if (str_starts_with($argument['name'], '-')) {
                $option = substr($argument['name'], 2);
                $known[] = $option;
                $value = $opts[$option] ?? null;
                if (($argument['required'] ?? false) && (!is_string($value) || $value === '')) {
                    fwrite(STDERR, $argument['name'] . " needs a value: " . $usage . "\n");

                    return 2;
                }
                if ($value !== null && !is_string($value)) {
                    fwrite(STDERR, $argument['name'] . " needs a value: " . $usage . "\n");

                    return 2;
                }
                $arguments[$dest] = $value;
            } else {
                if ($positional === []) {
                    fwrite(STDERR, 'the following arguments are required: ' . $argument['name'] . ". " . $usage . "\n");

                    return 2;
                }
                $arguments[$dest] = array_shift($positional);
            }
        }
        if ($positional !== []) {
            fwrite(STDERR, 'unrecognized arguments: ' . implode(' ', $positional) . ". " . $usage . "\n");

            return 2;
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($opts)), $known));
        if ($unknown !== []) {
            fwrite(STDERR, 'unrecognized arguments: --' . implode(' --', $unknown) . ". " . $usage . "\n");

            return 2;
        }

        return [$name, $arguments];
    }

    /** A command that runs without a home: the process is started all the same, with no state directory. Help is help. */
    public static function early(AddonManifest $manifest, array $args, array $opts): ?int
    {
        $name = $args[0] ?? null;
        if (isset($opts['help'])) {
            fwrite(STDOUT, self::usage($manifest) . "\n");

            return 0;
        }
        if ($name === null || $name === '') {
            fwrite(STDERR, self::usage($manifest) . "\n");

            return 2;
        }
        if ($manifest->hosts !== null && in_array($name, self::HOST_COMMANDS, true)) {
            return null;
        }
        $spec = $manifest->commands[$name] ?? null;
        if ($spec === null) {
            fwrite(STDERR, self::usage($manifest) . "\n");

            return 2;
        }
        if (($spec['runtime'] ?? true) !== false) {
            return null;
        }
        $bound = self::bind($manifest, $args, $opts);
        if (is_int($bound)) {
            return $bound;
        }

        return self::runCommand($manifest, $bound[0], $spec, $bound[1], null);
    }

    public static function command(AddonManifest $manifest, array $args, array $opts, Runtime $runtime): int
    {
        $name = (string) ($args[0] ?? '');
        if ($manifest->hosts !== null && in_array($name, self::HOST_COMMANDS, true)) {
            try {
                if ($name === 'list') {
                    if (count($args) !== 1) {
                        fwrite(STDERR, 'aamio addon ' . $manifest->name . " list\n");

                        return 2;
                    }
                    self::out(['hosts' => self::allowedHosts($runtime, $manifest)]);
                } else {
                    if (count($args) !== 2) {
                        fwrite(STDERR, 'aamio addon ' . $manifest->name . ' ' . $name . " HOST\n");

                        return 2;
                    }
                    if ($name === 'allow') {
                        self::out(self::allowHost($runtime, $manifest, $args[1]) + ['note' => 'This runtime may now read this website for the add-on ' . $manifest->name . ' when a call asks for it. Nothing was read now.']);
                    } else {
                        self::out(self::forgetHost($runtime, $manifest, $args[1]));
                    }
                }
            } catch (AddonRefused $refused) {
                self::out(self::refusal($refused));

                return 2;
            }

            return 0;
        }
        $bound = self::bind($manifest, $args, $opts);
        if (is_int($bound)) {
            return $bound;
        }

        return self::runCommand($manifest, $bound[0], $manifest->commands[$bound[0]], $bound[1], $runtime);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $arguments
     */
    private static function runCommand(AddonManifest $manifest, string $name, array $spec, array $arguments, ?Runtime $runtime): int
    {
        $subject = null;
        $ownKey = null;
        $host = null;
        try {
            foreach ($spec['arguments'] ?? [] as $argument) {
                $dest = self::dest($argument['name']);
                if (($argument['host'] ?? false) === true && $arguments[$dest] !== null && $runtime !== null) {
                    $host = $arguments[$dest] = self::normaliseHost($arguments[$dest]);
                    if (!in_array($host, self::allowedHosts($runtime, $manifest), true)) {
                        throw self::notAllowed($manifest, $host);
                    }
                }
                if (isset($argument['subject']) && $arguments[$dest] !== null && $runtime !== null) {
                    $subject = self::subjectFor($runtime, $argument['subject'], $arguments[$dest]);
                }
            }
            if (in_array('own_key', $spec['needs'] ?? [], true) && $runtime !== null) {
                $ownKey = $runtime->keys->public;
            }
        } catch (AddonRefused $refused) {
            self::out(self::refusal($refused, $host, $subject));

            return 2;
        }
        [$directory, $noDirectory] = $runtime !== null ? self::ownDirectory($runtime, $manifest) : [null, null];
        if ($noDirectory !== null) {
            self::out($noDirectory['result']);

            return $noDirectory['exit_code'];
        }
        $busy = self::take($manifest->name);
        if ($busy !== null) {
            self::out(self::refusal(new AddonRefused($busy, 'At most ' . self::MAX_AT_ONCE . ' calls of the add-on ' . $manifest->name . ' run at once and ' . self::MAX_PER_WINDOW . ' start per minute here. Nothing was done. Try again shortly.', 'unreachable'), $host));

            return 5;
        }
        try {
            $answer = self::run($manifest, 'command', $name, $arguments, $subject, $ownKey, $directory, static function (string $text): void {
                fwrite(STDERR, $text);
            });
        } finally {
            self::giveBack($manifest->name);
        }
        if (array_key_exists('raw', $answer)) {
            fwrite(STDOUT, $answer['raw']);
        } elseif (($answer['result'] ?? null) !== null) {
            self::out($answer['result']);
        }

        return $answer['exit_code'];
    }

    // --------------------------------------------------------------------- MCP

    /**
     * The form for a website that is not on the user's list. The user types the
     * domain, and is not shown the one asked for: a lookalike reads as right when
     * it is put in front of someone, so what the user types has to match it.
     *
     * @return array<string, mixed>
     */
    public static function hostQuestion(AddonManifest $manifest, ?array $subject): array
    {
        if ($subject === null) {
            $who = 'what the add-on ' . $manifest->name . ' was asked about';
        } elseif (($subject['subject_source'] ?? null) === 'partner') {
            $who = 'your partner ' . $subject['partner'];
        } else {
            $who = 'the key that signed a message (hash ' . Keys::hashPrefixOf((string) $subject['key']) . ')';
        }

        return [
            'message' => 'Your agent asks to read a website, through the add-on ' . $manifest->name . ', about ' . $who . '. Which website do you expect? '
                . 'Type the domain from your own records, such as a contract or an earlier exchange, and not from a message: anyone can publish what they like on their own site. '
                . 'What you type has to match what your agent asked for, or nothing is read. A match stays on this runtime\'s list of websites it may read for ' . $manifest->name . ' until you run aamio addon ' . $manifest->name . ' forget.',
            'requestedSchema' => ['type' => 'object', 'properties' => ['domain' => ['type' => 'string', 'title' => 'Website domain', 'description' => 'the host name only, as example.org, with no https:// and no path', 'minLength' => 3, 'maxLength' => 253]], 'required' => ['domain']],
        ];
    }

    /** One tool call, through the add-on's process, after the core has settled consent and the subject. */
    public static function dispatch(AddonManifest $manifest, Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array
    {
        $spec = null;
        foreach ($manifest->toolSpecs as $candidate) {
            if ($candidate['name'] === $name) {
                $spec = $candidate;
                break;
            }
        }
        if ($spec === null) {
            return null;
        }
        $fields = array_map('strval', array_keys($spec['inputSchema']['properties'] ?? []));
        $extra = array_values(array_filter(array_map('strval', array_keys($arguments)), static fn (string $field): bool => !in_array($field, $fields, true)));
        sort($extra);
        if ($extra !== []) {
            return McpServer::resultOf([
                'error' => $name . ' takes ' . implode(', ', $fields) . ', and was given ' . implode(', ', $extra),
                'fix' => 'Call it with those alone. A key comes from the address book or from the signature this process checked, never from the conversation.',
                'given' => $extra,
            ], true);
        }
        $host = null;
        $subject = null;
        $ownKey = null;
        try {
            if (isset($spec['host']) && ($arguments[$spec['host']] ?? null) !== null) {
                $host = $arguments[$spec['host']] = self::normaliseHost($arguments[$spec['host']]);
            }
            $roles = $spec['subject'] ?? [];
            $given = [];
            foreach ($roles as $role => $field) {
                if (($arguments[$field] ?? null) !== null) {
                    $given[$role] = $arguments[$field];
                }
            }
            if ($roles !== [] && count($given) !== 1) {
                $named = [];
                foreach ($roles as $role => $field) {
                    $named[] = $field . ' (' . $role . ')';
                }
                throw new AddonRefused('choose_subject', 'Name exactly one of ' . implode(' or ', $named) . '. A key is never given here.');
            }
            if ($given !== []) {
                $subject = self::subjectFor($runtime, (string) array_key_first($given), $given[array_key_first($given)]);
            }
            if (in_array('own_key', $spec['needs'] ?? [], true)) {
                $ownKey = $runtime->keys->public;
            }
            if ($host !== null && !in_array($host, self::allowedHosts($runtime, $manifest), true)) {
                $answer = self::consent($manifest, $runtime, $name, $host, $subject, $arguments, $ask);
                if ($answer !== null) {
                    return McpServer::resultOf($answer, isset($answer['fix']));
                }
            }
        } catch (AddonRefused $refused) {
            return McpServer::resultOf(self::refusal($refused, $host, $subject), true);
        }
        [$directory, $noDirectory] = self::ownDirectory($runtime, $manifest);
        if ($noDirectory !== null) {
            return McpServer::resultOf($noDirectory['result'], true);
        }
        $busy = self::take($manifest->name);
        if ($busy !== null) {
            return McpServer::resultOf(self::refusal(new AddonRefused($busy, 'At most ' . self::MAX_AT_ONCE . ' calls of the add-on ' . $manifest->name . ' run at once and ' . self::MAX_PER_WINDOW . ' start per minute here. Nothing was done. Try again shortly.', 'unreachable'), $host), true);
        }
        try {
            $answer = self::run($manifest, 'tool', $name, $arguments, $subject, $ownKey, $directory);
        } finally {
            self::giveBack($manifest->name);
        }
        if (array_key_exists('raw', $answer)) {
            return McpServer::resultOf(['raw_base64' => base64_encode($answer['raw'])], $answer['is_error']);
        }

        return McpServer::resultOf($answer['result'], $answer['is_error']);
    }

    /**
     * A website not on the list: the user is asked in a form, or the call is
     * refused with the way by hand. Null once the host is allowed.
     *
     * @return array<string, mixed>|null
     */
    private static function consent(AddonManifest $manifest, Runtime $runtime, string $tool, string $host, ?array $subject, array $arguments, ?callable $ask): ?array
    {
        if ($subject !== null && ($subject['subject_source'] ?? null) === 'message') {
            $byHand = 'run aamio addon ' . $manifest->name . ' allow ' . $host . ' the next time this server is stopped, and check a later message from the same sender. Stopping it for this message would lose it: what this process verified is not kept, and a message cannot be read again';
        } else {
            $byHand = 'stop this server, run aamio addon ' . $manifest->name . ' allow ' . $host . ', and start it again';
        }
        if ($ask === null) {
            return self::refusal(new AddonRefused('host_not_allowed', 'This website is not on the user\'s list, and this app did not say it can show them a form (the elicitation capability of MCP), so nothing was read. The user adds it from their own records: ' . $byHand . '.'), $host, $subject);
        }
        // The whole call is what the answer belongs to, so an answer cannot be
        // carried over to another website or another subject.
        ksort($arguments);
        $about = json_encode([$manifest->name, $tool, $host, $arguments === [] ? new \stdClass() : $arguments], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $asked = $ask(self::hostQuestion($manifest, $subject), $about, $tool, self::HOST_QUESTION);
        if (isset($asked['no_dialog'])) {
            return self::refusal(new AddonRefused('host_not_allowed', $asked['no_dialog'] . ', so nothing was read. Call ' . $tool . ' again to ask the user again, or the user adds the website on the command line: ' . $byHand . '.'), $host, $subject);
        }
        $answer = is_array($asked['answer'] ?? null) ? $asked['answer'] : [];
        $action = $answer['action'] ?? null;
        if ($action !== 'accept') {
            return ['status' => 'invalid', 'reason' => $action === 'decline' ? 'host_declined' : 'host_not_answered', 'host' => $host, 'note' => 'The user did not agree to this website, so nothing was read and nothing was added. Do not ask again unless the user says so.'];
        }
        $content = is_array($answer['content'] ?? null) ? $answer['content'] : [];
        if (!self::isSameHost($content['domain'] ?? null, $host)) {
            return self::refusal(new AddonRefused('host_not_confirmed', 'The user typed a different website from the one you asked for, so nothing was read and nothing was added. Ask the user which website they meant, and take it from them and not from a message.'), $host, $subject);
        }
        self::allowHost($runtime, $manifest, $host);

        return null;
    }
}
