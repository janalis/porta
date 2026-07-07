<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Lsp\LspSessionPool;
use Porta\Lsp\ServerLocator;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The per-language LSP-server model behind the settings view's Languages
 * section: exposes each language's server-command row, persists a change into
 * .porta.json's `lsp` map, and applies it live — re-resolving the servers,
 * reconfiguring the session pool, and re-opening buffers so the new server
 * starts without a restart.
 *
 * @phpstan-type FormRow array{key: string, label: string, kind: string, value: bool|int|string, restart: bool, options?: list<string>, min?: int, max?: int, hint?: string}
 */
final class SettingsLspController
{
    /**
     * @param array<string, string> $overrides languageId => command from {@see IdeConfig::$lspServers}
     * @param \Closure(): void       $reopenBuffers re-send didOpen for every open buffer
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly LspSessionPool $lsp,
        private array $overrides,
        private readonly ?string $lspOverride,
        private readonly \Closure $reopenBuffers,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
    ) {}

    /**
     * The LSP-server row for a language: a text field holding the configured
     * command, hinting the auto-detected server when empty.
     *
     * @return list<FormRow>
     */
    public function rows(string $language): array
    {
        $auto = ServerLocator::autoDetected($this->rootDir)[$language]['name'] ?? null;

        return [[
            'key' => 'lspServer',
            'label' => 'LSP server',
            'kind' => 'text',
            'value' => $this->overrides[$language] ?? '',
            'restart' => false,
            'hint' => null !== $auto ? 'auto: '.$auto : 'auto-detect',
        ]];
    }

    /**
     * Set (or clear) a language's LSP command: persist it, re-resolve the
     * servers, reconfigure the pool, and re-open buffers so the change applies
     * live. A `--lsp` override on the command line wins over config, so an edit
     * is refused with a flash while it is in force.
     */
    public function changeLspServer(string $language, string $command): void
    {
        if (null !== $this->lspOverride && '' !== $this->lspOverride) {
            $this->flash('LSP is set via --lsp; the settings change was not applied');

            return;
        }

        $command = trim($command);
        $persisted = IdeConfig::persistLspServer($this->rootDir, $language, $command);
        if ('' === $command) {
            unset($this->overrides[$language]);
        } else {
            $this->overrides[$language] = $command;
        }

        $this->lsp->reconfigure(ServerLocator::locateAll($this->rootDir, $this->lspOverride));
        ($this->reopenBuffers)();

        $label = LanguageCatalog::displayName($language);
        $this->flash(\sprintf('%s LSP: %s%s', $label, '' === $command ? 'auto-detect' : $command, $persisted ? '' : ' (not saved)'));
    }

    private function flash(string $message): void
    {
        $this->statusBar->flash($message);
        $this->tui->requestRender();
    }
}
