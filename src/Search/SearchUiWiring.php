<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Container\AsIdeService;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Event\FindQueryUpdated;
use Porta\Event\ResultRowActivated;
use Porta\Event\ResultsExportRequested;
use Porta\Event\ResultsRerunRequested;
use Porta\Overlay\State\FindModalCallbacks;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockTab;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\EditorArea;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Wires the search-facing chrome to its controllers: the find bar's match
 * mirroring, the go-to-file palette, the shared bottom results panel (palette /
 * symbol / find-in-project / history modes) and the Find-in-Files modal. Owns
 * the one shared query debounce — the panel's query row and the modal's
 * query/mask fields cancel each other's pending searches.
 */
#[AsIdeService]
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
        private readonly ResultContextProvider $resultContext,
        private readonly FileOpener $bufferService,
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

        $this->resultsPanel->setContextProvider(fn (string $relativePath, int $line): array => $this->resultContext->around($relativePath, $line));

        // A Search tab taking the display rehydrates its frozen query
        // definition; ×ing one records its query/mask and cancels its run.

        // Per-tab close recording happens in onToolClosed; the widget-level
        // close (last tab gone, picker close) only returns the focus.
        $this->resultsPanel->onClose(function (): void {
            $this->panels->focusEditor();
        });
    }

    /**
     * The find bar's query or options changed.
     *
     * Only a repaint: the match mirror lives on the bar's own FindTarget (the
     * editor's highlight, or the docs pane's), so there is nothing to
     * recompute here.
     */
    #[AsEventListener]
    public function onFindQueryUpdated(FindQueryUpdated $event): void
    {
        $this->tui->requestRender();
    }

    /**
     * F5 re-runs the current find-in-project query (picks up on-disk changes)
     * or, on the TODO tab, re-scans. Routed by the BOUND tab's mode — the
     * dock's mode field may lag behind tool switches driven outside the dock.
     */
    #[AsEventListener]
    public function onRerunRequested(ResultsRerunRequested $event): void
    {
        $tool = $this->panels->activeTool();
        $handlers = $tool?->handlers;
        if (null !== $handlers) {
            // A contributed tool's own F5, already inside its plugin's fault
            // barrier; a null rerun leaves the key inert.
            ($handlers->rerun ?? static function (): void {})();

            return;
        }
        if (null !== $tool?->searchState) {
            $this->projectSearch->rerun(); // only real find-in-project tabs re-grep
        }
    }

    /** Alt+X writes the current results to a file and opens it in the editor. */
    #[AsEventListener]
    public function onExportRequested(ResultsExportRequested $event): void
    {
        $path = $this->projectSearch->exportResults();
        if ('' !== $path) {
            $this->panels->closeTab(DockTab::Results); // only the exported search's tab closes
            $this->bufferService->openFile($path, focus: OpenFocus::Editor);
            $this->tui->requestRender();
        }
    }

    /** Enter on a result row. */
    #[AsEventListener]
    public function onRowActivated(ResultRowActivated $event): void
    {
        $relativePath = $event->relativePath;
        $line = $event->line;

        $tool = $this->panels->activeTool();
        $activate = $tool?->handlers?->activate;
        if (null !== $activate) {
            // A contributed tool's own Enter.
            $activate($relativePath, $line);

            return;
        }
        // A contributed tool that declares no activate falls through to the
        // built-in open-and-jump, which is what most tools want; a CORE tab
        // is routed by its mode.
        if (null === $tool?->handlers) {
            // Spelled out rather than `$tool->mode ?? $this->panels->mode()`:
            // a contributed tab has a null MODE too, so the coalesce would
            // silently fall back to whatever core tool was bound last and
            // route this jump by it. Only a missing TAB may fall back.
            $mode = null === $tool ? $this->panels->mode() : $tool->mode;
            if (\in_array($mode, [ResultsPanelMode::LspLog, ResultsPanelMode::Notifications], strict: true)) {
                return; // log rows carry no file location
            }
        }
        $this->bufferService->openFile($this->rootDir.'/'.$relativePath, focus: OpenFocus::Editor);
        $this->editor->jumpTo($line, 0);
        $this->tui->requestRender();
    }

    /** Wire the Find-in-Files modal callbacks to the project-search controller. */
    private function wireFindModal(): void
    {
        $this->editor
            ->findModal()
            ->setCallbacks(new FindModalCallbacks(
                // The query and mask edits re-run the search, debounced like the pane.
                onQuery: function (string $query): void {
                    $this->cancelDebounce();
                    $this->editor->findModal()->setSearching();
                    $this->searchDebounce = $this->scheduler->delay(0.25, function () use ($query): void {
                        $this->searchDebounce = null;
                        $this->projectSearch->search($query);
                    });
                },
                onMaskChanged: function (string $mask): void {
                    $this->cancelDebounce();
                    $this->editor->findModal()->setSearching();
                    $this->searchDebounce = $this->scheduler->delay(0.25, function () use ($mask): void {
                        $this->searchDebounce = null;
                        $this->projectSearch->setMask($mask);
                    });
                },
                onScopeCycle: function (int $index): void {
                    $this->projectSearch->setScopeIndex($index);
                },
                onToggleOption: function (string $name): void {
                    $this->projectSearch->toggleOption($name);
                },
                onJump: function (string $relativePath, int $line): void {
                    $this->editor->findModal()->close();
                    $this->bufferService->openFile($this->rootDir.'/'.$relativePath, focus: OpenFocus::Editor);
                    $this->editor->jumpTo($line, 0);
                    $this->tui->requestRender();
                },
                onOpenInFindWindow: function (): void {
                    $this->projectSearch->promoteToPane(); // binding the tab sets the mode
                },
                /** @param list<int> $excluded */
                onReplaceAll: function (string $replacement, array $excluded): void {
                    $this->projectSearch->replaceAll($replacement, $excluded);
                },
                previewReplacement: fn (string $text, string $replacement): string => $this->projectSearch->previewLine($text, $replacement),
                onClose: function (): void {
                    $this->editor->findModal()->close();
                    $this->projectSearch->cancel();
                },
            ));
    }

    private function cancelDebounce(): void
    {
        if (null !== $this->searchDebounce) {
            $this->scheduler->cancel($this->searchDebounce);
        }
        $this->searchDebounce = null;
    }
}
