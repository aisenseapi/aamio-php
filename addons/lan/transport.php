<?php

declare(strict_types=1);

/*
 * aamio over the local network, for PHP: a transport under contract 3 that sends each frame as one UDP datagram.
 *
 * The same protocol as aamio_lan.transport in aamio-python and transport.js in aamio-js, so any of them can talk to any
 * other. A frame to nobody in particular goes to the broadcast address of the network and to the peers named in
 * AAMIO_LAN_PEERS; a frame to an address goes to that address alone. An address is lan:<ip>:<port>. Each datagram is
 * "AL1", eight bytes that name this process, and the frame: a transport drops what it sent itself, which a broadcast
 * brings back to it, and anything that is not of this kind. The core seals and verifies; the network sees that frames
 * go, how big they are and the hints in them.
 *
 * The port is opened after hello, and never when hello says probe: enable then only sees that this starts and
 * describes itself. A port that cannot be opened, as one another program holds, is said as down and tried again every
 * two seconds, and said as up with room once it opens.
 *
 * One stream_select waits on the core and the network alike. On Windows a process cannot wait on its standard input
 * that way: stream_select never wakes for it, and a read that does not block never returns anything. So there this
 * file runs twice. The first process reads standard input in blocking reads and passes each line on, over a socket on
 * 127.0.0.1 that only a process given its token is let through, to a second process of this file, which waits on that
 * socket and on the network at once and writes to the standard output the first one gave it.
 *
 *   AAMIO_LAN_PORT       the UDP port this transport listens on, 47474 if left out
 *   AAMIO_LAN_BIND       the address it listens on, 0.0.0.0 if left out
 *   AAMIO_LAN_BROADCAST  where a frame to nobody in particular goes, 255.255.255.255 if left out, none for nowhere
 *   AAMIO_LAN_PEERS      host:port of other transports it also sends such frames to, comma separated
 */

const LAN_NAME = 'lan';
const LAN_VERSION = '0.1.0';
const LAN_MAGIC = 'AL1';
const LAN_CREDIT = 32;
const LAN_MAX_FRAME = 1200;
const LAN_RETRY_SECONDS = 2.0;

function lan_env(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false ? $default : $value;
}

function lan_say(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

function lan_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function lan_unb64url(string $text): string|false
{
    return base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
}

$relay = getenv('AAMIO_LAN_RELAY');
if (PHP_OS_FAMILY === 'Windows' && $relay === false) {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, 'aamio-lan: no socket on 127.0.0.1 for the second process: ' . $errstr . "\n");
        exit(1);
    }
    $token = bin2hex(random_bytes(16));
    $env = getenv();
    $env['AAMIO_LAN_RELAY'] = stream_socket_get_name($server, false) . '/' . $token;
    $worker = proc_open([PHP_BINARY, __FILE__], [0 => ['file', 'NUL', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
    $conn = false;
    $until = hrtime(true) / 1e9 + 10;
    while ($worker !== false && $conn === false && hrtime(true) / 1e9 < $until) {
        $candidate = @stream_socket_accept($server, max(0.1, $until - hrtime(true) / 1e9));
        // Only the second process knows the token: anything else that connects is closed and waited past.
        if ($candidate !== false && trim((string) fgets($candidate)) === $token) {
            $conn = $candidate;
        } elseif ($candidate !== false) {
            fclose($candidate);
        }
    }
    fclose($server);
    if ($conn === false) {
        fwrite(STDERR, "aamio-lan: the second process did not start\n");
        if ($worker !== false) {
            proc_terminate($worker);
        }
        exit(1);
    }
    while (($line = fgets(STDIN)) !== false) {
        if (@fwrite($conn, $line) === false) {
            break;
        }
        $message = json_decode($line, true);
        if (is_array($message) && ($message['t'] ?? null) === 'stop') {
            break;
        }
    }
    // The second process ends when the socket closes, stopped or not.
    fclose($conn);
    proc_close($worker);
    exit(0);
}
$in = STDIN;
if ($relay !== false) {
    [$relayAddress, $relayToken] = array_pad(explode('/', $relay, 2), 2, '');
    $in = @stream_socket_client('tcp://' . $relayAddress, $errno, $errstr, 10);
    if ($in === false) {
        fwrite(STDERR, 'aamio-lan: the first process could not be reached: ' . $errstr . "\n");
        exit(1);
    }
    fwrite($in, $relayToken . "\n");
}

$portText = lan_env('AAMIO_LAN_PORT', '47474');
if (preg_match('/\A[0-9]{1,5}\z/', $portText) !== 1 || (int) $portText < 1 || (int) $portText > 65535) {
    fwrite(STDERR, 'aamio-lan: AAMIO_LAN_PORT is no port: ' . $portText . "\n");
    exit(1);
}
$port = (int) $portText;
$bind = lan_env('AAMIO_LAN_BIND', '0.0.0.0');
$broadcastSetting = trim(lan_env('AAMIO_LAN_BROADCAST', '255.255.255.255'));
$broadcast = in_array(strtolower($broadcastSetting), ['', 'none'], true) ? null : $broadcastSetting;
$peers = [];
foreach (explode(',', lan_env('AAMIO_LAN_PEERS', '')) as $part) {
    $part = trim($part);
    if ($part === '') {
        continue;
    }
    $at = strrpos($part, ':');
    $host = $at === false ? '' : substr($part, 0, $at);
    $peerPort = $at === false ? '' : substr($part, $at + 1);
    $ip = $host === '' ? false : gethostbyname($host);
    if ($ip === false || preg_match('/\A[0-9]{1,5}\z/', $peerPort) !== 1 || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        fwrite(STDERR, 'aamio-lan: ' . json_encode($part) . " is no host:port, and is left out\n");
        continue;
    }
    $peers[] = [$ip, (int) $peerPort];
}
$me = random_bytes(8);
$socket = null;
$opening = false;
$saidDown = false;
$nextTry = 0.0;
$sendsRead = 0;
$pending = '';

$now = static fn (): float => hrtime(true) / 1e9;

$open = static function () use (&$socket, &$saidDown, &$nextTry, &$sendsRead, $bind, $port, $now): void {
    // PHP sets SO_REUSEADDR unless told not to, and on Windows two processes can then hold one UDP port, each getting
    // some of what comes: a message a partner sent would reach the other one and be refused there, with nothing said.
    $context = stream_context_create(['socket' => ['so_broadcast' => true, 'so_reuseaddr' => false]]);
    $errno = 0;
    $errstr = '';
    $made = @stream_socket_server('udp://' . $bind . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND, $context);
    if ($made === false) {
        if (!$saidDown) {
            lan_say(['t' => 'status', 'state' => 'down', 'reason' => 'port ' . $port . ' on ' . $bind . ' could not be opened: ' . ($errstr !== '' ? $errstr : 'error ' . $errno)]);
            $saidDown = true;
        }
        $nextTry = $now() + LAN_RETRY_SECONDS;

        return;
    }
    stream_set_blocking($made, false);
    $socket = $made;
    if ($saidDown) {
        lan_say(['t' => 'status', 'state' => 'up', 'reason' => 'port ' . $port . ' is open']);
    }
    lan_say(['t' => 'ready', 'credit' => LAN_CREDIT, 'sends_read' => $sendsRead]);
};

$targets = static function (?string $to) use (&$peers, $broadcast, $port): ?array {
    if ($to !== null) {
        $parts = explode(':', $to);
        if (count($parts) !== 3 || $parts[0] !== 'lan' || $parts[1] === '' || preg_match('/\A[0-9]{1,5}\z/', $parts[2]) !== 1) {
            return null;
        }

        return [[$parts[1], (int) $parts[2]]];
    }
    $found = $peers;
    if ($broadcast !== null) {
        $found[] = [$broadcast, $port];
    }

    return $found;
};

$send = static function (array $message) use (&$socket, $me, $targets): array {
    $id = $message['id'] ?? null;
    $frame = is_string($message['frame'] ?? null) ? lan_unb64url($message['frame']) : false;
    if ($frame === false) {
        return ['t' => 'error', 'id' => $id, 'code' => 'bad_frame', 'message' => 'the frame is not base64url'];
    }
    if ($socket === null) {
        return ['t' => 'error', 'id' => $id, 'code' => 'down', 'message' => 'the port is not open'];
    }
    if (strlen($frame) > LAN_MAX_FRAME) {
        return ['t' => 'error', 'id' => $id, 'code' => 'too_large', 'message' => 'the frame is larger than ' . LAN_MAX_FRAME . ' bytes'];
    }
    $to = $message['to'] ?? null;
    $found = $targets(is_string($to) ? $to : null);
    if ($found === null) {
        return ['t' => 'error', 'id' => $id, 'code' => 'bad_address', 'message' => 'an address is lan:<ip>:<port>'];
    }
    if ($found === []) {
        return ['t' => 'error', 'id' => $id, 'code' => 'nowhere', 'message' => 'no broadcast and no peers: a frame to nobody in particular has nowhere to go'];
    }
    $datagram = LAN_MAGIC . $me . $frame;
    $sent = 0;
    foreach ($found as [$host, $peerPort]) {
        if (@stream_socket_sendto($socket, $datagram, 0, $host . ':' . $peerPort) === strlen($datagram)) {
            $sent++;
        } else {
            fwrite(STDERR, 'aamio-lan: a frame to ' . $host . ':' . $peerPort . " did not go\n");
        }
    }
    if ($sent === 0) {
        return ['t' => 'error', 'id' => $id, 'code' => 'send_failed', 'message' => 'the frame could not be given to the network'];
    }

    return ['t' => 'sent', 'id' => $id];
};

$hear = static function () use (&$socket, $me): void {
    while ($socket !== null) {
        $from = '';
        // A datagram to a port nobody listens on comes back on Windows as an error on the next receive. On the network a
        // datagram that finds nobody is just gone, and listening goes on.
        $data = @stream_socket_recvfrom($socket, 65535, 0, $from);
        if ($data === false || $data === '') {
            return;
        }
        if (strlen($data) < strlen(LAN_MAGIC) + 8 + 10 || !str_starts_with($data, LAN_MAGIC) || substr($data, 3, 8) === $me) {
            continue;
        }
        $frame = substr($data, 11);
        if (strlen($frame) > LAN_MAX_FRAME) {
            continue;
        }
        lan_say(['t' => 'received', 'from' => 'lan:' . $from, 'frame' => lan_b64url($frame)]);
    }
};

stream_set_blocking($in, false);
while (true) {
    $read = [$in];
    if ($socket !== null) {
        $read[] = $socket;
    }
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 200000);
    if ($ready === false) {
        usleep(50000);
    }
    if ($socket !== null && in_array($socket, $read, true)) {
        $hear();
    }
    if (in_array($in, $read, true)) {
        $chunk = fread($in, 65536);
        if ($chunk === false || ($chunk === '' && feof($in))) {
            break;
        }
        $pending .= $chunk;
        $stop = false;
        while (($at = strpos($pending, "\n")) !== false) {
            $line = substr($pending, 0, $at);
            $pending = substr($pending, $at + 1);
            $message = json_decode($line, true);
            if (!is_array($message)) {
                continue;
            }
            $kind = $message['t'] ?? null;
            if ($kind === 'hello') {
                lan_say(['t' => 'describe', 'name' => LAN_NAME, 'version' => LAN_VERSION, 'aamio_addon' => 3, 'medium' => 'lan',
                    'properties' => ['max_frame_up' => LAN_MAX_FRAME, 'max_frame_down' => LAN_MAX_FRAME, 'rate_up' => 1000000, 'rate_down' => 1000000,
                        'direction' => 'duplex', 'addressing' => 'device', 'link_ack' => false, 'airtime' => null,
                        'metadata_visible_to' => 'range', 'medium_encrypts' => false]]);
                if (($message['probe'] ?? false) !== true) {
                    $opening = true;
                    $open();
                }
            } elseif ($kind === 'send') {
                $sendsRead++;
                lan_say($send($message));
                lan_say(['t' => 'ready', 'credit' => LAN_CREDIT, 'sends_read' => $sendsRead]);
            } elseif ($kind === 'stop') {
                $stop = true;
                break;
            }
            // cancel: a datagram is on the network as soon as it is given, so nothing is left to cancel
        }
        if ($stop) {
            break;
        }
    }
    if ($opening && $socket === null && $now() >= $nextTry) {
        $open();
    }
}
if ($socket !== null) {
    fclose($socket);
}
