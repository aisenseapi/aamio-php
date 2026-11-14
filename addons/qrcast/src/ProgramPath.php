<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * Finding a program by its full path, never in the working directory.
 *
 * A program asked for by a bare name was looked for in the working directory
 * first on Windows, and in any folder PATH names relatively: a sox.exe in the
 * folder the agent ran from was the one that ran (a security review of 13
 * November 2026), and a lookup that found the installed one was followed by a
 * start by the bare name (a review of 14 November). which() looks only in the
 * folders PATH names by a full path, leaves out the working directory however
 * PATH names it, and gives back the full path of the file, which is what is
 * then started. On Windows it takes an .exe and nothing else, and cmd and
 * powershell come from the folder Windows keeps them in.
 *
 * The same in aamio-earshot and aamio-qrcast, and as aamio-python's and the
 * Node runtime's add-ons find their programs.
 */
final class ProgramPath
{
    /** Programs Windows brings, where Windows keeps them. */
    private const SYSTEM = ['cmd' => ['System32'], 'powershell' => ['System32', 'WindowsPowerShell', 'v1.0']];

    /**
     * The full path of the program, or null where no folder PATH names by a full path, other than the working directory, has it.
     *
     * @param null|array<string, string> $environ
     * @param null|callable(string): bool $isProgram
     */
    public static function find(string $name, string $platform, ?array $environ = null, ?string $cwd = null, ?callable $isProgram = null): ?string
    {
        $windows = $platform === 'win32';
        $env = static function (string $key) use ($environ): string {
            if ($environ === null) {
                return (string) (getenv($key) ?: '');
            }
            foreach ($environ as $name => $value) {
                if (strtoupper((string) $name) === $key) {
                    return (string) $value;
                }
            }

            return '';
        };
        $isProgram ??= static fn (string $path): bool => is_file($path) && ($windows || is_executable($path));
        $here = $cwd ?? (string) getcwd();
        $file = $windows && !str_ends_with(strtolower($name), '.exe') ? $name . '.exe' : $name;
        $system = self::SYSTEM[strtolower($name)] ?? null;
        if ($windows && $system !== null) {
            $root = $env('SYSTEMROOT') !== '' ? $env('SYSTEMROOT') : 'C:\\Windows';
            $candidate = rtrim($root, '\\/') . '\\' . implode('\\', $system) . '\\' . $file;
            if (self::isAbsolute($root, $platform) && $isProgram($candidate)) {
                return $candidate;
            }
        }
        foreach (explode($windows ? ';' : ':', $env('PATH')) as $folder) {
            $folder = trim($folder);
            if ($windows) {
                $folder = trim($folder, '"');
            }
            if ($folder === '' || !self::isAbsolute($folder, $platform) || self::isHere($folder, $here, $platform)) {
                continue;
            }
            $candidate = rtrim($folder, $windows ? '\\/' : '/') . ($windows ? '\\' : '/') . $file;
            if ($isProgram($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** A full path: with a drive and a root, or a share, on Windows; from the root elsewhere. C:folder and \folder are not. */
    public static function isAbsolute(string $folder, string $platform): bool
    {
        if ($platform === 'win32') {
            return preg_match('~^[A-Za-z]:[\\\\/]~', $folder) === 1 || preg_match('~^[\\\\/]{2}[^\\\\/]+[\\\\/]+[^\\\\/]+~', $folder) === 1;
        }

        return str_starts_with($folder, '/');
    }

    private static function isHere(string $folder, string $here, string $platform): bool
    {
        $windows = $platform === 'win32';
        $normal = static function (string $path) use ($windows): string {
            $path = $windows ? str_replace('/', '\\', $path) : $path;
            $path = rtrim($path, $windows ? '\\' : '/');

            return $windows ? strtolower($path) : $path;
        };
        if ($normal($folder) === $normal($here)) {
            return true;
        }
        $real = @realpath($folder);
        $realHere = @realpath($here);

        return $real !== false && $realHere !== false && $normal($real) === $normal($realHere);
    }
}
