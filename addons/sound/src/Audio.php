<?php

declare(strict_types=1);

namespace Aamio\Sound;

/**
 * Playing a WAV file and recording one, on the machine the add-on runs on.
 *
 * PHP has no sound of its own, so a program does it. Playing: PowerShell's
 * Media.SoundPlayer on Windows, afplay on macOS, and paplay, pw-play, aplay or
 * ffplay on Linux. Recording: sox on Windows, sox or ffmpeg on macOS, and
 * arecord, sox or ffmpeg on Linux, as mono 16-bit PCM at 48 kHz, which
 * aisenseapi.com reads. Without one the answer says what to install. The
 * operating system asks the user once for the microphone. The same as
 * aamio-python's aamio_sound.audio, where Python reaches the microphone itself.
 */
final class Audio
{
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'win32',
            'Darwin' => 'darwin',
            default => 'linux',
        };
    }

    /** @return list<list<string>> */
    public static function players(string $path, string $platform): array
    {
        if ($platform === 'win32') {
            return [['powershell', '-NoProfile', '-NonInteractive', '-Command', "(New-Object Media.SoundPlayer '" . str_replace("'", "''", $path) . "').PlaySync()"]];
        }
        if ($platform === 'darwin') {
            return [['afplay', $path]];
        }

        return [['paplay', $path], ['pw-play', $path], ['aplay', '-q', $path], ['ffplay', '-nodisp', '-autoexit', '-loglevel', 'quiet', $path]];
    }

    /** @return list<list<string>> */
    public static function recorders(int $seconds, string $path, string $platform): array
    {
        $rec = ['rec', '-q', '-c', '1', '-r', '48000', '-b', '16', $path, 'trim', '0', (string) $seconds];
        if ($platform === 'win32') {
            return [['sox', '-q', '-t', 'waveaudio', 'default', '-c', '1', '-r', '48000', '-b', '16', $path, 'trim', '0', (string) $seconds]];
        }
        if ($platform === 'darwin') {
            return [$rec, ['ffmpeg', '-loglevel', 'error', '-f', 'avfoundation', '-i', ':0', '-t', (string) $seconds, '-ac', '1', '-ar', '48000', '-c:a', 'pcm_s16le', '-y', $path]];
        }

        return [
            ['arecord', '-q', '-f', 'S16_LE', '-r', '48000', '-c', '1', '-d', (string) $seconds, $path],
            $rec,
            ['ffmpeg', '-loglevel', 'error', '-f', 'pulse', '-i', 'default', '-t', (string) $seconds, '-ac', '1', '-ar', '48000', '-c:a', 'pcm_s16le', '-y', $path],
        ];
    }

    /** The full path of a program on PATH, or null. */
    public static function which(string $name): ?string
    {
        $extensions = DIRECTORY_SEPARATOR === '\\' ? array_merge([''], explode(';', (string) (getenv('PATHEXT') ?: '.EXE;.BAT;.CMD'))) : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $folder) {
            if ($folder === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $name . strtolower($extension);
                if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Run a program to the end, within $seconds. Null when it did, the reason when it did not.
     *
     * @param list<string> $command
     */
    public static function run(array $command, int $seconds): ?string
    {
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $errFile = (string) tempnam(sys_get_temp_dir(), 'aamio-sound-');
        $pipes = [];
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($process)) {
            @unlink($errFile);

            return $command[0] . ' could not be started';
        }
        $until = microtime(true) + $seconds;
        $status = proc_get_status($process);
        while ($status['running'] === true && microtime(true) < $until) {
            usleep(50000);
            $status = proc_get_status($process);
        }
        if ($status['running'] === true) {
            proc_terminate($process, 9);
            proc_close($process);
            @unlink($errFile);

            return $command[0] . ' did not finish within ' . $seconds . ' seconds';
        }
        proc_close($process);
        $err = (string) @file_get_contents($errFile, false, null, 0, 65536);
        @unlink($errFile);
        if ($status['exitcode'] !== 0) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $err)), static fn (string $line): bool => $line !== ''));

            return $command[0] . ' stopped with code ' . $status['exitcode'] . ': ' . ($lines !== [] ? substr($lines[count($lines) - 1], 0, 200) : 'no reason given');
        }

        return null;
    }

    /** Play the file to the end, and say what played it. Unreachable when nothing here can. */
    public static function play(string $path, ?callable $which = null, ?callable $run = null, ?string $platform = null): string
    {
        $which ??= [self::class, 'which'];
        $run ??= [self::class, 'run'];
        $failed = [];
        foreach (self::players($path, $platform ?? self::platform()) as $command) {
            if ($which($command[0]) !== null) {
                $why = $run($command, 60);
                if ($why === null) {
                    return $command[0];
                }
                $failed[] = $why;
            }
        }
        if ($failed !== []) {
            throw new Unreachable('player_failed', 'The sound could not be played: ' . implode('; ', $failed) . '. Check the speaker and the volume, or write the sound to a file with --out (out over MCP) and play it another way.');
        }
        throw new Unreachable('no_player', 'Nothing on this machine plays a sound file: install pulseaudio-utils (paplay), alsa-utils (aplay) or ffmpeg, or write the sound to a file with --out (out over MCP) and play it another way.');
    }

    /** Record this many seconds from the default microphone into $path, and say what recorded it. */
    public static function record(int $seconds, string $path, ?callable $which = null, ?callable $run = null, ?string $platform = null): string
    {
        $which ??= [self::class, 'which'];
        $run ??= [self::class, 'run'];
        $failed = [];
        foreach (self::recorders($seconds, $path, $platform ?? self::platform()) as $command) {
            if ($which($command[0]) !== null) {
                $why = $run($command, $seconds + 15);
                if ($why === null) {
                    return $command[0];
                }
                $failed[] = $why;
            }
        }
        if ($failed !== []) {
            throw new Unreachable('recorder_failed', 'Nothing was recorded: ' . implode('; ', $failed) . '. Check that a microphone is connected and that this app may use it: macOS asks once for the app the agent runs in, Windows has it under the privacy settings for desktop apps.');
        }
        throw new Unreachable('no_recorder', 'Nothing on this machine records from the microphone: install sox, or alsa-utils (arecord) or ffmpeg on Linux, or record the sound another way and read the file with aamio addon sound decode FILE (aamio_addon_sound_decode).');
    }
}
