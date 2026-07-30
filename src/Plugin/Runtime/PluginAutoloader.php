<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

/**
 * PSR-4 autoloading for plugin-owned classes. Composer maps only `Porta\ =>
 * src/`, and a third-party plugin cannot edit composer.json, so a plugin that
 * ships classes needs its prefix registered at runtime — from its
 * {@see PluginManifest}, before its `plugin.php` is required.
 *
 * One `spl_autoload_register` for all of them rather than one per plugin: the
 * map is what grows. Registering the same prefix twice is a no-op, which is
 * what lets the IDE and the test bootstrap both call {@see register()} without
 * coordinating.
 *
 * Deliberately narrow: no classmap, no fallback directories, no file-existence
 * warnings. A missing class is left to PHP's own "class not found", which names
 * the class and the plugin's namespace — enough to see what is wrong.
 */
final class PluginAutoloader
{
    /** @var array<string, string> namespace prefix (trailing \) => directory */
    private static array $prefixes = [];

    private static bool $registered = false;

    /** Map $manifest's namespace to its directory; a plugin that ships no classes is ignored. */
    public static function register(PluginManifest $manifest): void
    {
        $directory = $manifest->autoloadDir();
        if (null === $manifest->namespace || null === $directory) {
            return;
        }
        self::$prefixes[$manifest->namespace.'\\'] = $directory;
        if (!self::$registered) {
            self::$registered = true;
            spl_autoload_register(self::load(...));
        }
    }

    /** Every registered prefix, for tests and for `composer container`-style introspection. */
    public static function prefixes(): array
    {
        return self::$prefixes;
    }

    /** Forget every mapping (tests, which build whole IDEs over throwaway plugin dirs). */
    public static function reset(): void
    {
        self::$prefixes = [];
    }

    private static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', replace: '/', subject: substr($class, \strlen($prefix)));
            $file = $directory.'/'.$relative.'.php';
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
