<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\I18n\Translator;
use Porta\Plugin\InstallOutcome;
use Porta\Plugin\PluginInstaller;
use Porta\Plugin\PluginLifecycleOps;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginSettings;
use Porta\Plugin\ToolInstaller;
use Porta\Workbench\Notifier;

/**
 * The Plugins section of the settings view: a scope choice (project/global),
 * an install field (git URL or local path, handed to {@see PluginInstaller})
 * and one row per installed plugin — a removal button, or an inert "Built-in"
 * marker for the plugins bundled with Porta. A successful install is activated
 * live through {@see PluginLifecycleOps} — its commands land in the palette
 * and Plugins menu immediately; only default keybindings wait for a restart
 * (the Keybindings map is immutable). Removal deletes the directory AND
 * deactivates the plugin live (menu, palette, keys, hooks). Removal is guarded
 * by a two-press confirm ($armed). Each active plugin also gets its own
 * settings page ({@see pluginRows}) fed by {@see PluginSettings}.
 */
final class SettingsPluginsController
{
    /** Session-only install target; not persisted. */
    private string $scope = 'project';
    /** The removal row key awaiting its confirming second press. */
    private ?string $armed = null;

    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PluginSettings $pluginSettings,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly PluginLifecycleOps $lifecycle,
        private readonly PluginManager $plugins,
        private readonly ToolInstaller $installerTool,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $rows = [
            new SettingsRow(
                key: 'pluginScope',
                label: $this->translator->trans('Install into'),
                kind: SettingsRowKind::Choice,
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
                kind: SettingsRowKind::Text,
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
                    kind: SettingsRowKind::Action,
                    value: $this->translator->trans('Built-in'),
                    restart: false,
                )
                : new SettingsRow(
                    key: 'pluginRemove:'.$plugin['scope'].':'.$plugin['name'],
                    label: $plugin['name'].' ('.$this->translator->trans($plugin['scope']).')',
                    kind: SettingsRowKind::Action,
                    value: $this->translator->trans('Remove'),
                    restart: false,
                    // The armed half of the two-press confirm reads as danger.
                    danger: $this->armed === 'pluginRemove:'.$plugin['scope'].':'.$plugin['name'],
                );
        }

        return $rows;
    }

    /** Whether a plugin is installed (its settings page is reachable — disabled ones too). */
    public function hasPlugin(string $name): bool
    {
        return \in_array($name, $this->lifecycle->listedNames(), strict: true);
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
        $enabled = !$this->lifecycle->isDisabled($name);
        $rows = [
            new SettingsRow(
                key: 'pluginEnabled',
                label: $this->translator->trans('Enabled'),
                kind: SettingsRowKind::Bool,
                value: $enabled,
                restart: false,
            ),
            ...$this->pluginSettings->rows($name),
        ];

        // When an enabled plugin's declared tool binary isn't installed, offer
        // a one-click install through its package manager(s).
        $offer = $this->plugins->installOffer($name);
        if (null !== $offer && !$offer['available']) {
            $rows[] = new SettingsRow(
                key: 'pluginInstallBinary',
                label: $this->translator->trans('Install %tool%', ['%tool%' => $offer['tool']]),
                kind: SettingsRowKind::Action,
                value: false,
                restart: false,
            );
        }

        return $rows;
    }

    /** Route a plugin settings-page edit: the Enabled toggle or a plugin setting. */
    public function changePlugin(string $name, string $key, bool|int|string $value): void
    {
        if ('pluginEnabled' === $key) {
            $this->armed = null;
            (bool) $value ? $this->lifecycle->enable($name) : $this->lifecycle->disable($name);

            return;
        }
        if ('pluginInstallBinary' === $key) {
            $this->armed = null;
            $this->installBinary($name);

            return;
        }
        if (str_starts_with($key, 'pluginSetting:')) {
            $this->pluginSettings->change($name, substr($key, offset: 14), $value);
            $this->lifecycle->onProviderSettingChanged($name);
        }
    }

    /** Install the (enabled) plugin's declared tool binary through its package manager(s). */
    private function installBinary(string $name): void
    {
        $offer = $this->plugins->installOffer($name);
        if (null === $offer) {
            return;
        }
        $tool = $offer['tool'];
        // @mago-expect lint:no-boolean-flag-parameter -- $ok is the install-result callback (succeeded/failed), not a mode switch
        $outcome = $this->installerTool->install($offer['via'], function (bool $ok) use ($name, $tool): void {
            $this->notifier->flash(
                $ok
                    ? $this->translator->trans('Installed %tool%', ['%tool%' => $tool])
                    : $this->translator->trans('Could not install %tool%', ['%tool%' => $tool]),
                5.0,
            );
            if ($ok) {
                $this->lifecycle->onProviderSettingChanged($name); // relaunch an LSP provider now its binary exists
            }
            $this->lifecycle->refreshView(); // the install row drops once the binary is available
        });
        match ($outcome) {
            InstallOutcome::Started => $this->notifier->flash($this->translator->trans('Installing %tool%…', ['%tool%' => $tool])),
            InstallOutcome::Busy => $this->notifier->flash($this->translator->trans('An install is already running')),
            InstallOutcome::NoManager => $this->notifier->flash($this->translator->trans('No installer found for %tool% — install it via Homebrew or cargo, or put its binary on your PATH', [
                '%tool%' => $tool,
            ]), 5.0),
        };
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
            $message = $error ?? $this->lifecycle->activateInstalled((string) $file) ?? $this->translator->trans('Plugin installed — default keybindings apply on restart');
            $this->notifier->flash($message, 5.0);
            $this->lifecycle->refreshView();
        });
    }

    private function removalPressed(string $key): void
    {
        $parts = explode(':', $key, limit: 3);
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
        null !== $error ? $this->notifier->error($error) : $this->notifier->success($this->translator->trans('Plugin "%name%" removed', ['%name%' => $name]), 5.0);
        if (null === $error) {
            $this->lifecycle->deactivate($name);
        }
    }
}
