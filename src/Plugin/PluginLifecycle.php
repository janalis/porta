<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\I18n\Translator;
use Porta\Lsp\LspReconfigurer;
use Porta\Ui\Menu\MenuRebuilder;
use Porta\Ui\Notifier;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Tui;

/**
 * Live plugin lifecycle: activate a just-installed plugin, deactivate a
 * removed one (falling back to the next shadowed copy), and flip the
 * persisted enabled/disabled switch — keeping the menu bar, the palette, key
 * dispatch and the settings sidebar in sync without a restart. Default
 * keybindings still need a restart: the immutable Keybindings map was built
 * at boot.
 */
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
        private readonly PluginLoader $loader,
        private readonly PluginManager $plugins,
        private readonly CommandRegistry $commands,
        private readonly PluginInstaller $installer,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly MenuRebuilder $menuRebuilder,
        private readonly SettingsNavWidget $settingsNav,
        private readonly WorkspaceViews $views,
        private readonly Tui $tui,
        private readonly LspReconfigurer $lspReconfigurer,
        private readonly string $rootDir,
        array $disabledPlugins,
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
        $this->persistDisabledChoice($name);
        $wasProvider = $this->plugins->providesLanguageServer($name);
        $this->plugins->deactivate($name, $this->commands); // no shadow fallback: the NAME is off
        $this->reconfigureLspIf($wasProvider);
        $this->notifier->flash($this->translator->trans('Plugin "%name%" disabled', ['%name%' => $name]));
        $this->afterToggle();
    }

    /**
     * Enable a plugin live: load the highest-precedence installed copy. The
     * choice persists in .porta.json's `disabledPlugins` list so the next boot
     * honors it.
     */
    public function enable(string $name): void
    {
        $this->persistEnabledChoice($name);
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
                $this->reconfigureLspIf($this->plugins->providesLanguageServer($name));
                $this->notifier->flash($this->translator->trans('Plugin "%name%" enabled — default keybindings apply on restart', ['%name%' => $name]));
            }
            break;
        }
        $this->afterToggle();
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
            IdeConfig::persistBundledDisabled($this->rootDir, $name);

            return;
        }
        $this->disabled[$name] = true;
        IdeConfig::persistPluginDisabled($this->rootDir, $name);
    }

    /**
     * Persist an enable: bundled plugins join `enabledPlugins`, installed ones
     * leave `disabledPlugins`.
     */
    private function persistEnabledChoice(string $name): void
    {
        if ($this->isBundled($name)) {
            $this->enabled[$name] = true;
            IdeConfig::persistBundledEnabled($this->rootDir, $name);

            return;
        }
        unset($this->disabled[$name]);
        IdeConfig::persistPluginEnabled($this->rootDir, $name);
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

    /** Sync the settings sidebar's per-plugin sub-items with the installed set. */
    public function refreshNav(): void
    {
        $this->settingsNav->setPluginEntries($this->listedNames());
    }

    public function refreshView(): void
    {
        $this->views->refreshSettings();
    }
}
