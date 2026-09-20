<?php

declare(strict_types=1);

namespace Aamio;

/**
 * What this runtime keeps on the machine, who can read it, and for how long.
 *
 * The service forgets a thread when it expires. This runtime does not: it keeps
 * a key, the read keys of open threads, an outbox, and, unless told otherwise,
 * an archive of decrypted messages. "Ephemeral" is about the network, and an
 * outside assessment on 18 September 2026 pointed out how easily it is read as
 * a promise about the whole system, and that mode 600 says little on Windows,
 * where inherited access decides who reads a folder.
 *
 * So three things live here. A check that says who can in fact read the home,
 * by mode bits where those mean something and by the access control list where
 * they do not. An archive that is a choice with a lifetime: keep, off, or so
 * many days, with a ceiling on size. And the small things that make a write
 * survive being interrupted.
 */
final class Storage
{
    public const ARCHIVE_MODES = ['keep', 'off', 'days'];
    public const DEFAULT_POLICY = ['mode' => 'keep', 'days' => null, 'max_mb' => null];

    /**
     * Who may have access to a private folder on Windows without it being a
     * finding: the system itself, the administrators of the machine, and
     * whoever created or owns the file.
     */
    private const WINDOWS_EXPECTED = ['S-1-5-18', 'S-1-5-32-544', 'S-1-3-0', 'S-1-3-4'];
    private const WINDOWS_NAMES = [
        'S-1-1-0' => 'Everyone',
        'S-1-5-11' => 'Authenticated Users',
        'S-1-5-32-545' => 'Users',
        'S-1-5-32-546' => 'Guests',
        'S-1-5-4' => 'Interactive',
    ];

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /** The folder, for this user only where mode bits mean that. */
    public static function makePrivateDir(string $path): void
    {
        if (!is_dir($path)) {
            $mask = umask(0077);
            try {
                @mkdir($path, 0700, true);
            } finally {
                umask($mask);
            }
        }
        if (!self::isWindows()) {
            @chmod($path, 0700);
        }
    }

    /** Appends one line to a file nobody else can read, from its first byte. */
    public static function appendPrivate(string $path, string $line): void
    {
        $existed = file_exists($path);
        $mask = umask(0077);
        try {
            // 'ab+' and not 'ab': the torn-line check below reads the last byte
            // through this same handle, under the same lock. A write-only append
            // handle made that fread fail, and false !== "\n" is true, so the
            // repair fired on every append and wrote a blank line before every
            // record. PHP said so as a Notice, fifty-four times in one test run.
            $handle = @fopen($path, 'ab+');
            if ($handle === false) {
                throw new \RuntimeException('could not append to ' . $path);
            }
        } finally {
            umask($mask);
        }
        if (!$existed && !self::isWindows()) {
            @chmod($path, 0600);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('could not lock ' . $path);
            }
            // A crash in the middle of a write leaves a last line with no end.
            // Appended to as it was, the next record became part of that line,
            // and a reader passed over both.
            $torn = false;
            if (fseek($handle, 0, SEEK_END) === 0 && ftell($handle) > 0 && fseek($handle, -1, SEEK_END) === 0) {
                $last = fread($handle, 1);
                // A read that failed says nothing about the last byte. Treating it
                // as "not a newline" is what made this repair fire blind.
                $torn = $last !== false && $last !== "\n";
                fseek($handle, 0, SEEK_END);
            }
            if (fwrite($handle, ($torn ? "\n" : '') . $line . "\n") === false) {
                throw new \RuntimeException('could not append to ' . $path);
            }
            fflush($handle);
            self::sync($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** The bytes on the disk, not only in the buffer. fsync is PHP 8.1 and up. */
    public static function sync($handle): void
    {
        if (function_exists('fsync')) {
            @fsync($handle);
        }
    }

    /**
     * Files an older version wrote with the default mode are made private.
     * Returns what it changed. PHP cannot fsync a folder, so a rename is as
     * durable as the platform makes it.
     *
     * @return string[]
     */
    public static function tighten(string $home): array
    {
        $changed = [];
        if (self::isWindows() || !is_dir($home)) {
            return $changed;
        }
        foreach (self::walk($home) as $path) {
            $mode = @fileperms($path);
            if ($mode === false) {
                continue;
            }
            if (($mode & 0077) !== 0) {
                @chmod($path, is_dir($path) ? 0700 : 0600);
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /** @return string[] the folder, its subfolders and every file in them */
    private static function walk(string $home): array
    {
        $found = [$home];
        foreach ((array) @scandir($home) as $name) {
            if ($name === '.' || $name === '..' || $name === false) {
                continue;
            }
            $path = $home . DIRECTORY_SEPARATOR . $name;
            $found = array_merge($found, is_dir($path) ? self::walk($path) : [$path]);
        }

        return $found;
    }

    /** Temporary files an interrupted write left behind. The file they were to replace is whole. */
    public static function leftovers(string $home): array
    {
        return array_values(array_filter(self::walk($home), static fn (string $path): bool => str_ends_with($path, '.tmp') && is_file($path)));
    }

    // --------------------------------------------------- who can read it --

    /**
     * Findings from `sid|rights|type` lines, one per access rule.
     *
     * @param string[] $lines
     */
    public static function windowsAclFindings(array $lines, string $me): array
    {
        $findings = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', trim($line)));
            if (count($parts) !== 3 || $parts[0] === '' || strtolower($parts[2]) !== 'allow') {
                continue;
            }
            [$sid, $rights] = $parts;
            if ($sid === $me || in_array($sid, self::WINDOWS_EXPECTED, true)) {
                continue;
            }
            $who = self::WINDOWS_NAMES[$sid] ?? $sid;
            $findings[] = ['who' => $who, 'rights' => $rights, 'problem' => $who . ' has access to this folder (' . $rights . '), so the key and everything else in it can be read by others on this machine'];
        }

        return $findings;
    }

    /** @return array{0: string, 1: string[]} the current user's SID, and one line per access rule */
    private static function windowsRules(string $path): array
    {
        $quoted = str_replace("'", "''", $path);
        $script = '$me = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value; ' . "'me|' + " . '$me; '
            . "(Get-Acl -LiteralPath '" . $quoted . "').Access | ForEach-Object { "
            . '$sid = try { $_.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { $_.IdentityReference.Value }; '
            . "'{0}|{1}|{2}' -f " . '$sid, $_.FileSystemRights, $_.AccessControlType }';
        $out = @shell_exec('powershell -NoProfile -NonInteractive -Command ' . escapeshellarg($script) . ' 2>&1');
        if (!is_string($out) || !str_contains($out, 'me|')) {
            throw new \RuntimeException('the access control list could not be read');
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $line): bool => $line !== ''));
        $me = '';
        foreach ($lines as $line) {
            if (str_starts_with($line, 'me|')) {
                $me = trim(substr($line, 3));
            }
        }

        return [$me, array_values(array_filter($lines, static fn (string $line): bool => !str_starts_with($line, 'me|')))];
    }

    /**
     * Who can read the home, as far as this platform lets us find out.
     *
     * private is true, false, or null when it could not be checked, and a null
     * is never reported as a yes.
     */
    public static function check(string $home): array
    {
        $result = ['home' => $home, 'private' => null, 'how' => null, 'findings' => []];
        if (!is_dir($home)) {
            $result['how'] = 'not checked: there is no such folder yet';

            return $result;
        }
        if (self::isWindows()) {
            $result['how'] = "the folder's access control list, read with Get-Acl. Mode bits say nothing on Windows.";
            try {
                [$me, $rules] = self::windowsRules($home);
            } catch (\Throwable) {
                $result['how'] = 'not checked: the access control list could not be read';
                $result['fix'] = 'Run `icacls "' . $home . '"` and see that only you, SYSTEM and Administrators are listed.';

                return $result;
            }
            $result['findings'] = self::windowsAclFindings($rules, $me);
            $result['private'] = $result['findings'] === [];
            if ($result['findings'] !== []) {
                $result['fix'] = 'Remove the inherited access and keep your own: icacls "' . $home . '" /inheritance:r /grant:r "%USERNAME%":(OI)(CI)F';
            }

            return $result;
        }
        $result['how'] = 'owner and mode bits of the folder and every file in it';
        $me = function_exists('posix_geteuid') ? posix_geteuid() : null;
        foreach (self::walk($home) as $path) {
            $mode = @fileperms($path);
            if ($mode === false) {
                continue;
            }
            $owner = @fileowner($path);
            if ($me !== null && $owner !== false && $owner !== $me) {
                $result['findings'][] = ['path' => $path, 'problem' => sprintf('owned by another user (uid %d)', $owner)];
            } elseif (($mode & 0077) !== 0) {
                $result['findings'][] = ['path' => $path, 'problem' => sprintf('readable by others (mode %o)', $mode & 0777)];
            }
        }
        $result['private'] = $result['findings'] === [];
        if ($result['findings'] !== []) {
            $result['fix'] = 'chmod -R go-rwx "' . $home . '"';
        }

        return $result;
    }

    // ------------------------------------------------------- the archive --

    /** The archive policy of this home. No file means keep, which is what every version did. */
    public static function readPolicy(string $home): array
    {
        $stored = null;
        $text = @file_get_contents($home . DIRECTORY_SEPARATOR . 'config.json');
        if (is_string($text)) {
            $parsed = json_decode($text, true);
            $stored = is_array($parsed) ? ($parsed['archive'] ?? null) : null;
        }
        $policy = self::DEFAULT_POLICY;
        if (is_array($stored) && in_array($stored['mode'] ?? null, self::ARCHIVE_MODES, true)) {
            $policy['mode'] = $stored['mode'];
            if (is_int($stored['days'] ?? null) && $stored['days'] > 0) {
                $policy['days'] = $stored['days'];
            }
            if ((is_int($stored['max_mb'] ?? null) || is_float($stored['max_mb'] ?? null)) && $stored['max_mb'] > 0) {
                $policy['max_mb'] = (float) $stored['max_mb'];
            }
        }
        if ($policy['mode'] === 'days' && !$policy['days']) {
            $policy['mode'] = 'keep';
        }

        return $policy;
    }

    /** keep, off or days:N, as typed on the command line. */
    public static function parsePolicy(string $text, ?float $maxMb = null): array
    {
        $text = strtolower(trim($text));
        $policy = self::DEFAULT_POLICY;
        if ($text === 'keep' || $text === 'off') {
            $policy['mode'] = $text;
        } elseif (str_starts_with($text, 'days:') && preg_match('/^[1-9][0-9]*$/D', substr($text, 5)) === 1) {
            $policy['mode'] = 'days';
            $policy['days'] = (int) substr($text, 5);
        } else {
            throw new \InvalidArgumentException('the archive is keep, off or days:N with N a whole number of days, not ' . $text);
        }
        if ($maxMb !== null) {
            if ($maxMb <= 0) {
                throw new \InvalidArgumentException('--max-mb is a size above zero');
            }
            $policy['max_mb'] = $maxMb;
        }

        return $policy;
    }

    /** One sentence a person or a model can act on. */
    public static function describe(array $policy): string
    {
        $size = ($policy['max_mb'] ?? null) ? ' and never more than ' . $policy['max_mb'] . ' MB, oldest first out' : '';
        if ($policy['mode'] === 'off') {
            return 'Nothing decrypted is written to this machine. The key, the read keys of open threads and the outbox with sealed bytes still are, since the runtime cannot work without them.';
        }
        if ($policy['mode'] === 'days') {
            return 'Decrypted messages and receipts are kept in archive/ for ' . $policy['days'] . ' days' . $size . ', then removed. The service itself keeps nothing past a thread\'s expiry: this archive is yours, on this machine.';
        }

        return 'Decrypted messages and receipts are kept in archive/ until you remove them' . $size . '. The service itself keeps nothing past a thread\'s expiry: this archive is yours, on this machine. `aamio archive days:30` gives it a lifetime, `aamio archive off` stops it.';
    }

    /** @return array<int, array{0: ?float, 1: string}> each record's time, and the line as written */
    private static function records(string $path): array
    {
        $found = [];
        foreach (explode("\n", (string) @file_get_contents($path)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parsed = json_decode($line, true);
            $at = is_array($parsed) && (is_int($parsed['at'] ?? null) || is_float($parsed['at'] ?? null)) ? (float) $parsed['at'] : null;
            $found[] = [$at, $line . "\n"];
        }

        return $found;
    }

    /**
     * Removes what the policy no longer keeps. Returns how many records went,
     * and how many bytes are left.
     *
     * A record this cannot date is kept: what cannot be told old is not thrown
     * away as old. Each file is rewritten beside itself and renamed over, so a
     * crash in the middle leaves the old file whole.
     */
    public static function prune(string $home, array $policy, ?float $now = null, bool $everything = false): array
    {
        $folder = $home . DIRECTORY_SEPARATOR . 'archive';
        $now = $now ?? microtime(true);
        $removed = 0;
        if (!is_dir($folder)) {
            return ['removed' => 0, 'bytes' => 0];
        }
        $names = array_values(array_filter((array) @scandir($folder), static fn ($name): bool => is_string($name) && str_ends_with($name, '.jsonl')));
        sort($names);
        if ($everything) {
            foreach ($names as $name) {
                $removed += count(self::records($folder . DIRECTORY_SEPARATOR . $name));
                @unlink($folder . DIRECTORY_SEPARATOR . $name);
            }

            return ['removed' => $removed, 'bytes' => 0];
        }
        $kept = [];
        $horizon = ($policy['mode'] ?? null) === 'days' && ($policy['days'] ?? null) ? $now - $policy['days'] * 86400 : null;
        foreach ($names as $name) {
            $kept[$name] = [];
            foreach (self::records($folder . DIRECTORY_SEPARATOR . $name) as [$at, $line]) {
                if ($horizon !== null && $at !== null && $at < $horizon) {
                    $removed++;
                } else {
                    $kept[$name][] = [$at, $line];
                }
            }
        }
        $ceiling = ($policy['max_mb'] ?? null) ? (int) ($policy['max_mb'] * 1024 * 1024) : null;
        if ($ceiling !== null) {
            $total = 0;
            $oldestFirst = [];
            foreach ($kept as $name => $records) {
                foreach ($records as $index => [$at, $line]) {
                    $total += strlen($line);
                    $oldestFirst[] = [$at ?? $now, $name, $index];
                }
            }
            usort($oldestFirst, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $gone = [];
            foreach ($oldestFirst as [$at, $name, $index]) {
                if ($total <= $ceiling) {
                    break;
                }
                $total -= strlen($kept[$name][$index][1]);
                $gone[$name . '|' . $index] = true;
                $removed++;
            }
            foreach ($kept as $name => $records) {
                $kept[$name] = array_values(array_filter($records, static fn ($record, $index): bool => !isset($gone[$name . '|' . $index]), ARRAY_FILTER_USE_BOTH));
            }
        }
        $left = 0;
        foreach ($kept as $name => $records) {
            $path = $folder . DIRECTORY_SEPARATOR . $name;
            $text = implode('', array_column($records, 1));
            $left += strlen($text);
            if ($removed === 0) {
                continue;
            }
            $mask = umask(0077);
            try {
                $written = @file_put_contents($path . '.tmp', $text);
            } finally {
                umask($mask);
            }
            if ($written === false || !@rename($path . '.tmp', $path)) {
                throw new \RuntimeException('could not rewrite ' . $path);
            }
        }

        return ['removed' => $removed, 'bytes' => $left];
    }

    public static function status(string $home, array $policy): array
    {
        $folder = $home . DIRECTORY_SEPARATOR . 'archive';
        $files = 0;
        $size = 0;
        $oldest = null;
        if (is_dir($folder)) {
            foreach ((array) @scandir($folder) as $name) {
                if (!is_string($name) || !str_ends_with($name, '.jsonl')) {
                    continue;
                }
                $files++;
                $size += (int) @filesize($folder . DIRECTORY_SEPARATOR . $name);
                foreach (self::records($folder . DIRECTORY_SEPARATOR . $name) as [$at, $line]) {
                    if ($at !== null && ($oldest === null || $at < $oldest)) {
                        $oldest = $at;
                    }
                }
            }
        }

        return ['policy' => $policy, 'means' => self::describe($policy), 'files' => $files, 'bytes' => $size, 'oldest_at' => $oldest === null ? null : (int) $oldest];
    }
}
