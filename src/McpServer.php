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
    private array $tools;
    private string $instructions;
    private array $supported;

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
                    return self::resultOf($r->send(self::text($a, 'to', true), self::text($a, 'text'), self::map($a, 'data')));
                case 'aamio_read':
                    $asked = self::number($a, 'limit');
                    $budget = isset($a['max_bytes']) ? self::number($a, 'max_bytes') : null;

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
        switch ($method) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? null;
                $version = in_array($requested, $this->supported, true) ? $requested : '2025-11-25';

                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'aamio', 'version' => Http::VERSION], 'instructions' => $this->instructions]];
            case 'ping':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
            case 'tools/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $this->tools]];
            case 'tools/call':
                $name = is_string($params['name'] ?? null) ? $params['name'] : '';
                $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $result = $this->dispatch($name, $arguments);
                if ($result === null) {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool: ' . $name]];
                }

                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
            case 'resources/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
            case 'prompts/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
            case 'resources/templates/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resourceTemplates' => []]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
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
