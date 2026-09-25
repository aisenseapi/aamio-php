<?php

declare(strict_types=1);

// Included by run.php, sharing its checks. No network: the transport hook
// refuses every call and records it, and nothing here is expected to reach it.
//
// The local MCP server delivers the revision it announces. It has said
// 2026-07-28 in mcp-tools.json since that revision was published, and answered
// every request the way the revisions before it want: server/discover was an
// unknown method, tools/list carried only the tools, and ping was {}. That
// revision has no handshake and requires resultType on every result, with
// ttlMs and cacheScope on every list. A client that speaks it validates each
// answer: Claude Code refused the hosted service's tools/list with "Invalid
// result for tools/list: missing required resultType" and showed zero tools
// from 16 to 21 September 2026, until the service was fixed. The local servers
// had the same gap. Finding MCP-1 of the collaboration round of 21 September.
//
// The older revisions are strict the other way: their reference SDK's empty
// result refuses any field, so an older client must get exactly what it got.
// The checks are the ones the service's self-test runs, for both eras.
use Aamio\Http;
use Aamio\McpServer;
use Aamio\Runtime;

echo "mcp: the revision it announces\n";

$mcpHome = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aamio-php-mcp-revision-' . getmypid();
$mcpHeld = Http::$override;
$mcpReached = [];
Http::$override = static function (string $method, string $url) use (&$mcpReached): array {
    $mcpReached[] = $method . ' ' . $url;

    return [0, null, []];
};
$mcpRuntime = new Runtime($mcpHome, 'https://aamio.invalid');
$mcpServer = new McpServer($mcpRuntime);
$mcpSupported = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
$mcpOlder = ['2025-11-25', '2025-06-18', '2025-03-26'];
$mcpLists = ['tools/list' => 'tools', 'prompts/list' => 'prompts', 'resources/list' => 'resources', 'resources/templates/list' => 'resourceTemplates'];

/** A request the way a 2026-07-28 client sends it, or with version null the way an older one does. */
$mcpRequest = static function (string $method, array $params = [], ?string $version = '2026-07-28'): array {
    if ($version !== null) {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'check', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
    }

    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
};
$mcpAnswer = static fn (McpServer $server, string $method, array $params = [], ?string $version = '2026-07-28'): ?array => $server->handle($mcpRequest($method, $params, $version));
/** A server in which initialize settled on a revision, the way an older client opens. */
$mcpOpenedAt = static function (string $requested) use ($mcpRuntime): array {
    $server = new McpServer($mcpRuntime);
    $reply = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $requested]]);

    return [$server, $reply['result'] ?? []];
};

$mcpDiscover = $mcpAnswer($mcpServer, 'server/discover')['result'] ?? [];
$check(($mcpDiscover['resultType'] ?? null) === 'complete' && ($mcpDiscover['supportedVersions'] ?? null) === $mcpSupported && ($mcpDiscover['_meta']['io.modelcontextprotocol/serverInfo'] ?? null) === ['name' => 'aamio', 'version' => Http::VERSION], 'server/discover names the versions and the server');
$check(($mcpDiscover['capabilities']['tools']['listChanged'] ?? null) === false && is_string($mcpDiscover['instructions'] ?? null) && str_contains($mcpDiscover['instructions'], 'llms.txt') && ($mcpDiscover['ttlMs'] ?? 0) > 0 && ($mcpDiscover['cacheScope'] ?? null) === 'public', 'and the capabilities, the instructions and how long to cache them');
$check(($mcpAnswer($mcpServer, 'server/discover', [], null)['result']['supportedVersions'] ?? null) === $mcpSupported, 'a legacy client may ask too');

$mcpListed = $mcpAnswer($mcpServer, 'tools/list')['result'] ?? [];
$check(count($mcpListed['tools'] ?? []) === 23 && ($mcpListed['tools'][0]['name'] ?? null) === 'aamio_whoami', 'a modern tools/list has the twenty-two tools');
// The shape the revision requires, not only the count.
$check(($mcpListed['resultType'] ?? null) === 'complete' && ($mcpListed['cacheScope'] ?? null) === 'public' && ($mcpListed['ttlMs'] ?? 0) > 0, 'and it carries resultType, ttlMs and cacheScope, which 2026-07-28 requires on a list and a client of that revision refuses it without');
$mcpShapeless = [];
foreach (['ping', 'prompts/list', 'resources/list', 'resources/templates/list', 'server/discover'] as $mcpMethod) {
    $mcpResult = $mcpAnswer($mcpServer, $mcpMethod)['result'] ?? [];
    if (($mcpResult['resultType'] ?? null) !== 'complete') {
        $mcpShapeless[] = $mcpMethod;
    }
    if ($mcpMethod !== 'ping' && (($mcpResult['cacheScope'] ?? null) !== 'public' || ($mcpResult['ttlMs'] ?? 0) <= 0)) {
        $mcpShapeless[] = $mcpMethod . ' (ttlMs, cacheScope)';
    }
    if (isset($mcpLists[$mcpMethod]) && ($mcpResult[$mcpLists[$mcpMethod]] ?? null) !== []) {
        $mcpShapeless[] = $mcpMethod . ' (items)';
    }
}
$check($mcpShapeless === [], 'every other result a modern client can ask for carries resultType, and every list ttlMs and cacheScope' . ($mcpShapeless === [] ? '' : ': ' . implode(', ', $mcpShapeless)));

$mcpCalled = $mcpAnswer($mcpServer, 'tools/call', ['name' => 'aamio_scopes', 'arguments' => []])['result'] ?? [];
$check(($mcpCalled['isError'] ?? null) === false && ($mcpCalled['structuredContent'] ?? null) === ['scopes' => []] && json_decode((string) ($mcpCalled['content'][0]['text'] ?? ''), true) === ['scopes' => []], 'a modern tools/call is served');
$check(($mcpCalled['resultType'] ?? null) === 'complete' && is_array($mcpCalled['content'] ?? null) && ($mcpCalled['content'][0]['type'] ?? null) === 'text', 'and its result carries resultType and content, which the revision requires on a call');
// A refused budget is decided before the runtime is touched.
$mcpRefused = $mcpAnswer($mcpServer, 'tools/call', ['name' => 'aamio_read', 'arguments' => ['max_bytes' => 1]])['result'] ?? [];
$check(($mcpRefused['isError'] ?? null) === true && ($mcpRefused['resultType'] ?? null) === 'complete' && is_array($mcpRefused['content'] ?? null) && str_contains((string) ($mcpRefused['structuredContent']['error'] ?? ''), '512'), 'a tool error is a result with resultType and content, and isError says what it is');

$mcpStayed = true;
foreach (['2026-07-28', null] as $mcpVersion) {
    $mcpUnknownTool = $mcpAnswer($mcpServer, 'tools/call', ['name' => 'aamio_nope', 'arguments' => []], $mcpVersion);
    $mcpUnknownMethod = $mcpAnswer($mcpServer, 'nope', [], $mcpVersion);
    $mcpStayed = $mcpStayed && ($mcpUnknownTool['error']['code'] ?? null) === -32602 && !isset($mcpUnknownTool['result']) && ($mcpUnknownMethod['error']['code'] ?? null) === -32601 && !isset($mcpUnknownMethod['result']) && str_contains((string) ($mcpUnknownMethod['error']['message'] ?? ''), 'server/discover');
}
$check($mcpStayed, 'an unknown tool and an unknown method stay protocol errors in both eras, and the message lists server/discover among what is answered');

$check(($mcpAnswer($mcpServer, 'ping')['result'] ?? null) === ['resultType' => 'complete'], 'a modern ping carries resultType');
$mcpEmpty = true;
foreach (array_merge([null], $mcpOlder) as $mcpVersion) {
    $mcpPinged = $mcpAnswer($mcpServer, 'ping', [], $mcpVersion)['result'] ?? null;
    $mcpEmpty = $mcpEmpty && $mcpPinged instanceof \stdClass && json_encode($mcpPinged) === '{}';
}
$check($mcpEmpty, 'an older ping is {} and nothing else, since the reference SDK of those revisions refuses any field in it');

$mcpExact = true;
foreach (array_merge([null], $mcpOlder) as $mcpVersion) {
    $mcpExact = $mcpExact && array_keys($mcpAnswer($mcpServer, 'tools/list', [], $mcpVersion)['result'] ?? []) === ['tools'];
    foreach ($mcpLists as $mcpMethod => $mcpField) {
        $mcpExact = $mcpExact && ($mcpMethod === 'tools/list' || ($mcpAnswer($mcpServer, $mcpMethod, [], $mcpVersion)['result'] ?? null) === [$mcpField => []]);
    }
    $mcpExact = $mcpExact && array_keys($mcpAnswer($mcpServer, 'tools/call', ['name' => 'aamio_scopes', 'arguments' => []], $mcpVersion)['result'] ?? []) === ['content', 'structuredContent', 'isError'];
}
$check($mcpExact, 'an older client gets exactly the shapes it had: the items alone on a list, and content, structuredContent and isError on a call');

// The hosted service refuses 2031-01-01 with -32022, and so does this server
// now: the shape such a client wants is unknown, and the older shape was a
// guess. Finding N7 of the health check of 21 September 2026. An unknown tool
// named in the same request would answer -32602 if dispatch came first.
$mcpRefused = true;
foreach (['ping' => [], 'tools/list' => [], 'server/discover' => [], 'tools/call' => ['name' => 'aamio_nope', 'arguments' => []]] as $mcpMethod => $mcpParams) {
    $mcpReply = $mcpAnswer($mcpServer, $mcpMethod, $mcpParams, '2031-01-01');
    $mcpRefused = $mcpRefused && !isset($mcpReply['result']) && ($mcpReply['error']['code'] ?? null) === -32022 && ($mcpReply['error']['data'] ?? null) === ['supported' => $mcpSupported, 'requested' => '2031-01-01'] && str_contains((string) ($mcpReply['error']['message'] ?? ''), '2026-07-28');
}
$check($mcpRefused, 'a revision this server does not know is refused with -32022 before anything is done, naming what is supported, as the hosted service refuses it');

[$mcpOpened, $mcpInit] = $mcpOpenedAt('2026-07-28');
$mcpListedAfter = $mcpAnswer($mcpOpened, 'tools/list', [], null)['result'] ?? [];
$check(($mcpInit['protocolVersion'] ?? null) === '2026-07-28' && ($mcpInit['resultType'] ?? null) === 'complete' && ($mcpInit['serverInfo']['version'] ?? null) === Http::VERSION, 'initialize at 2026-07-28 settles on it, and its own result carries resultType');
// No _meta on these, as a client that opened with initialize sends them.
$check(($mcpListedAfter['resultType'] ?? null) === 'complete' && ($mcpListedAfter['ttlMs'] ?? 0) > 0 && ($mcpListedAfter['cacheScope'] ?? null) === 'public' && ($mcpAnswer($mcpOpened, 'ping', [], null)['result'] ?? null) === ['resultType' => 'complete'] && ($mcpAnswer($mcpOpened, 'tools/call', ['name' => 'aamio_scopes', 'arguments' => []], null)['result']['resultType'] ?? null) === 'complete', 'and what it settled holds for every later request that names no revision itself');
$check(($mcpAnswer($mcpOpened, 'ping', [], '2025-11-25')['result'] ?? null) instanceof \stdClass, 'while a request that names an older revision itself is served that way, whatever was settled');
$mcpOlderHold = true;
foreach (array_merge($mcpOlder, ['2031-01-01']) as $mcpRequested) {
    [$mcpOlderServer, $mcpOlderInit] = $mcpOpenedAt($mcpRequested);
    $mcpOlderHold = $mcpOlderHold && ($mcpOlderInit['protocolVersion'] ?? null) === (in_array($mcpRequested, $mcpOlder, true) ? $mcpRequested : '2025-11-25') && !isset($mcpOlderInit['resultType']) && ($mcpAnswer($mcpOlderServer, 'ping', [], null)['result'] ?? null) instanceof \stdClass && array_keys($mcpAnswer($mcpOlderServer, 'tools/list', [], null)['result'] ?? []) === ['tools'];
}
$check($mcpOlderHold, 'an older initialize, or one naming a revision this server lacks, settles the older shapes for the rest of the process');

$check($mcpReached === [], 'and none of this reached the network' . ($mcpReached === [] ? '' : ': ' . implode(', ', $mcpReached)));

$mcpRuntime->close();
Http::$override = $mcpHeld;
$mcpSweep = static function (string $dir) use (&$mcpSweep): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? $mcpSweep($path) : @unlink($path);
    }
    @rmdir($dir);
};
$mcpSweep($mcpHome);
