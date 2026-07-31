<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

/**
 * Class resolution for plugin-owned code: the plugin's OWN classes, from its
 * {@see PluginManifest} namespace, and the classes of any packages it vendored
 * with composer.
 *
 * Composer maps only `Porta\ => src/`, and a third-party plugin cannot edit
 * composer.json, so both halves have to be registered at runtime — before the
 * plugin's `plugin.php` is required, since its first line may already name one
 * of its own classes.
 *
 * **Composer's own autoloader is deliberately never run.** The obvious
 * implementation, `require $plugin.'/vendor/autoload.php'`, is a trap:
 * composer's generated `autoload_real.php` ends in `$loader->register(true)` —
 * *prepend* — so a plugin vendoring any package Porta also ships would take
 * over that package **for the whole process**, breaking the IDE rather than the
 * plugin. It would also execute the package's `autoload.files` at discovery
 * time, before {@see PluginOrder} has decided anything may load at all and
 * outside the per-plugin fault barrier. So the generated MAPS are read instead
 * and served from this one appended loader, where Porta's own classes are
 * always found first and a plugin's copy is simply never reached.
 *
 * Two of composer's four autoload modes are unsupported, and a plugin
 * depending on either gets a plain "class not found":
 * `autoload_namespaces.php` (PSR-0, effectively dead) and `autoload_files.php`
 * (side-effect includes — running them is exactly the hazard above).
 *
 * One `spl_autoload_register` for every plugin rather than one per plugin: the
 * map is what grows. Registering the same prefix twice is a no-op, which is
 * what lets the IDE and the test bootstrap both call {@see register()} without
 * coordinating.
 */
final class PluginAutoloader
{
    /** @var array<string, list<string>> namespace prefix (trailing \) => directories, first match wins */
    private static array $prefixes = [];

    /** @var array<string, string> fully-qualified class name => file, from vendored classmaps */
    private static array $classes = [];

    /** Whether the one loader is on the stack; two maps feed it, so neither can be the flag. */
    private static bool $registered = false;

    /**
     * Map $manifest's namespace to its directory and add whatever it vendored.
     * A plugin that ships no classes and vendors nothing is ignored.
     */
    public static function register(PluginManifest $manifest): void
    {
        $directory = $manifest->autoloadDir();
        if (null !== $manifest->namespace && null !== $directory) {
            self::addPrefix($manifest->namespace.'\\', $directory);
        }
        self::addVendor($manifest->vendorDir());
    }

    /** Composer's generated PSR-4 and classmap tables for one vendor directory. */
    private static function addVendor(?string $vendorDir): void
    {
        if (null === $vendorDir) {
            return;
        }
        self::ensureRegistered();
        /** @var mixed $psr4 */
        $psr4 = self::readMap($vendorDir.'/composer/autoload_psr4.php');
        /** @var mixed $dirs */
        foreach (\is_array($psr4) ? $psr4 : [] as $prefix => $dirs) {
            if (!\is_string($prefix)) {
                continue;
            }
            /** @var mixed $dir */
            foreach (\is_array($dirs) ? $dirs : [$dirs] as $dir) {
                if (!\is_string($dir)) {
                    continue;
                }
                self::addPrefix($prefix, $dir);
            }
        }

        /** @var mixed $classmap */
        $classmap = self::readMap($vendorDir.'/composer/autoload_classmap.php');
        /** @var mixed $file */
        foreach (\is_array($classmap) ? $classmap : [] as $class => $file) {
            // A class already claimed keeps its first claimant: plugins are
            // registered in load order, so the earlier plugin wins the same way
            // it wins a command-key collision.
            if (!\is_string($class) || !\is_string($file) || array_key_exists($class, self::$classes)) {
                continue;
            }
            self::$classes[$class] = $file;
        }
    }

    /** A composer-generated map file's return value, or null when it is absent or not a file. */
    private static function readMap(string $path): mixed
    {
        return is_file($path) ? (require $path) : null;
    }

    private static function addPrefix(string $prefix, string $directory): void
    {
        self::ensureRegistered();
        $known = self::$prefixes[$prefix] ?? [];
        if (!\in_array($directory, $known, strict: true)) {
            $known[] = $directory;
        }
        self::$prefixes[$prefix] = $known;
    }

    /** Appended, never prepended — see the class docblock for why that direction is load-bearing. */
    private static function ensureRegistered(): void
    {
        if (!self::$registered) {
            self::$registered = true;
            spl_autoload_register(self::load(...));
        }
    }

    private static function load(string $class): void
    {
        $mapped = self::$classes[$class] ?? null;
        if (null !== $mapped && is_file($mapped)) {
            require $mapped;

            return;
        }
        foreach (self::$prefixes as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', replace: '/', subject: substr($class, \strlen($prefix)));
            foreach ($directories as $directory) {
                $file = $directory.'/'.$relative.'.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        }
    }
}
