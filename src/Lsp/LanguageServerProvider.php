<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Supplies the language servers contributed by LSP-provider plugins, resolved
 * to launch commands. The LSP subsystem consumes this rather than depending on
 * the plugin subsystem directly; {@see \Porta\Plugin\Runtime\PluginManager} implements
 * it (its resolvers read each plugin's current `binary` setting).
 */
interface LanguageServerProvider
{
    /**
     * Keyed by LSP languageId, FIRST entry first: the language's own server,
     * then any supplementary ones that run beside it.
     *
     * @return array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>>
     */
    public function languageServers(): array;
}
