<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\History\LocalHistoryController;
use Porta\Lsp\NavigationController;
use Porta\Support\Scheduler;
use Porta\Ui\BottomDock;
use Porta\Ui\CommandPalette;
use Porta\Ui\EditorArea;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * Wires the search-facing chrome to its controllers: the find bar's match
 * mirroring, the go-to-file palette, the shared bottom results panel (palette /
 * symbol / find-in-project / history modes) and the Find-in-Files modal. Owns
 * the one shared query debounce — the panel's query row and the modal's
 * query/mask fields cancel each other's pending searches.
 */
final class SearchUiWiring
{
    private ?string $searchDebounce = null;

    public function __construct(
        private readonly FindBarWidget $findBar,
        private readonly GoToFileWidget $goToFile,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly CodeEditorWidget $editor,
        private readonly EditorArea $editorArea,
        private readonly Tui $tui,
        private readonly BottomDock $panels,
        private readonly CommandPalette $commandPalette,
        private readonly NavigationController $navigation,
        private readonly ProjectSearchController $projectSearch,
        private readonly LocalHistoryController $localHistory,
        private readonly TodoScanner $todoScanner,
        private readonly ResultContextProvider $resultContext,
        private readonly BufferService $bufferService,
        private readonly Scheduler $scheduler,
        private readonly string $rootDir,
    ) {}

    public function wire(): void
    {
        $this->wireFindAndGoto();
        $this->wireResultsPanel();
        $this->wireFindModal();
    }

    /** Find bar and go-to-file palette: search-match mirroring and focus flow. */
    private function wireFindAndGoto(): void
    {
        $this->findBar->onUpdate(function (): void {
            $this->editor->setSearchMatches($this->findBar->getMatchesByLine());
            $this->tui->requestRender();
        });

        $this->findBar->onClose(function (): void {
            $this->editor->setSearchMatches([]);
            $this->tui->setFocus($this->editorArea->widget());
            $this->tui->requestRender();
        });

        $this->goToFile->onPick(function (string $path): void {
            $this->bufferService->openFile($path, focus: OpenFocus::Editor);
        });

        $this->goToFile->onClose(function (): void {
            if ($this->tui->getFocus() === $this->goToFile) {
                $this->tui->setFocus($this->editorArea->widget());
            }
            $this->tui->requestRender();
        });
    }

    /** The shared bottom results panel: live queries, toggles, jumps, close. */
    private function wireResultsPanel(): void
    {
        // The results panel's query row serves the command palette and workspace
        // symbol search (find-in-project's query lives in the modal). The
        // BottomDock's mode selects the handler.
        $this->resultsPanel->onQueryChanged(function (string $query): void {
            $this->cancelDebounce();
            if (ResultsPanelMode::Action === $this->panels->mode()) {
                // The command palette filters synchronously (no I/O).
                $this->commandPalette->filter($query);

                return;
            }
            // Workspace symbol search is the only other query mode; debounce it.
            $this->resultsPanel->setSearching();
            $this->searchDebounce = $this->scheduler->delay(0.25, function () use ($query): void {
                $this->searchDebounce = null;
                $this->navigation->requestWorkspaceSymbols($query);
            });
        });

        // F5 re-runs the current find-in-project query (picks up on-disk
        // changes) or, on the TODO tab, re-scans the project.
        $this->resultsPanel->onRerun(function (): void {
            if (ResultsPanelMode::Search === $this->panels->mode()) {
                $this->projectSearch->rerun();
            }
            if (ResultsPanelMode::Todo === $this->panels->mode()) {
                $this->todoScanner->scan();
            }
        });

        $this->resultsPanel->setContextProvider(fn (string $relativePath, int $line): array => $this->resultContext->around($relativePath, $line));

        // Alt+X writes the current results to a file and opens it in the editor.
        $this->resultsPanel->onExport(function (): void {
            $path = $this->projectSearch->exportResults();
            if ('' !== $path) {
                $this->resultsPanel->close();
                $this->bufferService->openFile($path, focus: OpenFocus::Editor);
                $this->tui->requestRender();
            }
        });

        $this->resultsPanel->onPin(function (): void {
            $this->projectSearch->togglePin();
        });

        $this->resultsPanel->onJump(function (string $relativePath, int $line): void {
            if (ResultsPanelMode::LspLog === $this->panels->mode()) {
                return; // log rows carry no file location
            }
            if (ResultsPanelMode::Action === $this->panels->mode()) {
                $this->commandPalette->runAt($line);

                return;
            }
            if (ResultsPanelMode::History === $this->panels->mode()) {
                $this->localHistory->restore($line);

                return;
            }
            $this->bufferService->openFile($this->rootDir.'/'.$relativePath, focus: OpenFocus::Editor);
            $this->editor->jumpTo($line, 0);
            $this->tui->requestRender();
        });

        $this->resultsPanel->onClose(function (): void {
            $this->projectSearch->cancel();
            $this->panels->focusEditor();
        });
    }

    /** Wire the Find-in-Files modal callbacks to the project-search controller. */
    private function wireFindModal(): void
    {
        $modal = $this->editor->findModal();

        // The query and mask edits re-run the search, debounced like the pane.
        $modal->onQuery(function (string $query): void {
            $this->cancelDebounce();
            $this->editor->findModal()->setSearching();
            $this->searchDebounce = $this->scheduler->delay(0.25, function () use ($query): void {
                $this->searchDebounce = null;
                $this->projectSearch->search($query);
            });
        });
        $modal->onMaskChanged(function (string $mask): void {
            $this->cancelDebounce();
            $this->editor->findModal()->setSearching();
            $this->searchDebounce = $this->scheduler->delay(0.25, function () use ($mask): void {
                $this->searchDebounce = null;
                $this->projectSearch->setMask($mask);
            });
        });
        $modal->onScopeCycle(function (int $index): void {
            $this->projectSearch->setScopeIndex($index);
        });
        $modal->onToggleOption(function (string $name): void {
            $this->projectSearch->toggleOption($name);
        });
        $modal->onJump(function (string $relativePath, int $line): void {
            $this->editor->findModal()->close();
            $this->bufferService->openFile($this->rootDir.'/'.$relativePath, focus: OpenFocus::Editor);
            $this->editor->jumpTo($line, 0);
            $this->tui->requestRender();
        });
        $modal->onOpenInFindWindow(function (): void {
            $this->panels->setMode(ResultsPanelMode::Search);
            $this->projectSearch->promoteToPane();
        });
        $modal->onReplaceAll(
            /** @param list<int> $excluded */
            function (string $replacement, array $excluded): void {
                $this->projectSearch->replaceAll($replacement, $excluded);
            },
        );
        $modal->setPreviewTransformer(fn (string $text, string $replacement): string => $this->projectSearch->previewLine($text, $replacement));
        $modal->onClose(function (): void {
            $this->editor->findModal()->close();
            $this->projectSearch->cancel();
        });
    }

    private function cancelDebounce(): void
    {
        if (null !== $this->searchDebounce) {
            $this->scheduler->cancel($this->searchDebounce);
        }
        $this->searchDebounce = null;
    }
}
