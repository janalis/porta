<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Api\PortaPlugin;
use Tempest\Highlight\TerminalTheme;

/**
 * Discovers and activates plugins from `<project>/.porta/plugins/<name>/plugin.php`
 * and `<global>/.porta/plugins/<name>/plugin.php` (project wins when both define
 * the same directory name). Each file must `return` a {@see PortaPlugin}. Every
 * failure — a parse error, a wrong return value, a bad name, a throwing
 * `activate()` — is contained per plugin: the plugin contributes nothing
 * (registrations buffer in its {@see PluginApi} and only commit on success)
 * and a boot warning is collected. Plugins are trusted in-process PHP, like
 * composer scripts.
 *
 * A plugin that ships CLASSES of its own declares them in a `plugin.json`
 * beside its entry file ({@see PluginManifest}); those namespaces are
 * registered with {@see PluginAutoloader} before anything is required.
 */
final class PluginLoader
{
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';

    /**
     * @param list<string> $disabled plugin names skipped at boot (.porta.json `disabledPlugins`)
     * @param list<string> $enabled  bundled (embedded) plugin names opted in (.porta.json `enabledPlugins`); embedded plugins are off unless listed
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $globalBase,
        private readonly PluginSettings $settings,
        private readonly ?string $embeddedDir = null,
        private readonly array $disabled = [],
        private readonly array $enabled = [],
        private readonly ?TerminalTheme $syntaxTheme = null,
    ) {}

    /** The home directory used for the global plugin dir (PORTA_HOME overrides HOME). */
    public static function globalBase(): ?string
    {
        $portaHome = getenv('PORTA_HOME');
        $base = $portaHome ? $portaHome : getenv('HOME');

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

        // src/Plugin/Runtime → the package root is three levels up.
        return \is_string($dir) && '' !== $dir ? $dir : \dirname(__DIR__, levels: 3).'/plugins';
    }

    /** Discover, activate and commit every plugin into $manager. */
    public function loadInto(PluginManager $manager): void
    {
        $files = $this->discover();
        // Every manifest is read, and every namespace registered, BEFORE any
        // plugin PHP runs: a plugin's own classes have to resolve from the first
        // line of its plugin.php, and nothing here may depend on load order.
        foreach ($files as $file) {
            PluginAutoloader::register(PluginManifest::beside($file));
        }
        foreach ($files as $file) {
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
        $seen = array_fill_keys($this->disabled, value: true);
        $enabled = array_fill_keys($this->enabled, value: true);
        $files = [];

        // Project then global: user-installed plugins, loaded by default (opt-out).
        $optOut = [$this->rootDir.'/.porta/plugins', null !== $this->globalBase ? $this->globalBase.'/.porta/plugins' : null];
        foreach ($optOut as $dir) {
            if (null === $dir) {
                continue;
            }
            $dirFiles = glob($dir.'/*/plugin.php');
            foreach (\is_array($dirFiles) ? $dirFiles : [] as $file) {
                $name = basename(\dirname($file));
                if (!array_key_exists($name, $seen)) {
                    $seen[$name] = true;
                    $files[] = $file;
                }
            }
        }

        // Bundled (embedded) plugins are off unless opted into `enabledPlugins`.
        $embeddedFiles = null === $this->embeddedDir ? [] : glob($this->embeddedDir.'/*/plugin.php');
        foreach (\is_array($embeddedFiles) ? $embeddedFiles : [] as $file) {
            $name = basename(\dirname($file));
            if (array_key_exists($name, $seen)) {
                continue; // shadowed by a project/global copy, or explicitly disabled
            }
            $seen[$name] = true;
            if (array_key_exists($name, $enabled)) {
                $files[] = $file;
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
        // The live path too (a just-installed or re-enabled plugin never went
        // through loadInto's pre-pass).
        PluginAutoloader::register(PluginManifest::beside($file));
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

            $api = new PluginApi($name, $manager, $this->rootDir, $this->settings, $this->syntaxTheme);
            $plugin->activate($api);
            $manager->commit($api); // atomic: a throwing activate() lands nothing
        } catch (\Throwable $error) {
            return \sprintf('Plugin "%s" failed to load: %s', basename(\dirname($file)), $error->getMessage());
        }

        return null;
    }
}
