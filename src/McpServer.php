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

    private array $tools;
    private string $instructions;
    private array $supported;
    /** The revision initialize settled on, for every later request that names none itself. */
    private ?string $negotiated = null;

    public function __construct(private readonly Runtime $runtime)
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/mcp-tools.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->tools = self::objects($spec['tools']);
        $this->instructions = $spec['instructions'];
        $this->supported = $spec['protocol_versions'];
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

    public function dispatch(string $name, array $arguments): ?array
    {
        $r = $this->runtime;
        $a = $arguments;
        try {
            switch ($name) {
                case 'aamio_whoami':
                    return self::resultOf($r->whoami());
                case 'aamio_partners':
                    return self::resultOf(['partners' => $r->partnerList()]);
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
                    // is not written out twice.
                    try {
                        return self::resultOf($r->openChannel(self::text($a, 'label', true), self::number($a, 'ttl', 600), self::strings($a, 'allow'), self::map($a, 'gate')));
                    } catch (\InvalidArgumentException $wrong) {
                        if (!str_contains($wrong->getMessage(), 'gate')) {
                            throw $wrong;
                        }

                        return self::resultOf([
                            'error' => $wrong->getMessage(),
                            'fix' => 'Send a gate with require, advise or both, as in {"require": {"pow": {"bits": 20}, "per_key": 5}}, or leave gate out to take writes from anyone on the allowlist.',
                            'given' => $a['gate'] ?? null,
                        ], true);
                    }
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

            return self::resultOf(($error->opened === null ? [] : ['opened' => $error->opened]) + ['error' => $error->getMessage(), 'error_code' => 'send_' . $error->outcome, 'operation' => ['aamio_board_answer' => 'board_answer', 'aamio_open_channel' => 'open_channel'][$name] ?? 'send', 'outcome' => $error->outcome, 'message_id' => $error->messageId, 'status' => $error->status, 'retryable' => $retryable, 'fix' => $fix], true);
        } catch (GateStop $error) {
            return self::resultOf(['error' => $error->getMessage(), 'error_code' => 'gate', 'operation' => 'send', 'retryable' => false, 'fix' => $error->fix], true);
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
        $modern = $this->versionOf($params) === self::MODERN;
        switch ($method) {
            case 'server/discover':
                // A 2026-07-28 method, so its answer has that revision's shape
                // whoever asks; the hosted service answers a legacy client too.
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->discoverResult()];
            case 'initialize':
                $requested = $params['protocolVersion'] ?? null;
                $version = in_array($requested, $this->supported, true) ? $requested : '2025-11-25';
                // What the two sides settled on decides the shape of every
                // later answer that names no revision itself.
                $this->negotiated = $version;

                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'aamio', 'version' => Http::VERSION], 'instructions' => $this->instructions], $version === self::MODERN)];
            case 'ping':
                // An older client's empty result refuses any field, so {} stays {} for it.
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $modern ? ['resultType' => 'complete'] : new \stdClass()];
            case 'tools/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::shaped(['tools' => $this->tools], $modern, true)];
            case 'tools/call':
                $name = is_string($params['name'] ?? null) ? $params['name'] : '';
                $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $result = $this->dispatch($name, $arguments);
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
        while (($line = fgets($in)) !== false) {
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
            fwrite($out, Codec::json($reply) . "\n");
            fflush($out);
        }
        $this->runtime->close();
    }
}
