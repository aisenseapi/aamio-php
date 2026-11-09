<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * Frames from a camera on this machine, through ffmpeg.
 *
 * ffmpeg opens the camera, with DirectShow on Windows, AVFoundation on macOS
 * and Video4Linux on Linux, and writes grey frames, four a second, as PGM
 * images to a pipe. Sound is never taken. ffmpeg is the one program the add-on
 * needs for this: on Windows it comes with winget install Gyan.FFmpeg, and
 * winget's link folder is looked in as well, on macOS with brew install ffmpeg,
 * and on Linux as the ffmpeg package. The operating system decides who may use
 * the camera. The same as aamio-python's aamio_qrcast.camera.
 */
final class Camera
{
    public const FPS = 4;
    public const MAX_SIDE = 4096;

    public static function installHint(string $platform): string
    {
        return match ($platform) {
            'win32' => 'On Windows, ffmpeg comes with winget install Gyan.FFmpeg.',
            'darwin' => 'On macOS, ffmpeg comes with brew install ffmpeg.',
            default => 'On Linux, it is the ffmpeg package, as apt install ffmpeg.',
        };
    }

    public static function privacyHint(string $platform): string
    {
        return match ($platform) {
            'win32' => 'Check that the camera is connected and not in use, and that desktop apps may use it: Settings, Privacy, Camera.',
            'darwin' => 'macOS asks once for the app the agent runs in: Privacy & Security, Camera.',
            default => 'Check that the camera is connected and that this user is in the video group.',
        };
    }

    /**
     * @param null|callable(string): ?string $which
     * @param null|array<string, string> $environ
     */
    public static function findFfmpeg(?callable $which = null, ?string $platform = null, ?array $environ = null): ?string
    {
        $found = ($which ?? [Programs::class, 'which'])('ffmpeg');
        if ($found !== null) {
            return $found;
        }
        $base = $environ === null ? (getenv('LOCALAPPDATA') ?: null) : ($environ['LOCALAPPDATA'] ?? null);
        if (($platform ?? Programs::platform()) === 'win32' && is_string($base) && $base !== '') {
            $link = $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, ['Microsoft', 'WinGet', 'Links', 'ffmpeg.exe']);
            if (is_file($link)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * The cameras ffmpeg sees, by the names it takes them by.
     *
     * @param null|callable(list<string>, int): array{0: ?int, 1: string, 2: string} $run
     * @return list<string>
     */
    public static function listCameras(string $ffmpeg, string $platform, ?callable $run = null): array
    {
        if ($platform !== 'win32' && $platform !== 'darwin') {
            $found = [];
            foreach (glob('/dev/video*') ?: [] as $device) {
                if (preg_match('#^/dev/video[0-9]+$#D', $device) === 1) {
                    $found[] = $device;
                }
            }
            sort($found);

            return $found;
        }
        $command = $platform === 'win32'
            ? [$ffmpeg, '-hide_banner', '-list_devices', 'true', '-f', 'dshow', '-i', 'dummy']
            : [$ffmpeg, '-hide_banner', '-f', 'avfoundation', '-list_devices', 'true', '-i', ''];
        [, , $text] = ($run ?? [Programs::class, 'run'])($command, 20);
        if ($platform === 'darwin') {
            $section = explode('AVFoundation audio devices:', explode('AVFoundation video devices:', $text, 2)[1] ?? '', 2)[0];
            preg_match_all('/\]\s+\[[0-9]+\]\s+(.+?)\s*$/m', $section, $matches);

            return array_values($matches[1]);
        }
        preg_match_all('/"([^"]+)"\s+\(video\)/', $text, $matches);
        $names = array_values($matches[1]);
        if ($names === [] && str_contains($text, 'DirectShow video devices')) {
            $section = explode('DirectShow audio devices', explode('DirectShow video devices', $text, 2)[1], 2)[0];
            foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
                if (preg_match('/\]\s+"([^"@][^"]*)"\s*$/', $line, $match) === 1) {
                    $names[] = $match[1];
                }
            }
        }

        return $names;
    }

    /** @param null|callable(list<string>, int): array{0: ?int, 1: string, 2: string} $run */
    public static function defaultCamera(string $ffmpeg, string $platform, ?callable $run = null): string
    {
        if ($platform === 'win32') {
            $names = self::listCameras($ffmpeg, $platform, $run);
            if ($names === []) {
                throw new Unreachable('no_camera', 'ffmpeg finds no camera on this machine. ' . self::privacyHint($platform));
            }

            return $names[0];
        }
        if ($platform === 'darwin') {
            return '0';
        }
        if (!file_exists('/dev/video0')) {
            throw new Unreachable('no_camera', 'There is no /dev/video0 on this machine. Connect a camera, or name one with --camera (camera over MCP), as /dev/video2.');
        }

        return '/dev/video0';
    }

    /** @return list<string> */
    public static function inputFor(string $platform, string $camera): array
    {
        return match ($platform) {
            'win32' => ['-f', 'dshow', '-i', 'video=' . $camera],
            'darwin' => ['-f', 'avfoundation', '-framerate', '30', '-i', $camera],
            default => ['-f', 'v4l2', '-i', $camera],
        };
    }

    /**
     * One P5 frame from ffmpeg's pipe, or null at the end of the stream.
     *
     * @param resource $stream
     * @return null|array{0: int, 1: int, 2: string}
     */
    public static function readPgm($stream): ?array
    {
        $fields = [];
        $token = '';
        while (count($fields) < 4) {
            $ch = fread($stream, 1);
            if ($ch === false || $ch === '') {
                return null;
            }
            if ($ch === '#' && $token === '') {
                while ($ch !== "\n" && $ch !== '' && $ch !== false) {
                    $ch = fread($stream, 1);
                }
                continue;
            }
            if (ctype_space($ch)) {
                if ($token !== '') {
                    $fields[] = $token;
                    $token = '';
                }
                continue;
            }
            $token .= $ch;
            if (strlen($token) > 10) {
                throw new Unreachable('camera_failed', 'ffmpeg wrote something other than the grey frames asked for.');
            }
        }
        [$magic, $width, $height, $depth] = $fields;
        if ($magic !== 'P5' || !ctype_digit($width) || !ctype_digit($height) || $depth !== '255') {
            throw new Unreachable('camera_failed', 'ffmpeg wrote something other than the grey frames asked for.');
        }
        $width = (int) $width;
        $height = (int) $height;
        if ($width < 1 || $width > self::MAX_SIDE || $height < 1 || $height > self::MAX_SIDE) {
            throw new Unreachable('camera_failed', 'ffmpeg gave a frame of ' . $width . ' x ' . $height . ' pixels, and at most ' . self::MAX_SIDE . ' a side are read.');
        }
        $data = '';
        $left = $width * $height;
        while ($left > 0) {
            $chunk = fread($stream, $left);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
            $left -= strlen($chunk);
        }

        return [$width, $height, $data];
    }

    /**
     * ffmpeg started on a command, as frames() uses it: its standard output, a way to stop it, and its exit code.
     *
     * @param list<string> $command
     * @return array{stdout: resource, stop: callable(): void, wait: callable(): ?int}
     */
    public static function start(array $command, string $errFile): array
    {
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Unreachable('camera_failed', 'ffmpeg could not be started.');
        }
        $stdout = $pipes[1];
        $code = null;

        return [
            'stdout' => $stdout,
            'stop' => static function () use ($process, $stdout): void {
                if (is_resource($process) && proc_get_status($process)['running'] === true) {
                    proc_terminate($process);
                    $until = microtime(true) + 5;
                    while (proc_get_status($process)['running'] === true && microtime(true) < $until) {
                        usleep(20000);
                    }
                    if (proc_get_status($process)['running'] === true) {
                        proc_terminate($process, 9);
                    }
                }
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            },
            'wait' => static function () use ($process, &$code): ?int {
                if (!is_resource($process)) {
                    return $code;
                }
                $until = microtime(true) + 10;
                $status = proc_get_status($process);
                while ($status['running'] === true && microtime(true) < $until) {
                    usleep(20000);
                    $status = proc_get_status($process);
                }
                $code = $status['running'] === true ? null : $status['exitcode'];

                return $code;
            },
        ];
    }

    /**
     * Grey frames from the camera, four a second, for this many seconds. Leaving the loop stops ffmpeg.
     *
     * @param null|callable(string): ?string $which
     * @param null|array<string, string> $environ
     * @param null|callable(list<string>, string): array{stdout: resource, stop: callable(): void, wait: callable(): ?int} $start
     * @param null|callable(list<string>, int): array{0: ?int, 1: string, 2: string} $run
     * @return \Generator<int, array{0: int, 1: int, 2: string}>
     */
    public static function frames(int $seconds, ?string $camera = null, ?callable $which = null, ?string $platform = null, ?array $environ = null, ?callable $start = null, ?callable $run = null): \Generator
    {
        $platform ??= Programs::platform();
        $ffmpeg = self::findFfmpeg($which, $platform, $environ);
        if ($ffmpeg === null) {
            throw new Unreachable('no_ffmpeg', 'ffmpeg, which reads the camera, is not on this machine. ' . self::installHint($platform) . ' Or take a picture another way and read it with aamio addon qrcast read FILE (aamio_addon_qrcast_read).');
        }
        $camera ??= self::defaultCamera($ffmpeg, $platform, $run);
        $command = array_merge([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin'], self::inputFor($platform, $camera),
            ['-t', (string) $seconds, '-an', '-vf', 'fps=' . self::FPS . ',format=gray', '-f', 'image2pipe', '-c:v', 'pgm', '-']);
        $errFile = (string) tempnam(sys_get_temp_dir(), 'aamio-qrcast-');
        $running = ($start ?? [self::class, 'start'])($command, $errFile);
        $got = 0;
        try {
            while (($frame = self::readPgm($running['stdout'])) !== null) {
                $got++;
                yield $frame;
            }
            $code = $running['wait']();
            if ($got === 0) {
                $said = (string) @file_get_contents($errFile, false, null, 0, 8192);
                throw new Unreachable('camera_failed', 'ffmpeg gave no picture from the camera ' . $camera . ': ' . Programs::lastLine($said) . '. ' . self::privacyHint($platform));
            }
            // The pictures stop either because the time is up, and ffmpeg ends
            // well, or because the camera went away, and ffmpeg ends with an
            // error. Only the exit code tells the two apart: without it a camera
            // unplugged halfway would read as a scan in which no code was seen.
            if ($code !== null && $code !== 0) {
                $said = (string) @file_get_contents($errFile, false, null, 0, 8192);
                throw new Unreachable('camera_failed', 'The camera ' . $camera . ' stopped giving pictures after ' . $got . ', and ffmpeg ended with exit code ' . $code . ': ' . Programs::lastLine($said) . '. Check that the camera is connected and not taken by another program, and scan again.');
            }
        } finally {
            ($running['stop'])();
            @unlink($errFile);
        }
    }
}
