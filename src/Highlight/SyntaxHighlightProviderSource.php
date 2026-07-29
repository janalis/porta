<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * The plugin-supplied highlighting backends.
 *
 * Implemented by `Porta\Plugin\Runtime\PluginManager` and consumed by
 * {@see HighlightRegistry}. The interface lives here, in the consuming
 * namespace, so the highlighting subsystem never depends on `Porta\Plugin` —
 * the same arrangement as {@see \Porta\Lsp\LanguageServerProvider}.
 *
 * Providers arrive already wrapped in their plugin's fault barrier, so a
 * consumer cannot forget to guard them.
 */
interface SyntaxHighlightProviderSource
{
    /** @return list<SyntaxHighlightProvider> in plugin load order */
    public function highlightProviders(): array;
}
