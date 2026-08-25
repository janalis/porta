<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Config\CommandRegistry;
use Porta\Config\ConfigWarning;
use Porta\Config\KeyDispatch;
use Porta\Config\RebindableActionSource;
use Porta\Container\ListenerPriority;
use Porta\Debug\DebugAdapterProvider;
use Porta\Editor\EditorPaneSource;
use Porta\Editor\ViewerProviderSource;
use Porta\Event\PluginObservable;
use Porta\Highlight\CallableHighlightProvider;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\Lsp\LanguageServerProvider;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Search\TokenSpanProvider;
use Porta\Workbench\Dock\DockToolSource;
use Porta\Workbench\Menu\ContributedMenuSource;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuSurface;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusSegmentSource;
use Porta\Workbench\View\ContributedViewSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The committed plugin state: commands, default keys, menu entries, hook
 * listeners and boot warnings. Every plugin handler and hook listener is
 * wrapped in a catch-all so a faulting plugin flashes a message instead of
 * killing the event loop (core commands stay unguarded — a core throw is a
 * bug that must fail loudly). The runtime is wired late in Ide's construction;
 * an action method used before that (i.e. during activate()) hits the
 * {@see runtime()} gate.
 */
// Of the many source roles this class plays, SyntaxHighlightProviderSource is
// the one with a second implementation, so the loader derives no alias and the
// class names itself. The Highlight subsystem autowires the interface it owns
// and never depends on Porta\Plugin.
#[AsAlias(SyntaxHighlightProviderSource::class)]
final class PluginManager implements
    ContributedMenuSource,
    ContributedViewSource,
    DebugAdapterProvider,
    DockToolSource,
    EditorPaneSource,
    LanguageServerProvider,
    RebindableActionSource,
    StatusSegmentSource,
    SyntaxHighlightProviderSource,
    TokenSpanProvider,
    ViewerProviderSource
{
    /** @var list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    private array $commands = [];
    /** @var array<string, list<string>> namespaced command id => default key specs */
    private array $keys = [];
    /** @var list<PluginMenuSpec> Plugins-menu entries */
    private array $menuSpecs = [];
    /** @var list<array{plugin: string, languageId: string, name: string, resolve: \Closure(): (list<string>|null), initOptions: (\Closure(): array<string, mixed>)|null}> committed LSP providers, in load order */
    private array $languageServers = [];
    /** @var list<array{plugin: string, tool: string, available: \Closure(): bool, via: list<array{0: string, 1: string}>}> committed install offers */
    private array $installers = [];
    /** @var list<array{plugin: string, id: string, label: string, languages: \Closure(): list<string>, highlight: \Closure(string, string, \Closure(string): void): void, available: (\Closure(): bool)|null}> committed highlighting backends, in load order */
    private array $highlighters = [];
    /** @var list<array{plugin: string, languages: \Closure(): list<string>, classify: \Closure(string, string): (list<array{start: int, end: int}>|null)}> committed search classifiers, in load order */
    private array $tokenSpans = [];
    /** @var list<array{plugin: string, id: string, claims: \Closure(string): bool}> committed viewer routes, in load order */
    private array $viewers = [];
    /** @var list<array{plugin: string, id: string, pane: \Closure, load: \Closure}> committed panes, in load order */
    private array $panes = [];
    /** @var list<array{plugin: string, id: string, factory: \Closure}> committed full-screen views, in load order */
    private array $views = [];
    /** @var list<array{plugin: string, contribution: MenuContribution}> committed top-level menus, in load order */
    private array $menus = [];
    /** @var list<array{plugin: string, listener: \Closure(string, bool|int|string): void}> committed own-setting listeners */
    private array $settingHooks = [];
    /** @var list<array{plugin: string, id: string, label: string}> committed rebindable actions, in load order */
    private array $actions = [];
    /** @var list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> committed dock list tools, in load order */
    private array $listTools = [];
    /** @var list<array{plugin: string, segment: \Closure(): (string|StatusCell|null)}> committed status-bar segments, in load order */
    private array $statusSegments = [];
    /** @var list<array{plugin: string, languageId: string, resolve: \Closure(): (list<string>|null)}> committed debug adapters, in load order */
    private array $debugAdapters = [];
    /** @var list<ConfigWarning> */
    private array $warnings = [];

    /** @var list<string> enabled plugins whose composer packages are missing */
    private array $pendingInstalls = [];

    /** @var list<string> plugins held out of boot only because a PENDING one is what they require */
    private array $deferred = [];
    /** @var array<string, true> loaded plugin names */
    private array $names = [];
    /**
     * The WRAPPED listeners each plugin registered, so deactivate() can hand
     * the same instances back to removeListener().
     *
     * @var array<string, list<array{0: class-string, 1: \Closure(object): void}>>
     */
    private array $pluginListeners = [];
    /** Watermark into $commands: how many are already in the registry. */
    private int $registered = 0;
    /**
     * Plugins whose closure on a given channel (menu-enabled, status segment,
     * highlighter, viewer claim) already reported a throw. Those closures run
     * per render or per file open, so a broken plugin reports once per
     * channel, not once per frame.
     *
     * @var array<string, array<string, true>> channel => plugin => true
     */
    private array $reported = [];

    /** The {@see $reported} channels — consts so a typo is an analyzer error, not a silent second bucket. */
    private const string CHANNEL_HIGHLIGHTER = 'highlighter';
    private const string CHANNEL_SEGMENT = 'segment';
    private const string CHANNEL_VIEWER = 'viewer';
    private const string CHANNEL_WHEN = 'when';

    private ?PluginRuntime $runtime = null;

    /**
     * Third-party listeners observe after every priority-0 core listener.
     *
     * Not "after every core listener", which is already false:
     * {@see \Porta\Highlight\HighlightThemeListener} sits at -200 on purpose,
     * so a plugin that bakes theme colours rebuilds before the caches it feeds
     * are dropped.
     */
    public const int PLUGIN_PRIORITY = ListenerPriority::PLUGIN;

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly PluginSettings $settings,
    ) {}

    public function hasPlugin(string $name): bool
    {
        return array_key_exists($name, $this->names);
    }

    /** Whether $plugin has registered a language server (so an enable/disable/setting change reconfigures LSP). */
    public function providesLanguageServer(string $plugin): bool
    {
        foreach ($this->languageServers as $server) {
            if ($plugin === $server['plugin']) {
                return true;
            }
        }

        return false;
    }

    /** Land one successfully activated plugin's buffered registrations. */
    public function commit(PluginApi $api): void
    {
        $name = $api->pluginName();
        $this->names[$name] = true;
        foreach ($api->bufferedCommands() as $command) {
            $this->commands[] = $command;
            if ([] !== $command['keys']) {
                $this->keys[$command['id']] = $command['keys'];
            }
        }
        foreach ($api->bufferedMenuItems() as $item) {
            $this->menuSpecs[] = new PluginMenuSpec(
                plugin: $name,
                spec: 'i:'.$item['id'],
                when: null === $item['when'] ? null : $this->wrapWhen($name, $item['when']),
                surface: $item['surface'],
            );
        }
        foreach ($api->bufferedHooks() as [$eventClass, $listener]) {
            $wrapped = $this->wrap($name, $listener);
            // Kept because removeListener() matches by IDENTITY: the instance
            // handed to addListener() is the one deactivate() must hand back.
            $this->pluginListeners[$name][] = [$eventClass, $wrapped];
            $this->events->addListener($eventClass, $wrapped, self::PLUGIN_PRIORITY);
        }
        foreach ($api->bufferedLanguageServers() as $server) {
            $this->languageServers[] = $server;
        }
        foreach ($api->bufferedInstallers() as $installer) {
            $this->installers[] = $installer;
        }
        foreach ($api->bufferedHighlighters() as $highlighter) {
            $this->highlighters[] = $highlighter;
        }
        foreach ($api->bufferedTokenSpans() as $spans) {
            $this->tokenSpans[] = $spans;
        }
        foreach ($api->bufferedViewers() as $viewer) {
            $this->viewers[] = $viewer;
        }
        foreach ($api->bufferedPanes() as $pane) {
            $this->panes[] = $pane;
        }
        foreach ($api->bufferedViews() as $view) {
            $this->views[] = $view;
        }
        foreach ($api->bufferedMenus() as $menu) {
            $this->menus[] = $menu;
        }
        foreach ($api->bufferedSettingHooks() as $hook) {
            $this->settingHooks[] = $hook;
        }
        foreach ($api->bufferedActions() as $action) {
            $this->actions[] = $action;
        }
        foreach ($api->bufferedListTools() as $tool) {
            $this->listTools[] = $tool;
        }
        foreach ($api->bufferedStatusSegments() as $segment) {
            $this->statusSegments[] = $segment;
        }
        foreach ($api->bufferedDebugAdapters() as $adapter) {
            $this->debugAdapters[] = $adapter;
        }
        $this->settings->registerFor($name, $api->bufferedSettings());
    }

    /**
     * The install offer $plugin declared for its tool binary — the tool name,
     * whether that binary is available now, and the ordered [manager, package]
     * candidates — or null when the plugin declared none. The Settings page
     * offers to install while the binary is unavailable.
     *
     * @return array{tool: string, available: bool, via: list<array{0: string, 1: string}>}|null
     */
    public function installOffer(string $plugin): ?array
    {
        foreach ($this->installers as $installer) {
            if ($plugin === $installer['plugin']) {
                return ['tool' => $installer['tool'], 'available' => $installer['available'](), 'via' => $installer['via']];
            }
        }

        return null;
    }

    /**
     * The language servers provided by the loaded (enabled) plugins, resolved
     * to launch commands: `languageId => {command, name}`. Each resolver runs
     * now (reading the plugin's current `binary` setting), so this is the live
     * server map. A resolver returning null (binary missing) leaves the
     * language to the next available provider; the first available in load
     * order wins. A throwing resolver is reported and treated as unavailable.
     * The optional initializationOptions closure resolves here too — same
     * laziness, same failure handling — so a plugin's settings reach the
     * server relaunched by the next (re)configure.
     *
     * @return array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>
     */
    #[\Override]
    public function languageServers(): array
    {
        $servers = [];
        foreach ($this->languageServers as $server) {
            if (array_key_exists($server['languageId'], $servers)) {
                continue; // an earlier provider already serves this language
            }
            try {
                $command = $server['resolve']();
                $options = null === $server['initOptions'] ? [] : $server['initOptions']();
                // Validate encodability HERE, inside the per-plugin guard:
                // the session pool json-encodes options into its session
                // signature on the render path, where a NAN/INF/bad-UTF-8
                // value from a plugin would otherwise become a recurring
                // render fault instead of one reported plugin failure.
                json_encode($options, \JSON_THROW_ON_ERROR);
            } catch (\Throwable $error) {
                $this->reportFailure($server['plugin'], $error);

                continue;
            }
            if (null === $command || [] === $command) {
                continue; // the server's binary is not available
            }
            $entry = ['command' => array_values($command), 'name' => $server['name']];
            if ([] !== $options) {
                $entry['initializationOptions'] = $options;
            }
            $servers[$server['languageId']] = $entry;
        }

        return $servers;
    }

    /**
     * Undo one plugin's registrations live (Settings → Plugins removal): its
     * commands leave the registry (key dispatch and the palette derive from
     * it), its menu entries and hooks drop, and the name frees up so the same
     * plugin can be reinstalled without a restart. Loaded PHP code cannot be
     * unloaded, but nothing references it afterwards. The watermark reset is
     * exact because deactivation is only reachable post-boot, when every
     * committed command has already been registered.
     */
    public function deactivate(string $name, CommandRegistry $registry): void
    {
        if (!array_key_exists($name, $this->names)) {
            return;
        }
        unset($this->names[$name]);

        $removed = [];
        $this->commands = array_values(array_filter($this->commands, function (array $command) use ($name, &$removed): bool {
            if ($name !== $command['plugin']) {
                return true;
            }
            $removed[] = $command['id'];
            unset($this->keys[$command['id']]);

            return false;
        }));
        $this->menuSpecs = array_values(array_filter($this->menuSpecs, static fn (PluginMenuSpec $spec): bool => $name !== $spec->plugin));
        $this->languageServers = array_values(array_filter($this->languageServers, static fn (array $server): bool => $name !== $server['plugin']));
        $this->installers = array_values(array_filter($this->installers, static fn (array $installer): bool => $name !== $installer['plugin']));
        $this->highlighters = array_values(array_filter($this->highlighters, static fn (array $highlighter): bool => $name !== $highlighter['plugin']));
        $this->tokenSpans = array_values(array_filter($this->tokenSpans, static fn (array $spans): bool => $name !== $spans['plugin']));
        $this->viewers = array_values(array_filter($this->viewers, static fn (array $viewer): bool => $name !== $viewer['plugin']));
        $this->panes = array_values(array_filter($this->panes, static fn (array $pane): bool => $name !== $pane['plugin']));
        $this->views = array_values(array_filter($this->views, static fn (array $view): bool => $name !== $view['plugin']));
        $this->menus = array_values(array_filter($this->menus, static fn (array $menu): bool => $name !== $menu['plugin']));
        $this->settingHooks = array_values(array_filter($this->settingHooks, static fn (array $hook): bool => $name !== $hook['plugin']));
        $this->actions = array_values(array_filter($this->actions, static fn (array $action): bool => $name !== $action['plugin']));
        $this->listTools = array_values(array_filter($this->listTools, static fn (array $tool): bool => $name !== $tool['plugin']));
        $this->statusSegments = array_values(array_filter($this->statusSegments, static fn (array $segment): bool => $name !== $segment['plugin']));
        $this->debugAdapters = array_values(array_filter($this->debugAdapters, static fn (array $adapter): bool => $name !== $adapter['plugin']));
        $this->registered = \count($this->commands);

        foreach (array_keys($this->reported) as $channel) {
            unset($this->reported[$channel][$name]);
        }
        $registry->remove($removed);
        foreach ($this->pluginListeners[$name] ?? [] as [$eventClass, $wrapped]) {
            $this->events->removeListener($eventClass, $wrapped);
        }
        unset($this->pluginListeners[$name]);
        $this->settings->removeFor($name);
    }

    public function addWarning(ConfigWarning $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Record a plugin that is enabled but owes a composer install, so boot can
     * do something about it rather than only complain.
     */
    public function addPendingInstall(string $name): void
    {
        $this->pendingInstalls[] = $name;
    }

    /**
     * The enabled plugins boot could not load because their packages are not
     * installed, in discovery order.
     *
     * @return list<string>
     */
    public function pendingInstalls(): array
    {
        return $this->pendingInstalls;
    }

    /** Record a plugin whose only obstacle is a requirement that is being installed. */
    public function addDeferred(string $name): void
    {
        $this->deferred[] = $name;
    }

    /**
     * Plugins that were skipped at boot ONLY because something they require is
     * being installed — they load as soon as it does.
     *
     * @return list<string>
     */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /** @return list<ConfigWarning> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, list<string>> merged under the user keymap at Keybindings construction */
    public function defaultKeys(): array
    {
        return $this->keys;
    }

    /**
     * The plugin menu entries for ONE surface, in load order.
     *
     * Filtered here rather than at the consumer because "which menu is this
     * for" is the registration's own fact — a consumer that had to know about
     * the other surfaces in order to skip them would be the wrong shape the
     * moment a third one exists.
     *
     * @return list<PluginMenuSpec>
     */
    public function menuSpecs(MenuSurface $surface = MenuSurface::Plugins): array
    {
        return array_values(array_filter($this->menuSpecs, static fn (PluginMenuSpec $spec): bool => $surface === $spec->surface));
    }

    /**
     * Register every committed command not yet registered. Always keyBound —
     * the id may carry no default key yet be bound by the user's .porta.json
     * keymap; matches() is a cheap false for unbound ids. Incremental so a
     * live-installed plugin's commands can be added after boot without
     * duplicating earlier ones in the palette; assumes the single registry
     * built once by Ide::wireCommands().
     */
    public function registerCommands(CommandRegistry $commands): void
    {
        for ($count = \count($this->commands); $this->registered < $count; ++$this->registered) {
            $command = $this->commands[$this->registered];
            $commands->add(
                $command['id'],
                $this->wrapCommand($command['plugin'], $command['handler']),
                keyBound: KeyDispatch::Bound,
                label: $command['label'],
            );
        }
    }

    public function wireRuntime(PluginRuntime $runtime): void
    {
        $this->runtime = $runtime;
    }

    /**
     * Put a plugin's event on the bus.
     *
     * The counterpart to the listener registration above, and deliberately a
     * METHOD rather than a lent dispatcher: a plugin that held the dispatcher
     * could reach every internal event, which is what the single-parameter
     * listener wrapper exists to prevent. It hands over one event at a time,
     * and the type says the event is one a plugin may also observe.
     */
    public function dispatch(PluginObservable $event): void
    {
        $this->events->dispatch($event);
    }

    /** The live IDE seams; guarded so activate()-time action calls fail as plugin errors. */
    public function runtime(): PluginRuntime
    {
        return $this->runtime ?? throw new PluginException('IDE actions are unavailable during activate() — call them from a command handler or event hook');
    }

    /**
     * The plugin-supplied highlighting backends, in load order.
     *
     * Each arrives with its closures already wrapped in this plugin's fault
     * barrier, so a consumer cannot forget to guard them: a throwing claim list
     * declines the language, and a throwing highlight pass reports once and
     * re-throws for {@see \Porta\Highlight\CodeHighlighter} to turn into plain
     * text. Reporting is once-per-plugin because this runs on the typing path —
     * the same reasoning as {@see wrapWhen()}, which guards menu closures.
     *
     * @return list<SyntaxHighlightProvider>
     */
    #[\Override]
    public function highlightProviders(): array
    {
        $providers = [];
        foreach ($this->highlighters as $highlighter) {
            $plugin = $highlighter['plugin'];
            $languages = $highlighter['languages'];
            $highlight = $highlighter['highlight'];
            $providers[] = new CallableHighlightProvider(
                $highlighter['id'],
                function () use ($plugin, $languages): array {
                    try {
                        return $languages();
                    } catch (\Throwable $error) {
                        $this->reportOnce(self::CHANNEL_HIGHLIGHTER, $plugin, $error);

                        return [];
                    }
                },
                function (string $content, string $language, \Closure $deliver) use ($plugin, $highlight): void {
                    try {
                        $highlight($content, $language, $deliver);
                    } catch (\Throwable $error) {
                        $this->reportOnce(self::CHANNEL_HIGHLIGHTER, $plugin, $error);

                        throw $error;
                    }
                },
                $highlighter['available'],
            );
        }

        return $providers;
    }

    /**
     * The first plugin classifier (in load order) that claims $language and
     * returns spans. A throwing claim list or classifier declines: search's
     * scope filter degrades to a no-op rather than dropping results.
     *
     * @return list<array{start: int, end: int}>|null
     */
    #[\Override]
    public function commentStringSpans(string $content, string $language): ?array
    {
        foreach ($this->tokenSpans as $spans) {
            try {
                $claimed = $spans['languages']();
                if (!\in_array('*', $claimed, strict: true) && !\in_array($language, $claimed, strict: true)) {
                    continue;
                }
                $result = $spans['classify']($content, $language);
            } catch (\Throwable $error) {
                $this->reportOnce(self::CHANNEL_HIGHLIGHTER, $spans['plugin'], $error);

                continue;
            }
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Every committed pane registration, in load order — drained once by
     * {@see PluginPanes} into the core registry that actually holds them.
     *
     * @return list<array{plugin: string, id: string, pane: \Closure, load: \Closure}>
     */
    #[\Override]
    public function panes(): array
    {
        return $this->panes;
    }

    /**
     * The plugin-provided debug adapters, resolved now — the
     * {@see languageServers()} shape, including its first-provider-wins rule
     * and its "a null resolve means not installed" convention.
     *
     * @return array<string, list<string>>
     */
    #[\Override]
    public function debugAdapters(): array
    {
        $adapters = [];
        foreach ($this->debugAdapters as $adapter) {
            if (array_key_exists($adapter['languageId'], $adapters)) {
                continue; // an earlier plugin already claims this language
            }
            try {
                $command = $adapter['resolve']();
            } catch (\Throwable $error) {
                $this->reportFailure($adapter['plugin'], $error);

                continue;
            }
            if (null === $command || [] === $command) {
                continue; // the adapter's binary is not available
            }
            $adapters[$adapter['languageId']] = array_values($command);
        }

        return $adapters;
    }

    /**
     * The plugins' status-bar segments, evaluated now — this is pulled once per
     * render, so a throwing closure reads as nothing and reports at most once
     * per plugin (the {@see wrapWhen()} reasoning, for the same reason).
     *
     * @return list<string|StatusCell>
     */
    #[\Override]
    public function statusSegments(): array
    {
        $segments = [];
        foreach ($this->statusSegments as $entry) {
            try {
                /** @var mixed $text */
                $text = $entry['segment']();
            } catch (\Throwable $error) {
                $this->reportOnce(self::CHANNEL_SEGMENT, $entry['plugin'], $error);

                continue;
            }
            if ($text instanceof StatusCell) {
                if ('' !== $text->text) {
                    $segments[] = $text;
                }

                continue;
            }
            if (\is_string($text) && '' !== $text) {
                $segments[] = $text;
            }
        }

        return $segments;
    }

    /**
     * The dock list tools plugins declared, in load order — each closure
     * wrapped in its own plugin's fault barrier, so a throwing Enter or F5
     * flashes instead of killing the loop.
     *
     * @return list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}>
     */
    #[\Override]
    public function listTools(): array
    {
        return array_map(fn (array $tool): array => [
            ...$tool,
            'rerun' => null === $tool['rerun'] ? null : $this->wrapCommand($tool['plugin'], $tool['rerun']),
            'activate' => null === $tool['activate'] ? null : $this->wrapActivate($tool['plugin'], $tool['activate']),
        ], $this->listTools);
    }

    /**
     * The rebindable actions plugins declared, in load order — what
     * {@see \Porta\Config\ActionRegistry} composes onto core's catalog so the
     * keymap page lists them and `.porta.json` may rebind them.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function rebindableActions(): array
    {
        $labels = [];
        foreach ($this->actions as $action) {
            $labels[$action['id']] = $action['label'];
        }

        return $labels;
    }

    /**
     * The first committed viewer route (in load order) whose claim matches
     * $path, or null. A throwing claim declines and reports once per plugin —
     * this runs on every file open.
     */
    #[\Override]
    public function viewerFor(string $path): ?string
    {
        foreach ($this->viewers as $viewer) {
            try {
                if ($viewer['claims']($path)) {
                    return $viewer['id'];
                }
            } catch (\Throwable $error) {
                $this->reportOnce(self::CHANNEL_VIEWER, $viewer['plugin'], $error);
            }
        }

        return null;
    }

    /** Report a plugin's failure at most once per $channel — see {@see $reported}. */
    private function reportOnce(string $channel, string $plugin, \Throwable $error): void
    {
        if (array_key_exists($plugin, $this->reported[$channel] ?? [])) {
            return;
        }
        $this->reported[$channel][$plugin] = true;
        $this->reportFailure($plugin, $error);
    }

    /** A pane that could not be built — {@see EditorPaneSource}'s half of the fault barrier. */
    #[\Override]
    public function reportPaneFailure(string $plugin, \Throwable $error): void
    {
        $this->reportFailure($plugin, $error);
    }

    #[\Override]
    public function reportViewFailure(string $plugin, \Throwable $error): void
    {
        $this->reportFailure($plugin, $error);
    }

    /**
     * Tell $plugin one of ITS settings changed, inside its own fault barrier.
     *
     * Per plugin, not broadcast: a plugin has no business seeing another's
     * configuration, and a listener that had to filter would be a listener that
     * could forget to.
     */
    public function notifySettingChanged(string $plugin, string $key, bool|int|string $value): void
    {
        foreach ($this->settingHooks as $hook) {
            if ($plugin !== $hook['plugin']) {
                continue;
            }
            try {
                $hook['listener']($key, $value);
            } catch (\Throwable $error) {
                $this->reportFailure($plugin, $error);
            }
        }
    }

    /** @return list<MenuContribution> */
    #[\Override]
    public function contributedMenus(): array
    {
        return array_map(static fn (array $entry): MenuContribution => $entry['contribution'], $this->menus);
    }

    /** @return list<array{plugin: string, id: string, factory: \Closure}> */
    #[\Override]
    public function contributedViews(): array
    {
        return $this->views;
    }

    /** Report a plugin fault: flash when the IDE is up, else a boot warning. */
    public function reportFailure(string $plugin, \Throwable $error): void
    {
        $runtime = $this->runtime;
        if (null === $runtime) {
            $this->addWarning(new ConfigWarning('Plugin "%name%" failed: %message%', ['%name%' => $plugin, '%message%' => $error->getMessage()]));

            return;
        }
        $runtime->notifier->error($runtime->translator->trans('Plugin "%name%" failed: %message%', ['%name%' => $plugin, '%message%' => $error->getMessage()]));
    }

    /**
     * @param \Closure(object): void $listener
     *
     * @return \Closure(object): void
     */
    private function wrap(string $plugin, \Closure $listener): \Closure
    {
        // ONE parameter, and that is load-bearing beyond types: the dispatcher
        // calls listeners as ($event, $eventName, $dispatcher), so a wrapper
        // that declared (...$args) would hand every plugin the bus itself.
        // Plugins listen; they never dispatch.
        return function (object $event) use ($plugin, $listener): void {
            try {
                $listener($event);
            } catch (\Throwable $error) {
                $this->reportFailure($plugin, $error);
            }
        };
    }

    /**
     * Guard a plugin's menu-enabled closure: menus re-render constantly, so a
     * throwing closure reads as disabled and reports the fault exactly once.
     *
     * @param \Closure(): bool $when
     *
     * @return \Closure(): bool
     */
    private function wrapWhen(string $plugin, \Closure $when): \Closure
    {
        return function () use ($plugin, $when): bool {
            try {
                return $when();
            } catch (\Throwable $error) {
                $this->reportOnce(self::CHANNEL_WHEN, $plugin, $error);

                return false;
            }
        };
    }

    /**
     * Guard a list tool's row activation — the one plugin closure that takes
     * arguments, so it cannot ride {@see wrapCommand()}.
     *
     * @param \Closure(string, int): void $activate
     *
     * @return \Closure(string, int): void
     */
    private function wrapActivate(string $plugin, \Closure $activate): \Closure
    {
        return function (string $path, int $line) use ($plugin, $activate): void {
            try {
                $activate($path, $line);
            } catch (\Throwable $error) {
                $this->reportFailure($plugin, $error);
            }
        };
    }

    /**
     * @param \Closure(): void $handler
     *
     * @return \Closure(): void
     */
    private function wrapCommand(string $plugin, \Closure $handler): \Closure
    {
        return function () use ($plugin, $handler): void {
            try {
                $handler();
            } catch (\Throwable $error) {
                $this->reportFailure($plugin, $error);
            }
        };
    }
}
