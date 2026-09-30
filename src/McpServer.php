<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The runtime as an MCP server over stdio: one JSON-RPC message per line in,
 * one per line out. The tools, their descriptions and the instructions are
 * the same as aamio-python's, loaded from mcp-tools.json, so a model sees one
 * aamio whichever runtime is behind it.
 */
final class McpServer
{
    /**
     * What a read hands over when the caller names no budget.
     *
     * One message's maximum, the same number the hosted endpoint and the Python
     * client use. Fifty messages of that size is more than the conversation
     * calling this can carry, and the tool said so while handing it over.
     */
    public const DEFAULT_MAX_BYTES = 65536;
    /**
     * From 2026-07-28 there is no handshake: every request names its revision
     * in params._meta, and every result carries resultType. Before it,
     * initialize settles the revision, and the reference SDK's empty result
     * is strict and refuses any field, so an older client must get the
     * shapes it had.
     */
    private const MODERN = '2026-07-28';
    /** What a client that never named a revision is taken to speak: the oldest, served the old way. */
    private const UNSAID = '2025-03-26';
    /**
     * How long a client may keep the tools and the discovery answer, in
     * milliseconds. An hour, as the hosted service says: nothing in them
     * changes while the process runs.
     */
    private const CACHE_MS = 3600000;
    /** aamio_partner_add asks the user one question, under this name in inputRequests. */
    public const PARTNER_QUESTION = 'aamio_partner_key';
    /**
     * How long an answer to that question may take to come back over
     * 2026-07-28, in seconds: time for a person to find a key, and not so long
     * that an unanswered question lies about as a standing permission.
     */
    public const CONFIRM_SECONDS = 900;
    /**
     * A partner's name as that tool takes it. The command line takes any name;
     * this one is written into the question the user reads, so it cannot be a
     * sentence.
     */
    private const PARTNER_NAME = '/^[\p{L}\p{N}][\p{L}\p{N}_.-]{0,31}$/Du';

    private array $tools;
    private string $instructions;
    private array $supported;
    /** The revision initialize settled on, for every later request that names none itself. */
    private ?string $negotiated = null;
    /** What initialize said the client can do, which before 2026-07-28 holds for the process. */
    private array $capabilities = [];
    /** What this process signs its questions with, and the nonces of the ones already answered. */
    private string $secret;
    /** @var array<string, int> */
    private array $taken = [];
    /** @var resource|null The stdio connection while serve() runs. */
    private $in = null;
    /** @var resource|null */
    private $out = null;
    /** @var list<string> Lines that came in while a question waited, served afterwards in order. */
    private array $held = [];
    private int $asked = 0;

    public function __construct(private readonly Runtime $runtime)
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/mcp-tools.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->tools = self::objects($spec['tools']);
        $this->instructions = $spec['instructions'];
        $this->supported = $spec['protocol_versions'];
        $this->secret = random_bytes(32);
    }

    /** json_decode as arrays turns {} into []; a schema's empty properties must go back out as {}. */
    private static function objects(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $value) {
            $node[$key] = $key === 'properties' && $value === [] ? new \stdClass() : self::objects($value);
        }

        return $node;
    }

    public static function resultOf(mixed $data, bool $isError = false): array
    {
        return ['content' => [['type' => 'text', 'text' => Codec::json($data)]], 'structuredContent' => is_array($data) && !array_is_list($data) ? ($data === [] ? new \stdClass() : $data) : ['result' => $data], 'isError' => $isError];
    }

    /** A string argument, or null when it is not there. Anything else is refused, never cast. */
    private static function text(array $arguments, string $name, bool $required = false): ?string
    {
        $value = $arguments[$name] ?? null;
        if ($value === null && $required) {
            throw new \InvalidArgumentException($name . ' is required, as a string');
        }
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException($name . ' must be a string');
        }

        return $value;
    }

    /** A whole number argument, or the default when it is not there. */
    private static function number(array $arguments, string $name, int $default = 0): int
    {
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_int($value) && !(is_float($value) && floor($value) === $value && abs($value) < 1e9) && !(is_string($value) && preg_match('/^-?[0-9]{1,9}$/D', $value) === 1)) {
            throw new \InvalidArgumentException($name . ' must be a whole number');
        }

        return (int) $value;
    }

    /** A list of strings, or null when it is not there. */
    private static function strings(array $arguments, string $name): ?array
    {
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || array_filter($value, static fn ($item): bool => !is_string($item)) !== []) {
            throw new \InvalidArgumentException($name . ' must be a list of strings');
        }

        return $value;
    }

    /** An object argument, or null when it is not there. */
    private static function map(array $arguments, string $name): ?array
    {
        $value = $arguments[$name] ?? null;
        if ($value !== null && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
            throw new \InvalidArgumentException($name . ' must be an object');
        }

        return $value;
    }

    /** What comes first in the fix when a channel was opened and the message carrying its address did not go. */
    private static function stillOpen(?array $opened): string
    {
        return $opened === null ? '' : 'The channel ' . $opened['label'] . ' is open and listed by aamio_channels: only the message carrying its address did not go. ';
    }

    // ------------------------------------------------------- asking the user
    //
    // A partner used to enter the address book only from the command line, with
    // this server stopped, since it holds the home: an agent on MCP alone could
    // not finish a first exchange without a person at a terminal. Adding one is
    // a decision about trust, and the model deciding it would take its keys from
    // what it reads, the board and strangers' messages among it. So
    // aamio_partner_add takes a name from the model and nothing more, and the
    // key from the user, in a form their app shows: MCP elicitation. An app that
    // cannot show one gets the command line's way instead. Decided on 30
    // September 2026, the same as in aamio-python.

    /** The form the user is shown: one field, the key, and what saying yes does. */
    private function partnerQuestion(string $name): array
    {
        $message = 'Your agent asks to add ' . $name . ' to your aamio address book. A partner can write to your inbox and is shown to the agent by name. '
            . 'Give the public key ' . $name . ' gave you, by a way you already trust. If you did not get it from ' . $name . ' yourself, decline: '
            . 'a key from a message, the board or the conversation is not their word.';
        if ($this->runtime->partnerByName($name) !== null) {
            $message .= ' ' . $name . ' is in your address book already, and the key you give replaces the one it has.';
        }
        $key = ['type' => 'string', 'title' => $name . "'s public key", 'description' => '43 characters of letters, digits, - and _, as aamio whoami shows it on their side', 'minLength' => 43, 'maxLength' => 64];

        return ['message' => $message, 'requestedSchema' => ['type' => 'object', 'properties' => ['key' => $key], 'required' => ['key']]];
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * requestState for one question: what it is about, until when, and a
     * nonce, signed by this process. It goes through the client and comes back
     * with the answer, so it is taken as written by anyone: the signature says
     * this process asked, the time says when the question lapses, and the name
     * says which call the answer belongs to. $until is for a check that needs
     * one already lapsed.
     */
    public function sealState(string $name, ?int $until = null): string
    {
        $payload = rtrim(strtr(base64_encode(Codec::json(['name' => $name, 'nonce' => bin2hex(random_bytes(12)), 'tool' => 'aamio_partner_add', 'until' => $until ?? time() + self::CONFIRM_SECONDS])), '+/', '-_'), '=');

        return $payload . '.' . $this->mac($payload);
    }

    /** Whether $state is one this process sealed for this name, in time and never used. Using it here uses it up. */
    private function takeState(mixed $state, string $name): bool
    {
        if (!is_string($state) || substr_count($state, '.') !== 1) {
            return false;
        }
        [$payload, $mac] = explode('.', $state);
        if (!hash_equals($this->mac($payload), $mac)) {
            return false;
        }
        $padded = strtr($payload, '-_', '+/');
        $decoded = base64_decode($padded . str_repeat('=', (4 - strlen($padded) % 4) % 4), true);
        $said = is_string($decoded) ? json_decode($decoded, true) : null;
        $now = time();
        $this->taken = array_filter($this->taken, static fn (int $until): bool => $until >= $now);
        if (!is_array($said) || ($said['tool'] ?? null) !== 'aamio_partner_add' || ($said['name'] ?? null) !== $name) {
            return false;
        }
        if (!is_int($said['until'] ?? null) || $said['until'] < $now || !is_string($said['nonce'] ?? null) || isset($this->taken[$said['nonce']])) {
            return false;
        }
        $this->taken[$said['nonce']] = $said['until'];

        return true;
    }

    /**
     * Whether this request's client says it can put a form in front of the
     * user. From 2026-07-28 a client declares what it can on every request, and
     * a server must not take it from an earlier one. Before that, initialize
     * declared it once, and 2025-03-26 had no elicitation at all.
     */
    private function canAskTheUser(array $params, string $version): bool
    {
        if ($version === self::MODERN) {
            $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
            $declared = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
        } elseif (in_array($version, ['2025-06-18', '2025-11-25'], true)) {
            $declared = $this->capabilities;
        } else {
            return false;
        }
        $elicitation = is_array($declared) ? ($declared['elicitation'] ?? null) : null;

        // An empty object is form mode, as 2025-11-25 says of clients from before
        // the modes; one that names its modes and leaves form out cannot show a form.
        return is_array($elicitation) && ($elicitation === [] || array_key_exists('form', $elicitation));
    }

    /**
     * How a tool in this request asks the user a question, or null when this
     * client cannot be asked. The function returns ['answer' => ElicitResult]
     * or ['no_dialog' => why]. Over 2026-07-28 the question is the call's
     * result, thrown as an McpInterrupt, and the answer comes with the call
     * made again. Before it, the question is a request of its own to the
     * client, sent while the call waits.
     */
    private function asker(array $params, string $version, mixed $waiting): ?callable
    {
        if (!$this->canAskTheUser($params, $version)) {
            return null;
        }
        // Modes came with 2025-11-25, and the revision before it has none to name.
        $form = static fn (array $question): array => in_array($version, ['2025-11-25', self::MODERN], true) ? ['mode' => 'form'] + $question : $question;

        if ($version === self::MODERN) {
            return function (array $question, string $about) use ($params, $form): array {
                $asking = ['resultType' => 'input_required', 'inputRequests' => [self::PARTNER_QUESTION => ['method' => 'elicitation/create', 'params' => $form($question)]], 'requestState' => $this->sealState($about)];
                $state = $params['requestState'] ?? null;
                $responses = $params['inputResponses'] ?? null;
                if ($state === null && $responses === null) {
                    throw new McpInterrupt($asking, 'the user is asked first');
                }
                if (!$this->takeState($state, $about)) {
                    return ['no_dialog' => 'the answer did not come with a question this server asked, in the last ' . intdiv(self::CONFIRM_SECONDS, 60) . ' minutes, about ' . $about];
                }
                $answer = is_array($responses) ? ($responses[self::PARTNER_QUESTION] ?? null) : null;
                // The answer itself is missing: asked again, as the revision
                // says, rather than refused.
                if (!is_array($answer)) {
                    throw new McpInterrupt($asking, 'the answer to the question was missing');
                }

                return ['answer' => $answer];
            };
        }

        if ($this->in === null || $this->out === null) {
            return null;
        }

        return function (array $question, string $about) use ($form, $waiting): array {
            $answered = $this->ask('elicitation/create', $form($question), $waiting);
            if (!is_array($answered['result'] ?? null)) {
                $error = is_array($answered['error'] ?? null) ? $answered['error'] : [];
                $why = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : 'it gave no reason';

                return ['no_dialog' => 'the app answered the question with an error: ' . $why];
            }

            return ['answer' => $answered['result']];
        };
    }

    /** aamio_partner_add: the name from the model, the key from the user, and nothing added without both. */
    private function addPartner(array $arguments, ?callable $ask): array
    {
        $r = $this->runtime;
        $name = $arguments['name'] ?? null;
        $extra = array_values(array_filter(array_map('strval', array_keys($arguments)), static fn (string $field): bool => $field !== 'name'));
        sort($extra);

        // A key beside the name is refused rather than passed over: the model
        // that sent one is told where keys come from, and cannot mistake
        // silence for use.
        if ($extra !== []) {
            return self::resultOf([
                'error' => 'aamio_partner_add takes a name and nothing else, and was given ' . implode(', ', $extra),
                'fix' => 'Call it with the name alone. The key comes from the user, in the question this server puts to them, never from the conversation.',
                'given' => $extra,
            ], true);
        }
        if (!is_string($name) || preg_match(self::PARTNER_NAME, $name) !== 1) {
            return self::resultOf([
                'error' => 'name must be 1 to 32 letters, digits, dots, dashes and underscores, starting with a letter or a digit',
                'fix' => 'Use a short name the user knows the partner by, as bob or arctic-freight. It is written into the question the user is shown.',
                'given' => $name,
            ], true);
        }
        // The book's own spelling, so the key replaces the entry the user is told about.
        $held = $r->partnerByName($name);
        if ($held !== null) {
            $name = $held['name'];
        }
        $byHand = 'stop this server, run aamio partner add ' . $name . ' KEY with the key ' . $name . ' gave them, and start it again';
        if ($ask === null) {
            return self::resultOf([
                'added' => false,
                'error' => 'this app did not say it can show the user a form (the elicitation capability of MCP), so the user was not asked and nothing was added',
                'error_code' => 'no_dialog',
                'fix' => 'The user adds the partner on the command line: ' . $byHand . '.',
            ], true);
        }
        $asked = $ask($this->partnerQuestion($name), $name);
        if (isset($asked['no_dialog'])) {
            return self::resultOf([
                'added' => false,
                'error' => $asked['no_dialog'] . ', so nothing was added',
                'error_code' => 'no_dialog',
                'fix' => 'Call aamio_partner_add again to ask the user again, or the user adds the partner on the command line: ' . $byHand . '.',
            ], true);
        }
        $answer = $asked['answer'];
        $action = $answer['action'] ?? null;
        if ($action === 'decline') {
            return self::resultOf(['added' => false, 'partner' => $name, 'action' => 'decline', 'note' => 'The user declined, so nothing was added. Do not ask again unless the user says so.']);
        }
        if ($action !== 'accept') {
            return self::resultOf(['added' => false, 'partner' => $name, 'action' => 'cancel', 'note' => 'The user closed the question without answering, so nothing was added.']);
        }
        $content = is_array($answer['content'] ?? null) ? $answer['content'] : [];
        $key = is_string($content['key'] ?? null) ? trim($content['key']) : null;
        if ($key === null || !Codec::isKey($key)) {
            return self::resultOf([
                'added' => false,
                'partner' => $name,
                'error' => 'what the user gave is not an aamio public key, so nothing was added',
                'fix' => "A key is 43 characters of letters, digits, - and _, as aamio whoami or aamio_whoami shows it on the partner's side. Call aamio_partner_add again and the user is asked again.",
            ], true);
        }
        $other = $r->partnerByKey($key);
        if ($other !== null && $other['name'] !== $name) {
            return self::resultOf([
                'added' => false,
                'partner' => $name,
                'error' => 'that key is in the address book already, as ' . $other['name'] . ', so nothing was changed',
                'fix' => 'Send to them as ' . $other['name'] . '. To have them called ' . $name . ' instead, the user removes ' . $other['name'] . ' on the command line first.',
            ], true);
        }
        $changed = $r->partnerAdd($name, $key);

        return self::resultOf($changed + ['added' => true, 'partners' => $r->partnerList(), 'next' => 'If ' . $name . ' does not have your key, give it to them the same way: aamio_whoami shows it.']);
    }

    /** One tool call. $ask is how a tool that needs the user's answer asks for it, and null where it cannot. */
    public function dispatch(string $name, array $arguments, ?callable $ask = null): ?array
    {
        $r = $this->runtime;
        $a = $arguments;
        try {
            switch ($name) {
                case 'aamio_whoami':
                    return self::resultOf($r->whoami());
                case 'aamio_partners':
                    return self::resultOf(['partners' => $r->partnerList()]);
                case 'aamio_partner_add':
                    return $this->addPartner($a, $ask);
                case 'aamio_presence_lookup':
                    return self::resultOf($r->lookup(self::strings($a, 'names'), self::number($a, 'wait')));
                case 'aamio_send':
                    return self::resultOf($r->send(self::text($a, 'to', true), self::text($a, 'text'), self::map($a, 'data'), null, self::text($a, 're')));
                case 'aamio_trace':
                    return self::resultOf($r->trace(self::text($a, 'who'), isset($a['limit']) ? self::number($a, 'limit') : 20));
                case 'aamio_read':
                    $asked = self::number($a, 'limit');
                    // Fifty messages of 65536 bytes is more than the conversation
                    // calling this can carry, and a caller that named no budget was
                    // the one who found out. Same number as the other two surfaces.
                    $budget = isset($a['max_bytes']) ? self::number($a, 'max_bytes') : self::DEFAULT_MAX_BYTES;

                    // 512 is the floor because one message can be 65536 bytes and a
                    // signed message cannot be cut in half and still verify. Under the
                    // floor there is nothing sensible to do, so the refusal says so.
                    if ($budget !== null && $budget < 512) {
                        return self::resultOf([
                            'error' => 'max_bytes must be a whole number of bytes, 512 or more',
                            'fix' => 'One message can be 65536 bytes. A budget under that is answered with the message named in too_large rather than cut, since a signed message cannot be half sent.',
                            'given' => $a['max_bytes'],
                        ], true);
                    }

                    $messages = $r->read(self::number($a, 'wait'), $asked > 0 ? min($asked, 200) : 50, $budget);
                    $attention = $r->attentionTaken();
                    $result = ['messages' => $messages, 'count' => count($messages)];

                    return self::resultOf($attention === [] ? $result : $result + ['attention' => $attention]);
                case 'aamio_receipt':
                    return self::resultOf($r->receipt(self::text($a, 'channel') ?: 'inbox', (bool) ($a['anchor'] ?? false)));
                case 'aamio_open_channel':
                    // A wrong gate is worth a refusal rather than an inbox that is
                    // already open: there is no changing it afterwards. The runtime
                    // checks the shape and the service checks the rest, so the rule
                    // is not written out twice. Checked before the call, so this fix
                    // goes with a wrong gate and not with an unknown partner, or with
                    // an inbox on the way whose gate stops the handover: that one is
                    // a GateStop and has its own answer below.
                    if (($a['gate'] ?? null) !== null) {
                        try {
                            Runtime::checkGate(self::map($a, 'gate'));
                        } catch (\InvalidArgumentException $wrong) {
                            return self::resultOf([
                                'error' => $wrong->getMessage(),
                                'fix' => 'Send a gate with require, advise or both, as in {"require": {"pow": {"bits": 20}, "per_key": 5}}, or leave gate out to take writes from anyone on the allowlist.',
                                'given' => $a['gate'],
                            ], true);
                        }
                    }

                    return self::resultOf($r->openChannel(self::text($a, 'label', true), self::number($a, 'ttl', 600), self::strings($a, 'allow'), self::map($a, 'gate'), self::text($a, 'to'), self::text($a, 'note')));
                case 'aamio_channels':
                    return self::resultOf(['channels' => $r->channelList()]);
                case 'aamio_close_channel':
                    return self::resultOf($r->closeChannel(self::text($a, 'label', true)));
                case 'aamio_board_post':
                    return self::resultOf($r->boardPost(self::text($a, 'kind', true), self::text($a, 'title', true), self::text($a, 'text', true), self::strings($a, 'tags'), self::number($a, 'ttl', Runtime::BOARD_TTL) ?: Runtime::BOARD_TTL, self::text($a, 'lang'), self::text($a, 'deadline'), self::text($a, 'scope')));
                case 'aamio_board_find':
                    return self::resultOf($r->boardFind(self::text($a, 'kind'), self::strings($a, 'tags'), self::text($a, 'lang'), null, self::number($a, 'after'), self::number($a, 'wait'), self::number($a, 'min_work_bits'), self::text($a, 'scope')));
                case 'aamio_board_answer':
                    return self::resultOf($r->boardAnswer(self::text($a, 'post', true), self::text($a, 'text'), self::map($a, 'data'), self::text($a, 'scope')));
                case 'aamio_board_withdraw':
                    return self::resultOf($r->boardWithdraw(self::text($a, 'post', true)));
                case 'aamio_pending':
                    $pending = array_map(static fn (array $p): array => array_diff_key($p, ['envelope' => 1, 'to_key' => 1]), $r->outboxPending());

                    return self::resultOf(['count' => count($pending), 'pending' => $pending]);
                case 'aamio_outbox_retry':
                    $messageId = (string) $arguments['id'];
                    $done = $r->outboxRetry($messageId);

                    if ($done !== []) {
                        return self::resultOf($done[0]);
                    }

                    // Nothing was sent, and the two reasons want different next moves.
                    return self::resultOf([
                        'id' => $messageId,
                        'retried' => false,
                        'why' => isset($r->outbox[$messageId])
                            ? 'that message has a settled outcome, or aamio refused it for a reason that will not change, so the same bytes are not sent again'
                            : 'this outbox has no message with that id: aamio_pending lists what is unsettled here',
                    ], true);
                case 'aamio_outbox_forget':
                    return self::resultOf($r->outboxForget((string) $arguments['id']));
                case 'aamio_board_tags':
                    return self::resultOf($r->boardTags());
                case 'aamio_scopes':
                    return self::resultOf(['scopes' => $r->scopeList()]);
                case 'aamio_scope_new':
                    return self::resultOf($r->scopeNew(self::text($a, 'name', true)));
                case 'aamio_scope_add':
                    return self::resultOf($r->scopeAdd(self::text($a, 'name', true), self::text($a, 'key'), self::text($a, 'address')));
                case 'aamio_scope_share':
                    return self::resultOf($r->scopeShare(self::text($a, 'name', true), self::text($a, 'to', true), self::text($a, 'access', true)));
                case 'aamio_scope_remove':
                    return self::resultOf($r->scopeRemove(self::text($a, 'name', true)));
            }

            return null;
        } catch (SendFailed $error) {
            [$retryable, $fix] = Runtime::sendAdvice($error->outcome, $error->status);

            return self::resultOf(($error->opened === null ? [] : ['opened' => $error->opened]) + ['error' => $error->getMessage(), 'error_code' => 'send_' . $error->outcome, 'operation' => ['aamio_board_answer' => 'board_answer', 'aamio_open_channel' => 'open_channel'][$name] ?? 'send', 'outcome' => $error->outcome, 'message_id' => $error->messageId, 'status' => $error->status, 'retryable' => $retryable, 'fix' => self::stillOpen($error->opened) . $fix], true);
        } catch (GateStop $error) {
            return self::resultOf(($error->opened === null ? [] : ['opened' => $error->opened]) + ['error' => $error->getMessage(), 'error_code' => 'gate', 'operation' => $name === 'aamio_open_channel' ? 'open_channel' : 'send', 'retryable' => false, 'fix' => self::stillOpen($error->opened) . $error->fix], true);
        } catch (\InvalidArgumentException | \RuntimeException | \LogicException $error) {
            return self::resultOf(['error' => $error->getMessage()], true);
        } catch (\TypeError | \ValueError | \JsonException $error) {
            // An argument of a type the runtime does not take. This call's
            // failure, and the server stays up for the next one.
            return self::resultOf(['error' => $error->getMessage(), 'fix' => "Check each argument against the tool's inputSchema and call again."], true);
        }
    }

    /** The revision a request speaks: named in params._meta, else the one initialize settled on, else UNSAID. */
    private function versionOf(array $params): string
    {
        $named = is_array($params['_meta'] ?? null) ? ($params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null) : null;

        return is_string($named) && $named !== '' ? $named : ($this->negotiated ?? self::UNSAID);
    }

    /**
     * A result in the shape the requested revision wants.
     *
     * 2026-07-28 requires resultType on every result, and ttlMs and cacheScope
     * beside the items of a list. Without them a client of that revision
     * refuses the whole answer: Claude Code did, with "Invalid result for
     * tools/list: missing required resultType", and connected to the hosted
     * service with zero tools from 16 to 21 September 2026. This server
     * announced the revision and had the same gap. An older client gets
     * exactly what it got, since the reference SDK's empty result of those
     * revisions refuses any field.
     */
    private static function shaped(array $result, bool $modern, bool $listing = false): array
    {
        if (!$modern) {
            return $result;
        }

        return ['resultType' => 'complete'] + $result + ($listing ? ['ttlMs' => self::CACHE_MS, 'cacheScope' => 'public'] : []);
    }

    /** What server/discover answers: the revisions, the capabilities, who is speaking and the words, cacheable for an hour. */
    private function discoverResult(): array
    {
        return [
            'resultType' => 'complete',
            'supportedVersions' => $this->supported,
            'capabilities' => ['tools' => ['listChanged' => false]],
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'aamio', 'version' => Http::VERSION]],
            'instructions' => $this->instructions,
            'ttlMs' => self::CACHE_MS,
            'cacheScope' => 'public',
        ];
    }

    public function handle(mixed $message): ?array
    {
        if (is_array($message) && !array_is_list($message) && !array_key_exists('method', $message) && array_key_exists('id', $message) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
            // A response, to a question whose wait is over: a late answer to one
            // the call was cancelled under. Nothing to answer, and an error sent
            // back would be a reply to the client's own reply.
            return null;
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return ['jsonrpc' => '2.0', 'id' => is_array($message) ? ($message['id'] ?? null) : null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']];
        }
        $method = $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (!array_key_exists('id', $message) || str_starts_with($method, 'notifications/')) {
            return null;
        }
        $id = $message['id'];
        // A request that names a revision this server does not know is refused
        // before anything is done, as the hosted service refuses it: the shape
        // such a client wants is unknown, and the older shape was a guess. What
        // an initialize asks for is negotiated as before, down to one that is
        // known.
        $named = is_array($params['_meta'] ?? null) ? ($params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null) : null;
        if (is_string($named) && $named !== '' && !in_array($named, $this->supported, true)) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32022, 'message' => 'Unsupported protocol version ' . $named . '. Retry with one of ' . implode(', ', $this->supported) . ', in params._meta.', 'data' => ['supported' => $this->supported, 'requested' => $named]]];
        }
        $version = $this->versionOf($params);
        $modern = $version === self::MODERN;
        switch ($method) {
            case 'server/discover':
                // A 2026-07-28 method, so its answer has that revision's shape
                // whoever asks; the hosted service answers a legacy client too.
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->discoverResult()];
            case 'initialize':
                $requested = $params['protocolVersion'] ?? null;
                $version = in_array($requested, $this->supported, true) ? $requested : '2025-11-25';
                // What the two sides settled on decides the shape of every
                // later answer that names no revision itself, and what the
                // client said it can do holds as long: before 2026-07-28 it is
                // declared here and nowhere else.
                $this->negotiated = $version;
                $this->capabilities = is_array($params['capabilities'] ?? null) ? $params['capabilities'] : [];

                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'aamio', 'version' => Http::VERSION], 'instructions' => $this->instructions], $version === self::MODERN)];
            case 'ping':
                // An older client's empty result refuses any field, so {} stays {} for it.
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $modern ? ['resultType' => 'complete'] : new \stdClass()];
            case 'tools/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['tools' => $this->tools], $modern, true)];
            case 'tools/call':
                $name = is_string($params['name'] ?? null) ? $params['name'] : '';
                $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                try {
                    $result = $this->dispatch($name, $arguments, $this->asker($params, $version, $id));
                } catch (McpInterrupt $interrupt) {
                    // Either the question, as this call's result in the shape
                    // 2026-07-28 gives it: the client puts it to the user and
                    // calls again with the answer. Or the call was cancelled, or
                    // the input ended, while the user was being asked, and a
                    // cancelled request is not answered.
                    return $interrupt->result === null ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $interrupt->result];
                }
                if ($result === null) {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool: ' . $name]];
                }

                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped($result, $modern)];
            case 'resources/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['resources' => []], $modern, true)];
            case 'prompts/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['prompts' => []], $modern, true)];
            case 'resources/templates/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['resourceTemplates' => []], $modern, true)];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method . '. This server answers server/discover, initialize, ping, tools/list and tools/call, and empty lists for resources and prompts.']];
    }

    /** handle, with anything it did not expect answered as an internal error instead of ending the server. */
    public function safely(mixed $message): ?array
    {
        try {
            return $this->handle($message);
        } catch (\Throwable $error) {
            $kind = (new \ReflectionClass($error))->getShortName();
            ($this->runtime->log)($kind . ': ' . $error->getMessage());
            if (!is_array($message) || !array_key_exists('id', $message)) {
                return null;
            }

            return ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32603, 'message' => 'Internal error: ' . $kind . '. The server is still running.']];
        }
    }

    private function send(array $message): void
    {
        fwrite($this->out, Codec::json($message) . "\n");
        fflush($this->out);
    }

    /** The next line to serve: one kept while a question waited, else a new one. False when the input has ended. */
    private function nextLine(): string|false
    {
        return $this->held !== [] ? array_shift($this->held) : fgets($this->in);
    }

    /**
     * One request to the client, and the response to it, while the client's
     * own request $waiting waits. What arrives meanwhile is kept and served
     * afterwards, in order: a ping is answered at once, and a cancellation of
     * the call that waits ends the wait, withdraws the question, and answers
     * nothing.
     */
    private function ask(string $method, array $params, mixed $waiting): array
    {
        $this->asked++;
        $id = 'aamio-' . $this->asked;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        while (($line = fgets($this->in)) !== false) {
            $message = json_decode($line, true);
            if (!is_array($message) || array_is_list($message)) {
                if (trim($line) !== '') {
                    $this->held[] = $line;
                }
                continue;
            }
            if (!array_key_exists('method', $message) && ($message['id'] ?? null) === $id) {
                return $message;
            }
            $cancelled = ($message['method'] ?? null) === 'notifications/cancelled' && is_array($message['params'] ?? null) ? ($message['params']['requestId'] ?? null) : null;
            if ($cancelled !== null && $cancelled === $waiting) {
                $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => $id, 'reason' => 'the call it was asked for was cancelled']]);

                throw new McpInterrupt(null, 'the call was cancelled while the user was being asked');
            }
            if ($cancelled !== null) {
                // A request kept for later that is cancelled now is not served.
                $this->held = array_values(array_filter($this->held, static function (string $kept) use ($cancelled): bool {
                    $request = json_decode($kept, true);

                    return !(is_array($request) && !array_is_list($request) && array_key_exists('method', $request) && ($request['id'] ?? null) === $cancelled);
                }));
                continue;
            }
            if (($message['method'] ?? null) === 'ping' && array_key_exists('id', $message)) {
                $reply = $this->safely($message);
                if ($reply !== null) {
                    $this->send($reply);
                }
                continue;
            }
            $this->held[] = $line;
        }

        throw new McpInterrupt(null, 'the input ended while the user was being asked');
    }

    /** Reads stdin line by line until it closes. */
    public function serve($in = STDIN, $out = STDOUT): void
    {
        // stdout carries JSON-RPC and nothing else. A warning printed there
        // would break the line the client is reading.
        ini_set('display_errors', 'stderr');
        // A host cuts a tool call after a minute or so, and this server cannot
        // work in the background, so longer work is refused with a reason that
        // points at the command line rather than started in a call that times
        // out with nobody knowing whether the message went.
        $this->runtime->client->workBudget = 40.0;
        $this->runtime->log = static function (string $line): void {
            fwrite(STDERR, '[aamio ' . date('H:i:s') . '] ' . $line . "\n");
        };
        $this->runtime->ensureInbox();
        ($this->runtime->log)('serving on stdio, inbox ' . ($this->runtime->whoami()['inbox'] ?? '?'));
        $this->in = $in;
        $this->out = $out;
        while (($line = $this->nextLine()) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $message = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $reply = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];
            } else {
                $replies = array_values(array_filter(array_map([$this, 'safely'], is_array($message) && array_is_list($message) ? $message : [$message]), static fn ($r) => $r !== null));
                if ($replies === []) {
                    continue;
                }
                $reply = is_array($message) && array_is_list($message) ? $replies : $replies[0];
            }
            $this->send($reply);
        }
        $this->runtime->close();
    }
}
