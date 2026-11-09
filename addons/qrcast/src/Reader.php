<?php

declare(strict_types=1);

namespace Aamio\Qrcast;

/**
 * Reading the QR codes in a picture on this machine, with zbarimg.
 *
 * PHP reads no QR code of its own, so zbarimg does it, from zbar-tools on Linux
 * and zbar on macOS, asked for QR codes only, never barcodes. The picture's
 * size is checked first, at most 10 MiB and 25 megapixels as at aisenseapi.com,
 * from its header, before a pixel is decoded. A picture is written to a private
 * folder for zbarimg and deleted at once, and nothing leaves the machine. A
 * camera frame goes the same way as a PGM picture. aamio-python reads with
 * zxing-cpp instead: the same codes, by another decoder.
 */
final class Reader
{
    public const FORMATS = ['png', 'jpeg', 'gif', 'webp'];
    public const TIMEOUT = 20;
    public const COMMAND = ['-q', '--raw', '-Sdisable', '-Sqrcode.enable'];

    /** png, jpeg, gif or webp from the first bytes of a file, or null. */
    public static function kindOf(string $head): ?string
    {
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, "\xff\xd8\xff")) {
            return 'jpeg';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    public static function installHint(string $platform): string
    {
        return match ($platform) {
            'darwin' => 'On macOS it comes with brew install zbar.',
            'win32' => 'Windows has no package of it this add-on is tried with; aamio-python reads here with zxing-cpp.',
            default => 'On Linux it is the zbar-tools package, as apt install zbar-tools.',
        };
    }

    /**
     * The texts of the QR codes in a picture file's bytes. $zbar is for tests.
     *
     * @param null|callable(string): list<string> $zbar
     * @return list<string>
     */
    public static function read(string $data, ?callable $zbar = null): array
    {
        $kind = self::kindOf(substr($data, 0, 16));
        if ($kind === null) {
            throw new Unreadable('not_a_picture', 'This is not a PNG, JPEG, GIF or WebP picture.');
        }
        $size = @getimagesizefromstring($data);
        if (!is_array($size) || !is_int($size[0] ?? null) || !is_int($size[1] ?? null) || $size[0] < 1 || $size[1] < 1) {
            throw new Unreadable('not_readable', 'The picture could not be opened: its header says no size.');
        }
        if ($size[0] * $size[1] > Service::MAX_PIXELS) {
            throw new Unreadable('too_big', 'The picture is ' . $size[0] . ' x ' . $size[1] . ' pixels, and at most 25 megapixels are read. Send a smaller picture of the code.');
        }

        return self::through($data, 'picture.' . $kind, $zbar);
    }

    /**
     * The texts of the QR codes in one grey frame from the camera, a byte a pixel.
     *
     * @param null|callable(string): list<string> $zbar
     * @return list<string>
     */
    public static function readFrame(int $width, int $height, string $gray, ?callable $zbar = null): array
    {
        return self::through('P5' . "\n" . $width . ' ' . $height . "\n255\n" . $gray, 'frame.pgm', $zbar);
    }

    /**
     * @param null|callable(string): list<string> $zbar
     * @return list<string>
     */
    private static function through(string $data, string $name, ?callable $zbar): array
    {
        $zbar ??= [self::class, 'zbarimg'];
        $folder = Programs::folder();
        if ($folder === null) {
            throw new Unreadable('not_readable', 'No private folder could be made in the temporary directory for the picture. Check the temporary directory.');
        }
        $path = $folder . DIRECTORY_SEPARATOR . $name;
        try {
            file_put_contents($path, $data);

            return $zbar($path);
        } finally {
            Programs::clean($folder);
        }
    }

    /**
     * zbarimg on a file: the texts of the QR codes in it, none when it found none.
     *
     * @param null|callable(string): ?string $which
     * @param null|callable(list<string>, int): array{0: ?int, 1: string, 2: string} $run
     * @return list<string>
     */
    public static function zbarimg(string $path, ?callable $which = null, ?callable $run = null, ?string $platform = null): array
    {
        $program = ($which ?? [Programs::class, 'which'])('zbarimg');
        if ($program === null) {
            throw new Unreadable('no_reader', 'zbarimg, which reads the codes here, is not on this machine. ' . self::installHint($platform ?? Programs::platform()) . ' Or read with engine aisense, which sends the picture to aisenseapi.com.');
        }
        [$code, $out, $err] = ($run ?? [Programs::class, 'run'])(array_merge([$program], self::COMMAND, [$path]), self::TIMEOUT);
        if ($code === 4) {
            return [];
        }
        if ($code !== 0) {
            throw new Unreadable('not_readable', 'zbarimg could not read the picture' . ($code === null ? ' within ' . self::TIMEOUT . ' seconds' : ': ' . Programs::lastLine($err)) . '.');
        }
        $texts = [];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            if ($line !== '' && !in_array($line, $texts, true)) {
                $texts[] = $line;
            }
        }

        return $texts;
    }
}
