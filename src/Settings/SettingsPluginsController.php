<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallOutcome;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Lifecycle\ToolInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Workbench\Notifier;

/**
 * The Plugins section of the settings view: a scope choice (project/global),
 * an install field (a git URL, a local path, or a vendor/name package, handed
 * to {@see PluginInstaller}) and one row per installed plugin — a removal
 * button, or an inert "Built-in" marker for the plugins bundled with Porta. A
 * successful install is activated live through {@see PluginLifecycleOps} —
 * its commands, menu entries AND default keybindings land immediately (the
 * lifecycle rebuilds the live keymap). Removal deletes the directory AND
 * deactivates the plugin live (menu, palette, keys, hooks). Removal is guarded
 * by a two-press confirm ($armed). Each active plugin also gets its own
 * settings page ({@see pluginRows}) fed by {@see PluginSettings}.
 */
final class SettingsPluginsController
{
    /** Prefix marking a row that belongs to the plugin's own descriptors. */
    private const string SETTING_PREFIX = 'pluginSetting:';

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
                hint: $this->translator->trans('git URL, local path, or vendor/name package'),
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
                // No default on purpose: "enabled" has no single stock value
                // to differ from. A bundled plugin is off unless it is in
                // DEFAULT_ENABLED_PLUGINS; a project or global one is on. A
                // marker here would be wrong for one of the two.
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
                // A caption, not `false`: an Action's value IS the text on its
                // button, and false stringifies to '' — the row rendered as
                // `Install phpstan  []`, a button with nothing written on it.
                value: $this->translator->trans('Install'),
                restart: false,
            );
        }

        return $rows;
    }

    /** Route a plugin settings-page edit: the Enabled toggle or a plugin setting. */
    /**
     * Put one of a plugin's own settings back to its descriptor default.
     *
     * Only `pluginSetting:` rows: Enabled is a lifecycle toggle with no stock
     * value, and the two action rows are buttons.
     */
    public function resetPlugin(string $name, string $key): void
    {
        if (!str_starts_with($key, self::SETTING_PREFIX)) {
            return;
        }
        $setting = substr($key, offset: \strlen(self::SETTING_PREFIX));
        $this->pluginSettings->reset($name, $setting);
        // The plugin hears about it like any other write, so a holder it keeps
        // is put back too rather than staying on the value just removed.
        $this->lifecycle->onPluginSettingChanged($name, $setting, $this->pluginSettings->value($name, $setting));
    }

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
        if (str_starts_with($key, self::SETTING_PREFIX)) {
            $setting = substr($key, offset: \strlen(self::SETTING_PREFIX));
            $this->pluginSettings->change($name, $setting, $value);
            $this->lifecycle->onPluginSettingChanged($name, $setting, $value);
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
            $message = $error ?? $this->lifecycle->activateInstalled((string) $file) ?? $this->translator->trans('Plugin installed');
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
        $this->installer->remove($name, $scope, function (?string $error) use ($name): void {
            // Flash before deactivating: a failing shadow-fallback reload
            // flashes its own error, which must win over the removal
            // confirmation.
            null !== $error ? $this->notifier->error($error) : $this->notifier->success($this->translator->trans('Plugin "%name%" removed', ['%name%' => $name]), 5.0);
            if (null === $error) {
                $this->lifecycle->deactivate($name);
            }
        });
    }
}
