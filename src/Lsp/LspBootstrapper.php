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
 * DiagnosticsSink::applyDiagnostics, server-initiated workspace edits go
 * through WorkspaceEditor with a status-bar flash, `window/showMessage`
 * reaches the user through the Notifier, and `$/progress` becomes the status
 * bar's activity cell. Sessions still start lazily — each on its language's
 * first didOpen.
 */
#[AsIdeService]
final readonly class LspBootstrapper
{
    public function __construct(
        private LspSessionPool $lsp,
        private LspLog $log,
        private WorkspaceEditor $workspaceEditor,
        private DiagnosticsSink $bufferService,
        private Notifier $notifier,
        private Translator $translator,
        private LanguageServerProvider $pluginServers,
        private ServerMessageReporter $messages,
        private ServerProgress $progress,
        private string $rootDir,
        private ?string $lspCommand,
        private bool $lspDisabled,
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
                $outcome = $this->workspaceEditor->applyWorkspaceEdit($edit);
                if ($outcome->isRefused()) {
                    $this->notifier->flash(
                        $this->translator->trans('Refused: %reason% — nothing was changed', ['%reason%' => (string) $outcome->refused]),
                    );

                    return false;
                }
                if ([] !== $outcome->skipped) {
                    $this->notifier->flash($this->translator->trans(
                        'Applied to %count% file, %skipped% could not be written|Applied to %count% files, %skipped% could not be written',
                        ['%count%' => $outcome->touched, '%skipped%' => \count($outcome->skipped)],
                    ));

                    return $outcome->touched > 0;
                }
                if ($outcome->touched > 0) {
                    $this->notifier->flash(
                        $this->translator->trans('Applied server edit to %count% file|Applied server edit to %count% files', ['%count%' => $outcome->touched]),
                    );
                }

                return $outcome->touched > 0;
            },
            function (array $params): void {
                /** @var array<string, mixed> $params */
                $this->messages->report($params);
            },
            function (array $params): void {
                /** @var array<string, mixed> $params */
                $this->progress->handle($params);
            },
        );
    }
}
