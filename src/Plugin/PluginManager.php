<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Config\CommandRegistry;

/**
 * The committed plugin state: commands, default keys, menu entries, hook
 * listeners and boot warnings. Every plugin handler and hook listener is
 * wrapped in a catch-all so a faulting plugin flashes a message instead of
 * killing the event loop (core commands stay unguarded — a core throw is a
 * bug that must fail loudly). The runtime is wired late in Ide's construction;
 * an action method used before that (i.e. during activate()) hits the
 * {@see runtime()} gate.
 */
final class PluginManager
{
    /** @var list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    private array $commands = [];
    /** @var array<string, list<string>> namespaced command id => default key specs */
    private array $keys = [];
    /** @var list<array{plugin: string, spec: string, when: (\Closure(): bool)|null}> Plugins-menu entries */
    private array $menuSpecs = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, true> loaded plugin names */
    private array $names = [];
    /** Watermark into $commands: how many are already in the registry. */
    private int $registered = 0;
    /** @var array<string, true> plugins whose menu-enabled closure already reported a throw */
    private array $reportedWhens = [];

    private ?PluginRuntime $runtime = null;

    public function __construct(
        private readonly PluginEvents $events,
        private readonly PluginSettings $settings,
    ) {}

    public function hasPlugin(string $name): bool
    {
        return isset($this->names[$name]);
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
            $this->menuSpecs[] = [
                'plugin' => $name,
                'spec' => 'i:'.$item['id'],
                'when' => null === $item['when'] ? null : $this->wrapWhen($name, $item['when']),
            ];
        }
        foreach ($api->bufferedHooks() as [$event, $listener]) {
            $this->events->subscribe($name, $event, $this->wrap($name, $listener));
        }
        $this->settings->registerFor($name, $api->bufferedSettings());
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
        if (!isset($this->names[$name])) {
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
        $this->menuSpecs = array_values(array_filter($this->menuSpecs, static fn (array $spec): bool => $name !== $spec['plugin']));
        $this->registered = \count($this->commands);

        unset($this->reportedWhens[$name]);
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

    /** @return list<array{plugin: string, spec: string, when: (\Closure(): bool)|null}> entries for MenuModel's Plugins menu */
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
                keyBound: true,
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
                if (!isset($this->reportedWhens[$plugin])) {
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
