<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\DiagnosticsSink;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;

/**
 * Declares the configured/detected language servers on the session pool at
 * boot and owns the shared inbound hooks: published diagnostics ride
 * DiagnosticsSink::applyDiagnostics, and server-initiated workspace edits go
 * through WorkspaceEditor with a status-bar flash. Sessions still start
 * lazily — each on its language's first didOpen.
 */
#[AsIdeService]
final class LspBootstrapper
{
    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly LspLog $log,
        private readonly WorkspaceEditor $workspaceEditor,
        private readonly DiagnosticsSink $bufferService,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly LanguageServerProvider $pluginServers,
        private readonly string $rootDir,
        private readonly ?string $lspCommand,
        private readonly bool $lspDisabled,
    ) {}

    public function start(): void
    {
        if ($this->lspDisabled) {
            $this->lsp->disable();

            return;
        }
        $this->lsp->configure(
            ServerLocator::locateAll($this->rootDir, $this->lspCommand, $this->pluginServers->languageServers()),
            fn (string $line) => $this->log->append($line),
            function (array $params): void {
                /** @var array{uri?: string, diagnostics?: list<array{severity?: int, message?: string, range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}}>} $params */
                $this->bufferService->applyDiagnostics($params['uri'] ?? '', LspMapper::diagnosticsByLine($params));
            },
            function (array $edit): bool {
                /** @var array<string, mixed> $edit */
                $count = $this->workspaceEditor->applyWorkspaceEdit($edit);
                if ($count > 0) {
                    $this->notifier->flash(
                        $this->translator->trans('Applied server edit to %count% file|Applied server edit to %count% files', ['%count%' => $count]),
                    );
                }

                return $count > 0;
            },
        );
    }
}
