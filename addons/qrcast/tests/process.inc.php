<?php

declare(strict_types=1);

/*
 * QRcast on contract 2: the process, the two engines, the screen, the camera, the
 * calls to aisenseapi.com, zbarimg, and the real package through the core.
 * Offline, with a fake aisense, a fake screen, a fake reader and a fake camera,
 * except the live check under AAMIO_LIVE=1: a code made at aisenseapi.com and
 * read back there. Nothing here opens a window or a camera. The same checks as
 * aamio-python's tests/test_qrcast_process.py, with zbarimg where Python has
 * zxing-cpp.
 *
 * Included by the core's tests/runtime.php, with $root, $check, $childPhp and
 * $cliRun in scope.
 */

require_once dirname(__DIR__) . '/autoload.php';

use Aamio\AddonProcess;
use Aamio\Addons;
use Aamio\McpServer;
use Aamio\Qrcast\Camera;
use Aamio\Qrcast\NothingSeen;
use Aamio\Qrcast\Process;
use Aamio\Qrcast\Programs;
use Aamio\Qrcast\Reader;
use Aamio\Qrcast\Refused;
use Aamio\Qrcast\Screen;
use Aamio\Qrcast\Service;
use Aamio\Qrcast\Symbol;
use Aamio\Qrcast\Unreachable;
use Aamio\Qrcast\Unreadable;
use Aamio\Runtime;

echo "qrcast: the process\n";

(static function () use ($root, $check, $childPhp, $cliRun): void {
    $address = 'k7rq2m4xw5tbz3yd6ena';
    $other = 'm2n3p4q5r6s7t2u3v4w5';
    $scopeKey = 'abcdefghijklmnopqrstuvwxyz';
    $readKey = '0123456789abcdefghijklmnop';
    $now = 1794150000;
    $card = 'aamio:scope/' . $address . '?n=stue-tv&t=' . ($now + 3600);
    $folder = $root . DIRECTORY_SEPARATOR . 'qrcast';
    mkdir($folder, 0700, true);
    $quiet = static function (string $line): void {
    };
    $request = static fn (string $kind, string $call, array $arguments = []): array => ['aamio_addon' => 2, 'kind' => $kind, 'name' => $call, 'arguments' => $arguments, 'subject' => null, 'own_key' => null, 'state_dir' => null, 'deadline_seconds' => 90, 'aamio' => ['version' => '0.3.13', 'runtime' => 'php']];
    $picture = static function (string $name, ?string $data = null) use ($folder, $card): string {
        $path = $folder . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $data ?? Symbol::png(Symbol::encode($card), 4));

        return $path;
    };
    $caught = static function (callable $try): ?\Throwable {
        try {
            $try();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    };
    /** aisense, the screen, the reader and the camera as the process sees them, and what each was asked. */
    $world = static function (array $texts = [], ?\Throwable $aisense = null, ?\Throwable $screen = null, ?\Throwable $local = null, array $frames = [], array $seen = [], ?\Throwable $camera = null) use ($now): object {
        return new class ($texts, $aisense, $screen, $local, $frames, $seen, $camera, $now) {
            public array $encoded = [];
            public array $decoded = [];
            public array $shown = [];
            public array $read = [];
            public array $asked = [];
            public int $given = 0;
            public bool $closed = false;

            public function __construct(public array $texts, public ?\Throwable $aisense, public ?\Throwable $screen, public ?\Throwable $local, public array $frames, public array $seen, public ?\Throwable $camera, public int $at)
            {
            }

            public function seams(): array
            {
                return [
                    'encode' => function (string $card): string {
                        $this->encoded[] = $card;
                        if ($this->aisense !== null) {
                            throw $this->aisense;
                        }

                        return "\x89PNG\r\n\x1a\naisense";
                    },
                    'decode' => function (string $picture, string $kind): array {
                        $this->decoded[] = [strlen($picture), $kind];
                        if ($this->aisense !== null) {
                            throw $this->aisense;
                        }

                        return $this->texts;
                    },
                    'read_local' => function (string $data): array {
                        $this->read[] = strlen($data);
                        if ($this->local !== null) {
                            throw $this->local;
                        }

                        return $this->texts;
                    },
                    'display' => function (string $path): string {
                        $this->shown[] = [$path, (string) file_get_contents($path)];
                        if ($this->screen !== null) {
                            throw $this->screen;
                        }

                        return 'fake-viewer';
                    },
                    'frames' => function (int $seconds, ?string $which): \Generator {
                        $this->asked[] = [$seconds, $which];
                        try {
                            if ($this->camera !== null) {
                                throw $this->camera;
                            }
                            foreach ($this->frames as $frame) {
                                $this->given++;
                                yield $frame;
                            }
                        } finally {
                            $this->closed = true;
                        }
                    },
                    'read_frame' => fn (int $width, int $height, string $gray): array => $this->seen[$gray] ?? [],
                    'clock' => fn (): int => $this->at,
                ];
            }
        };
    };

    $said = Process::handle($request('probe', 'probe'));
    $check($said === ['result' => ['ok' => true, 'name' => 'qrcast', 'version' => Process::VERSION, 'aamio_addon' => 2], 'exit_code' => 0, 'is_error' => false], 'the probe names the add-on');
    $check(Process::handle(['aamio_addon' => 1, 'kind' => 'command', 'name' => 'card'])['result']['reason'] === 'not_contract_2', 'and a request off contract 2 is refused');
    $w = $world([$card]);
    $made = Process::handle($request('command', 'card', ['address' => $address, 'name' => 'stue-tv']), $w->seams())['result'];
    $check(($made['card'] ?? null) === $card && ($made['bytes'] ?? null) === 55 && ($made['expires_at'] ?? null) === $now + 3600, 'card is the text, good for an hour');

    // show
    foreach ([$scopeKey, $readKey, str_repeat('a1', 32)] as $key) {
        $w = $world();
        $said = Process::handle($request('tool', 'aamio_addon_qrcast_show', ['address' => $key]), $w->seams());
        $check($said['exit_code'] === 2 && $said['result']['reason'] === 'a_key_not_an_address' && $w->shown === [] && $w->encoded === [], 'a key is refused before anything is drawn or sent');
    }
    $w = $world();
    $said = Process::handle($request('command', 'show', ['address' => $address, 'name' => 'stue-tv']), $w->seams());
    $result = $said['result'];
    $check($said['exit_code'] === 0 && $result['status'] === 'shown' && $result['engine'] === 'local' && $result['viewer'] === 'fake-viewer' && $w->encoded === []
        && ($result['code'] ?? null) === ['version' => 4, 'modules' => 33, 'error_correction' => 'M', 'quiet_zone' => 4], 'show draws the code here, sends nothing, and opens it on the screen');
    $check(($w->shown[0][1] ?? '') === Symbol::png(Symbol::encode($card), 24) && $w->shown[0][0] === $result['file'] && str_contains($result['note'], 'aamio board find --scope NAME --wait 25'), 'as a PNG of about 1000 pixels a side, with the next step');
    foreach (['.png' => 'png', '.svg' => 'svg', '.txt' => 'text'] as $ending => $format) {
        $w = $world();
        $out = $folder . DIRECTORY_SEPARATOR . 'card' . $ending;
        $said = Process::handle($request('command', 'show', ['address' => $address, 'name' => 'stue-tv', 'out' => $out]), $w->seams());
        $code = Symbol::encode($card);
        $expected = match ($format) {
            'svg' => Symbol::svg($code),
            'text' => Symbol::text($code),
            default => Symbol::png($code, 24),
        };
        $check($said['exit_code'] === 0 && $said['result']['status'] === 'written' && $said['result']['format'] === $format && file_get_contents($out) === $expected && $w->shown === [], '--out ' . $ending . ' writes the code as ' . $format . ', and shows nothing');
    }
    $there = $folder . DIRECTORY_SEPARATOR . 'there.png';
    file_put_contents($there, 'mine');
    $w = $world();
    $check(Process::handle($request('command', 'show', ['address' => $address, 'out' => $there]), $w->seams())['result']['reason'] === 'out_exists' && file_get_contents($there) === 'mine', 'a file that is there is never written over');
    $check(Process::handle($request('command', 'show', ['address' => $address, 'out' => $folder . DIRECTORY_SEPARATOR . 'card.jpg']), $w->seams())['result']['reason'] === 'invalid_out', 'and the ending says the format');
    $w = $world();
    $out = $folder . DIRECTORY_SEPARATOR . 'theirs.png';
    $said = Process::handle($request('command', 'show', ['address' => $address, 'out' => $out, 'engine' => 'aisense']), $w->seams());
    $check($said['result']['engine'] === 'aisense' && !isset($said['result']['code']) && file_get_contents($out) === "\x89PNG\r\n\x1a\naisense" && count($w->encoded) === 1, 'aisense makes the code when asked');
    $check(Process::handle($request('command', 'show', ['address' => $address, 'out' => $folder . DIRECTORY_SEPARATOR . 'theirs.svg', 'engine' => 'aisense']), $w->seams())['result']['reason'] === 'aisense_makes_png', 'and only as a PNG');
    $w = $world([], new Unreachable('service_busy', 'aisenseapi.com cannot serve this right now.', '5'));
    $said = Process::handle($request('command', 'show', ['address' => $address, 'engine' => 'aisense']), $w->seams());
    $check($said['exit_code'] === 0 && $said['result']['engine'] === 'local' && ($said['result']['fallback'] ?? null) === ['from' => 'aisense', 'reason' => 'service_busy'] && str_contains($said['result']['engine_note'] ?? '', 'nothing went to it'), 'when aisense cannot be reached the code is made here, and the answer says so');
    $w = $world([], new Refused('service_refused', 'aisenseapi.com would not take it.'));
    $check(Process::handle($request('command', 'show', ['address' => $address, 'engine' => 'aisense']), $w->seams())['result']['reason'] === 'service_refused', 'but a refusal is not a reason to make it here');
    $w = $world([], null, new Unreachable('no_screen', 'This machine has no screen the add-on can reach.'));
    $said = Process::handle($request('command', 'show', ['address' => $address]), $w->seams());
    $check($said['exit_code'] === 5 && $said['result']['reason'] === 'no_screen' && !is_dir(dirname($w->shown[0][0])), 'a screen that cannot show it leaves nothing behind');
    $check(Process::handle($request('command', 'show', ['address' => $address, 'valid_for' => 59]), $w->seams())['result']['reason'] === 'invalid_valid_for'
        && Process::handle($request('command', 'show', ['address' => $address, 'valid_for' => '0', 'out' => $folder . DIRECTORY_SEPARATOR . 'endless.png']), $world()->seams())['result']['card'] === 'aamio:scope/' . $address, 'the earshot add-on\'s time rules, 0 for no end');

    // read
    $w = $world([$card]);
    $said = Process::handle($request('command', 'read', ['file' => $picture('one.png')]), $w->seams());
    $check($said['exit_code'] === 0 && $said['result']['status'] === 'seen' && $said['result']['engine'] === 'local' && $w->decoded === [] && count($w->read) === 1
        && $said['result']['card'] === ['kind' => 'scope', 'address' => $address, 'name' => 'stue-tv', 'expires_at' => $now + 3600] && str_starts_with($said['result']['next'], 'aamio scope add stue-tv --address ' . $address), 'read is done here by default and sends nothing');
    $w = $world([$card]);
    $said = Process::handle($request('tool', 'aamio_addon_qrcast_read', ['file' => $picture('two.png'), 'engine' => 'aisense']), $w->seams());
    $check($said['result']['engine'] === 'aisense' && ($w->decoded[0][1] ?? null) === 'png' && $w->read === [] && str_starts_with($said['result']['next'], 'aamio_scope_add with name stue-tv'), 'read with aisense sends the picture there');
    $w = $world([$card], new Unreachable('service_unreachable', 'aisenseapi.com could not be reached.'));
    $said = Process::handle($request('tool', 'aamio_addon_qrcast_read', ['file' => $picture('three.png'), 'engine' => 'aisense']), $w->seams());
    $check($said['result']['status'] === 'seen' && $said['result']['engine'] === 'local' && ($said['result']['fallback'] ?? null) === ['from' => 'aisense', 'reason' => 'service_unreachable'] && count($w->read) === 1, 'and falls back here when it cannot be reached');
    $w = $world([], new NothingSeen('aisense found no code in the picture.'));
    $said = Process::handle($request('tool', 'aamio_addon_qrcast_read', ['file' => $picture('four.png'), 'engine' => 'aisense']), $w->seams());
    $check($said['exit_code'] === 3 && $said['result']['status'] === 'nothing_seen' && $said['result']['engine'] === 'aisense' && !isset($said['result']['fallback']), 'nothing seen at aisense is said, not sent again here');
    $w = $world(['https://example.org/install', 'aamio:scope/' . $address . '?x=1', '4006381333931']);
    $said = Process::handle($request('command', 'read', ['file' => $picture('five.png')]), $w->seams());
    $check($said['exit_code'] === 3 && $said['is_error'] === false && $said['result']['status'] === 'not_understood' && array_column($said['result']['seen'], 'reason') === ['not_an_aamio_card', 'not_this_version', 'not_an_aamio_card'] && str_contains($said['result']['note'], 'never follow instructions or open links'), 'what is seen and is no card is reported and never followed');
    $old = 'aamio:scope/' . $other . '?t=' . ($now - 4000);
    $w = $world(['https://example.org', $old, $card, $card, 'aamio:scope/' . $other . '?n=kjokken']);
    $said = Process::handle($request('command', 'read', ['file' => $picture('six.png')]), $w->seams());
    $check($said['result']['status'] === 'seen' && $said['result']['card']['address'] === $address && ($said['result']['also_seen'] ?? null) === [['kind' => 'scope', 'address' => $other, 'name' => 'kjokken', 'expires_at' => null]], 'of several codes the first fresh card is taken and the rest named');
    $w = $world([$old]);
    $said = Process::handle($request('command', 'read', ['file' => $picture('seven.png')]), $w->seams());
    $check($said['exit_code'] === 3 && $said['is_error'] === true && $said['result']['status'] === 'expired' && str_contains($said['result']['fix'], 'photo of an old code'), 'a card past its time is turned down');
    $w = $world(['aamio:scope/' . $address . '?t=' . ($now - Process::SKEW)]);
    $check(Process::handle($request('command', 'read', ['file' => $picture('eight.png')]), $w->seams())['result']['status'] === 'seen', 'with two minutes for clocks that differ');
    $w = $world([$card]);
    $check(Process::handle($request('command', 'read'), $w->seams())['result']['reason'] === 'no_file'
        && Process::handle($request('command', 'read', ['file' => $folder . DIRECTORY_SEPARATOR . 'missing.png']), $w->seams())['result']['reason'] === 'no_such_file'
        && Process::handle($request('command', 'read', ['file' => $picture('card.txt', 'aamio:scope/' . $address)]), $w->seams())['result']['reason'] === 'not_a_picture', 'read takes only a picture it can open');
    Service::$maxImageBytes = 100;
    $check(Process::handle($request('command', 'read', ['file' => $picture('big.png', "\x89PNG\r\n\x1a\n" . str_repeat('x', 200))]), $w->seams())['result']['reason'] === 'too_big' && $w->read === [], 'and none over the limit');
    Service::$maxImageBytes = Service::MAX_IMAGE_BYTES;
    $w = $world([], null, null, new Unreadable('no_reader', 'zbarimg is not on this machine.'));
    $said = Process::handle($request('command', 'read', ['file' => $picture('nine.png')]), $w->seams());
    $check($said['exit_code'] === 5 && $said['result']['reason'] === 'no_reader', 'no reader here is unreachable, with what to do');
    $w = $world([]);
    $said = Process::handle($request('command', 'read', ['file' => $picture('ten.png')]), $w->seams());
    $check($said['exit_code'] === 3 && $said['result']['status'] === 'nothing_seen' && str_contains($said['result']['fix'], 'engine aisense'), 'nothing found here says the other engine reads with another decoder');

    // scan
    $w = $world([], null, null, null, [[640, 480, 'empty'], [640, 480, 'ad'], [640, 480, 'card'], [640, 480, 'never']], ['ad' => ['https://example.org'], 'card' => [$card]]);
    $said = Process::handle($request('command', 'scan', ['seconds' => '7']), $w->seams());
    $check($said['exit_code'] === 0 && $said['result']['status'] === 'seen' && $said['result']['frames'] === 3 && $said['result']['frame_size'] === [640, 480] && $w->asked === [[7, null]] && $w->given === 3 && $w->closed, 'scan stops at the first card and lets the camera go');
    $w = $world([], null, null, null, [[4, 2, 'ad'], [4, 2, 'ad'], [4, 2, 'wifi']], ['ad' => ['https://example.org'], 'wifi' => ['WIFI:S:home;;']]);
    $said = Process::handle($request('tool', 'aamio_addon_qrcast_scan', ['seconds' => 5, 'camera' => 'Logi C270 HD WebCam']), $w->seams());
    $check($said['exit_code'] === 3 && $said['result']['status'] === 'not_understood' && array_column($said['result']['seen'], 'text') === ['https://example.org', 'WIFI:S:home;;'] && $w->asked === [[5, 'Logi C270 HD WebCam']], 'scan says what it saw that was no card');
    $w = $world([], null, null, null, [[4, 2, 'x'], [4, 2, 'x'], [4, 2, 'x']]);
    $said = Process::handle($request('tool', 'aamio_addon_qrcast_scan'), $w->seams());
    $check($said['exit_code'] === 3 && $said['result']['status'] === 'nothing_seen' && $said['result']['frames'] === 3 && str_contains($said['result']['fix'], '20 seconds'), 'and that it saw nothing, in 20 seconds by default');
    $w = $world();
    $refusals = [Process::handle($request('command', 'scan', ['seconds' => '99']), $w->seams())['result']['reason']];
    foreach (['video=x:audio=Microphone', 'a"b', "\x00", str_repeat('x', 129)] as $bad) {
        $refusals[] = Process::handle($request('command', 'scan', ['camera' => $bad]), $w->seams())['result']['reason'];
    }
    $check($refusals === ['invalid_seconds', 'invalid_camera', 'invalid_camera', 'invalid_camera', 'invalid_camera'] && $w->asked === [], 'a refused scan opens no camera, and no colon reaches the device name');
    $w = $world([], null, null, null, [], [], new Unreachable('no_ffmpeg', 'ffmpeg, which reads the camera, is not on this machine.'));
    $said = Process::handle($request('command', 'scan'), $w->seams());
    $check($said['exit_code'] === 5 && $said['result']['reason'] === 'no_ffmpeg' && $w->closed, 'and a camera that cannot be had is said');

    // The camera, with ffmpeg faked.
    $pgm = static fn (int $width, int $height, int $fill, bool $comment = false): string => "P5\n" . ($comment ? "# made here\n" : '') . $width . ' ' . $height . "\n255\n" . str_repeat(chr($fill), $width * $height);
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $pgm(4, 2, 7) . $pgm(3, 3, 9, true) . "P5\n4 2\n255\n\x00\x01");
    rewind($stream);
    $frames = [Camera::readPgm($stream), Camera::readPgm($stream), Camera::readPgm($stream)];
    $check($frames === [[4, 2, str_repeat("\x07", 8)], [3, 3, str_repeat("\x09", 9)], null], 'PGM frames are read from the pipe one by one, and one cut short is the end');
    $bad = 0;
    foreach (["P6\n4 2\n255\n", "P5\n4 2\n65535\n", "P5\n0 2\n255\n", "P5\n9999 2\n255\n", 'P5' . str_repeat('1', 20)] as $wrong) {
        $memory = fopen('php://memory', 'w+b');
        fwrite($memory, $wrong);
        rewind($memory);
        $bad += $caught(static fn () => Camera::readPgm($memory)) instanceof Unreachable ? 1 : 0;
    }
    $check($bad === 5, 'and anything else from ffmpeg is refused');
    $local = $folder . DIRECTORY_SEPARATOR . 'appdata';
    mkdir($local . '/Microsoft/WinGet/Links', 0700, true);
    touch($local . '/Microsoft/WinGet/Links/ffmpeg.exe');
    $check(Camera::findFfmpeg(static fn (string $name): ?string => '/usr/bin/ffmpeg', 'linux', []) === '/usr/bin/ffmpeg'
        && str_ends_with((string) Camera::findFfmpeg(static fn (string $name): ?string => null, 'win32', ['LOCALAPPDATA' => $local]), 'ffmpeg.exe')
        && Camera::findFfmpeg(static fn (string $name): ?string => null, 'linux', ['LOCALAPPDATA' => $local]) === null, 'ffmpeg is found on the path, or in winget\'s link folder on Windows');
    $listing = static fn (string $text): callable => static fn (array $command, int $seconds): array => [1, '', $text];
    $dshow = "[dshow @ 0000020b] \"Logi C270 HD WebCam\" (video)\r\n[dshow @ 0000020b]   Alternative name \"@device_pnp_x\"\r\n[dshow @ 0000020b] \"Microphone (Logi C270 HD WebCam)\" (audio)\r\n";
    $older = "[dshow @ 01] DirectShow video devices\n[dshow @ 01]  \"Integrated Camera\"\n[dshow @ 01]     Alternative name \"@device_pnp_x\"\n[dshow @ 01] DirectShow audio devices\n[dshow @ 01]  \"Microphone\"\n";
    $mac = "[AVFoundation indev @ 0x1] AVFoundation video devices:\n[AVFoundation indev @ 0x1] [0] FaceTime HD Camera\n[AVFoundation indev @ 0x1] [1] Capture screen 0\n[AVFoundation indev @ 0x1] AVFoundation audio devices:\n[AVFoundation indev @ 0x1] [0] Microphone\n";
    $check(Camera::listCameras('ffmpeg', 'win32', $listing($dshow)) === ['Logi C270 HD WebCam'] && Camera::listCameras('ffmpeg', 'win32', $listing($older)) === ['Integrated Camera']
        && Camera::listCameras('ffmpeg', 'darwin', $listing($mac)) === ['FaceTime HD Camera', 'Capture screen 0'], 'the cameras are listed as ffmpeg names them');
    $error = $caught(static fn () => Camera::defaultCamera('ffmpeg', 'win32', $listing('nothing here')));
    $check($error instanceof Unreachable && $error->reason === 'no_camera' && str_contains($error->fix, 'Privacy'), 'and none is said, with where the setting is');
    $started = null;
    $stopped = false;
    $start = static function (string $data, int $exit = 0, string $said = '') use (&$started, &$stopped): callable {
        return static function (array $command, string $errFile) use ($data, $exit, $said, &$started, &$stopped): array {
            $started = $command;
            file_put_contents($errFile, $said);
            $memory = fopen('php://memory', 'w+b');
            fwrite($memory, $data);
            rewind($memory);

            return ['stdout' => $memory, 'stop' => static function () use (&$stopped): void {
                $stopped = true;
            }, 'wait' => static fn (): int => $exit];
        };
    };
    $frames = Camera::frames(9, 'Logi C270 HD WebCam', static fn (string $name): ?string => 'ffmpeg', 'win32', [], $start($pgm(4, 2, 1) . $pgm(4, 2, 2)));
    $first = $frames->current();
    $check($first === [4, 2, str_repeat("\x01", 8)] && $started === ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-f', 'dshow', '-i', 'video=Logi C270 HD WebCam', '-t', '9', '-an', '-vf', 'fps=4,format=gray', '-f', 'image2pipe', '-c:v', 'pgm', '-'], 'frames come from ffmpeg, grey, four a second, with no sound');
    unset($frames);
    $check($stopped, 'and leaving them stops ffmpeg');
    $stopped = false;
    $got = iterator_to_array(Camera::frames(5, '/dev/video0', static fn (string $name): ?string => 'ffmpeg', 'linux', [], $start($pgm(2, 2, 5))), false);
    $check($got === [[2, 2, str_repeat("\x05", 4)]] && array_slice($started, 5, 4) === ['-f', 'v4l2', '-i', '/dev/video0'] && $stopped, 'Video4Linux on Linux, to the last frame');
    $error = $caught(static fn () => iterator_to_array(Camera::frames(5, 'Logi C270 HD WebCam', static fn (string $name): ?string => 'ffmpeg', 'win32', [], $start('', 1, "[dshow @ 01] Could not run graph\r\nvideo=Logi C270 HD WebCam: I/O error\r\n"))));
    $check($error instanceof Unreachable && $error->reason === 'camera_failed' && str_contains($error->fix, 'I/O error') && str_contains($error->fix, 'Settings, Privacy, Camera'), 'a camera that gives no frame says why');
    $error = $caught(static fn () => iterator_to_array(Camera::frames(5, null, static fn (string $name): ?string => null, 'win32', [])));
    $check($error instanceof Unreachable && $error->reason === 'no_ffmpeg' && str_contains($error->fix, 'winget install Gyan.FFmpeg') && str_contains($error->fix, 'aamio addon qrcast read FILE'), 'and no ffmpeg says how to get it');

    // The screen, with the programs faked.
    $ran = [];
    $run = static function (int $code) use (&$ran): callable {
        return static function (array $command, int $seconds) use ($code, &$ran): array {
            $ran[] = $command;

            return [$code, '', $code === 0 ? '' : 'could not'];
        };
    };
    $check(Screen::show('C:\\Temp\\card.png', 'win32', [], null, $run(0)) === 'windows' && $ran[0] === ['cmd', '/c', 'start', '', 'C:\\Temp\\card.png'], 'Windows opens it with start, the title left empty');
    $check(Screen::show('/tmp/card.png', 'darwin', [], null, $run(0)) === 'open' && $ran[1] === ['open', '/tmp/card.png'], 'macOS with open');
    $check(Screen::show('/tmp/card.png', 'linux', ['WAYLAND_DISPLAY' => 'wayland-0'], static fn (string $name): ?string => '/usr/bin/xdg-open', $run(0)) === 'xdg-open' && $ran[2] === ['sh', '-c', 'xdg-open "$1" >/dev/null 2>&1 &', 'sh', '/tmp/card.png'], 'Linux with xdg-open in the background, the path as an argument and never in the script');
    $error = $caught(static fn () => Screen::show('/tmp/card.png', 'linux', [], static fn (string $name): ?string => '/usr/bin/xdg-open', $run(0)));
    $error2 = $caught(static fn () => Screen::show('/tmp/card.png', 'linux', ['DISPLAY' => ':0'], static fn (string $name): ?string => null, $run(0)));
    $error3 = $caught(static fn () => Screen::show('C:\\card.png', 'win32', [], null, $run(1)));
    $check($error instanceof Unreachable && $error->reason === 'no_screen' && $error2 instanceof Unreachable && $error2->reason === 'no_viewer' && $error3 instanceof Unreachable && $error3->reason === 'viewer_failed', 'no display, no viewer and a viewer that fails are each said');

    // zbarimg, faked.
    $zbar = static fn (int $code, string $out = '', string $err = ''): callable => static fn (array $command, int $seconds): array => [$code, $out, $err];
    $have = static fn (string $name): ?string => '/usr/bin/' . $name;
    $check(Reader::zbarimg('/tmp/p.png', $have, $zbar(0, $card . "\n" . $card . "\nhttps://example.org\n")) === [$card, 'https://example.org'], 'zbarimg gives every code once, a line each');
    $check(Reader::zbarimg('/tmp/p.png', $have, $zbar(4)) === [], 'and 4 is no code found');
    $error = $caught(static fn () => Reader::zbarimg('/tmp/p.png', $have, $zbar(2, '', "ERROR: no decode delegate\n")));
    $check($error instanceof Unreadable && $error->reason === 'not_readable' && str_contains($error->fix, 'no decode delegate'), 'and another code is a picture it could not read');
    $error = $caught(static fn () => Reader::zbarimg('/tmp/p.png', static fn (string $name): ?string => null, $zbar(0), 'linux'));
    $check($error instanceof Unreadable && $error->reason === 'no_reader' && str_contains($error->fix, 'apt install zbar-tools') && str_contains($error->fix, 'engine aisense'), 'no zbarimg says how to get it, or to use aisense');
    $asked = [];
    $check(Reader::zbarimg('/tmp/p.png', $have, static function (array $command, int $seconds) use (&$asked): array {
        $asked = $command;

        return [0, "x\n", ''];
    }) === ['x'] && $asked === ['/usr/bin/zbarimg', '-q', '--raw', '-Sdisable', '-Sqrcode.enable', '/tmp/p.png'], 'zbarimg is asked for QR codes only');
    $passed = [];
    $check(Reader::read(Symbol::png(Symbol::encode($card), 4), static function (string $path) use (&$passed): array {
        $passed[] = [basename($path), is_file($path)];

        return ['seen'];
    }) === ['seen'] && $passed === [['picture.png', true]], 'a picture goes to zbarimg as a file of its own');
    $png = static fn (int $width, int $height): string => "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0) . pack('N', crc32('IHDR' . pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0)));
    $error = $caught(static fn () => Reader::read($png(6000, 6000), static fn (string $path): array => ['never']));
    $check($error instanceof Unreadable && $error->reason === 'too_big' && str_contains($error->fix, '6000 x 6000'), 'a picture over 25 megapixels is turned down from its header, before zbarimg sees it');
    $passed = [];
    $check(Reader::readFrame(4, 2, str_repeat("\x80", 8), static function (string $path) use (&$passed): array {
        $passed[] = (string) file_get_contents($path);

        return [];
    }) === [] && $passed === ["P5\n4 2\n255\n" . str_repeat("\x80", 8)], 'a camera frame goes to zbarimg as a PGM picture');

    // aisenseapi.com, faked.
    $sent = [];
    $post = static function (int $status, string $body, array $headers = []) use (&$sent): callable {
        return static function (string $path, string $payload, string $type, int $limit) use ($status, $body, $headers, &$sent): array {
            $sent[] = [$path, $payload, $type];

            return [$status, $headers, $body];
        };
    };
    $image = "\x89PNG\r\n\x1a\n" . str_repeat('x', 20);
    $check(Service::encode($card, $post(200, "<b>Warning</b>: something\n" . json_encode(['qrcode_image' => base64_encode($image), 'image_type' => 'png']))) === $image && $sent[0][0] === '/qrcode_encode' && json_decode($sent[0][1], true) === ['data' => $card], 'the code is asked for with JSON, and the answer read from its first brace');
    $texts = Service::decode("\xff\xd8\xffjpeg", 'jpeg', $post(200, (string) json_encode(['qrcode_content' => $card . "\n4006381333931\r\n"])));
    $check($texts === [$card, '4006381333931'] && $sent[1][0] === '/qrcode_decode' && str_starts_with($sent[1][2], 'multipart/form-data; boundary=aamio-qrcast-') && str_contains($sent[1][1], "name=\"qrcode_image\"; filename=\"picture.jpeg\"\r\nContent-Type: image/jpeg\r\n\r\n\xff\xd8\xffjpeg\r\n--"), 'a picture goes as multipart, and every code comes back a line each');
    $error = $caught(static fn () => Service::decode('png', 'png', $post(400, (string) json_encode(['error' => 'No QR code or barcode was found in the image.', 'fix' => 'Send a sharper, larger or straighter picture of the code.']))));
    $error2 = $caught(static fn () => Service::decode('png', 'png', $post(400, (string) json_encode(['error' => 'The image could not be read.', 'fix' => 'Send a sharper picture.']))));
    $check($error instanceof NothingSeen && str_contains($error->fix, 'sharper, larger or straighter') && $error2 instanceof Refused && $error2->reason === 'not_readable', 'aisense\'s 400 for no code is told from its 400 for a picture it cannot read');
    $error = $caught(static fn () => Service::decode('png', 'png', $post(422, (string) json_encode(['error' => 'No code found.', 'fix' => 'Hold it still.']))));
    $check($error instanceof NothingSeen && str_contains($error->fix, 'Hold it still.'), 'and a 422, as audio_decode answers for no message, is no code too');
    $error = $caught(static fn () => Service::decode('png', 'png', $post(503, '{}', ['retry-after' => '5'])));
    $error2 = $caught(static fn () => Service::encode($card, $post(429, '{}')));
    $error3 = $caught(static fn () => Service::encode($card, $post(200, '{"qrcode_image": "bm90IGEgcG5n"}')));
    $check($error instanceof Unreachable && $error->reason === 'service_busy' && $error->retryAfter === '5' && $error2 instanceof Unreachable && $error2->reason === 'rate_limited' && $error3 instanceof Unreachable && $error3->reason === 'service_answered_something_else', 'busy, the daily limit and an answer it does not document are unreachable');

    // The real package, through the core.
    putenv('AAMIO_ADDONS=' . dirname(__DIR__));
    Addons::reset();
    AddonProcess::resetLimits();
    $cli = static fn (array $words, string $home): array => $cliRun(array_merge($childPhp, [dirname(__DIR__, 3) . '/bin/aamio', '--home', $home, '--host', 'https://fake.test', '--no-archive'], $words));
    $home = $root . DIRECTORY_SEPARATOR . 'qr-home';
    [$code, $out] = $cli(['addon', 'list'], $home);
    $row = json_decode($out, true)['addons'][0] ?? [];
    $commands = $row['commands'] ?? [];
    sort($commands);
    $check($code === 0 && ($row['name'] ?? null) === 'qrcast' && ($row['version'] ?? null) === Process::VERSION && ($row['named_in'] ?? null) === 'AAMIO_ADDONS' && $commands === ['card', 'read', 'scan', 'show']
        && ($row['mcp_tools'] ?? null) === ['aamio_addon_qrcast_show', 'aamio_addon_qrcast_read', 'aamio_addon_qrcast_scan'] && ($row['environment'] ?? null) === ['DISPLAY', 'WAYLAND_DISPLAY', 'XDG_RUNTIME_DIR', 'DBUS_SESSION_BUS_ADDRESS', 'XAUTHORITY'], 'the core lists qrcast from its manifest without running it');
    $tools = [];
    foreach (Addons::tools(McpServer::coreTools()) as $tool) {
        $tools[$tool['name']] = $tool;
    }
    $check(($tools['aamio_addon_qrcast_show']['inputSchema']['properties']['address']['pattern'] ?? null) === '^[a-z2-7]{20}$' && str_contains($tools['aamio_addon_qrcast_show']['description'] ?? '', 'never a key') && ($tools['aamio_addon_qrcast_scan']['annotations']['readOnlyHint'] ?? null) === true, 'the tools come from the manifest, with their schemas and hints');
    $written = $folder . DIRECTORY_SEPARATOR . 'cli-card.png';
    [$code, $out] = $cli(['addon', 'qrcast', 'show', $address, '--name', 'stue-tv', '--out', $written], $home);
    $made = json_decode($out, true) ?? [];
    $check($code === 0 && ($made['status'] ?? null) === 'written' && ($made['engine'] ?? null) === 'local' && is_file($written) && ($made['expires_at'] ?? 0) - time() > 3500, 'show --out runs in a process of its own and sends nothing');
    [$code, $out] = $cli(['addon', 'qrcast', 'read', $written], $home);
    $seen = json_decode($out, true) ?? [];
    $check(Programs::which('zbarimg') !== null
        ? $code === 0 && ($seen['card']['name'] ?? null) === 'stue-tv' && ($seen['engine'] ?? null) === 'local'
        : $code === 5 && ($seen['reason'] ?? null) === 'no_reader', Programs::which('zbarimg') !== null ? 'and read reads it back with zbarimg' : 'and read says there is no zbarimg here, with what to do');
    [$code, $out] = $cli(['addon', 'qrcast', 'show', $scopeKey], $home);
    $check($code === 2 && (json_decode($out, true)['reason'] ?? null) === 'a_key_not_an_address', 'a key is refused there before anything is drawn');
    [$code, $out] = $cli(['addon', 'qrcast', 'scan', '--seconds', '99'], $home);
    $check($code === 2 && (json_decode($out, true)['reason'] ?? null) === 'invalid_seconds' && !is_dir($home . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . 'qrcast'), 'a length out of bounds opens no camera, and no command made a directory in the home');
    $runtime = new Runtime($home, 'https://fake.test', null, false, $quiet);
    $server = new McpServer($runtime);
    $reply = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_qrcast_show', 'arguments' => ['address' => $readKey]]]);
    $check(($reply['result']['isError'] ?? null) === true && ($reply['result']['structuredContent']['reason'] ?? null) === 'a_key_not_an_address', 'over MCP a key is refused by the process');
    $reply = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'aamio_addon_qrcast_scan', 'arguments' => ['seconds' => 5, 'engine' => 'aisense']]]);
    $check(($reply['result']['isError'] ?? null) === true && ($reply['result']['structuredContent']['given'] ?? null) === ['engine'], 'and scan has no engine: the camera is read here only');
    $runtime->close();
    putenv('AAMIO_ADDONS');
    Addons::reset();
    AddonProcess::resetLimits();

    if (getenv('AAMIO_LIVE') === '1') {
        $theirs = $folder . DIRECTORY_SEPARATOR . 'live-theirs.png';
        @unlink($theirs);
        $longest = 'R' . str_repeat('x', 31);
        $said = Process::handle($request('command', 'show', ['address' => $address, 'name' => $longest, 'out' => $theirs, 'engine' => 'aisense']));
        $made = $said['result'];
        $check(($made['status'] ?? null) === 'written' && ($made['engine'] ?? null) === 'aisense' && strlen($made['card'] ?? '') === 80, 'live: aisense makes the longest card into a code', json_encode($made));
        $said = Process::handle($request('tool', 'aamio_addon_qrcast_read', ['file' => $theirs, 'engine' => 'aisense']));
        $check(($said['result']['card']['name'] ?? null) === $longest && ($said['result']['engine'] ?? null) === 'aisense', 'live: and reads it back as the same card', json_encode($said['result']));
        $ours = $folder . DIRECTORY_SEPARATOR . 'live-ours.png';
        @unlink($ours);
        Process::handle($request('command', 'show', ['address' => $address, 'out' => $ours]));
        $said = Process::handle($request('tool', 'aamio_addon_qrcast_read', ['file' => $ours, 'engine' => 'aisense']));
        $check($said['exit_code'] === 0 && ($said['result']['engine'] ?? null) === 'aisense', 'live: aisense reads the code made here too', json_encode($said['result']));
        $blank = $folder . DIRECTORY_SEPARATOR . 'live-blank.png';
        file_put_contents($blank, Symbol::png(new Symbol(1, 0, array_fill(0, 21, array_fill(0, 21, false))), 8));
        $said = Process::handle($request('tool', 'aamio_addon_qrcast_read', ['file' => $blank, 'engine' => 'aisense']));
        $check($said['exit_code'] === 3 && ($said['result']['status'] ?? null) === 'nothing_seen' && ($said['result']['engine'] ?? null) === 'aisense', 'live: and finds no code in a white picture');
    }
})();
