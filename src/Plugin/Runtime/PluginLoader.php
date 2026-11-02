<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Config\ConfigWarning;
use Porta\Editor\EditorDisplayOptions;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Api\PortaPlugin;
use Porta\Plugin\Tool\ToolLocator;
use Porta\Support\Files;
use Tempest\Highlight\TerminalTheme;

/**
 * Discovers and activates plugins from `<project>/.porta/plugins/<name>/porta.php`
 * and `<global>/.porta/plugins/<name>/porta.php` (project wins when both define
 * the same directory name). Each file must `return` a {@see PortaPlugin}. Every
 * failure — a parse error, a wrong return value, a bad name, a throwing
 * `activate()` — is contained per plugin: the plugin contributes nothing
 * (registrations buffer in its {@see PluginApi} and only commit on success)
 * and a boot warning is collected. Plugins are trusted in-process PHP, like
 * composer scripts.
 *
 * A plugin that ships CLASSES of its own declares them in a `composer.json`
 * beside its entry file ({@see PluginManifest}); those namespaces are
 * registered with {@see PluginAutoloader} before anything is required.
 *
 * A plugin that ships a `translations/` dir beside its entry file gets it
 * served by the {@see Translator} once it commits — its own strings, never
 * core's (core's catalog wins a shared id).
 */
final readonly class PluginLoader
{
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';

    /**
     * @param list<string> $disabled plugin names skipped at boot (.porta.json `disabledPlugins`)
     * @param list<string> $enabled  bundled (embedded) plugin names opted in (.porta.json `enabledPlugins`); embedded plugins are off unless listed
     * @param bool         $trusted  whether the project's own `.porta/plugins` may load — see {@see \Porta\Config\WorkspaceTrust}
     * @param (\Closure(): EditorDisplayOptions)|null $display the editor's display options, for {@see PluginApi::softWrap()}
     */
    public function __construct(
        private string $rootDir,
        private ?string $globalBase,
        private PluginSettings $settings,
        private ?string $embeddedDir = null,
        private array $disabled = [],
        private array $enabled = [],
        private ?TerminalTheme $syntaxTheme = null,
        private ?Translator $translator = null,
        private bool $trusted = true,
        private ?\Closure $display = null,
        // Trailing and optional: a direct construction gets one built on the
        // same settings; PluginHost hands in the one the container shares.
        private ?ToolLocator $locator = null,
    ) {}

    /**
     * The plugins bundled inside the Porta package itself — a plugins dir, not
     * a base like {@see \Porta\Config\PortaHome::base()}, because it is
     * package-relative.
     * PORTA_EMBEDDED_PLUGINS overrides (the test suite pins it to /nonexistent
     * so scenarios opt in explicitly).
     */
    public static function embeddedDir(): string
    {
        $dir = \getenv('PORTA_EMBEDDED_PLUGINS');

        // src/Plugin/Runtime → the package root is three levels up.
        return \is_string($dir) && '' !== $dir ? $dir : \dirname(__DIR__, levels: 3).'/plugins';
    }

    /** Discover, activate and commit every plugin into $manager. */
    public function loadInto(PluginManager $manager): void
    {
        // Every manifest is read, and every namespace registered, BEFORE any
        // plugin PHP runs: a plugin's own classes have to resolve from the first
        // line of its porta.php, and the requires[] that decide LOAD ORDER
        // cannot be discovered by loading, since loading is what they order.
        $manifests = [];
        foreach ($this->discover() as $file) {
            $manifestError = null;
            $manifest = PluginManifest::beside($file, $manifestError);
            if (null !== $manifestError) {
                // The plugin still loads on the defaults (degrade, never
                // decline) — but a typo'd manifest used to lose its classes
                // with nothing anywhere saying why.
                $manager->addWarning(new ConfigWarning('Plugin "%name%" has an unreadable composer.json (%error%) — its manifest is ignored', [
                    '%name%' => $manifest->name,
                    '%error%' => $manifestError,
                ]));
            }
            // Before the install check: a plugin this host cannot RUN is not
            // one an install would fix. Never entering $manifests is what
            // makes its dependents skip through PluginOrder like any other
            // absent requirement.
            $unmet = $this->unmet($manifest);
            if (null !== $unmet) {
                $manager->addWarning($unmet);

                continue;
            }
            if ($manifest->needsInstall()) {
                // Not loaded now — its classes are not there — but recorded, so
                // the drain that runs once the IDE is up installs it and
                // activates it live. Boot itself never shells out: this method
                // runs while the container is still being built.
                //
                // Its dependents are skipped by name too, because it never
                // enters the order; they come back when it does.
                $manager->addPendingInstall($manifest->name);

                continue;
            }
            if ($manifest->hasLegacyManifest()) {
                // It still LOADS — only its manifest is being ignored, and a
                // plugin that ships no classes and requires nothing loses
                // nothing at all. Saying so is the point: the alternative is a
                // plugin whose classes silently stop resolving.
                $manager->addWarning(new ConfigWarning('Plugin "%name%" still has a plugin.json — its fields moved to composer.json (autoload.psr-4, extra.porta.requires)', [
                    '%name%' => $manifest->name,
                ]));
            }
            foreach ($manifest->shadowedPackages() as $package) {
                $manager->addWarning(new ConfigWarning('Plugin "%name%" vendors %package%, which Porta ships — Porta\'s copy is used', ['%name%' => $manifest->name, '%package%' => $package]));
            }
            try {
                PluginAutoloader::register($manifest);
            } catch (\Throwable $error) {
                $manager->addWarning(new ConfigWarning('Plugin "%name%" failed to load: %message%', ['%name%' => $manifest->name, '%message%' => $error->getMessage()]));

                continue;
            }
            $manifests[] = $manifest;
        }

        ['order' => $order, 'skipped' => $skipped] = PluginOrder::resolve($manifests);
        $pending = $manager->pendingInstalls;
        // Walked over the MANIFESTS rather than over $skipped, so each name's
        // requires are in hand without a lookup that could miss.
        foreach ($manifests as $manifest) {
            if (!\array_key_exists($manifest->name, $skipped)) {
                continue;
            }
            // A plugin held out because something it needs is being INSTALLED
            // is a different story from one held out because its requirement is
            // absent: this one gets to load the moment the install lands, so it
            // is deferred rather than complained about.
            if ([] !== \array_intersect($manifest->requires, $pending)) {
                $manager->addDeferred($manifest->name);

                continue;
            }
            $manager->addWarning($skipped[$manifest->name]);
        }
        foreach ($order as $manifest) {
            $error = $this->loadFile($manifest->directory.'/porta.php', $manager);
            if (null !== $error) {
                $manager->addWarning($error);
            }
        }
    }

    /**
     * Plugin entry files in precedence order — project (when trusted), then
     * global, then the plugins bundled with Porta — where a directory name seen earlier shadows
     * later sources. Glob order keeps this deterministic. In a Porta dev
     * checkout the project and embedded dirs coincide; basename shadowing
     * makes that a natural dedup.
     *
     * @return list<string>
     */
    private function discover(): array
    {
        // Disabled names are pre-seeded as "seen" so every copy is skipped.
        $seen = \array_fill_keys($this->disabled, value: true);
        $enabled = \array_fill_keys($this->enabled, value: true);
        $files = [];

        // Project then global: user-installed plugins, loaded by default (opt-out).
        // A project's own only once it is trusted: loading one is running it.
        $optOut = [$this->trusted ? $this->rootDir.'/.porta/plugins' : null, null !== $this->globalBase ? $this->globalBase.'/.porta/plugins' : null];
        foreach ($optOut as $dir) {
            if (null === $dir) {
                continue;
            }
            foreach (Files::childFiles($dir, 'porta.php') as $file) {
                $name = \basename(\dirname($file));
                if (!\array_key_exists($name, $seen)) {
                    $seen[$name] = true;
                    $files[] = $file;
                }
            }
        }

        // Bundled (embedded) plugins are off unless opted into `enabledPlugins`.
        foreach (null === $this->embeddedDir ? [] : Files::childFiles($this->embeddedDir, 'porta.php') as $file) {
            $name = \basename(\dirname($file));
            if (\array_key_exists($name, $seen)) {
                continue; // shadowed by a project/global copy, or explicitly disabled
            }
            $seen[$name] = true;
            if (\array_key_exists($name, $enabled)) {
                $files[] = $file;
            }
        }

        return \array_values($files);
    }

    /**
     * The one sentence saying why this host cannot run the plugin, or null
     * when it can: unmet platform requirements (`php`, `ext-*`) and a
     * mismatched plugin-API major, combined so the user reads one line. The
     * joined `%needs%` list stays untranslated detail, like an exception
     * message elsewhere — it names versions and extension ids, not prose.
     */
    private function unmet(PluginManifest $manifest): ?ConfigWarning
    {
        $needs = $manifest->unmetPlatform();
        if (null !== $manifest->api && PluginApi::API_VERSION !== $manifest->api) {
            $needs[] = \sprintf('plugin API %d (this Porta speaks %d)', $manifest->api, PluginApi::API_VERSION);
        }
        if ([] === $needs) {
            return null;
        }

        return new ConfigWarning('Plugin "%name%" needs %needs% — not loaded', ['%name%' => $manifest->name, '%needs%' => \implode(', ', $needs)]);
    }

    /**
     * Whether the plugin in $dir may run here: anything but a copy in an
     * untrusted project's own `.porta/plugins` — unless that directory is
     * also the user's global one (a project opened at `~`) or the bundled
     * set (a Porta checkout), which load whatever the project is.
     */
    public function mayLoad(string $dir): bool
    {
        $projectPlugins = \realpath($this->rootDir.'/.porta/plugins');
        $sharedWith = \array_map(\realpath(...), \array_filter([$this->embeddedDir, null === $this->globalBase ? null : $this->globalBase.'/.porta/plugins']));

        return $this->trusted || false === $projectPlugins || \realpath(\dirname($dir)) !== $projectPlugins || \in_array($projectPlugins, $sharedWith, strict: true);
    }

    /**
     * Load, activate and commit one plugin entry file (also used for a plugin
     * installed after boot). Returns the failure, or null on success.
     */
    public function loadFile(string $file, PluginManager $manager): ?ConfigWarning
    {
        // The live path too (a just-installed or re-enabled plugin never went
        // through loadInto's pre-pass) — including the compatibility check.
        // And the trust check: the boot pass never looks in an untrusted
        // project's own plugins, and switching one on must not run its code
        // either. Reading the manifest runs nothing.
        $manifest = PluginManifest::beside($file);
        $refusal = $this->mayLoad(\dirname($file))
            ? $this->unmet($manifest)
            : new ConfigWarning('Plugin "%name%" is in an untrusted project — not loaded', ['%name%' => \basename(\dirname($file))]);
        if (null !== $refusal) {
            return $refusal;
        }
        $failure = null;
        try {
            PluginAutoloader::register($manifest);
            /** @var mixed $plugin */
            $plugin = (static fn (): mixed => require $file)();
            if (!$plugin instanceof PortaPlugin) {
                throw new PluginException('porta.php must return a PortaPlugin instance');
            }
            $name = $plugin->name();
            if (1 !== \preg_match(self::NAME_PATTERN, $name)) {
                throw new PluginException(\sprintf('invalid plugin name "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $name));
            }
            if ($manager->hasPlugin($name)) {
                throw new PluginException(\sprintf('duplicate plugin name "%s"', $name));
            }

            $api = $manager->api($name, $this->rootDir, $this->settings, $this->syntaxTheme, $this->display, $this->locator, $manifest->tools);
            $plugin->activate($api);
            $manager->commit($api, $plugin); // atomic: a throwing activate() lands nothing
            if (\is_dir($manifest->directory.'/translations')) {
                $this->translator?->addCatalogDirectory($manifest->directory.'/translations');
            }
        } catch (\Throwable $error) {
            $failure = new ConfigWarning('Plugin "%name%" failed to load: %message%', ['%name%' => \basename(\dirname($file)), '%message%' => $error->getMessage()]);
        }

        return $failure;
    }
}
