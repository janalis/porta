<?php

declare(strict_types=1);

namespace Porta\Plugin;

/**
 * Discovers and activates plugins from `<project>/.porta/plugins/<name>/plugin.php`
 * and `<global>/.porta/plugins/<name>/plugin.php` (project wins when both define
 * the same directory name). Each file must `return` a {@see PortaPlugin}. Every
 * failure — a parse error, a wrong return value, a bad name, a throwing
 * `activate()` — is contained per plugin: the plugin contributes nothing
 * (registrations buffer in its {@see PluginApi} and only commit on success)
 * and a boot warning is collected. Plugins are trusted in-process PHP, like
 * composer scripts.
 */
final class PluginLoader
{
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';

    /** @param list<string> $disabled plugin names skipped at boot (.porta.json `disabledPlugins`) */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $globalBase,
        private readonly PluginSettings $settings,
        private readonly ?string $embeddedDir = null,
        private readonly array $disabled = [],
    ) {}

    /** The home directory used for the global plugin dir (PORTA_HOME overrides HOME). */
    public static function globalBase(): ?string
    {
        $base = getenv('PORTA_HOME') ?: getenv('HOME');

        return \is_string($base) && '' !== $base ? $base : null;
    }

    /**
     * The plugins bundled inside the Porta package itself — a plugins dir, not
     * a base like {@see globalBase()}, because it is package-relative.
     * PORTA_EMBEDDED_PLUGINS overrides (the test suite pins it to /nonexistent
     * so scenarios opt in explicitly).
     */
    public static function embeddedDir(): string
    {
        $dir = getenv('PORTA_EMBEDDED_PLUGINS');

        return \is_string($dir) && '' !== $dir ? $dir : \dirname(__DIR__, 2).'/.porta/plugins';
    }

    /** Discover, activate and commit every plugin into $manager. */
    public function loadInto(PluginManager $manager): void
    {
        foreach ($this->discover() as $file) {
            $error = $this->loadFile($file, $manager);
            if (null !== $error) {
                $manager->addWarning($error);
            }
        }
    }

    /**
     * Plugin entry files in precedence order — project, then global, then the
     * plugins bundled with Porta — where a directory name seen earlier shadows
     * later sources. Glob order keeps this deterministic. In a Porta dev
     * checkout the project and embedded dirs coincide; basename shadowing
     * makes that a natural dedup.
     *
     * @return list<string>
     */
    private function discover(): array
    {
        // Disabled names are pre-seeded as "seen" so every copy is skipped.
        $seen = array_fill_keys($this->disabled, true);
        $files = [];
        foreach (glob($this->rootDir.'/.porta/plugins/*/plugin.php') ?: [] as $file) {
            $name = basename(\dirname($file));
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $files[] = $file;
            }
        }

        $sources = [null !== $this->globalBase ? $this->globalBase.'/.porta/plugins' : null, $this->embeddedDir];
        foreach ($sources as $dir) {
            if (null === $dir) {
                continue;
            }
            foreach (glob($dir.'/*/plugin.php') ?: [] as $file) {
                $name = basename(\dirname($file));
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    $files[] = $file;
                }
            }
        }

        return array_values($files);
    }

    /**
     * Load, activate and commit one plugin entry file (also used for a plugin
     * installed after boot). Returns the failure message, or null on success.
     */
    public function loadFile(string $file, PluginManager $manager): ?string
    {
        try {
            /** @var mixed $plugin */
            $plugin = (static fn (): mixed => require $file)();
            if (!$plugin instanceof PortaPlugin) {
                throw new PluginException('plugin.php must return a PortaPlugin instance');
            }
            $name = $plugin->name();
            if (1 !== preg_match(self::NAME_PATTERN, $name)) {
                throw new PluginException(\sprintf('invalid plugin name "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $name));
            }
            if ($manager->hasPlugin($name)) {
                throw new PluginException(\sprintf('duplicate plugin name "%s"', $name));
            }

            $api = new PluginApi($name, $manager, $this->rootDir, $this->settings);
            $plugin->activate($api);
            $manager->commit($api); // atomic: a throwing activate() lands nothing
        } catch (\Throwable $error) {
            return \sprintf('Plugin "%s" failed to load: %s', basename(\dirname($file)), $error->getMessage());
        }

        return null;
    }
}
