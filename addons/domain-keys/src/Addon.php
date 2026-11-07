<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;

use Aamio\Runtime;

/**
 * Domain Keys, an add-on to aamio: does a website list this key.
 *
 * Lists is the format, Fetch the hardened read, Policy which key is compared and
 * which websites may be read at all, Mcp the tool aamio_addon_domain_check, and
 * this class the commands under `aamio addon domain`. It is a package of its own,
 * so that installing it is the user's choice; the core gives it the key, from the
 * address book or from a signature that process checked, and the home to keep
 * the user's list of websites in.
 */
final class Addon implements \Aamio\Addon
{
    public const VERSION = '0.1.0';

    /** One exit code per answer, so a script need not read the JSON to tell a key that is not listed from a list it could not read. */
    public const EXIT_CODES = ['listed' => 0, 'not_listed' => 3, 'no_list' => 4, 'unreachable' => 5, 'invalid' => 2];

    public function name(): string
    {
        return 'domain';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function commands(): array
    {
        return [
            'file' => 'file [--name NAME]: your public key as the list a website publishes at /.well-known/aamio-keys.json',
            'validate' => 'validate FILE: is this a valid list. Reads one file, no domain and no home',
            'allow' => 'allow DOMAIN: let this runtime read that website\'s list',
            'forget' => 'forget DOMAIN: take a website off that list',
            'list' => 'list: the websites this runtime may read',
            'check' => 'check DOMAIN --partner NAME [--name LABEL]: read a website\'s list once and say whether it lists a partner\'s key. Exits 0 listed, 3 not_listed, 4 no_list, 5 unreachable, 2 invalid or refused here',
        ];
    }

    private function usage(): string
    {
        return "aamio addon domain " . implode(' | ', array_keys($this->commands())) . "\n" . implode("\n", array_map(static fn (string $line): string => '  ' . $line, $this->commands()))
            . "\nlisted says this site listed this key when it was read, and permits nothing. The domain comes from your own records, never from a message. "
            . "A message can only be checked from inside aamio serve, through aamio_addon_domain_check: what a process verified is not kept for the next one. "
            . "AAMIO_DOMAIN_DENY: comma separated networks a check never reads.";
    }

    private static function out(mixed $value): void
    {
        fwrite(STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** validate, before any runtime: creating one takes the home's lock and tidies the home, and this reads one file. */
    public function early(array $args, array $opts): ?int
    {
        $action = $args[0] ?? '';
        if ($action === '' || $action === '--help' || isset($opts['help'])) {
            fwrite($action === '' ? STDERR : STDOUT, $this->usage() . "\n");

            return $action === '' ? 2 : 0;
        }
        if (!isset($this->commands()[$action])) {
            fwrite(STDERR, 'aamio addon domain: unknown command ' . $action . "\n" . $this->usage() . "\n");

            return 2;
        }
        if ($action === 'validate') {
            if (!isset($args[1])) {
                fwrite(STDERR, "aamio addon domain validate FILE\n");

                return 2;
            }
            [$checked, $code] = Lists::checkFile((string) $args[1]);
            self::out($checked);

            return $code;
        }

        return null;
    }

    /** The commands that need the runtime. Adding a website is the user's word, here or in a form over MCP. */
    public function command(array $args, array $opts, Runtime $runtime): int
    {
        $action = $args[0] ?? '';
        try {
            switch ($action) {
                case 'file':
                    try {
                        $raw = Lists::make([['key' => $runtime->keys->public, 'name' => $opts['name'] ?? null]]);
                    } catch (InvalidList $error) {
                        // To stderr: standard output here is the file, and
                        // `aamio addon domain file > aamio-keys.json` would publish the complaint.
                        fwrite(STDERR, json_encode(['error' => $error->reason, 'fix' => '--name is a label of 1 to 64 characters of a-z, 0-9, dot, underscore and dash.']) . "\n");

                        return 2;
                    }
                    fwrite(STDOUT, $raw);
                    fwrite(STDERR, 'aamio: this holds your public key and nothing secret. Publish it at https://YOUR-DOMAIN' . Lists::PATH . ", with no login and no redirect, on a domain you already control.\n");

                    return 0;
                case 'allow':
                    if (!isset($args[1])) {
                        fwrite(STDERR, "aamio addon domain allow DOMAIN\n");

                        return 2;
                    }
                    self::out(Policy::allow($runtime, $args[1]) + ['note' => "This runtime may now read this website's key list when a check asks for it. Nothing was read now, and a key it lists permits nothing."]);

                    return 0;
                case 'forget':
                    if (!isset($args[1])) {
                        fwrite(STDERR, "aamio addon domain forget DOMAIN\n");

                        return 2;
                    }
                    self::out(Policy::forget($runtime, $args[1]));

                    return 0;
                case 'list':
                    self::out(['domains' => Policy::allowedDomains($runtime)]);

                    return 0;
                case 'check':
                    if (!isset($args[1]) || !is_string($opts['partner'] ?? null)) {
                        fwrite(STDERR, "aamio addon domain check DOMAIN --partner NAME [--name LABEL]\n");

                        return 2;
                    }
                    $found = Policy::check($runtime, $args[1], $opts['partner'], null, $opts['name'] ?? null);
                    self::out($found);

                    return self::EXIT_CODES[$found['status']];
            }
        } catch (Refused $refused) {
            self::out(Policy::refusal($refused));

            return 2;
        }
        fwrite(STDERR, $this->usage() . "\n");

        return 2;
    }

    public function tools(): array
    {
        return Mcp::tools();
    }

    public function dispatch(Runtime $runtime, string $name, array $arguments, ?callable $ask): ?array
    {
        return $name === Mcp::TOOL ? Mcp::check($runtime, $arguments, $ask) : null;
    }
}
