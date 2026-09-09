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

    /**
     * Re-resolve every server and relaunch, even when the map is unchanged.
     *
     * For the install-a-binary path: a first install changes the map (the
     * plugin resolved no command before), an upgrade does not (same path, same
     * argv) — and only the forced drop makes the second one take effect.
     */
    public function relaunch(): void
    {
        $this->lsp->reload(ServerLocator::locateAll($this->rootDir, $this->lspCommand, $this->pluginServers->languageServers()));
        $this->reopener->reopenAllInLsp();
    }

    /** Re-resolve every server (config + plugins), swap the pool, and re-open buffers. */
    public function reconfigure(): void
    {
        // The pool skips an identical map, and then the buffers must not be
        // re-opened either: the provider-setting hook fires for EVERY setting
        // of a provider plugin, so without the diff a palette toggle of
        // phpantom's fix-on-save would tear down and relaunch every server.
        if ($this->lsp->reconfigure(ServerLocator::locateAll($this->rootDir, $this->lspCommand, $this->pluginServers->languageServers()))) {
            $this->reopener->reopenAllInLsp();
        }
    }
}
