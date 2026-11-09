<?php

declare(strict_types=1);

namespace Porta\Install;

/**
 * The binary that is running: the phar when this is one, else the package's
 * own `bin/porta` (a Composer `vendor/bin` proxy includes that file, so this is
 * the package's copy either way). It is what git is told to run and what the
 * `porta` on PATH links to — asked here, rather than handed down from
 * `bin/porta`, so the IDE knows it without a boot argument.
 */
final class PortaBinary
{
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
