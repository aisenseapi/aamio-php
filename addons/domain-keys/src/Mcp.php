<?php

declare(strict_types=1);

namespace Aamio\DomainKeys;

use Aamio\Codec;
use Aamio\Keys;
use Aamio\McpServer;
use Aamio\Runtime;

/** aamio_addon_domain_check: the domain from the model, the key from this machine, and a new website only on the user's word. */
final class Mcp
{
    public const TOOL = 'aamio_addon_domain_check';
    /** The question the tool asks, under this name in inputRequests, when the website is not on the user's list. */
    public const QUESTION = 'aamio_addon_domain';

    /** @return list<array> the one tool, from mcp-tools.json beside this file, the same text as aamio-python's */
    public static function tools(): array
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/mcp-tools.json'), true, 512, JSON_THROW_ON_ERROR);

        return $spec['tools'];
    }

    /**
     * The form for a website that is not on the user's list. The user types the
     * domain, and is not shown the one asked for.
     *
     * A lookalike reads as right when it is put in front of someone, and a form
     * that offered it could be answered with one click. So the domain the agent
     * asked for is not in the form: what the user types has to match it, or
     * nothing is read. Who is being checked is said in words this runtime holds,
     * a name from the address book or a key's hash, and nothing a message wrote
     * is in the text.
     */
    public static function question(array $subject): array
    {
        $who = $subject['subject_source'] === 'partner' ? 'your partner ' . $subject['partner'] : 'the key that signed a message (hash ' . Keys::hashPrefixOf($subject['key']) . ')';
        $message = "Your agent asks to read a website's public list of agent keys, to see whether it lists " . $who . '. Which website do you expect to list it? '
            . 'Type the domain from your own records, such as a contract or an earlier exchange, and not from a message: anyone can list their own key on their own site. '
            . "What you type has to match what your agent asked for, or nothing is read. A match stays on this runtime's list of websites it may read until you run aamio addon domain forget. "
            . 'A listed key shows only that the site listed it, and permits nothing.';
        $domain = ['type' => 'string', 'title' => 'Website domain', 'description' => 'the host name only, as example.org, with no https:// and no path', 'minLength' => 3, 'maxLength' => 253];

        return ['message' => $message, 'requestedSchema' => ['type' => 'object', 'properties' => ['domain' => $domain], 'required' => ['domain']]];
    }

    public static function check(Runtime $r, array $arguments, ?callable $ask): array
    {
        $extra = array_values(array_filter(array_map('strval', array_keys($arguments)), static fn (string $field): bool => !in_array($field, ['domain', 'partner', 'message', 'name'], true)));
        sort($extra);

        // A key or a verified flag beside the rest is refused rather than passed
        // over: the model that sent one is told where keys come from, and cannot
        // mistake silence for use.
        if ($extra !== []) {
            return McpServer::resultOf([
                'error' => self::TOOL . ' takes domain, partner or message, and name, and was given ' . implode(', ', $extra),
                'fix' => 'Call it with those alone. The key comes from the address book or from the signature this process checked, never from the conversation.',
                'given' => $extra,
            ], true);
        }
        $domain = $arguments['domain'] ?? null;
        $partner = $arguments['partner'] ?? null;
        $message = $arguments['message'] ?? null;
        $label = $arguments['name'] ?? null;
        try {
            [$host, $subject] = Policy::prepare($r, $domain, $partner, $message, $label);
            $known = in_array($host, Policy::allowedDomains($r), true);
        } catch (Refused $refused) {
            return McpServer::resultOf(Policy::refusal($refused), true);
        }
        if (!$known) {
            // Stopping the server costs a partner check nothing. It costs a
            // message check the message: what this process verified lives in its
            // memory, and a read never goes back, so the way on from here is the
            // sender's next message.
            $byHand = $subject['subject_source'] === 'partner'
                ? 'stop this server, run aamio addon domain allow ' . $host . ', and start it again'
                : 'run aamio addon domain allow ' . $host . ' the next time this server is stopped, and check a later message from the same sender. Stopping it for this message would lose it: what this process verified is not kept, and a message cannot be read again';
            $stopped = static fn (string $reason, string $fix): array => McpServer::resultOf(Policy::refusal(new Refused($reason, $fix), $host, $subject, $label), true);
            if ($ask === null) {
                return $stopped('domain_not_allowed', "This website is not on the user's list, and this app did not say it can show them a form (the elicitation capability of MCP), so nothing was read. The user adds it from their own records: " . $byHand . '.');
            }
            // The whole call is what the answer belongs to, so an answer cannot
            // be carried over to another domain or another subject.
            $about = Codec::json([$host, $partner, $message, $label]);
            $asked = $ask(self::question($subject), $about, self::TOOL, self::QUESTION);
            if (isset($asked['no_dialog'])) {
                return $stopped('domain_not_allowed', $asked['no_dialog'] . ', so nothing was read. Call ' . self::TOOL . ' again to ask the user again, or the user adds the website on the command line: ' . $byHand . '.');
            }
            $answer = $asked['answer'];
            $action = $answer['action'] ?? null;
            if ($action !== 'accept') {
                return McpServer::resultOf(['status' => 'invalid', 'reason' => $action === 'decline' ? 'domain_declined' : 'domain_not_answered', 'domain' => $host, 'note' => 'The user did not agree to this website, so nothing was read and nothing was added. Do not ask again unless the user says so.']);
            }
            $content = is_array($answer['content'] ?? null) ? $answer['content'] : [];
            if (!Policy::isSameHost($content['domain'] ?? null, $host)) {
                return $stopped('domain_not_confirmed', 'The user typed a different website from the one you asked for, so nothing was read and nothing was added. Ask the user which website they meant, and take it from them and not from a message.');
            }
            try {
                Policy::allow($r, $host);
            } catch (Refused $refused) {
                return McpServer::resultOf(Policy::refusal($refused, $host, $subject, $label), true);
            }
        }
        $answer = Policy::check($r, $host, $partner, $message, $label);

        return McpServer::resultOf($answer, array_key_exists('fix', $answer));
    }
}
