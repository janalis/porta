<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\I18n\Translator;
use Porta\Ui\MenuRebuilder;
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
    /** @var array<string, true> live disabled-plugin set (persisted as .porta.json `disabledPlugins`) */
    private array $disabled;

    /** @param list<string> $disabledPlugins */
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
        private readonly string $rootDir,
        array $disabledPlugins,
    ) {
        $this->disabled = array_fill_keys($disabledPlugins, value: true);
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
                $this->notifier->flash($error, 5.0);
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
        $this->disabled[$name] = true;
        IdeConfig::persistPluginDisabled($this->rootDir, $name);
        $this->plugins->deactivate($name, $this->commands); // no shadow fallback: the NAME is off
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
        unset($this->disabled[$name]);
        IdeConfig::persistPluginEnabled($this->rootDir, $name);
        foreach ($this->installer->installed() as $candidate) {
            if ($candidate['name'] !== $name) {
                continue;
            }

            $error = $this->loader->loadFile($candidate['dir'].'/plugin.php', $this->plugins);
            if (null !== $error) {
                $this->notifier->flash($error, 5.0);
            }
            if (null === $error) {
                $this->plugins->registerCommands($this->commands);
                $this->notifier->flash($this->translator->trans('Plugin "%name%" enabled — default keybindings apply on restart', ['%name%' => $name]));
            }
            break;
        }
        $this->afterToggle();
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
        return array_key_exists($name, $this->disabled);
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
