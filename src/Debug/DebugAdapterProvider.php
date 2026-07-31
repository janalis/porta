<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * The debug adapters contributed by plugins, resolved to launch commands — the
 * {@see \Porta\Lsp\LanguageServerProvider} arrangement, for the same reason
 * and with the same shape: the debugger consumes this rather than depending on
 * the plugin subsystem, and {@see \Porta\Plugin\Runtime\PluginManager}
 * implements it (its resolvers read each plugin's current `binary` setting, so
 * a plugin that installs its adapter mid-session starts answering).
 *
 * Consulted BELOW `.porta.json`: a language the user configured by hand keeps
 * the adapter they named. A plugin fills the gap, it does not take the wheel.
 */
interface DebugAdapterProvider
{
    /** @return array<string, list<string>> languageId => adapter argv */
    public function debugAdapters(): array;
}
