<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * Opt-in teardown for a plugin that owns resources the registries cannot
 * remove for it: event-loop timers, child processes, open handles.
 *
 * {@see PluginManager::deactivate()} strips everything a plugin REGISTERED —
 * commands, listeners, hooks, claims — but what the plugin's own code started
 * keeps running. A live disable removes even the plugin's `onShutdown()`
 * listener, so without this hook a status poll or debounce timer survives its
 * plugin in a still-running IDE, orphaned.
 *
 * Called on live disable and removal, inside the plugin's fault barrier (a
 * throwing teardown is reported and the deactivation still completes), and
 * once more as the IDE closes, after {@see PluginApi::onShutdown()}'s
 * listeners: opening another project runs in the SAME process on the same
 * event loop, so a timer left armed kept firing into the next project's
 * session against this one's objects. Make it safe to call twice.
 *
 * Deliberately separate from {@see PortaPlugin} — adding a method there would
 * break every existing plugin for a hook most never need — so implementors
 * write `extends AbstractPlugin implements DisposablePlugin`.
 */
interface DisposablePlugin
{
    /** Stop everything this plugin's own code started: timers, subprocesses, handles. */
    public function deactivate(): void;
}
