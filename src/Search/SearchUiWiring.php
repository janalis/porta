<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\History\LocalHistoryController;
use Porta\Support\Scheduler;
use Porta\Ui\Dock\BottomDock;
use Porta\Ui\Dock\DockTab;
use Porta\Ui\Dock\DockToolTab;
use Porta\Ui\Dock\ResultsPanelMode;
use Porta\Ui\EditorArea;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FindBarWidget;
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
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly CodeEditorWidget $editor,
        private readonly EditorArea $editorArea,
        private readonly Tui $tui,
        private readonly BottomDock $panels,
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

    /** Find bar: search-match mirroring and focus flow. (Go-to-file is the
     * files-only Search Everywhere overlay, wired by its own controller.) */
    private function wireFindAndGoto(): void
    {
        // The match mirror lives on the bar's FindTarget now (editor highlight
        // or docs highlight), so this only repaints. On close the bar clears its
        // target's highlight; here we just return focus to the active pane.
        $this->findBar->onUpdate(function (): void {
            $this->tui->requestRender();
        });

        $this->findBar->onClose(function (): void {
            $this->tui->setFocus($this->editorArea->widget());
            $this->tui->requestRender();
        });
    }

    /** The shared bottom results panel: list tools, toggles, jumps, close. */
    private function wireResultsPanel(): void
    {
        // F5 re-runs the current find-in-project query (picks up on-disk
        // changes) or, on the TODO tab, re-scans the project. Routed by the
        // BOUND tab's mode — the dock's mode field may lag behind tool
        // switches driven outside the dock.
        $this->resultsPanel->onRerun(function (): void {
            $tool = $this->panels->activeTool();
            if (null !== $tool?->searchState) {
                $this->projectSearch->rerun(); // only real find-in-project tabs re-grep

                return;
            }
            if (ResultsPanelMode::Todo === $tool?->mode) {
                $this->todoScanner->scan();
            }
        });

        $this->resultsPanel->setContextProvider(fn (string $relativePath, int $line): array => $this->resultContext->around($relativePath, $line));

        // Alt+X writes the current results to a file and opens it in the editor.
        $this->resultsPanel->onExport(function (): void {
            $path = $this->projectSearch->exportResults();
            if ('' !== $path) {
                $this->panels->closeTab(DockTab::Results); // only the exported search's tab closes
                $this->bufferService->openFile($path, focus: OpenFocus::Editor);
                $this->tui->requestRender();
            }
        });

        // A Search tab taking the display rehydrates its frozen query
        // definition; ×ing one records its query/mask and cancels its run.
        $this->panels->onToolBound(function (DockToolTab $tab): void {
            if (ResultsPanelMode::Search === $tab->mode) {
                $this->projectSearch->adopt($tab);
            }
        });
        $this->panels->onToolClosed(function (DockToolTab $tab): void {
            if (null !== $tab->searchState) {
                $this->projectSearch->closeSearch($tab->searchState);
            }
        });

        $this->resultsPanel->onJump(function (string $relativePath, int $line): void {
            $mode = $this->panels->activeTool()->mode ?? $this->panels->mode();
            if (\in_array($mode, [ResultsPanelMode::LspLog, ResultsPanelMode::Notifications], strict: true)) {
                return; // log rows carry no file location
            }
            if (ResultsPanelMode::History === $mode) {
                $this->localHistory->restore($line);

                return;
            }
            $this->bufferService->openFile($this->rootDir.'/'.$relativePath, focus: OpenFocus::Editor);
            $this->editor->jumpTo($line, 0);
            $this->tui->requestRender();
        });

        // Per-tab close recording happens in onToolClosed; the widget-level
        // close (last tab gone, picker close) only returns the focus.
        $this->resultsPanel->onClose(function (): void {
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
            $this->projectSearch->promoteToPane(); // binding the tab sets the mode
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
