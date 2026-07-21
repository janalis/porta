<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Highlight\CallableHighlightProvider;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\Lsp\LanguageServerProvider;
use Porta\Search\TokenSpanProvider;

/**
 * The committed plugin state: commands, default keys, menu entries, hook
 * listeners and boot warnings. Every plugin handler and hook listener is
 * wrapped in a catch-all so a faulting plugin flashes a message instead of
 * killing the event loop (core commands stay unguarded — a core throw is a
 * bug that must fail loudly). The runtime is wired late in Ide's construction;
 * an action method used before that (i.e. during activate()) hits the
 * {@see runtime()} gate.
 */
final class PluginManager implements LanguageServerProvider, SyntaxHighlightProviderSource, TokenSpanProvider
{
    /** @var list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    private array $commands = [];
    /** @var array<string, list<string>> namespaced command id => default key specs */
    private array $keys = [];
    /** @var list<PluginMenuSpec> Plugins-menu entries */
    private array $menuSpecs = [];
    /** @var list<array{plugin: string, languageId: string, name: string, resolve: \Closure(): (list<string>|null)}> committed LSP providers, in load order */
    private array $languageServers = [];
    /** @var list<array{plugin: string, tool: string, available: \Closure(): bool, via: list<array{0: string, 1: string}>}> committed install offers */
    private array $installers = [];
    /** @var list<array{plugin: string, id: string, label: string, languages: \Closure(): list<string>, highlight: \Closure(string, string, \Closure(string): void): void, available: (\Closure(): bool)|null}> committed highlighting backends, in load order */
    private array $highlighters = [];
    /** @var list<array{plugin: string, languages: \Closure(): list<string>, classify: \Closure(string, string): (list<array{start: int, end: int}>|null)}> committed search classifiers, in load order */
    private array $tokenSpans = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, true> loaded plugin names */
    private array $names = [];
    /** Watermark into $commands: how many are already in the registry. */
    private int $registered = 0;
    /** @var array<string, true> plugins whose menu-enabled closure already reported a throw */
    private array $reportedWhens = [];
    /** @var array<string, true> plugins whose highlighting backend already reported a throw */
    private array $reportedHighlighters = [];

    private ?PluginRuntime $runtime = null;

    public function __construct(
        private readonly PluginEvents $events,
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

    /** @return list<string> the active plugin names, in load order */
    public function names(): array
    {
        return array_keys($this->names);
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
            );
        }
        foreach ($api->bufferedHooks() as [$event, $listener]) {
            $this->events->subscribe($name, $event, $this->wrap($name, $listener));
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
     *
     * @return array<string, array{command: list<string>, name: string}>
     */
    public function languageServers(): array
    {
        $servers = [];
        foreach ($this->languageServers as $server) {
            if (array_key_exists($server['languageId'], $servers)) {
                continue; // an earlier provider already serves this language
            }
            try {
                $command = $server['resolve']();
            } catch (\Throwable $error) {
                $this->reportFailure($server['plugin'], $error);

                continue;
            }
            if (null === $command || [] === $command) {
                continue; // the server's binary is not available
            }
            $servers[$server['languageId']] = ['command' => array_values($command), 'name' => $server['name']];
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
        $this->registered = \count($this->commands);

        unset($this->reportedWhens[$name], $this->reportedHighlighters[$name]);
        $registry->remove($removed);
        $this->events->removeFor($name);
        $this->settings->removeFor($name);
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, list<string>> merged under the user keymap at Keybindings construction */
    public function defaultKeys(): array
    {
        return $this->keys;
    }

    /** @return list<PluginMenuSpec> entries for MenuModel's Plugins menu */
    public function menuSpecs(): array
    {
        return $this->menuSpecs;
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
                        $this->reportHighlightFailure($plugin, $error);

                        return [];
                    }
                },
                function (string $content, string $language, \Closure $deliver) use ($plugin, $highlight): void {
                    try {
                        $highlight($content, $language, $deliver);
                    } catch (\Throwable $error) {
                        $this->reportHighlightFailure($plugin, $error);

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
                $this->reportHighlightFailure($spans['plugin'], $error);

                continue;
            }
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /** Report a highlighting fault at most once per plugin — this runs while typing. */
    private function reportHighlightFailure(string $plugin, \Throwable $error): void
    {
        if (array_key_exists($plugin, $this->reportedHighlighters)) {
            return;
        }
        $this->reportedHighlighters[$plugin] = true;
        $this->reportFailure($plugin, $error);
    }

    /** Report a plugin fault: flash when the IDE is up, else a boot warning. */
    public function reportFailure(string $plugin, \Throwable $error): void
    {
        $runtime = $this->runtime;
        if (null === $runtime) {
            $this->addWarning(\sprintf('Plugin "%s" failed: %s', $plugin, $error->getMessage()));

            return;
        }
        $runtime->notifier->flash($runtime->translator->trans('Plugin "%name%" failed: %message%', ['%name%' => $plugin, '%message%' => $error->getMessage()]), 5.0);
        $runtime->tui->requestRender();
    }

    /**
     * @param \Closure(string): void $listener
     *
     * @return \Closure(string): void
     */
    private function wrap(string $plugin, \Closure $listener): \Closure
    {
        return function (string $path) use ($plugin, $listener): void {
            try {
                $listener($path);
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
                if (!array_key_exists($plugin, $this->reportedWhens)) {
                    $this->reportedWhens[$plugin] = true;
                    $this->reportFailure($plugin, $error);
                }

                return false;
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
