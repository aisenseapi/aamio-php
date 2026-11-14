<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * Opening a picture on this machine's screen, in whatever shows pictures here.
 *
 * Windows opens it with the program set for PNG files, through start; macOS
 * with open; Linux with xdg-open when there is a display to open it on, DISPLAY
 * or WAYLAND_DISPLAY, which the manifest asks for by name, started in the
 * background so the viewer outlives the call. The picture stays where it was
 * written, for the viewer to show. Nothing is sent. The same as aamio-python's
 * aamio_qrcast.screen.
 */
final class Screen
{
    /**
     * Open the picture on the screen, and say what opened it. Unreachable when nothing here can.
     *
     * @param null|array<string, string> $environ
     * @param null|callable(string): ?string $which
     * @param null|callable(list<string>, int): array{0: ?int, 1: string, 2: string} $run
     */
    public static function show(string $path, ?string $platform = null, ?array $environ = null, ?callable $which = null, ?callable $run = null): string
    {
        $platform ??= Programs::platform();
        $run ??= [Programs::class, 'run'];
        // Each opener starts by the full path the lookup gave, never by its bare
        // name, which Windows looked for in the working directory first (a
        // security review of 13 November 2026).
        $find = $which ?? static fn (string $name): ?string => ProgramPath::find($name, $platform, $environ);
        $missing = static fn (string $name): Unreachable => new Unreachable('no_viewer', 'Nothing here opens a picture: ' . $name . ' is not in any folder PATH names. Write the code to a file with --out (out over MCP) and show it another way.');
        if ($platform === 'win32') {
            $cmd = $find('cmd') ?? throw $missing('cmd');
            [$code, , $err] = $run([$cmd, '/c', 'start', '', $path], 15);
            if ($code !== 0) {
                throw new Unreachable('viewer_failed', 'Windows could not open the picture: ' . Programs::lastLine($err) . '. Write the code to a file with --out (out over MCP) and show it another way.');
            }

            return 'windows';
        }
        if ($platform === 'darwin') {
            $open = $find('open') ?? throw $missing('open');
            [$code, , $err] = $run([$open, $path], 15);
            if ($code !== 0) {
                throw new Unreachable('viewer_failed', 'open could not open the picture: ' . Programs::lastLine($err) . '. Write the code to a file with --out (out over MCP) and show it another way.');
            }

            return 'open';
        }
        $env = static fn (string $name): string => $environ === null ? (string) getenv($name) : (string) ($environ[$name] ?? '');
        if ($env('DISPLAY') === '' && $env('WAYLAND_DISPLAY') === '') {
            throw new Unreachable('no_screen', 'This machine has no screen the add-on can reach: neither DISPLAY nor WAYLAND_DISPLAY is set. Write the code to a file with --out (out over MCP) and show it where it can be seen.');
        }
        $xdg = $find('xdg-open');
        if ($xdg === null) {
            throw new Unreachable('no_viewer', 'Nothing here opens a picture: xdg-open is missing. Install xdg-utils, or write the code to a file with --out (out over MCP) and show it another way.');
        }
        $sh = $find('sh') ?? throw $missing('sh');
        // Both by their full paths, and the path of the picture an argument, never part of the script.
        [$code, , $err] = $run([$sh, '-c', '"$1" "$2" >/dev/null 2>&1 &', 'sh', $xdg, $path], 15);
        if ($code !== 0) {
            throw new Unreachable('viewer_failed', 'xdg-open could not be started: ' . Programs::lastLine($err) . '. Write the code to a file with --out (out over MCP) and show it another way.');
        }

        return 'xdg-open';
    }
}
