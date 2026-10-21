<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

use Porta\Config\CommandRegistry;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Config\LiveKeybindings;
use Porta\Config\PortaJsonWriter;
use Porta\Container\AsIdeService;
use Porta\Editor\PaneRegistrar;
use Porta\Event\PluginContributionsActivated;
use Porta\Event\PluginContributionsRetracted;
use Porta\Event\SettingsRowsStale;
use Porta\I18n\Translator;
use Porta\Lsp\LspReconfigurer;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Plugin\Tool\ToolCatalog;
use Porta\Settings\SettingsScope;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Menu\MenuRegistrar;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
        private readonly MenuRegistrar $menuRegistrar,
        private readonly PluginPagesSync $pages,
        private readonly EventDispatcherInterface $events,
        private readonly SettingsScope $scope,
        private readonly ConfigFiles $files,
        private readonly Tui $tui,
        private readonly LspReconfigurer $lspReconfigurer,
        private readonly PortaJsonWriter $writer,
        #[Autowire('%env(json:PORTA_DISABLED_PLUGINS)%')]
        array $disabledPlugins,
        #[Autowire('%env(json:PORTA_ENABLED_PLUGINS)%')]
        array $enabledPlugins,
        // Trailing and nullable so the direct constructions in the lifecycle
        // tests stand: what a just-enabled plugin still needs is said only
        // when there is a catalog to ask.
        private readonly ?ToolCatalog $tools = null,
    ) {
        $this->disabled = \array_fill_keys($disabledPlugins, value: true);
        $this->enabled = \array_fill_keys($enabledPlugins, value: true);
    }

    /**
     * Every installed copy this session may load, in precedence order — the
     * installer's list without an untrusted project's own, which the loader
     * never runs. Seen through this, such a plugin is not here at all, and a
     * global or bundled copy of the same name is the one that counts.
     *
     * @return list<array{name: string, scope: InstallScope, dir: string}>
     */
    private function copies(): array
    {
        return \array_values(\array_filter($this->installer->installed(), fn (array $copy): bool => $this->loader->mayLoad($copy['dir'])));
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
        foreach ($this->copies() as $plugin) {
            if ($plugin['name'] !== $name) {
                continue;
            }

            $scope = $plugin['scope'];
            break;
        }

        return InstallScope::Embedded === $scope;
    }

    /**
     * Live-activate a just-installed plugin: load + commit it, register only
     * its commands (the manager's registration is incremental) and refresh the
     * Plugins menu. Returns the failure message or null.
     */
    #[\Override]
    public function activateInstalled(string $file): ?string
    {
        $error = $this->loader->loadFile($file, $this->plugins);
        if (null !== $error) {
            return $this->translator->trans($error->key, $error->params);
        }
        $this->plugins->registerCommands($this->commands);
        // Identity is the plugin's directory basename — the same convention
        // enabledPlugins, requires and the installer's listing all key on.
        $this->registerContributionsOf(\basename(\dirname($file)));
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
    #[\Override]
    public function deactivate(string $name): void
    {
        // Decided BEFORE the deactivate empties the slot: only a plugin that
        // was actually running gets its shadowed copy loaded in its place. A
        // name the user had already turned off was not loaded, and reviving a
        // lower-precedence copy of it would undo the persisted choice.
        $wasActive = $this->plugins->hasPlugin($name);
        $this->plugins->deactivate($name, $this->commands);
        $this->panes->forget($name);
        $this->retractContributionsOf($name);
        if ($wasActive) {
            foreach ($this->copies() as $candidate) {
                if ($candidate['name'] !== $name) {
                    continue;
                }

                $error = $this->loader->loadFile($candidate['dir'].'/plugin.php', $this->plugins);
                if (null !== $error) {
                    $this->notifier->error($this->translator->trans($error->key, $error->params));
                }
                if (null === $error) {
                    $this->plugins->registerCommands($this->commands);
                    // The forgets above emptied the slots, so the fallback
                    // copy's panes, view and menus must be rebuilt — unlike
                    // disable(), which leaves the standing pane in place.
                    $this->registerContributionsOf($name);
                }
                break;
            }
        }
        $this->keys->rebuild();
        $this->menuRebuilder->rebuild();
        $this->refreshNav();
        $this->events->dispatch(new SettingsRowsStale());
    }

    /**
     * Disable a plugin live (bundled plugins are disableable, never removable):
     * deactivate every registration. The choice persists in .porta.json's
     * `disabledPlugins` list so the next boot honors it.
     */
    #[\Override]
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
            $this->retractContributionsOf($target);
            $this->reconfigureLspIf($wasProvider);
        }
        // AFTER the deactivate loop, never before: deleting first could pull a
        // file out from under the shadow-fallback reload deactivate() does.
        foreach ([$name, ...$dependents] as $target) {
            $this->removeDependenciesOf($target);
        }
        $this->notifier->flash($this->translator->trans('Plugin "%name%" disabled', ['%name%' => $name]));
        $this->flashCompanions('Also disabled: %names%', $dependents);
        $this->afterToggle();
    }

    /**
     * Give back the disk the plugin's packages took — but only a tree Porta
     * installed, and only when nothing is being installed right now.
     *
     * Success is silent: the user asked to disable a plugin, not to hear about
     * its directories. The two failures speak because they leave the disk in a
     * state the next enable will behave differently in.
     */
    private function removeDependenciesOf(string $target): void
    {
        $dir = $this->installedDir($target);
        if (null === $dir) {
            return;
        }
        $message = match ($this->installer->removeDependencies($dir)) {
            DependencyRemoval::Busy => 'Cannot remove "%name%" dependencies now — an install is running',
            DependencyRemoval::Failed => 'Disabled "%name%", but its dependencies could not be removed',
            default => null,
        };
        if (null !== $message) {
            $this->notifier->flash($this->translator->trans($message, ['%name%' => $target]), seconds: 5.0);
        }
    }

    /**
     * Enable a plugin live: load the highest-precedence installed copy. The
     * choice persists in .porta.json's `disabledPlugins` list so the next boot
     * honors it.
     */
    #[\Override]
    public function enable(string $name): void
    {
        // What it requires comes with it, transitively and in order — a plugin
        // turned on without its dependencies would be turned on and broken.
        // Only here, never at boot: a startup that silently re-enables what the
        // user switched off is a worse surprise than a message.
        $required = $this->missingRequirementsOf($name);
        $targets = [...$required, $name];
        // PASS 1, complete and synchronous: every choice reaches .porta.json
        // before any subprocess starts, so a crash or a quit mid-install still
        // records what the user asked for.
        foreach ($targets as $target) {
            $this->persistEnabledChoice($target);
        }
        $this->activateQueue($targets, $name, $required, new EnableProgress());
    }

    /**
     * PASS 2: activate each target, installing its composer dependencies first
     * when it owes some.
     *
     * Serialized on purpose, and by the CALLER rather than inside the
     * installer: there is one process slot, the chain is deps-first, and a
     * plugin must not load while the one it requires is still installing.
     * Only one target is ever in flight, and the next is dispatched from the
     * previous one's exit callback.
     *
     * @param list<string> $queue    targets still to do, in order
     * @param ?string      $name      the plugin the user acted on, or null when nobody did (the boot
     *                                drain), in which case only failures are reported
     * @param list<string> $required the companions, for the closing flash
     */
    private function activateQueue(array $queue, ?string $name, array $required, EnableProgress $progress): void
    {
        while ([] !== $queue) {
            $target = \array_shift($queue);
            $dir = $this->installedDir($target);
            $outcome = null === $dir
                ? DependencyInstall::NotNeeded
                : $this->installer->installDependencies($dir, function (int $code) use ($target, $queue, $name, $required, $progress): void {
                    $this->afterDependencies($target, $code, $queue, $name, $required, $progress);
                });

            if (DependencyInstall::Started === $outcome) {
                $this->notifier->flash($this->translator->trans('Installing dependencies for "%name%"…', ['%name%' => $target]));
                $this->afterToggle(); // paint what already activated while composer works

                return; // resumes in afterDependencies()
            }
            if (DependencyInstall::NotNeeded === $outcome) {
                $progress = $progress->withActivation($this->activateInstalledCopy($target));

                continue;
            }
            // Busy or NoComposer. Loading it anyway would fatal on the first
            // missing symbol, somewhere far from the cause.
            $this->notifier->error(
                DependencyInstall::Busy === $outcome
                    ? $this->translator->trans('Cannot install "%name%" dependencies now — another install is already running', ['%name%' => $target])
                    : $this->translator->trans('Plugin "%name%" enabled, but composer is not available to install its dependencies', ['%name%' => $target]),
                seconds: 5.0,
            );
            $progress = $progress->withFailure();
        }
        $this->finishEnable($name, $required, $progress);
    }

    /**
     * One target's install finished: activate it, then carry on with the queue.
     *
     * @param list<string> $queue
     * @param list<string> $required
     */
    private function afterDependencies(string $target, int $code, array $queue, ?string $name, array $required, EnableProgress $progress): void
    {
        $progress = $this->progressAfterInstall($target, $code, $progress);
        $this->refreshView(); // an async landing arrives after the view rendered
        $this->activateQueue($queue, $name, $required, $progress);
    }

    /** What one finished install did to the enable's progress. */
    private function progressAfterInstall(string $target, int $code, EnableProgress $progress): EnableProgress
    {
        if (0 !== $code) {
            $this->notifier->error($this->translator->trans('Plugin "%name%" enabled, but composer install failed (exit code %code%)', ['%name%' => $target, '%code%' => $code]), seconds: 5.0);
        }

        // Turned back off while composer ran: the disable already said so,
        // so this is a failure with nothing left to report.
        return 0 !== $code || $this->isDisabled($target)
            ? $progress->withFailure()
            : $progress->withActivation($this->activateInstalledCopy($target));
    }

    /**
     * The closing report — three-way, not two.
     *
     * The choice is persisted either way (install the plugin later and it comes
     * up enabled), but saying "enabled" when nothing loaded reports something
     * that did not happen, and saying "is not installed" about a plugin whose
     * composer run merely failed is worse: it sends the reader looking for a
     * missing directory that is right there. When an error already named the
     * reason, the closing flash says nothing.
     *
     * @param list<string> $required
     */
    private function finishEnable(?string $name, array $required, EnableProgress $progress): void
    {
        if (null === $name) {
            // The boot drain: nobody pressed anything, so there is nothing to
            // report but the failures the queue already reported itself.
            $this->activateDeferred();
            $this->afterToggle();

            return;
        }
        // A write that another layer overrules must not report success:
        // "enabled" would be the only thing on screen, and it would be false.
        $blockedBy = $this->layerStillDisabling($name);
        $message = match (true) {
            $progress->activated && null === $blockedBy => 'Plugin "%name%" enabled',
            !$progress->reported => 'Plugin "%name%" is not installed',
            default => null, // an error, or the warning below, already named the reason
        };
        $missing = null === $message || null !== $blockedBy ? [] : $this->missingTools($name);
        match (true) {
            // Enabled, and unable to do its job: the one line on screen says
            // both, and where the fix is.
            [] !== $missing => $this->notifier->warn(
                $this->translator->trans('Plugin "%name%" enabled, but it needs %tools% — install it from its Settings page, or run "Plugins: Install Missing Tools"', [
                    '%name%' => $name,
                    '%tools%' => \implode(', ', $missing),
                ]),
                6.0,
            ),
            null !== $message => $this->notifier->flash($this->translator->trans($message, ['%name%' => $name])),
            default => null,
        };
        if (null !== $blockedBy) {
            // Notifier::notify() already requests a render.
            $this->notifier->warn($this->translator->trans('%plugin% stays off — %file% disables it', [
                '%plugin%' => $name,
                '%file%' => $this->files->label($blockedBy),
            ]));
        }
        $this->flashCompanions('Also enabled: %names%', $required);
        $this->afterToggle();
    }

    /** @return list<string> the names of $name's required tools that are not there */
    private function missingTools(string $name): array
    {
        $missing = [];
        foreach ($this->tools?->statuses($name) ?? [] as $status) {
            if (!$status->blocks()) {
                continue;
            }
            $missing[] = $status->tool->name;
        }

        return $missing;
    }

    /**
     * Install and activate the plugins boot found enabled but uninstalled.
     *
     * Called once the IDE is up, never during discovery: `loadInto()` runs
     * while the container is still being built, and a startup that blocks on
     * composer with no UI to explain itself is the failure this avoids.
     *
     * Deliberately NOT `enable()`: these plugins are already enabled, so
     * nothing is persisted and no requirement is auto-enabled — a boot that
     * silently re-enabled what the user switched off is the surprise the
     * lifecycle has always refused to be.
     */
    public function installPending(): void
    {
        $pending = $this->plugins->pendingInstalls;
        if ([] !== $pending) {
            $this->activateQueue($this->depsFirst($pending), name: null, required: [], progress: new EnableProgress());
        }
    }

    /**
     * $names ordered so a plugin follows every pending plugin it requires.
     *
     * Discovery order is alphabetical, which is not load order: `app` requiring
     * `zbase` would otherwise install and activate first, with its requirement
     * still missing. Requirements that are not themselves pending are ignored —
     * they are already loaded, or they are not coming.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function depsFirst(array $names): array
    {
        $ordered = [];
        $seen = [];
        foreach ($names as $name) {
            $this->appendDepsFirst($name, $names, $ordered, $seen);
        }

        return $ordered;
    }

    /**
     * Depth-first: a name is appended only after the pending plugins it
     * requires.
     *
     * @param list<string>        $pending
     * @param list<string>        $ordered accumulated, requirements first
     * @param array<string, true> $seen    claimed before recursing, so a requirement cycle terminates
     */
    private function appendDepsFirst(string $name, array $pending, array &$ordered, array &$seen): void
    {
        if (\array_key_exists($name, $seen)) {
            return;
        }
        $seen[$name] = true;
        foreach ($this->manifestsOf($name) as $required) {
            if (!\in_array($required, $pending, strict: true)) {
                continue; // already loaded, or not coming
            }
            $this->appendDepsFirst($required, $pending, $ordered, $seen);
        }
        $ordered[] = $name;
    }

    /**
     * Load the plugins boot held out ONLY because something they require was
     * being installed.
     *
     * Repeated until a pass changes nothing, because a chain unlocks one link
     * at a time: A requires B requires C, and C's install frees B, which frees
     * A. A plugin whose requirement did NOT come up (its install failed) stays
     * out — loading it would be loading a plugin that cannot work, which is
     * exactly what the order refused to do at boot.
     */
    private function activateDeferred(): void
    {
        $waiting = $this->plugins->deferred;
        do {
            $stillWaiting = [];
            foreach ($waiting as $name) {
                $ready = true;
                foreach ($this->manifestsOf($name) as $required) {
                    $ready = $ready && $this->plugins->hasPlugin($required);
                }
                if ($ready) {
                    $this->activateInstalledCopy($name);

                    continue;
                }
                $stillWaiting[] = $name;
            }
            $changed = \count($stillWaiting) !== \count($waiting);
            $waiting = $stillWaiting;
        } while ($changed && [] !== $waiting);
    }

    /** The highest-precedence installed copy of $name, or null when there is none. */
    private function installedDir(string $name): ?string
    {
        foreach ($this->copies() as $candidate) {
            if ($candidate['name'] === $name) {
                return $candidate['dir'];
            }
        }

        return null;
    }

    /** Load the highest-precedence installed copy of one plugin, reporting a failure. False when there is no copy to load. */
    private function activateInstalledCopy(string $name): bool
    {
        $dir = $this->installedDir($name);
        $error = null === $dir ? null : $this->loader->loadFile($dir.'/plugin.php', $this->plugins);
        if (null !== $error) {
            $this->notifier->error($this->translator->trans($error->key, $error->params));
        }
        if (null === $dir || null !== $error) {
            return false;
        }
        $this->plugins->registerCommands($this->commands);
        $this->registerContributionsOf($name);
        $this->reconfigureLspIf($this->plugins->providesLanguageServer($name));

        return true;
    }

    /**
     * Build one just-activated plugin's contributed surfaces: its panes, its
     * top-level menus, and — through the {@see PluginContributionsActivated}
     * fact — its full-screen view, region minted and layout recompiled. The
     * view surface listens rather than being held here, because a constructor
     * edge to it cycles through the overlay stack back to this very service
     * via WelcomeSteps; dispatch is synchronous, so the view exists before the
     * caller's tail runs. Commands are the caller's job — their registration
     * is watermarked, not per-plugin.
     */
    private function registerContributionsOf(string $name): void
    {
        $this->panes->registerFor($name);
        $this->menuRegistrar->registerFor($name);
        $this->events->dispatch(new PluginContributionsActivated($name));
    }

    /**
     * Retract them on disable/removal. Panes are deliberately ABSENT: a tab
     * opened before the toggle still renders in its pane, so disable leaves it
     * standing ({@see PaneRegistrar::forget()}'s docblock) — while an open
     * view is closed and un-minted (by the {@see PluginContributionsRetracted}
     * listener), because the main area must return to the editor.
     */
    private function retractContributionsOf(string $name): void
    {
        $this->menuRegistrar->forget($name);
        $this->events->dispatch(new PluginContributionsRetracted($name));
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

        return \array_values(\array_unique($needed));
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
        foreach ($this->copies() as $plugin) {
            $candidate = $plugin['name'];
            if ($this->isDisabled($candidate) || \in_array($candidate, $dependents, strict: true)) {
                continue;
            }
            if (\in_array($name, $this->manifestsOf($candidate), strict: true)) {
                $dependents = [...$dependents, $candidate, ...$this->dependentsOf($candidate)];
            }
        }

        return \array_values(\array_unique($dependents));
    }

    /**
     * What $name declares it requires, from its manifest.
     *
     * @return list<string>
     */
    private function manifestsOf(string $name): array
    {
        // The FIRST copy, like every other read: array_column() would key the
        // LAST one, so a plugin installed both in the project and globally
        // would take its requires from one copy and its code from the other.
        $dir = $this->installedDir($name);

        return null === $dir ? [] : PluginManifest::beside($dir.'/plugin.php')->requires;
    }

    /** @param list<string> $names */
    private function flashCompanions(string $message, array $names): void
    {
        if ([] !== $names) {
            $this->notifier->flash($this->translator->trans($message, ['%names%' => \implode(', ', $names)]));
        }
    }

    #[\Override]
    public function onProviderSettingChanged(string $name): void
    {
        $this->reconfigureLspIf($this->plugins->providesLanguageServer($name));
    }

    /**
     * $name replaced its language server's binary on disk.
     *
     * Distinct from {@see onProviderSettingChanged()}: across an UPGRADE the
     * resolved map does not move — same path, same argv — so the pool's
     * identical-map skip declines and the old process keeps serving.
     */
    #[\Override]
    public function onServerBinaryReplaced(string $name): void
    {
        if ($this->plugins->providesLanguageServer($name) && !$this->lspReconfigurer->isOverridden()) {
            $this->lspReconfigurer->relaunch();
        }
        // A binary appearing (or being replaced) on disk is exactly when an
        // install row stops saying "Install" and starts saying "Update", and
        // when what it reports as installed changes. The install lands
        // asynchronously, long after the page rendered, so the page has to be
        // told — the settings view ignores this unless it is open on a plugin.
        $this->refreshView();
    }

    /**
     * The Settings page wrote one of $name's own settings.
     *
     * Separate from {@see onProviderSettingChanged()} because that one is also
     * reached from the install-a-binary path, which has no key to report — and
     * an optional key would make "no key" and "some key" the same call.
     */
    #[\Override]
    public function onPluginSettingChanged(string $name, string $key, bool|int|string $value): void
    {
        $this->plugins->notifySettingChanged($name, $key, $value);
        $this->onProviderSettingChanged($name);
    }

    /**
     * Persist a disable: bundled plugins leave `enabledPlugins`, installed ones
     * join `disabledPlugins`.
     */
    private function persistDisabledChoice(string $name): void
    {
        if ($this->isBundled($name)) {
            unset($this->enabled[$name]);
            $this->writer->persistBundledDisabled($this->scope->current(), $name);

            return;
        }
        $this->disabled[$name] = true;
        $this->writer->persistPluginDisabled($this->scope->current(), $name);
    }

    /**
     * Persist an enable: bundled plugins join `enabledPlugins`, installed ones
     * leave `disabledPlugins`.
     */
    private function persistEnabledChoice(string $name): void
    {
        if ($this->isBundled($name)) {
            $this->enabled[$name] = true;
            $this->writer->persistBundledEnabled($this->scope->current(), $name);
            // `disabledPlugins` holds a bundled plugin back too: an enable
            // that left it there would write and change nothing.
            if (\array_key_exists($name, $this->disabled)) {
                unset($this->disabled[$name]);
                $this->writer->persistPluginEnabled($this->scope->current(), $name);
            }

            return;
        }
        unset($this->disabled[$name]);
        $this->writer->persistPluginEnabled($this->scope->current(), $name);
    }

    /**
     * The OTHER config file that still disables $name, or null when nothing
     * does.
     *
     * `disabledPlugins` unions across the two layers on purpose — both files
     * saying "not this one" have to agree, so a project cannot quietly
     * re-enable what the user turned off for every project. The consequence is
     * that pressing Enable there writes correctly and changes nothing, and a
     * toggle that does nothing while reporting success is worse than one that
     * fails.
     *
     * Asked of the EFFECTIVE config rather than of a file, so it answers what
     * the merge actually decided instead of re-deriving the merge. It is the
     * other file because the enable just took $name out of this one's
     * `disabledPlugins` — a bundled plugin's too ({@see persistEnabledChoice()}).
     */
    private function layerStillDisabling(string $name): ?ConfigScope
    {
        if (!\in_array($name, IdeConfig::load($this->files->rootDir())->disabledPlugins, strict: true)) {
            return null;
        }

        return ConfigScope::Project === $this->scope->current() ? ConfigScope::Global : ConfigScope::Project;
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
        // SettingsRowsStale's own docblock has always claimed it covers a
        // plugin being "installed, enabled, disabled or removed", and removal
        // raised it — but the enable/disable tail never did. Anything caching
        // the row set across a toggle was quietly wrong.
        $this->events->dispatch(new SettingsRowsStale());
        $this->tui->requestRender();
    }

    #[\Override]
    public function isDisabled(string $name): bool
    {
        // `disabledPlugins` wins for a bundled plugin too: boot skips every
        // copy of a name it lists, so a toggle drawn on would be a lie.
        return \array_key_exists($name, $this->disabled) || ($this->isBundled($name) && !\array_key_exists($name, $this->enabled));
    }

    /**
     * Every installed plugin name (project, global and bundled — disabled ones
     * included, so their pages stay reachable to re-enable them), first
     * occurrence per name in precedence order.
     *
     * @return list<string>
     */
    #[\Override]
    public function listedNames(): array
    {
        $names = [];
        foreach ($this->copies() as $plugin) {
            // Kept as the value: a name in digits would come back from
            // array_keys() as an int, which is not the list<string> promised.
            $names[$plugin['name']] ??= $plugin['name'];
        }

        return \array_values($names);
    }

    /** Sync the per-plugin settings pages with the installed set, and which of them are off. */
    public function refreshNav(): void
    {
        $names = $this->listedNames();
        $this->pages->refresh($names, \array_values(\array_filter($names, $this->isDisabled(...))));
    }

    #[\Override]
    public function refreshView(): void
    {
        $this->events->dispatch(new SettingsRowsStale());
    }
}
