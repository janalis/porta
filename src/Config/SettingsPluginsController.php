<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\I18n\Translator;
use Porta\Plugin\PluginInstaller;
use Porta\Plugin\PluginSettings;
use Porta\Ui\Notifier;

/**
 * The Plugins section of the settings view: a scope choice (project/global),
 * an install field (git URL or local path, handed to {@see PluginInstaller})
 * and one row per installed plugin — a removal button, or an inert "Built-in"
 * marker for the plugins bundled with Porta. A successful install is activated
 * live through the $activate closure — its commands land in the palette and
 * Plugins menu immediately; only default keybindings wait for a restart (the
 * Keybindings map is immutable). Removal deletes the directory AND deactivates
 * the plugin live via the $deactivate closure (menu, palette, keys, hooks).
 * Removal is guarded by a two-press confirm ($armed). Each active plugin also
 * gets its own settings page ({@see pluginRows}) fed by {@see PluginSettings}.
 */
final class SettingsPluginsController
{
    /** Session-only install target; not persisted. */
    private string $scope = 'project';
    /** The removal row key awaiting its confirming second press. */
    private ?string $armed = null;

    /**
     * @param \Closure(string): ?string    $activate    live-load an installed plugin.php; returns the failure message or null
     * @param \Closure(string): void       $deactivate  live-deactivate a removed plugin (and reactivate a shadowed copy)
     * @param \Closure(): void             $refresh     re-push the section rows (an async clone lands after the view already re-rendered)
     * @param \Closure(): list<string>     $listedNames every installed plugin name (disabled ones included), for the per-plugin pages
     * @param \Closure(string): bool       $isDisabled  whether a plugin is currently disabled
     * @param \Closure(string, bool): void $setEnabled  toggle a plugin live (persisting the choice)
     */
    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PluginSettings $pluginSettings,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly \Closure $activate,
        private readonly \Closure $deactivate,
        private readonly \Closure $refresh,
        private readonly \Closure $listedNames,
        private readonly \Closure $isDisabled,
        private readonly \Closure $setEnabled,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $rows = [
            new SettingsRow(
                key: 'pluginScope',
                label: $this->translator->trans('Install into'),
                kind: 'choice',
                value: $this->scope,
                restart: false,
                options: ['project', 'global'],
                optionLabels: [
                    'project' => $this->translator->trans('This project'),
                    'global' => $this->translator->trans('Global (all projects)'),
                ],
            ),
            new SettingsRow(
                key: 'pluginInstall',
                label: $this->translator->trans('Install plugin'),
                kind: 'text',
                value: '',
                restart: false,
                hint: $this->translator->trans('git URL or local path'),
            ),
        ];
        foreach ($this->installer->installed() as $plugin) {
            $rows[] = 'embedded' === $plugin['scope']
                ? new SettingsRow(
                    key: 'pluginInfo:'.$plugin['name'],
                    label: $plugin['name'].' ('.$this->translator->trans('built-in').')',
                    kind: 'action',
                    value: $this->translator->trans('Built-in'),
                    restart: false,
                )
                : new SettingsRow(
                    key: 'pluginRemove:'.$plugin['scope'].':'.$plugin['name'],
                    label: $plugin['name'].' ('.$this->translator->trans($plugin['scope']).')',
                    kind: 'action',
                    value: $this->translator->trans('Remove'),
                    restart: false,
                );
        }

        return $rows;
    }

    /** Whether a plugin is installed (its settings page is reachable — disabled ones too). */
    public function hasPlugin(string $name): bool
    {
        return \in_array($name, ($this->listedNames)(), true);
    }

    /**
     * One plugin's settings page: an Enabled toggle (bundled plugins cannot be
     * removed, but every plugin can be disabled) followed by its registered
     * setting rows — none while it is disabled (it never activated).
     *
     * @return list<SettingsRow>
     */
    public function pluginRows(string $name): array
    {
        $enabled = !($this->isDisabled)($name);

        return [
            new SettingsRow(
                key: 'pluginEnabled',
                label: $this->translator->trans('Enabled'),
                kind: 'bool',
                value: $enabled,
                restart: false,
            ),
            ...$this->pluginSettings->rows($name),
        ];
    }

    /** Route a plugin settings-page edit: the Enabled toggle or a plugin setting. */
    public function changePlugin(string $name, string $key, bool|int|string $value): void
    {
        if ('pluginEnabled' === $key) {
            $this->armed = null;
            ($this->setEnabled)($name, (bool) $value);

            return;
        }
        if (str_starts_with($key, 'pluginSetting:')) {
            $this->pluginSettings->change($name, substr($key, 14), $value);
        }
    }

    /** Route a settings edit/actuation for one of this section's keys. */
    public function change(string $key, string $value): void
    {
        if ('pluginScope' === $key) {
            $this->scope = $value;
            $this->armed = null;

            return;
        }
        if ('pluginInstall' === $key) {
            $this->armed = null;
            $this->install(trim($value));

            return;
        }
        if (str_starts_with($key, 'pluginInfo:')) {
            return; // a built-in marker / placeholder row — nothing to actuate
        }
        $this->removalPressed($key);
    }

    private function install(string $source): void
    {
        if ('' === $source) {
            return;
        }
        $this->installer->install($source, $this->scope, function (?string $error, ?string $file): void {
            $message = $error ?? ($this->activate)((string) $file) ?? $this->translator->trans('Plugin installed — default keybindings apply on restart');
            $this->notifier->flash($message, 5.0);
            ($this->refresh)();
        });
    }

    private function removalPressed(string $key): void
    {
        $parts = explode(':', $key, 3);
        $scope = $parts[1] ?? '';
        $name = $parts[2] ?? '';
        if ($this->armed !== $key) {
            $this->armed = $key;
            $this->notifier->flash($this->translator->trans('Press Enter again to remove "%name%"', ['%name%' => $name]));

            return;
        }
        $this->armed = null;
        $error = $this->installer->remove($name, $scope);
        // Flash before deactivating: a failing shadow-fallback reload flashes
        // its own error, which must win over the removal confirmation.
        $this->notifier->flash($error ?? $this->translator->trans('Plugin "%name%" removed', ['%name%' => $name]), 5.0);
        if (null === $error) {
            ($this->deactivate)($name);
        }
    }
}
