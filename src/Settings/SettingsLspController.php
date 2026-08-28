<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Config\LanguageCatalog;
use Porta\Config\PortaJsonWriter;
use Porta\Event\SettingsRowsStale;
use Porta\I18n\Translator;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\ServerLocator;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

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
     * The auto-detected servers, resolved at most once per generation.
     *
     * {@see ServerLocator::autoDetected()} walks every PATH entry for each
     * server it knows, and the ones it looks for are usually absent — three
     * full misses at ~3ms each. That is paid per {@see rows()} call, and the
     * settings nav rebuilds the page on EVERY arrow press, so walking the
     * thirteen language pages cost ~110ms of pure PATH scanning.
     *
     * @var array<string, array{command: list<string>, name: string}>|null
     */
    private ?array $detected = null;

    /**
     * @param array<string, string> $overrides languageId => command from {@see IdeConfig::$lspServers}
     */
    public function __construct(
        private readonly string $rootDir,
        #[Autowire('%env(json:PORTA_LSP_SERVERS)%')]
        private array $overrides,
        private readonly LspReconfigurer $reconfigurer,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly PortaJsonWriter $writer,
    ) {}

    /**
     * The LSP-server row for a language: a text field holding the configured
     * command, hinting the auto-detected server when empty.
     *
     * @return list<SettingsRow>
     */
    public function rows(string $language): array
    {
        $this->detected ??= ServerLocator::autoDetected($this->rootDir);
        $auto = $this->detected[$language]['name'] ?? null;

        return [new SettingsRow(
            key: 'lspServer',
            label: $this->translator->trans('LSP server'),
            kind: SettingsRowKind::Text,
            value: $this->overrides[$language] ?? '',
            restart: false,
            default: '', // unset means auto-detect
            hint: null !== $auto ? $this->translator->trans('auto: %server%', ['%server%' => $auto]) : $this->translator->trans('auto-detect'),
        )];
    }

    /**
     * Set (or clear) a language's LSP command: persist it, re-resolve the
     * servers, reconfigure the pool, and re-open buffers so the change applies
     * live. A `--lsp` override on the command line wins over config, so an edit
     * is refused with a flash while it is in force.
     */
    public function changeLspServer(string $language, string $command): bool
    {
        if ($this->reconfigurer->isOverridden()) {
            // Refused, so the form keeps the editor open: the command line
            // wins while --lsp is in force, and losing what was typed as well
            // would make an already-surprising refusal worse.
            $this->flash($this->translator->trans('LSP is set via --lsp; the settings change was not applied'));

            return false;
        }

        $command = trim($command);
        $persisted = $this->writer->persistLspServer(ConfigScope::Project, $language, $command);
        if ('' === $command) {
            unset($this->overrides[$language]);
        }
        if ('' !== $command) {
            $this->overrides[$language] = $command;
        }

        $this->reconfigurer->reconfigure();

        $shown = '' === $command ? $this->translator->trans('auto-detect') : $command;
        $saved = SettingsFlash::savedSuffix($this->translator, $persisted);
        $this->flash($this->translator->trans('%language% LSP: %shown%%saved%', [
            '%language%' => LanguageCatalog::displayName($language),
            '%shown%' => $shown,
            '%saved%' => $saved,
        ]));

        return true;
    }

    /**
     * A tool that was not on PATH when the page was first drawn may be there
     * now — installing one is exactly what raises this event — so the probe is
     * dropped rather than kept for the session.
     */
    #[AsEventListener]
    public function onSettingsRowsStale(SettingsRowsStale $event): void
    {
        $this->detected = null;
    }

    private function flash(string $message): void
    {
        $this->notifier->flash($message);
    }
}
