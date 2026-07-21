<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Re-resolves the language servers (user config + plugin-provided) and applies
 * the change live: swaps the session pool's server map and re-opens every
 * buffer so the new server launches without a restart. The single reconfigure
 * path shared by the Languages settings row and plugin enable/disable/setting
 * changes, so plugin servers are never dropped by a reconfigure that forgot
 * them. A `--lsp` CLI override wins over config, so live changes are refused
 * while it is in force ({@see isOverridden()}).
 */
final readonly class LspReconfigurer
{
    public function __construct(
        private LspSessionPool $lsp,
        private BufferReopener $reopener,
        private LanguageServerProvider $pluginServers,
        private string $rootDir,
        private ?string $lspCommand,
    ) {}

    /** Whether a `--lsp` override is in force, so a config/plugin change cannot apply. */
    public function isOverridden(): bool
    {
        return null !== $this->lspCommand && '' !== $this->lspCommand;
    }

    /** Re-resolve every server (config + plugins), swap the pool, and re-open buffers. */
    public function reconfigure(): void
    {
        $this->lsp->reconfigure(ServerLocator::locateAll($this->rootDir, $this->lspCommand, $this->pluginServers->languageServers()));
        $this->reopener->reopenAllInLsp();
    }
}
