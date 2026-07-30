<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Config\PortaJsonWriter;
use Porta\Container\AsIdeService;
use Porta\Editor\PaneRegistrar;
use Porta\I18n\Translator;
use Porta\Lsp\LspReconfigurer;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Notifier;
use Porta\Workbench\WorkspaceViews;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/**
 * Live plugin lifecycle: activate a just-installed plugin, deactivate a
 * removed one (falling back to the next shadowed copy), and flip the
 * persisted enabled/disabled switch — keeping the menu bar, the palette, key
 * dispatch, DEFAULT KEYBINDINGS (via {@see LiveKeybindings::rebuild()},
 * always before the menu rebuild so shortcut labels bake from the fresh map)
 * and the settings sidebar in sync without a restart.
 */
#[AsIdeService]
final class PluginLifecycle implements PluginLifecycleOps
{
    /** @var array<string, true> live disabled set for project/global plugins (persisted as .porta.json `disabledPlugins`) */
    private array $disabled;

    /** @var array<string, true> live opt-in set for bundled plugins (persisted as .porta.json `enabledPlugins`) */
    private array $enabled;

    /**
     * @param list<string> $disabledPlugins project/global plugins turned off
     * @param list<string> $enabledPlugins  bundled plugins opted in (bundled are off by default)
     */
    public function __construct(
        private readonly LiveKeybindings $keys,
        private readonly PluginLoader $loader,
        private readonly PluginManager $plugins,
        private readonly PaneRegistrar $panes,
        private readonly CommandRegistry $commands,
        private readonly PluginInstaller $installer,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly MenuRebuilder $menuRebuilder,
        private readonly PluginPagesSync $pages,
        private readonly WorkspaceViews $views,
        private readonly Tui $tui,
        private readonly LspReconfigurer $lspReconfigurer,
        private readonly string $rootDir,
        #[Autowire('%env(json:PORTA_DISABLED_PLUGINS)%')]
        array $disabledPlugins,
        #[Autowire('%env(json:PORTA_ENABLED_PLUGINS)%')]
        array $enabledPlugins,
    ) {
        $this->disabled = array_fill_keys($disabledPlugins, value: true);
        $this->enabled = array_fill_keys($enabledPlugins, value: true);
    }

    /**
     * Whether $name is a bundled (embedded-source) plugin — those are off by
     * default and opt into `enabledPlugins`; project/global plugins are on by
     * default and opt out via `disabledPlugins`. The effective (highest-precedence)
     * copy decides: a project copy shadowing a bundled one counts as project.
     */
    private function isBundled(string $name): bool
    {
        $scope = null;
        foreach ($this->installer->installed() as $plugin) {
            if ($plugin['name'] !== $name) {
                continue;
            }

            $scope = $plugin['scope'];
            break;
        }

        return 'embedded' === $scope;
    }

    /**
     * Live-activate a just-installed plugin: load + commit it, register only
     * its commands (the manager's registration is incremental) and refresh the
     * Plugins menu. Returns the failure message or null.
     */
    public function activateInstalled(string $file): ?string
    {
        $error = $this->loader->loadFile($file, $this->plugins);
        if (null !== $error) {
            return $error;
        }
        $this->plugins->registerCommands($this->commands);
        $this->keys->rebuild();
        $this->menuRebuilder->rebuild();
        $this->refreshNav();

        return null;
    }

    /**
     * Live-deactivate a removed plugin — its menu entries, palette commands,
     * key dispatch and hooks all die immediately — then re-activate the next
     * same-name copy down the precedence chain (project > global > built-in),
     * so removing a project override falls back to the shadowed copy live.
     */
    public function deactivate(string $name): void
    {
        $this->plugins->deactivate($name, $this->commands);
        $this->panes->forget($name);
        foreach ($this->installer->installed() as $candidate) {
            if ($candidate['name'] !== $name) {
                continue;
            }

            $error = $this->loader->loadFile($candidate['dir'].'/plugin.php', $this->plugins);
            if (null !== $error) {
                $this->notifier->error($error);
            }
            if (null === $error) {
                $this->plugins->registerCommands($this->commands);
            }
            break;
        }
        $this->keys->rebuild();
        $this->menuRebuilder->rebuild();
        $this->refreshNav();
        $this->views->refreshSettings();
    }

    /**
     * Disable a plugin live (bundled plugins are disableable, never removable):
     * deactivate every registration. The choice persists in .porta.json's
     * `disabledPlugins` list so the next boot honors it.
     */
    public function disable(string $name): void
    {
        // Whatever needs this one cannot work without it, so it goes too — the
        // counterpart of enable()'s auto-enable, and for the same reason: the
        // user acted on one plugin, and the honest answer names what followed.
        $dependents = $this->dependentsOf($name);
        foreach ([$name, ...$dependents] as $target) {
            $this->persistDisabledChoice($target);
            $wasProvider = $this->plugins->providesLanguageServer($target);
            $this->plugins->deactivate($target, $this->commands); // no shadow fallback: the NAME is off
            $this->reconfigureLspIf($wasProvider);
        }
        $this->notifier->flash($this->translator->trans('Plugin "%name%" disabled', ['%name%' => $name]));
        $this->flashCompanions('Also disabled: %names%', $dependents);
        $this->afterToggle();
    }

    /**
     * Enable a plugin live: load the highest-precedence installed copy. The
     * choice persists in .porta.json's `disabledPlugins` list so the next boot
     * honors it.
     */
    public function enable(string $name): void
    {
        // What it requires comes with it, transitively and in order — a plugin
        // turned on without its dependencies would be turned on and broken.
        // Only here, never at boot: a startup that silently re-enables what the
        // user switched off is a worse surprise than a message.
        $required = $this->missingRequirementsOf($name);
        foreach ([...$required, $name] as $target) {
            $this->persistEnabledChoice($target);
            $this->activateInstalledCopy($target);
        }
        $this->notifier->flash($this->translator->trans('Plugin "%name%" enabled', ['%name%' => $name]));
        $this->flashCompanions('Also enabled: %names%', $required);
        $this->afterToggle();
    }

    /** Load the highest-precedence installed copy of one plugin, reporting a failure. */
    private function activateInstalledCopy(string $name): void
    {
        foreach ($this->installer->installed() as $candidate) {
            if ($candidate['name'] !== $name) {
                continue;
            }

            $error = $this->loader->loadFile($candidate['dir'].'/plugin.php', $this->plugins);
            if (null !== $error) {
                $this->notifier->error($error);

                return;
            }
            $this->plugins->registerCommands($this->commands);
            $this->reconfigureLspIf($this->plugins->providesLanguageServer($name));

            return;
        }
    }

    /**
     * The disabled plugins $name requires, transitively, in load order — the
     * ones enabling it has to bring along.
     *
     * @return list<string>
     */
    private function missingRequirementsOf(string $name): array
    {
        $needed = [];
        foreach ($this->manifestsOf($name) as $required) {
            if (!$this->isDisabled($required) || \in_array($required, $needed, strict: true)) {
                continue;
            }
            $needed = [...$this->missingRequirementsOf($required), $required, ...$needed];
        }

        return array_values(array_unique($needed));
    }

    /**
     * The enabled plugins that require $name, transitively — the ones disabling
     * it takes with it.
     *
     * @return list<string>
     */
    private function dependentsOf(string $name): array
    {
        $dependents = [];
        foreach ($this->installer->installed() as $plugin) {
            $candidate = $plugin['name'];
            if ($this->isDisabled($candidate) || \in_array($candidate, $dependents, strict: true)) {
                continue;
            }
            if (\in_array($name, $this->manifestsOf($candidate), strict: true)) {
                $dependents = [...$dependents, $candidate, ...$this->dependentsOf($candidate)];
            }
        }

        return array_values(array_unique($dependents));
    }

    /**
     * What $name declares it requires, from its manifest.
     *
     * @return list<string>
     */
    private function manifestsOf(string $name): array
    {
        $dirs = array_column($this->installer->installed(), 'dir', 'name');
        $dir = $dirs[$name] ?? null;

        return null === $dir ? [] : PluginManifest::beside($dir.'/plugin.php')->requires;
    }

    /** @param list<string> $names */
    private function flashCompanions(string $message, array $names): void
    {
        if ([] !== $names) {
            $this->notifier->flash($this->translator->trans($message, ['%names%' => implode(', ', $names)]));
        }
    }

    public function onProviderSettingChanged(string $name): void
    {
        $this->reconfigureLspIf($this->plugins->providesLanguageServer($name));
    }

    /**
     * Persist a disable: bundled plugins leave `enabledPlugins`, installed ones
     * join `disabledPlugins`.
     */
    private function persistDisabledChoice(string $name): void
    {
        if ($this->isBundled($name)) {
            unset($this->enabled[$name]);
            PortaJsonWriter::persistBundledDisabled($this->rootDir, $name);

            return;
        }
        $this->disabled[$name] = true;
        PortaJsonWriter::persistPluginDisabled($this->rootDir, $name);
    }

    /**
     * Persist an enable: bundled plugins join `enabledPlugins`, installed ones
     * leave `disabledPlugins`.
     */
    private function persistEnabledChoice(string $name): void
    {
        if ($this->isBundled($name)) {
            $this->enabled[$name] = true;
            PortaJsonWriter::persistBundledEnabled($this->rootDir, $name);

            return;
        }
        unset($this->disabled[$name]);
        PortaJsonWriter::persistPluginEnabled($this->rootDir, $name);
    }

    /** Re-resolve the LSP servers when a toggled plugin provides one (and no --lsp override wins). */
    // @mago-expect lint:no-boolean-flag-parameter -- $provider is a computed guard shared by the three toggle paths, not a mode switch
    private function reconfigureLspIf(bool $provider): void
    {
        if ($provider && !$this->lspReconfigurer->isOverridden()) {
            $this->lspReconfigurer->reconfigure();
        }
    }

    /** Common tail after an enable/disable: refresh menu, sidebar and screen. */
    private function afterToggle(): void
    {
        $this->keys->rebuild();
        $this->menuRebuilder->rebuild();
        $this->refreshNav();
        $this->tui->requestRender();
    }

    public function isDisabled(string $name): bool
    {
        return $this->isBundled($name)
            ? !array_key_exists($name, $this->enabled)
            : array_key_exists($name, $this->disabled);
    }

    /**
     * Every installed plugin name (project, global and bundled — disabled ones
     * included, so their pages stay reachable to re-enable them), first
     * occurrence per name in precedence order.
     *
     * @return list<string>
     */
    public function listedNames(): array
    {
        $names = [];
        foreach ($this->installer->installed() as $plugin) {
            $names[$plugin['name']] = true;
        }

        return array_keys($names);
    }

    /** Sync the per-plugin settings pages with the installed set. */
    public function refreshNav(): void
    {
        $this->pages->refresh($this->listedNames());
    }

    public function refreshView(): void
    {
        $this->views->refreshSettings();
    }
}
