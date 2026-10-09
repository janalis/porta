<?php

declare(strict_types=1);

namespace Porta\FtpPlugin;

use Porta\Filesystem\RemoteSpec;

/**
 * Where an FTP server's password is kept: a netrc file per server project,
 * under `~/.porta/ftp/`, that only this user can read — written so it is
 * never readable by anyone else, not even for the moment between writing it
 * and narrowing it. curl reads it with `--netrc-file`, so the password never
 * appears on a command line, where any user's `ps` would show it.
 */
final class Credentials
{
    /** The netrc for $spec under $home, whether or not it exists. */
    public static function file(string $home, RemoteSpec $spec): string
    {
        return $home.'/.porta/ftp/'.$spec->id().'.netrc';
    }

    /** The netrc for $spec when one was stored, else null — an anonymous login. */
    public static function find(string $home, RemoteSpec $spec): ?string
    {
        $file = self::file($home, $spec);

        return \is_file($file) ? $file : null;
    }

    /** Keep $password for $spec's user, and answer the file it is in — or null when it cannot be written. */
    public static function store(string $home, RemoteSpec $spec, #[\SensitiveParameter] string $password): ?string
    {
        $file = self::file($home, $spec);
        $previous = \umask(0o077);
        try {
            $dir = \dirname($file);
            $stored = null;
            // @mago-expect lint:no-error-control-operator -- a directory that cannot be made warns; the null return is what is reported
            if (\is_dir($dir) || @\mkdir($dir, permissions: 0o700, recursive: true) || \is_dir($dir)) {
                $line = \sprintf("machine %s login %s password %s\n", self::token($spec->host), self::token((string) $spec->user), self::token($password));
                // @mago-expect lint:no-error-control-operator -- an unwritable directory warns; the null return is what is reported
                if (false !== @\file_put_contents($file.'.tmp', $line)) {
                    \chmod($file.'.tmp', permissions: 0o600);
                    \rename($file.'.tmp', $file);
                    $stored = $file;
                }
            }

            return $stored;
        } finally {
            \umask($previous);
        }
    }

    /** Forget $spec's password. */
    public static function forget(string $home, RemoteSpec $spec): void
    {
        $file = self::find($home, $spec);
        if (null !== $file) {
            \unlink($file);
        }
    }

    /**
     * $value as one netrc token: bare when it can be, else double-quoted with
     * `"` and `\` escaped — the quoting curl reads since 7.84.
     */
    public static function token(#[\SensitiveParameter] string $value): string
    {
        return '' !== $value && 1 !== \preg_match('/[\s"\\\\]/', $value) ? $value : '"'.\addcslashes($value, characters: "\"\\\n\t\r").'"';
    }
}
