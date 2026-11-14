<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/** The system, the programs on PATH, and one program run to its end within a time. */
final class Programs
{
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'win32',
            'Darwin' => 'darwin',
            default => 'linux',
        };
    }

    /** The full path of a program, from the folders PATH names by a full path and never the working directory, or null. */
    public static function which(string $name): ?string
    {
        return ProgramPath::find($name, self::platform());
    }

    /**
     * Run a program to the end within $seconds, and give its exit code, its output and its errors.
     * Null for the code when it did not finish, and it was stopped.
     *
     * @param list<string> $command
     * @return array{0: ?int, 1: string, 2: string}
     */
    public static function run(array $command, int $seconds): array
    {
        $outFile = (string) tempnam(sys_get_temp_dir(), 'aamio-qrcast-');
        $errFile = (string) tempnam(sys_get_temp_dir(), 'aamio-qrcast-');
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);

            return [-1, '', basename($command[0]) . ' could not be started'];
        }
        $until = microtime(true) + $seconds;
        $status = proc_get_status($process);
        while ($status['running'] === true && microtime(true) < $until) {
            usleep(20000);
            $status = proc_get_status($process);
        }
        $code = null;
        if ($status['running'] === true) {
            proc_terminate($process, 9);
        } else {
            $code = $status['exitcode'];
        }
        proc_close($process);
        $out = (string) @file_get_contents($outFile, false, null, 0, 1048576);
        $err = (string) @file_get_contents($errFile, false, null, 0, 65536);
        @unlink($outFile);
        @unlink($errFile);

        return [$code, $out, $err];
    }

    public static function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), static fn (string $line): bool => $line !== ''));

        return $lines !== [] ? substr($lines[count($lines) - 1], 0, 200) : 'no reason given';
    }

    /** A folder of this call's own in the temporary directory, private from the start, or null. */
    public static function folder(): ?string
    {
        $folder = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'aamio-qrcast-' . bin2hex(random_bytes(8));
        $mask = umask(0077);
        try {
            $made = @mkdir($folder, 0700);
        } finally {
            umask($mask);
        }

        return $made ? $folder : null;
    }

    public static function clean(string $folder): void
    {
        foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($folder);
    }
}
