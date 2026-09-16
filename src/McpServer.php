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

    public function dispatch(string $name, array $arguments): ?array
    {
        $r = $this->runtime;
        try {
            switch ($name) {
                case 'aamio_whoami':
                    return self::resultOf($r->whoami());
                case 'aamio_partners':
                    return self::resultOf(['partners' => $r->partnerList()]);
                case 'aamio_presence_lookup':
                    return self::resultOf($r->lookup($arguments['names'] ?? null, (int) ($arguments['wait'] ?? 0)));
                case 'aamio_send':
                    return self::resultOf($r->send((string) ($arguments['to'] ?? ''), $arguments['text'] ?? null, is_array($arguments['data'] ?? null) ? $arguments['data'] : null));
                case 'aamio_read':
                    $messages = $r->read((int) ($arguments['wait'] ?? 0));

                    return self::resultOf(['messages' => $messages, 'count' => count($messages)]);
                case 'aamio_receipt':
                    return self::resultOf($r->receipt((string) ($arguments['channel'] ?? 'inbox') ?: 'inbox', (bool) ($arguments['anchor'] ?? false)));
                case 'aamio_open_channel':
                    return self::resultOf($r->openChannel((string) $arguments['label'], (int) $arguments['ttl'], $arguments['allow'] ?? null));
                case 'aamio_channels':
                    return self::resultOf(['channels' => $r->channelList()]);
                case 'aamio_close_channel':
                    return self::resultOf($r->closeChannel((string) $arguments['label']));
                case 'aamio_board_post':
                    return self::resultOf($r->boardPost((string) $arguments['kind'], (string) $arguments['title'], (string) $arguments['text'], $arguments['tags'] ?? null, (int) ($arguments['ttl'] ?? Runtime::BOARD_TTL) ?: Runtime::BOARD_TTL, $arguments['lang'] ?? null, $arguments['deadline'] ?? null));
                case 'aamio_board_find':
                    return self::resultOf($r->boardFind($arguments['kind'] ?? null, $arguments['tags'] ?? null, $arguments['lang'] ?? null, null, (int) ($arguments['after'] ?? 0), (int) ($arguments['wait'] ?? 0), (int) ($arguments['min_work_bits'] ?? 0)));
                case 'aamio_board_answer':
                    return self::resultOf($r->boardAnswer((string) $arguments['post'], $arguments['text'] ?? null, is_array($arguments['data'] ?? null) ? $arguments['data'] : null));
                case 'aamio_board_withdraw':
                    return self::resultOf($r->boardWithdraw((string) $arguments['post']));
                case 'aamio_pending':
                    $pending = array_map(static fn (array $p): array => array_diff_key($p, ['envelope' => 1, 'to_key' => 1]), $r->outboxPending());

                    return self::resultOf(['count' => count($pending), 'pending' => $pending]);
                case 'aamio_board_tags':
                    return self::resultOf($r->boardTags());
            }

            return null;
        } catch (SendFailed $error) {
            [$retryable, $fix] = Runtime::sendAdvice($error->outcome, $error->status);

            return self::resultOf(['error' => $error->getMessage(), 'error_code' => 'send_' . $error->outcome, 'operation' => 'send', 'outcome' => $error->outcome, 'message_id' => $error->messageId, 'status' => $error->status, 'retryable' => $retryable, 'fix' => $fix], true);
        } catch (GateStop $error) {
            return self::resultOf(['error' => $error->getMessage(), 'error_code' => 'gate', 'operation' => 'send', 'retryable' => false, 'fix' => $error->fix], true);
        } catch (\InvalidArgumentException | \RuntimeException | \LogicException $error) {
            return self::resultOf(['error' => $error->getMessage()], true);
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

                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'aamio', 'version' => '0.1.0'], 'instructions' => $this->instructions]];
            case 'ping':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
            case 'tools/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $this->tools]];
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
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

    /** Reads stdin line by line until it closes. */
    public function serve($in = STDIN, $out = STDOUT): void
    {
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
                $replies = array_values(array_filter(array_map([$this, 'handle'], is_array($message) && array_is_list($message) ? $message : [$message]), static fn ($r) => $r !== null));
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
