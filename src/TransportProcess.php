<?php

declare(strict_types=1);

namespace Aamio;

/**
 * The host side of contract 3: one transport process, its lines, its room and
 * its frames (part B of docs/aamio-tillegg-kontrakt-3-2026-11-15.md in the
 * service repository).
 *
 * The process gets sockets for standard input, output and error, not pipes:
 * stream_select does not work on pipes on Windows, and a transport has to be
 * read while it runs. Room is only what a ready gives, credit after the sends
 * the transport had read, so lines that cross still leave one answer (B3.1).
 * The first answer for a frame decides its status, also after its deadline
 * (B3.2). A transport is hung when three frames in a row reach their deadline
 * unanswered, or when frames wait 60 seconds for room no ready gave; what else
 * it says does not count as progress. A transport that says down gets no frames
 * until it says up and a new ready gives room, and one that has said down for
 * 60 seconds is stopped to be started again.
 *
 * Writing never blocks: lines wait in order and go as far as the transport takes
 * them, a whole line at a time, so a transport that stops reading never holds up
 * the owner, its deadlines or its stop, and a cancel or a stop never lands inside
 * another line. A frame's deadline runs from when it is given. A send line not yet
 * begun when its deadline comes is withdrawn and not sent, and the room it held
 * comes back; one begun or written is unknown.
 *
 * A ready may say where others reach the transport now (B3: here). The latest is
 * kept, and said as an event only when it changed; one that cannot be taken is
 * logged and left out, and never stops a transport that moves frames. What the
 * transport said of the last frame it could not send is kept as well, for a send
 * that ends without an answer. Standard error is read as UTF-8, as contract 3
 * asks, or else in the system's own code page where PHP can read it.
 */
final class TransportProcess
{
    public const LINE_LIMIT = 262144;
    public const DESCRIBE_SECONDS = 10;
    public const STOP_SECONDS = 5;
    public const HANG_TIMEOUTS = 3;
    public const HANG_ROOM_SECONDS = 60;
    public const WRITE_QUEUE = 1024;
    public const WRITE_PIECE = 8192;
    /** A frame that settled is kept this long past its deadline, for a late answer and for status, and then forgotten. */
    public const FORGET_SECONDS = 600;
    /** here on a ready (B3): where others reach this transport now, at most this many addresses. */
    public const HERE_MOST = 4;
    /** An address in here is printable ASCII without spaces, as a card can carry it: medium:rest. */
    public const HERE_ADDRESS = '/\A[\x21-\x7e]{1,128}\z/';

    /** @var resource|null */
    private $process = null;
    private array $pipes = [];
    private string $buffer = '';
    private string $errors = '';
    public ?array $properties = null;
    /** The medium describe named. */
    public ?string $medium = null;
    public int $written = 0;
    public int $credit = 0;
    public int $sendsRead = 0;
    public array $frames = [];
    private int $counter = 0;
    private int $timeoutsInARow = 0;
    public ?float $waitingForRoomSince = null;
    /** when the transport said down, while it has not said up again */
    private ?float $downSince = null;
    /** down was said: no room until a ready that comes after up */
    private bool $afterDown = false;
    /** @var list<array{data: string, offset: int, frame: ?string}> lines on their way, in order */
    private array $out = [];
    public ?TransportFailed $failed = null;
    /** @var callable(): float */
    private $clock;
    /** @var callable(string): void */
    private $log;
    /** @var list<array> lines put in by hand, for the tests */
    public array $injected = [];
    /** @var list<array> what the transport said before start returned, for the first poll after it */
    private array $early = [];
    /** Lines taken from the transport in all. */
    private int $said = 0;
    /** @var list<string> the last lines it wrote on standard error */
    private array $lastWords = [];
    /** @var list<string>|null where others reach it now, as its latest ready said, or null while it has said nothing */
    public ?array $here = null;
    /** A ready has come since the start. */
    public bool $readied = false;
    /** @var array{0: float, 1: string, 2: ?string}|null the clock, code and message of the latest error it gave for a frame */
    public ?array $lastError = null;

    public function __construct(public readonly string $name, public readonly string $version, private array $command, private ?string $cwd, private array $env, ?callable $clock = null, ?callable $log = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->log = $log ?? static function (string $line): void {
        };
    }

    /** Contract 2's short list of variables, AAMIO_ADDON=3 and what the manifest declares; never the home's whereabouts. */
    public static function environment(array $declared, ?array $base = null): array
    {
        $base ??= getenv();
        $env = [];
        foreach ($base as $key => $value) {
            if (in_array(strtoupper((string) $key), AddonProcess::ENVIRONMENT, true)) {
                $env[$key] = $value;
            }
        }
        foreach ($declared as $name) {
            if (!in_array(strtoupper($name), AddonProcess::NEVER_PASSED, true) && array_key_exists($name, $base)) {
                $env[$name] = $base[$name];
            }
        }
        $env['AAMIO_ADDON'] = '3';

        return $env;
    }

    /**
     * Bytes a process wrote, as text: UTF-8, as contract 3 asks, or else the system's own code page.
     *
     * Python on Windows writes standard error to a pipe in the ANSI code page, so the system's own words for an error,
     * as Norwegian ones, came out as replacement characters (the field test over Bluetooth of 19 November 2026). This
     * PHP has no mbstring or iconv to count on: on Windows the ANSI code page is read with sapi_windows_cp_conv, and where
     * that cannot be had, what is not UTF-8 becomes U+FFFD, as aamio-python reads it where its code page is UTF-8.
     */
    public static function textOf(string $raw): string
    {
        if (preg_match('//u', $raw) === 1) {
            return $raw;
        }
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_get') && function_exists('sapi_windows_cp_conv')) {
            try {
                $page = sapi_windows_cp_get('ansi');
                $text = $page !== 65001 ? sapi_windows_cp_conv($page, 65001, $raw) : null;
            } catch (\Throwable) {
                $text = null;
            }
            if (is_string($text) && preg_match('//u', $text) === 1) {
                return $text;
            }
        }
        $text = json_decode((string) json_encode($raw, JSON_INVALID_UTF8_SUBSTITUTE));

        return is_string($text) ? $text : '';
    }

    /**
     * The addresses in a ready's here, or null when they cannot be taken: a list of at most HERE_MOST addresses, each
     * medium:rest in printable ASCII without spaces, at most 128 characters, each once.
     *
     * @return list<string>|null
     */
    public static function hereOf(mixed $value, ?string $medium): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::HERE_MOST || $medium === null) {
            return null;
        }
        foreach ($value as $address) {
            if (!is_string($address) || preg_match(self::HERE_ADDRESS, $address) !== 1 || !str_starts_with($address, $medium . ':') || strlen($address) === strlen($medium) + 1) {
                return null;
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            return null;
        }

        return $value;
    }

    /** The first $most characters of UTF-8 text, as Python cuts a string: a cut by bytes can split a letter in two, and JSON then refuses all of it. */
    public static function cut(string $text, int $most): string
    {
        return preg_match('/\A.{0,' . $most . '}/su', $text, $found) === 1 ? $found[0] : substr($text, 0, $most);
    }

    /** B4: what describe says about the medium, inside its bounds, or TransportFailed. */
    public static function checkProperties(mixed $properties): array
    {
        if (!is_array($properties)) {
            throw new TransportFailed('transport_bad_describe', 'properties is not an object');
        }
        foreach (['max_frame_up', 'max_frame_down'] as $key) {
            if (!is_int($properties[$key] ?? null) || $properties[$key] < 10 || $properties[$key] > 65535) {
                throw new TransportFailed('transport_bad_describe', $key . ' is a whole number of 10 to 65535 bytes');
            }
        }
        foreach (['rate_up', 'rate_down'] as $key) {
            if (!(is_int($properties[$key] ?? null) || is_float($properties[$key] ?? null)) || $properties[$key] <= 0) {
                throw new TransportFailed('transport_bad_describe', $key . ' is a positive number of bytes a second');
            }
        }
        foreach (['direction' => ['duplex', 'half', 'send_only', 'receive_only'], 'addressing' => ['none', 'device', 'routed'],
                  'metadata_visible_to' => ['range', 'local', 'operator']] as $key => $allowed) {
            if (!in_array($properties[$key] ?? null, $allowed, true)) {
                throw new TransportFailed('transport_bad_describe', $key . ' is one of ' . implode(', ', $allowed));
            }
        }
        foreach (['link_ack', 'medium_encrypts'] as $key) {
            if (!is_bool($properties[$key] ?? null)) {
                throw new TransportFailed('transport_bad_describe', $key . ' is true or false');
            }
        }
        $airtime = $properties['airtime'] ?? null;
        if ($airtime !== null && !(is_array($airtime) && (is_int($airtime['percent'] ?? null) || is_float($airtime['percent'] ?? null))
                && $airtime['percent'] > 0 && $airtime['percent'] <= 100 && is_int($airtime['window_s'] ?? null) && $airtime['window_s'] > 0)) {
            throw new TransportFailed('transport_bad_describe', 'airtime is null or {percent, window_s}');
        }

        return $properties;
    }

    // -------------------------------------------------------- the process --

    /** hello, and the describe that answers it. With $probe the transport is asked to take nothing of its medium in use (B3). */
    public function start(string $aamioVersion, string $runtime, string $stateDir, bool $probe = false): array
    {
        $process = @proc_open($this->command, [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $pipes, $this->cwd, $this->env);
        if (!is_resource($process)) {
            $this->fail(new TransportFailed('transport_down', 'the process could not be started'));
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[0], false);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $hello = ['t' => 'hello', 'aamio_addon' => 3, 'aamio' => ['version' => $aamioVersion, 'runtime' => $runtime], 'state_dir' => $stateDir];
        if ($probe) {
            $hello['probe'] = true;
        }
        $this->write($hello);
        $until = ($this->clock)() + self::DESCRIBE_SECONDS;
        while ($this->properties === null) {
            if (($this->clock)() >= $until) {
                $this->fail(new TransportFailed('transport_timeout', 'no describe within 10 seconds of hello: ' . $this->silence()));
            }
            // What comes before describe or with it is kept for whoever polls next: a status, or a frame that
            // came in as the medium opened. It was dropped here, so a port said as down in the same breath as
            // describe was never heard of (a full run of 16 November 2026). Each round waits for a line as
            // before, whatever is kept already.
            foreach ($this->heard(0.2) as $event) {
                $this->early[] = $event;
            }
        }

        return $this->properties;
    }

    /**
     * What is known of a transport that did not describe itself: whether the hello was written to it, whether its
     * process runs, what it said, and its last lines on standard error. Said alone, no describe told nothing of where
     * the start had stopped, and one that stopped once could not be told from any other (16 November 2026).
     */
    private function silence(): string
    {
        $written = $this->out === [] ? 'the hello was written to it' : 'the hello was not written to it';
        $status = is_resource($this->process) ? proc_get_status($this->process) : [];
        $runs = ($status['running'] ?? false) === true ? 'its process runs' : 'its process has ended';
        $said = match (true) {
            $this->said === 0 => 'it said nothing',
            $this->said === 1 => 'it said 1 line that was no describe',
            default => 'it said ' . $this->said . ' lines, none of them a describe',
        };

        return $written . ', ' . $runs . ', and ' . $said . ($this->lastWords === [] ? '' : '. On standard error: ' . implode(' | ', $this->lastWords));
    }

    /** A line on its way: it goes as far as the transport takes it now, and the rest in poll. */
    private function write(array $message, ?string $frame = null): void
    {
        if ($this->failed !== null) {
            throw $this->failed;
        }
        if (count($this->out) >= self::WRITE_QUEUE) {
            $this->fail(new TransportFailed('transport_hung', 'the transport has not read the last ' . self::WRITE_QUEUE . ' lines it was given'));
        }
        $this->out[] = ['data' => json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 'offset' => 0, 'frame' => $frame];
        $this->flushOut();
    }

    /**
     * What the transport takes now, never waiting for it: a line begun is finished before the next one. The socket is
     * set not to block, but that does not hold everywhere (WSL1 lets such a write wait for the reader), so each piece is
     * written only when stream_select says there is room for it, and pieces are small enough to fit that room.
     */
    private function flushOut(): void
    {
        while ($this->out !== [] && $this->process !== null) {
            $read = null;
            $write = [$this->pipes[0]];
            $except = null;
            if (@stream_select($read, $write, $except, 0, 0) < 1) {
                return;
            }
            $line = $this->out[0];
            $taken = @fwrite($this->pipes[0], substr($line['data'], $line['offset'], self::WRITE_PIECE));
            if ($taken === false) {
                $this->out = [];
                $this->fail(new TransportFailed('transport_down', 'the process does not take what is written to it'));
            }
            if ($taken === 0) {
                return;
            }
            $this->out[0]['offset'] += $taken;
            if ($this->out[0]['offset'] < strlen($line['data'])) {
                return;
            }
            array_shift($this->out);
            if ($line['frame'] !== null && isset($this->frames[$line['frame']])) {
                $this->frames[$line['frame']]['written'] = true;
            }
        }
    }

    /** Where the send line of a frame waits, not yet begun, or null when it is begun or gone. */
    private function unstarted(string $id): ?int
    {
        foreach ($this->out as $at => $line) {
            if ($line['frame'] === $id) {
                return $line['offset'] === 0 ? $at : null;
            }
        }

        return null;
    }

    private function fail(TransportFailed $failure): never
    {
        if ($this->failed === null) {
            $this->failed = $failure;
            ($this->log)('transport ' . $this->name . ': ' . $failure->getMessage());
        }
        throw $this->failed;
    }

    /**
     * B2: stop, five seconds to go, then ended. The stop line is written also
     * after a failure, as far as the socket takes it, so a transport that still
     * listens can go at once; and the five seconds are real time whatever clock
     * the rest of this class was given.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        // Lines not yet begun are withdrawn rather than waited for, and the stop line goes next.
        $kept = [];
        foreach ($this->out as $line) {
            if ($line['offset'] > 0) {
                $kept[] = $line;
            } elseif ($line['frame'] !== null && ($this->frames[$line['frame']]['status'] ?? null) === 'waiting') {
                $this->frames[$line['frame']]['status'] = 'not_sent';
                $this->frames[$line['frame']]['reason'] = 'stopped before it was written';
            }
        }
        $this->out = $kept;
        $this->out[] = ['data' => "{\"t\":\"stop\"}\n", 'offset' => 0, 'frame' => null];
        $until = hrtime(true) / 1e9 + self::STOP_SECONDS;
        while ((proc_get_status($this->process)['running'] ?? false) && hrtime(true) / 1e9 < $until) {
            try {
                $this->flushOut();
            } catch (TransportFailed) {
                $this->out = [];
            }
            usleep(50000);
        }
        if (proc_get_status($this->process)['running'] ?? false) {
            proc_terminate($this->process);
        }
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($this->process);
        $this->process = null;
    }

    // --------------------------------------------------- room and frames --

    public function room(): int
    {
        $free = $this->credit - ($this->written - $this->sendsRead);

        // After a down, the room it had is not trusted: only a ready that comes after up gives room again.
        return $this->afterDown ? min($free, 0) : $free;
    }

    /** The owner has frames that wait for room, or has none: B3.2 counts the sixty seconds from the first moment. */
    public function waitingForRoom(bool $waiting): void
    {
        if (!$waiting) {
            $this->waitingForRoomSince = null;
        } elseif ($this->room() <= 0) {
            $this->waitingForRoomSince ??= ($this->clock)();
        }
    }

    /** Give the transport a frame if there is room: its id, or null when there is no room now. */
    public function send(string $frame, ?string $to = null, int $deadlineMs = 30000): ?string
    {
        if ($this->room() <= 0) {
            $this->waitingForRoomSince ??= ($this->clock)();

            return null;
        }
        $this->counter++;
        $id = 'f-' . $this->counter;
        // The frame and its deadline are known before the line goes, so the deadline holds whatever the writing does.
        $this->frames[$id] = ['status' => 'waiting', 'deadline' => ($this->clock)() + $deadlineMs / 1000, 'late' => false, 'answered' => false, 'written' => false];
        $this->written++;
        $this->write(['t' => 'send', 'id' => $id, 'to' => $to, 'frame' => Codec::b64url($frame), 'deadline_ms' => $deadlineMs], $id);

        return $id;
    }

    /** What is known of a frame: waiting, sent, not_sent or unknown, and forgotten once it settled long ago. */
    public function status(string $id): string
    {
        return $this->frames[$id]['status'] ?? 'forgotten';
    }

    /** How many frames wait for an answer from the transport. */
    public function waiting(): int
    {
        return count(array_filter($this->frames, static fn (array $entry): bool => $entry['status'] === 'waiting'));
    }

    /** B3.2: frames past their deadline become unknown and are cancelled; a transport without progress is hung. */
    public function checkTime(): void
    {
        $now = ($this->clock)();
        // A frame that settled is kept for a while for a late answer and for status, and then forgotten, so a transport
        // that runs for days does not carry every frame it was ever given.
        foreach ($this->frames as $id => $entry) {
            if ($entry['status'] !== 'waiting' && $now - $entry['deadline'] > self::FORGET_SECONDS) {
                unset($this->frames[$id]);
            }
        }
        foreach ($this->frames as $id => $entry) {
            if ($entry['status'] === 'waiting' && $now > $entry['deadline']) {
                $this->timeoutsInARow++;
                $at = $this->unstarted((string) $id);
                if ($at !== null) {
                    // It never reached the transport: not sent, and the room it held comes back (B3.1).
                    array_splice($this->out, $at, 1);
                    $this->frames[$id]['status'] = 'not_sent';
                    $this->frames[$id]['reason'] = 'never written before its deadline';
                    $this->written--;
                    continue;
                }
                $this->frames[$id]['status'] = 'unknown';
                try {
                    $this->write(['t' => 'cancel', 'id' => $id]);
                } catch (TransportFailed) {
                }
            }
        }
        if ($this->timeoutsInARow >= self::HANG_TIMEOUTS) {
            $this->fail(new TransportFailed('transport_hung', $this->timeoutsInARow . ' frames in a row reached their deadline without an answer'));
        }
        if ($this->waitingForRoomSince !== null && $now - $this->waitingForRoomSince >= self::HANG_ROOM_SECONDS) {
            $this->fail(new TransportFailed('transport_hung', 'frames waited 60 seconds for room no ready gave'));
        }
        if ($this->downSince !== null && $now - $this->downSince >= self::HANG_ROOM_SECONDS) {
            // Also with nothing to send: a transport that cannot hear is started again (B2), not waited on for good.
            $this->fail(new TransportFailed('transport_down', 'the transport has said down for 60 seconds'));
        }
    }

    /**
     * What the transport said since last time, waiting up to $timeout seconds for
     * the first line: a list of ['received', from, frame], ['answer', id],
     * ['status', state, reason] and ['here', addresses] when where it can be
     * reached changed.
     */
    public function poll(float $timeout = 0.0): array
    {
        // What was said before start returned comes first, and is not waited behind.
        $events = $this->early;
        $this->early = [];
        foreach ($this->heard($events !== [] ? 0.0 : $timeout) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /** @return list<array> the lines that have come, taken in, after a wait of up to $timeout for the first: the events in them */
    private function heard(float $timeout): array
    {
        $events = [];
        $lines = $this->injected;
        $this->injected = [];
        if ($this->process !== null) {
            $this->flushOut();
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = $this->out !== [] ? [$this->pipes[0]] : null;
            $except = null;
            $seconds = (int) floor($timeout);
            if (@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1e6)) > 0) {
                foreach ($read as $stream) {
                    $chunk = (string) @fread($stream, 65536);
                    if ($stream === $this->pipes[2]) {
                        $this->stderr($chunk);
                        continue;
                    }
                    if ($chunk === '' && feof($stream)) {
                        $this->fail(new TransportFailed('transport_down', 'the process ended'));
                    }
                    $this->buffer .= $chunk;
                }
            }
            while (($at = strpos($this->buffer, "\n")) !== false) {
                if ($at > self::LINE_LIMIT) {
                    $this->fail(new TransportFailed('transport_protocol', 'a line longer than 262144 bytes'));
                }
                $lines[] = substr($this->buffer, 0, $at);
                $this->buffer = substr($this->buffer, $at + 1);
            }
            if (strlen($this->buffer) > self::LINE_LIMIT) {
                $this->fail(new TransportFailed('transport_protocol', 'a line longer than 262144 bytes'));
            }
            $this->flushOut();
        }
        foreach ($lines as $line) {
            foreach ($this->take($line) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function stderr(string $chunk): void
    {
        $this->errors .= $chunk;
        while (($at = strpos($this->errors, "\n")) !== false) {
            $words = rtrim(self::textOf(substr($this->errors, 0, $at)));
            $this->lastWords = array_slice([...$this->lastWords, self::cut($words, 200)], -3);
            ($this->log)('transport ' . $this->name . ': ' . $words);
            $this->errors = substr($this->errors, $at + 1);
        }
        if (strlen($this->errors) > 65536) {
            $this->errors = '';
        }
    }

    private function take(string $line): array
    {
        $message = json_decode($line, true);
        if (!is_array($message) || !is_string($message['t'] ?? null)) {
            $this->fail(new TransportFailed('transport_protocol', 'a line that is not a JSON object with t'));
        }
        $this->said++;
        switch ($message['t']) {
            case 'describe':
                if ($this->properties === null) {
                    if (($message['name'] ?? null) !== $this->name || ($message['version'] ?? null) !== $this->version || ($message['aamio_addon'] ?? null) !== 3) {
                        $this->fail(new TransportFailed('transport_bad_describe', 'describe names another add-on, version or contract than its manifest'));
                    }
                    try {
                        $this->properties = self::checkProperties($message['properties'] ?? null);
                    } catch (TransportFailed $failure) {
                        $this->fail($failure);
                    }
                    $this->medium = is_string($message['medium'] ?? null) ? $message['medium'] : null;
                }

                return [];
            case 'ready':
                $credit = $message['credit'] ?? null;
                $sendsRead = $message['sends_read'] ?? null;
                if (!is_int($credit) || $credit < 0 || !is_int($sendsRead) || $sendsRead < $this->sendsRead || $sendsRead > $this->written) {
                    $this->fail(new TransportFailed('transport_protocol', 'ready with credit and sends_read that do not add up'));
                }
                $before = $this->room();
                $this->credit = $credit;
                $this->sendsRead = $sendsRead;
                $this->readied = true;
                if ($this->downSince === null) {
                    $this->afterDown = false;
                }
                if ($this->room() > max($before, 0)) {
                    $this->waitingForRoomSince = null;
                }
                if (!array_key_exists('here', $message)) {
                    return [];
                }
                // Where others reach it is a hint for cards and bindings, never a reason to stop a transport that moves
                // frames: one that cannot be taken is said and left out, and the one said before stands.
                $here = self::hereOf($message['here'], $this->medium);
                if ($here === null) {
                    ($this->log)('transport ' . $this->name . ': here is not taken: it is a list of at most ' . self::HERE_MOST . ' addresses, each '
                        . $this->medium . ':... in printable ASCII without spaces, at most 128 characters');

                    return [];
                }
                if ($here === $this->here) {
                    return [];
                }
                $this->here = $here;

                return [['here', $here]];
            case 'sent':
            case 'error':
            case 'link_ack':
                if ($message['t'] === 'error' && ($message['id'] ?? null) === null) {
                    ($this->log)('transport ' . $this->name . ': ' . ($message['message'] ?? $message['code'] ?? 'error'));

                    return [];
                }
                $answered = $this->answer($message);

                return $answered === null ? [] : [['answer', $answered]];
            case 'received':
                $source = $message['from'] ?? null;
                if ($source !== null && (!is_string($source) || strlen($source) > 128)) {
                    $this->fail(new TransportFailed('transport_protocol', 'from is a string of at most 128 characters, or null'));
                }
                $frame = is_string($message['frame'] ?? null) ? Codec::unb64url($message['frame']) : null;
                if ($frame === null || $frame === '') {
                    $this->fail(new TransportFailed('transport_protocol', 'a received frame that is not base64url'));
                }

                return [['received', $source, $frame]];
            case 'status':
                $state = $message['state'] ?? null;
                if ($state === 'down') {
                    // Room is gone until up and a new ready; the sixty seconds run also with nothing to send.
                    $this->afterDown = true;
                    $this->downSince ??= ($this->clock)();
                } elseif ($state === 'up') {
                    $this->downSince = null;
                }

                return [['status', $state, $message['reason'] ?? null]];
        }
        $this->fail(new TransportFailed('transport_protocol', 'an unknown line ' . $message['t']));
    }

    private function answer(array $message): ?string
    {
        $id = $message['id'] ?? null;
        if (!is_string($id) || !isset($this->frames[$id])) {
            ($this->log)('transport ' . $this->name . ': an answer for a frame it was never given');

            return null;
        }
        $entry = &$this->frames[$id];
        if ($message['t'] === 'link_ack') {
            if ($entry['status'] === 'sent') {
                $entry['link_ack'] = true;
            }

            return null;
        }
        if (!in_array($entry['status'], ['waiting', 'unknown'], true) || $entry['answered']) {
            ($this->log)('transport ' . $this->name . ': a second answer for ' . $id . ' is not taken');

            return null;
        }
        $entry['answered'] = true;
        if ($entry['status'] === 'unknown') {
            $entry['late'] = true;
        } else {
            $this->timeoutsInARow = 0;
        }
        if ($message['t'] === 'sent') {
            $entry['status'] = 'sent';
        } else {
            $entry['status'] = 'not_sent';
            $entry['reason'] = (string) ($message['code'] ?? 'error');
            // What the transport said, kept for a send that ends without an answer: its words name the medium's own
            // trouble, as a Bluetooth address no machine answers on, which the core cannot know (19 November 2026).
            $said = $message['message'] ?? null;
            $this->lastError = [($this->clock)(), $entry['reason'], is_string($said) ? self::cut($said, 300) : null];
        }

        return $id;
    }
}
