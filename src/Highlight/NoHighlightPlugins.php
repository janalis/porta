<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * The empty provider source, for a highlighter built outside the container —
 * tests, and the {@see CodeHighlighterFactory} default. In a running IDE the
 * real source is `Porta\Plugin\Runtime\PluginManager`, aliased in `config/services.php`.
 */
final class NoHighlightPlugins implements SyntaxHighlightProviderSource
{
    #[\Override]
    public function highlightProviders(): array
    {
        return [];
    }
}
