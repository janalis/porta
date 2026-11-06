<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * An empty folder for a fixture that needs SOME root and reads nothing in it —
 * a file tree a plugin test never looks at. Never the system temp dir: a
 * {@see \Porta\Widget\FileTreeWidget} lists its root as it is built, and a
 * developer's temp dir holds tens of thousands of entries, which cost every
 * such construction most of a second.
 *
 * One per process, made on first use and removed when the process ends.
 */
final class EmptyFolder
{
    private static ?string $path = null;

    public static function path(): string
    {
        if (null === self::$path) {
            $path = \sys_get_temp_dir().'/porta-empty-'.(int) \getmypid().'-'.\bin2hex(\random_bytes(4));
            new Filesystem()->mkdir($path);
            \register_shutdown_function(static fn () => new Filesystem()->remove($path));
            self::$path = $path;
        }

        return self::$path;
    }
}
