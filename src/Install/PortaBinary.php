<?php

declare(strict_types=1);

namespace Porta\Install;

use Porta\Support\Files;

/**
 * The binary that is running: the phar when this is one, else the package's
 * own `bin/porta` (a Composer `vendor/bin` proxy includes that file, so this is
 * the package's copy either way). It is what git is told to run and what the
 * `porta` on PATH links to — asked here, rather than handed down from
 * `bin/porta`, so the IDE knows it without a boot argument.
 */
final class PortaBinary
{
    /** Preserve matching launch links; an unrelated wrapper must never become an update target. */
    public static function entry(string $invoked, string $binary): string
    {
        $actual = Files::canonical($binary);
        $entry = Files::canonical($invoked);
        $owner = static fn (string $path): ?string => \preg_replace('~/Cellar/porta/[^/]+/~', replacement: '/Cellar/porta/{version}/', subject: $path);
        $actualOwner = $owner($actual);
        $sameInstall = null !== $actualOwner && $owner($entry) === $actualOwner;

        return \is_file($invoked) && $sameInstall ? Files::canonicalEntry($invoked) : $binary;
    }

    /** Homebrew may replace PHP too; its opt link survives removal of an old keg. */
    public static function interpreter(string $binary): string
    {
        $parts = [];
        $cellar = \strpos($binary, needle: '/Cellar/');
        if (false !== $cellar && 1 === \preg_match('~/Cellar/([^/]+)/[^/]+/bin/php$~D', $binary, $parts)) {
            $linked = \substr($binary, offset: 0, length: $cellar).'/opt/'.$parts[1].'/bin/php';
            if (\is_file($linked)) {
                return $linked;
            }
        }

        return $binary;
    }

    public static function path(): string
    {
        $phar = \Phar::running(false);

        return '' !== $phar ? $phar : (string) \realpath(self::packageDir().'/bin/porta');
    }

    /** The folder this package lives in: a checkout, or `vendor/janalis/porta` (inside the phar, its `phar://` root). */
    public static function packageDir(): string
    {
        return \dirname(__DIR__, levels: 2);
    }

    /** The phar that is running, '' when this is not one — only a phar can update itself. */
    public static function phar(): string
    {
        return \Phar::running(false);
    }
}
