<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;

/**
 * The process the core runs for each call of this add-on: one request on
 * standard input, one answer on standard output.
 *
 *     php process.php < request.json
 *
 * The request and the answer are contract 2 of aamio's add-ons, written up at
 * https://aamio.at/docs/addons. The core has already settled what is the
 * core's: the website is one the user agreed to, the subject carries the key it
 * resolved from the address book or from a signature it checked, and own_key is
 * the runtime's public key where a command needs it. Nothing here opens the home.
 *
 *     kind    command | tool | probe
 *     name    file | validate | check | aamio_addon_domain_check
 *
 * An answer is {"result": …, "exit_code": n, "is_error": bool}, or {"raw":
 * base64} for bytes that go to standard output as they are, with "stderr" for a
 * line to the user. Standard output carries the answer and nothing else. The
 * same as aamio-python's aamio_domain_keys.process.
 */
final class Process
{
    public const CONTRACT = 2;
    public const NAME = 'domain';
    public const VERSION = '0.2.0';

    /** @return array<string, mixed> */
    public static function answer(mixed $result = null, int $exitCode = 0, ?bool $isError = null, ?string $raw = null, ?string $stderr = null): array
    {
        $said = ['exit_code' => $exitCode, 'is_error' => $isError ?? ($exitCode !== 0)];
        if ($raw !== null) {
            $said['raw'] = base64_encode($raw);
        } else {
            $said['result'] = $result;
        }
        if ($stderr !== null && $stderr !== '') {
            $said['stderr'] = $stderr;
        }

        return $said;
    }

    /**
     * The answer to one request. $network goes to Fetch::check, for tests.
     *
     * @return array<string, mixed>
     */
    public static function handle(mixed $request, array $network = []): array
    {
        if (!is_array($request) || ($request['aamio_addon'] ?? null) !== self::CONTRACT) {
            return self::answer(['error' => 'this add-on speaks contract ' . self::CONTRACT . " of aamio's add-ons, and the request is not on it", 'fix' => 'Upgrade aamio, or install the version of aisenseapi/aamio-domain-keys made for yours.'], 2, true);
        }
        $kind = $request['kind'] ?? null;
        $name = $request['name'] ?? null;
        $arguments = is_array($request['arguments'] ?? null) ? $request['arguments'] : [];
        $subject = $request['subject'] ?? null;
        if ($kind === 'probe') {
            return self::answer(['ok' => true, 'name' => self::NAME, 'version' => self::VERSION, 'aamio_addon' => self::CONTRACT]);
        }
        if ($kind === 'command' && $name === 'file') {
            try {
                $raw = Lists::make([['key' => $request['own_key'] ?? null, 'name' => $arguments['name'] ?? null]]);
            } catch (InvalidList $error) {
                // To the user and not to standard output: `aamio addon domain file > aamio-keys.json`
                // would publish the complaint.
                return self::answer(null, 2, true, null, json_encode(['error' => $error->reason, 'fix' => '--name is a label of 1 to 64 characters of a-z, 0-9, dot, underscore and dash.']));
            }

            return self::answer(null, 0, false, $raw, 'aamio: this holds your public key and nothing secret. Publish it at https://YOUR-DOMAIN' . Lists::PATH . ', with no login and no redirect, on a domain you already control.');
        }
        if ($kind === 'command' && $name === 'validate') {
            [$result, $code] = Lists::checkFile((string) ($arguments['file'] ?? ''));

            return self::answer($result, $code);
        }
        if (($kind === 'command' && $name === 'check') || ($kind === 'tool' && $name === 'aamio_addon_domain_check')) {
            $found = Policy::check($arguments['domain'] ?? null, $subject, $arguments['name'] ?? null, $network);

            return self::answer($found, Policy::EXIT_CODES[$found['status']], isset($found['fix']));
        }

        return self::answer(['error' => 'the add-on ' . self::NAME . ' has no ' . json_encode($kind) . ' called ' . json_encode($name), 'fix' => 'aamio addon ' . self::NAME . ' --help lists its commands, and aamio addon list its tools.'], 2, true);
    }

    /** Standard input to standard output, once. */
    public static function main(): int
    {
        $request = json_decode((string) stream_get_contents(STDIN), true);
        $said = self::handle($request);
        fwrite(STDOUT, json_encode($said, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush(STDOUT);

        return $said['exit_code'];
    }
}
