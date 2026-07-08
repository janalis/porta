<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\ServerLocator;
use Porta\Ui\Notifier;

/**
 * The per-language LSP-server model behind the settings view's Languages
 * section: exposes each language's server-command row, persists a change into
 * .porta.json's `lsp` map, and applies it live — re-resolving the servers,
 * reconfiguring the session pool, and re-opening buffers so the new server
 * starts without a restart.
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
        private readonly Notifier $notifier,
        private readonly Translator $translator,
    ) {}

    /**
     * The LSP-server row for a language: a text field holding the configured
     * command, hinting the auto-detected server when empty.
     *
     * @return list<SettingsRow>
     */
    public function rows(string $language): array
    {
        $auto = ServerLocator::autoDetected($this->rootDir)[$language]['name'] ?? null;

        return [new SettingsRow(
            key: 'lspServer',
            label: $this->translator->trans('LSP server'),
            kind: 'text',
            value: $this->overrides[$language] ?? '',
            restart: false,
            hint: null !== $auto ? $this->translator->trans('auto: %server%', ['%server%' => $auto]) : $this->translator->trans('auto-detect'),
        )];
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
            $this->flash($this->translator->trans('LSP is set via --lsp; the settings change was not applied'));

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

        $shown = '' === $command ? $this->translator->trans('auto-detect') : $command;
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->flash(\sprintf('%s LSP: %s%s', LanguageCatalog::displayName($language), $shown, $saved));
    }

    private function flash(string $message): void
    {
        $this->notifier->flash($message);
    }
}
