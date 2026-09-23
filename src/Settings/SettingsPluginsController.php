<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\CommandRegistry;
use Porta\Config\ConfigScope;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Lifecycle\InstallOutcome;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Lifecycle\ToolInstaller;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Runtime\ToolInstallOffers;
use Porta\Workbench\Notifier;

/**
 * The Plugins section of the settings view: the shared scope choice,
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

    /**
     * The config key a BUNDLED plugin's Enabled toggle writes.
     *
     * The only one of the two plugin lists a global write can be shadowed in:
     * `disabledPlugins` is a UNION, so a global disable always lands, while
     * `enabledPlugins` is replaced whole by the nearest layer that has it.
     */
    private const string BUNDLED_KEY = 'enabledPlugins';

    /** The removal row key awaiting its confirming second press. */
    private ?string $armed = null;

    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PluginSettings $pluginSettings,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly PluginLifecycleOps $lifecycle,
        private readonly ToolInstallOffers $plugins,
        private readonly ToolInstaller $installerTool,
        private readonly SettingsScopeRow $scopeRow,
        private readonly SettingsScope $scope,
        private readonly SettingsProvenance $provenance,
        // Trailing and nullable so the positional constructions in the
        // settings tests stand; the container always autowires it. Only a
        // SELF-installing plugin reaches for it.
        private readonly ?CommandRegistry $commands = null,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $rows = $this->scopeRow->lead(also: $this->translator->trans('new plugins install here too'), rows: [
            new SettingsRow(
                key: 'pluginInstall',
                label: $this->translator->trans('Install plugin'),
                kind: SettingsRowKind::Text,
                value: '',
                restart: false,
                hint: $this->translator->trans('git URL, local path, or vendor/name package'),
            ),
        ]);
        foreach ($this->installer->installed() as $plugin) {
            $rows[] = InstallScope::Embedded === $plugin['scope']
                ? new SettingsRow(
                    key: 'pluginInfo:'.$plugin['name'],
                    label: $plugin['name'].' ('.$this->translator->trans('built-in').')',
                    kind: SettingsRowKind::Action,
                    value: $this->translator->trans('Built-in'),
                    restart: false,
                )
                : new SettingsRow(
                    key: 'pluginRemove:'.$plugin['scope']->value.':'.$plugin['name'],
                    label: $plugin['name'].' ('.$this->translator->trans($plugin['scope']->value).')',
                    kind: SettingsRowKind::Action,
                    value: $this->translator->trans('Remove'),
                    restart: false,
                    // The armed half of the two-press confirm reads as danger.
                    danger: $this->armed === 'pluginRemove:'.$plugin['scope']->value.':'.$plugin['name'],
                );
        }

        return $rows;
    }

    /**
     * Which file decides whether this plugin loads, for the Enabled toggle.
     *
     * Every other settings row on every other page carries this, and it is
     * what {@see ShadowedWriteWarning} reads to say "you saved that globally
     * and this project ignores it". This row carried none, so the one toggle
     * whose key REPLACES rather than merges was the one that could not warn:
     * flip a bundled plugin on from Global scope while the project has an
     * `enabledPlugins` list, and the write lands, the list replaces it, and
     * nothing happens or is said.
     *
     * Null for a project- or globally-INSTALLED plugin: that toggle writes
     * `disabledPlugins`, which unions, so it can never be shadowed and a
     * provenance tag would claim a conflict that does not exist.
     */
    private function enabledOrigin(string $name): ?ConfigScope
    {
        $key = $this->bundledKey($name);

        return null === $key ? null : $this->provenance->originOf([$key]);
    }

    /** {@see BUNDLED_KEY} when $name is bundled with Porta, else null. */
    private function bundledKey(string $name): ?string
    {
        foreach ($this->installer->installed() as $plugin) {
            if ($plugin['name'] === $name) {
                return InstallScope::Embedded === $plugin['scope'] ? self::BUNDLED_KEY : null;
            }
        }

        return null;
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
                origin: $this->enabledOrigin($name),
                shadowed: null !== $this->bundledKey($name) && $this->provenance->shadowsGlobal([self::BUNDLED_KEY]),
            ),
            ...$this->pluginSettings->rows($name),
        ];

        // When an enabled plugin's declared tool binary isn't installed, offer
        // a one-click install through its package manager(s).
        //
        // A SELF-installer keeps its row once the tool is there, captioned
        // "Update": the plugin that downloads its own tool is the same thing
        // that replaces it, and a row that vanished on success would take the
        // update affordance with it. A package-manager offer still
        // disappears — `composer require` is not an upgrade path, and
        // pretending otherwise would run the wrong command.
        $offer = $this->plugins->installOffer($name);
        if (null !== $offer && (!$offer['available'] || self::isSelfInstalled($offer['via']))) {
            $rows[] = new SettingsRow(
                key: 'pluginInstallBinary',
                // The same wording either way, so a self-installer's row is
                // not a special shape on a page full of ordinary ones.
                label: $this->translator->trans($offer['available'] ? 'Update %tool%' : 'Install %tool%', ['%tool%' => $offer['tool']]),
                kind: SettingsRowKind::Action,
                // A caption, not `false`: an Action's value IS the text on its
                // button, and false stringifies to '' — the row rendered as
                // `Install phpstan  []`, a button with nothing written on it.
                value: $this->translator->trans($offer['available'] ? 'Update' : 'Install'),
                restart: false,
                hint: '' === $offer['describe'] ? null : $offer['describe'],
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
    /**
     * Every installed plugin's name, in the order the nav lists them.
     *
     * Delegates to the SAME call that feeds the nav's sub-items, so a page
     * cannot be reachable in one place and missing from the other.
     *
     * @return list<string>
     */
    public function pluginNames(): array
    {
        return $this->lifecycle->listedNames();
    }

    public function resetPlugin(string $name, string $key): void
    {
        if (!\str_starts_with($key, self::SETTING_PREFIX)) {
            return;
        }
        $setting = \substr($key, offset: \strlen(self::SETTING_PREFIX));
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
        if (\str_starts_with($key, self::SETTING_PREFIX)) {
            $setting = \substr($key, offset: \strlen(self::SETTING_PREFIX));
            $this->pluginSettings->change($name, $setting, $value);
            $this->lifecycle->onPluginSettingChanged($name, $setting, $value);
        }
    }

    /**
     * Whether $via says the plugin installs (and updates) the tool itself.
     *
     * @param list<array{0: string, 1: string}> $via
     */
    private static function isSelfInstalled(array $via): bool
    {
        return null !== \array_find($via, static fn (array $candidate): bool => PluginApi::INSTALL_SELF === $candidate[0]);
    }

    /** Install the (enabled) plugin's declared tool binary through its package manager(s). */
    private function installBinary(string $name): void
    {
        $offer = $this->plugins->installOffer($name);
        if (null === $offer) {
            return;
        }
        // A self-installer runs its OWN command: it reports, relaunches and
        // sweeps on its own terms, and ToolInstaller has no manager to hand it
        // to. Nothing is refreshed from here because nothing has happened yet
        // — the row re-derives when the binary actually lands, off
        // onServerBinaryReplaced().
        $self = \array_find($offer['via'], static fn (array $candidate): bool => PluginApi::INSTALL_SELF === $candidate[0]);
        if (null !== $self) {
            $this->commands?->run($name.':'.$self[1]);

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
        if ($this->scopeRow->owns($key)) {
            $this->scopeRow->change($value);
            $this->armed = null;

            return;
        }
        if ('pluginInstall' === $key) {
            $this->armed = null;
            $this->install(\trim($value));

            return;
        }
        if (\str_starts_with($key, 'pluginInfo:')) {
            return; // a built-in marker / placeholder row — nothing to actuate
        }
        $this->removalPressed($key);
    }

    private function install(string $source): void
    {
        if ('' === $source) {
            return;
        }
        $this->installer->install($source, InstallScope::of($this->scope->current()), function (?string $error, ?string $file): void {
            $message = $error ?? $this->lifecycle->activateInstalled((string) $file) ?? $this->translator->trans('Plugin installed');
            $this->notifier->flash($message, 5.0);
            $this->lifecycle->refreshView();
        });
    }

    private function removalPressed(string $key): void
    {
        $parts = \explode(':', $key, limit: 3);
        $scope = InstallScope::tryFrom($parts[1] ?? '');
        $name = $parts[2] ?? '';
        if (null === $scope) {
            return; // not a key rows() built
        }
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
