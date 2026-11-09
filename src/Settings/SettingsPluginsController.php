<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigScope;
use Porta\Config\PortaHome;
use Porta\Event\HelpPageRequested;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Store\PluginUpdate;
use Porta\Plugin\Store\PluginUpdates;
use Porta\Plugin\Store\StoreCatalog;
use Porta\Plugin\Tool\ToolCatalog;
use Porta\Plugin\Tool\ToolJobs;
use Porta\Plugin\Tool\ToolStatus;
use Porta\Plugin\Tool\ToolUpdates;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The Plugins section of the settings view: the shared scope choice,
 * an install field (a git URL, a local path, or a vendor/name package, handed
 * to {@see PluginInstaller}) and one row per installed plugin — a removal
 * button, or an inert "Built-in" marker for the plugins bundled with Porta. A
 * successful install is activated live through {@see PluginLifecycleOps} —
 * its commands, menu entries AND default keybindings land immediately (the
 * lifecycle rebuilds the live keymap). Removal deletes the directory AND
 * deactivates the plugin live (menu, palette, keys, hooks). Removal is guarded
 * by a two-press confirm ($armed). Each plugin also gets its own settings
 * page ({@see pluginRows}): its Enabled toggle, its own settings
 * ({@see PluginSettings}, an enabled plugin's only) and the tools it runs
 * ({@see ToolCatalog}), each with its Install or Update button.
 */
final class SettingsPluginsController
{
    /** Prefix marking a row that belongs to the plugin's own descriptors. */
    private const string SETTING_PREFIX = 'pluginSetting:';

    /** Prefix marking a row for one of the plugin's tools; the rest of the key is the tool's name. */
    private const string TOOL_PREFIX = 'pluginTool:';

    /** Prefix marking an index row; the rest of the key is the plugin whose page it opens. */
    private const string OPEN_PREFIX = 'pluginOpen:';

    /** Prefix marking a store Update button; the rest is `<install scope>:<plugin>`. */
    private const string UPDATE_PREFIX = 'pluginUpdate:';

    /** A bundled plugin's offer of the package its tool's owners publish in its place. */
    private const string TAKEOVER_KEY = 'pluginTakeover';

    /** Prefix marking a Remove button; the rest is `<install scope>:<plugin>`. */
    private const string REMOVE_PREFIX = 'pluginRemove:';

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
        private readonly ToolCatalog $tools,
        private readonly ToolJobs $jobs,
        private readonly ToolUpdates $updates,
        private readonly SettingsScopeRow $scopeRow,
        private readonly SettingsScope $scope,
        private readonly SettingsProvenance $provenance,
        // Trailing and nullable so the positional constructions in the
        // settings tests stand; the container always autowires it. The
        // Documentation row asks the help window for the plugin's page
        // through it.
        private readonly ?EventDispatcherInterface $events = null,
        // Trailing for the same reason: what may load here, so an untrusted
        // project's own copy is not the one described.
        private readonly ?PluginLoader $loader = null,
        // Trailing for the same reason: the store's newer releases, and the
        // packages that take a bundled plugin over.
        private readonly ?PluginUpdates $storeUpdates = null,
        private readonly ?StoreCatalog $catalog = null,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $rows = $this->scopeRow->lead(also: $this->also(), rows: [
            new SettingsRow(
                key: 'pluginInstall',
                label: $this->translator->trans('Install plugin'),
                kind: SettingsRowKind::Text,
                value: '',
                restart: false,
                hint: $this->translator->trans('git URL, local path, or vendor/name package'),
                fieldset: $this->translator->trans('Install'),
            ),
        ]);
        $fieldset = $this->translator->trans('Installed');
        foreach ($this->installed() as $plugin) {
            $rows[] = new SettingsRow(
                key: self::OPEN_PREFIX.$plugin->name,
                label: $plugin->name,
                kind: SettingsRowKind::Action,
                value: $this->translator->trans('Open'),
                restart: false,
                // Last, where the hint's truncation costs least: in the label, a
                // sentence-long description sized the label column and pushed every
                // row's button and state off the screen.
                hint: \implode(' · ', \array_filter([$plugin->facts, $plugin->description])),
                fieldset: $fieldset,
            );
        }

        return $rows;
    }

    /** The "Save changes to" row the store's Installed tab leads with, or null when there is nothing to choose. */
    public function scopeRow(): ?SettingsRow
    {
        return $this->scopeRow->row($this->also());
    }

    /** What the scope decides on the Plugins page beyond the file. */
    private function also(): string
    {
        return $this->translator->trans('new plugins install here too');
    }

    /**
     * Every installed plugin: what it is, whether it is on, where it comes
     * from and what it still needs. The Installed tab draws them, and the
     * settings search finds them through the index rows.
     *
     * @return list<InstalledPlugin>
     */
    public function installed(): array
    {
        $plugins = [];
        foreach ($this->lifecycle->listedNames() as $name) {
            $missing = \array_map(
                static fn (ToolStatus $status): string => $status->tool->name,
                \array_values(\array_filter($this->tools->statuses($name), static fn (ToolStatus $status): bool => $status->blocks())),
            );
            $scope = $this->scopeOf($name);
            $facts = [$this->translator->trans($this->lifecycle->isDisabled($name) ? 'off' : 'on')];
            if (null !== $scope) {
                $facts[] = $this->translator->trans(InstallScope::Embedded === $scope ? 'built-in' : $scope->value);
            }
            if ([] !== $missing) {
                $facts[] = $this->translator->trans('needs %tools%', ['%tools%' => \implode(', ', $missing)]);
            }
            $plugins[] = new InstalledPlugin($name, $this->tools->manifest($name)->description ?? '', \implode(' · ', $facts));
        }

        return $plugins;
    }

    /**
     * The installed copies that may load here — never an untrusted project's
     * own, which the lifecycle does not see either: a plugin it shadows is
     * described by the copy that actually runs.
     *
     * @return list<array{name: string, scope: InstallScope, dir: string}>
     */
    private function loading(): array
    {
        return \array_values(\array_filter($this->installer->installed(), fn (array $copy): bool => $this->loader?->mayLoad($copy['dir']) ?? true));
    }

    /** Where the copy of $name that loads comes from, or null when none is installed. */
    private function scopeOf(string $name): ?InstallScope
    {
        return \array_find($this->loading(), static fn (array $plugin): bool => $name === $plugin['name'])['scope'] ?? null;
    }

    /**
     * $name's Remove buttons, one per copy that can go — none for a bundled
     * plugin, which is switched off instead. Each is the two-press confirm,
     * its armed half drawn as danger.
     *
     * @return list<SettingsRow>
     */
    private function removeRows(string $name): array
    {
        $rows = [];
        foreach ($this->installer->installed() as $plugin) {
            if ($name !== $plugin['name'] || InstallScope::Embedded === $plugin['scope']) {
                continue;
            }
            $key = self::REMOVE_PREFIX.$plugin['scope']->value.':'.$name;
            $rows[] = new SettingsRow(
                key: $key,
                label: $this->translator->trans(InstallScope::Project === $plugin['scope'] ? 'Installed in this project' : 'Installed for every project'),
                kind: SettingsRowKind::Action,
                value: $this->translator->trans('Remove'),
                restart: false,
                danger: $this->armed === $key,
                fieldset: $this->translator->trans('Installed'),
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
        foreach ($this->loading() as $plugin) {
            if ($plugin['name'] === $name) {
                return InstallScope::Embedded === $plugin['scope'] ? self::BUNDLED_KEY : null;
            }
        }

        return null;
    }

    /**
     * $name's page came on screen: its tools are asked their versions the
     * first time, and the page re-reads itself once they are in.
     *
     * Not from {@see pluginRows()}: the settings search builds every page's
     * rows, and a search must not run every plugin's binaries.
     */
    public function pageShown(string $name): void
    {
        if ($this->tools->unprobed($name)) {
            $this->tools->probe([$name], $this->lifecycle->refreshView(...));
        }
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

        // What the plugin runs, and a button to install or update each —
        // shown for a DISABLED plugin too: what it needs is worth knowing
        // before switching it on.
        $rows = [...$rows, ...\array_map($this->toolRow(...), $this->tools->statuses($name))];

        // A plugin that ships its own docs offers them last, after its knobs
        // and buttons — a disabled one too, which is when reading what it
        // does is most useful.
        return [...$rows, ...$this->docsRow($name), ...$this->takeoverRows($name), ...$this->updateRows($name), ...$this->removeRows($name)];
    }

    /**
     * One tool's row: what it is for, how it stands, and the one button that
     * applies — Install while it is missing, Update while Porta manages the
     * copy. A button that cannot do anything here is drawn disabled, with the
     * hint saying why, rather than left off: a missing row would read as
     * "nothing is needed".
     */
    private function toolRow(ToolStatus $status): SettingsRow
    {
        $tool = $status->tool;
        $pending = $this->jobs->pending($tool);
        $installable = $status->available ? null !== $status->manager : $this->jobs->installable($tool);

        return new SettingsRow(
            key: self::TOOL_PREFIX.$tool->name,
            label: '' === $tool->purpose ? $tool->name : $tool->name.' — '.$this->translator->trans($tool->purpose),
            kind: SettingsRowKind::Action,
            value: $this->translator->trans(match (true) {
                $pending => 'Working…',
                $status->available => 'Update',
                default => 'Install',
            }),
            restart: false,
            hint: $this->toolHint($status),
            fieldset: $this->translator->trans('Tools'),
            disabled: $pending || !$installable,
        );
    }

    /** Where the tool stands, in a few words: its version and place, or what its absence costs. */
    private function toolHint(ToolStatus $status): string
    {
        if (!$status->available) {
            $missing = $status->tool->role->blocksWhenMissing() ? $this->translator->trans('missing') : $this->translator->trans('optional, missing');

            // "Set its path" only where there is a setting to set it in.
            $yourself = null === $status->tool->setting ? 'install it yourself' : 'install it yourself, or set its path above';

            return $this->jobs->installable($status->tool) ? $missing : $missing.' · '.$this->translator->trans($yourself);
        }
        $newer = $this->updates->newer($status);
        $version = null === $newer ? $status->version : $this->translator->trans('%from% → %to% available', ['%from%' => $status->version, '%to%' => $newer]);
        $parts = \array_values(\array_filter([$version, null === $status->path ? '' : PortaHome::tilde($status->path)], static fn (string $part): bool => '' !== $part));
        if (null === $status->manager) {
            $parts[] = $this->translator->trans('managed outside Porta');
        }

        return [] === $parts ? $this->translator->trans('installed') : \implode(' · ', $parts);
    }

    /**
     * An Update button per installed copy of $name the store has a newer
     * release of — the store's own Update, here where the plugin is.
     *
     * @return list<SettingsRow>
     */
    private function updateRows(string $name): array
    {
        $rows = [];
        foreach ($this->storeUpdates?->outdated() ?? [] as $update) {
            if ($name !== $update->name) {
                continue;
            }
            $working = $this->storeUpdates?->isUpdating($name, $update->scope) ?? false;
            $rows[] = new SettingsRow(
                key: self::UPDATE_PREFIX.$update->scope->value.':'.$name,
                label: $this->translator->trans('%from% → %to% available', ['%from%' => $update->from, '%to%' => $update->to]),
                kind: SettingsRowKind::Action,
                value: $this->translator->trans($working ? 'Working…' : 'Update'),
                restart: false,
                hint: $this->translator->trans('reloads live once updated'),
                fieldset: $this->translator->trans('Installed'),
                disabled: $working,
            );
        }

        return $rows;
    }

    /**
     * For a bundled plugin whose tool's owners publish their own in its
     * place: the offer to get theirs, which shadows this copy.
     *
     * @return list<SettingsRow>
     */
    private function takeoverRows(string $name): array
    {
        $package = InstallScope::Embedded === $this->scopeOf($name) ? $this->catalog?->replacing($name) : null;
        // One that does not run here is not offered: installed, it would
        // shadow the working built-in copy and then be declined at load.
        if (null === $package || !$package->compatible) {
            return [];
        }

        return [new SettingsRow(
            key: self::TAKEOVER_KEY,
            label: $this->translator->trans('Its maintainers publish %package%', ['%package%' => $package->package]),
            kind: SettingsRowKind::Action,
            value: $this->translator->trans('Get'),
            restart: false,
            hint: $this->translator->trans('installed, it takes the place of the built-in copy'),
        )];
    }

    /** @return list<SettingsRow> the Documentation row, when $name ships a guide */
    private function docsRow(string $name): array
    {
        if (null === $this->installer->docsIndex($name)) {
            return [];
        }

        return [new SettingsRow(
            key: 'pluginDocs',
            label: $this->translator->trans('Documentation'),
            kind: SettingsRowKind::Action,
            value: $this->translator->trans('Open'),
            restart: false,
        )];
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
        match (true) {
            'pluginEnabled' === $key => $this->toggle($name, $value),
            \str_starts_with($key, self::TOOL_PREFIX) => $this->toolPressed($name, \substr($key, offset: \strlen(self::TOOL_PREFIX))),
            'pluginDocs' === $key => $this->openDocs($name),
            \str_starts_with($key, self::REMOVE_PREFIX) => $this->removalPressed($key),
            \str_starts_with($key, self::UPDATE_PREFIX) => $this->updatePressed($key),
            self::TAKEOVER_KEY === $key => $this->takeoverPressed($name),
            \str_starts_with($key, self::SETTING_PREFIX) => $this->changeSetting($name, \substr($key, offset: \strlen(self::SETTING_PREFIX)), $value),
            default => null,
        };
    }

    private function toggle(string $name, bool|int|string $value): void
    {
        $this->armed = null;
        (bool) $value ? $this->lifecycle->enable($name) : $this->lifecycle->disable($name);
    }

    private function openDocs(string $name): void
    {
        $this->armed = null;
        $docs = $this->installer->docsIndex($name);
        if (null !== $docs) {
            $this->events?->dispatch(new HelpPageRequested($docs));
        }
    }

    private function changeSetting(string $name, string $setting, bool|int|string $value): void
    {
        $this->pluginSettings->change($name, $setting, $value);
        $this->lifecycle->onPluginSettingChanged($name, $setting, $value);
    }

    /** Install the tool when it is missing, update it when it is there. */
    private function toolPressed(string $name, string $tool): void
    {
        $this->armed = null;
        $status = \array_find($this->tools->statuses($name), static fn (ToolStatus $status): bool => $tool === $status->tool->name);
        if (null === $status) {
            return; // not a row pluginRows() built
        }
        $status->available ? $this->jobs->update($status->tool) : $this->jobs->install($status->tool);
        // The button reads "Working…" from now until the job's outcome re-reads the page.
        $this->lifecycle->refreshView();
    }

    /** Route a settings edit/actuation for one of this section's keys. */
    public function change(string $key, string $value): void
    {
        match (true) {
            $this->scopeRow->owns($key) => $this->changeScope($value),
            'pluginInstall' === $key => $this->install(\trim($value)),
            \str_starts_with($key, self::OPEN_PREFIX) => $this->open(\substr($key, offset: \strlen(self::OPEN_PREFIX))),
            default => null,
        };
    }

    /**
     * The index row's Open: the plugin's own page, the keyboard staying on the
     * form — on its Enabled row, not on whatever row index the index page had
     * reached, which on a plugin page is as likely as not Remove.
     */
    private function open(string $name): void
    {
        $this->armed = null;
        $this->events?->dispatch(new SettingsSectionRequested('plugin:'.$name, 'pluginEnabled'));
    }

    private function changeScope(string $value): void
    {
        $this->scopeRow->change($value);
        $this->armed = null;
    }

    /** @param string|null $version the exact release, for a package the store checked */
    private function install(string $source, ?string $version = null): void
    {
        $this->armed = null;
        if ('' === $source) {
            return;
        }
        $this->installer->install(
            $source,
            InstallScope::of($this->scope->current()),
            function (?string $error, ?string $file): void {
                $message = $error ?? $this->lifecycle->activateInstalled((string) $file) ?? $this->translator->trans('Plugin installed');
                $this->notifier->flash($message, 5.0);
                $this->lifecycle->refreshView();
            },
            $version,
        );
    }

    private function updatePressed(string $key): void
    {
        $this->armed = null;
        $update = \array_find($this->storeUpdates?->outdated() ?? [], static fn (PluginUpdate $update): bool => self::UPDATE_PREFIX.$update->scope->value.':'.$update->name === $key);
        if (null !== $update) {
            $this->storeUpdates?->update($update);
        }
    }

    private function takeoverPressed(string $name): void
    {
        $package = $this->catalog?->replacing($name);
        if (null !== $package && $package->compatible) {
            $this->install($package->package, $package->version);
        }
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
