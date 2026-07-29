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
    /** @return array<string, array{command: list<string>, name: string}> keyed by LSP languageId */
    public function languageServers(): array;
}
