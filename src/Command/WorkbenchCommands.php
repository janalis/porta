<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\History\LocalHistoryController;
use Porta\Layout\PaneResizer;
use Porta\Run\RunController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Ui\ContextMenuOpener;
use Porta\Ui\EditorArea;
use Porta\Ui\FocusCoordinator;
use Porta\Ui\WorkbenchActions;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Symfony\Component\Tui\Tui;

/** The key-bound-only workbench chords: quit, focus movement, global overlays. */
final class WorkbenchCommands implements CommandProvider
{
    public function __construct(
        private readonly WorkbenchActions $workbench,
        private readonly FocusCoordinator $focusCoordinator,
        private readonly ContextMenuOpener $contextMenuOpener,
        private readonly PaneResizer $paneResizer,
        private readonly BufferService $bufferService,
        private readonly SearchEverywhereController $searchEverywhere,
        private readonly LocalHistoryController $localHistory,
        private readonly RunController $run,
        private readonly WorkspaceViews $views,
        private readonly EditorArea $editorArea,
        private readonly Tui $tui,
        private readonly CodeEditorWidget $editor,
        private readonly ImageViewerWidget $imageViewer,
        private readonly FileTreeWidget $tree,
        private readonly MenuBarWidget $menuBar,
        private readonly GoToFileWidget $goToFile,
        private readonly FindBarWidget $findBar,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        // Key-bound only (not offered in the palette).
        $commands
            ->add('ide_quit', fn () => $this->workbench->quit(), keyBound: true, inPalette: false)
            ->add(
                'ide_toggle_focus',
                function (): void {
                    $inEditorArea = \in_array($this->tui->getFocus(), [$this->editor, $this->imageViewer], true);
                    $this->tui->setFocus($inEditorArea ? $this->tree : $this->editorArea->widget());
                    $this->tui->requestRender();
                },
                keyBound: true,
                inPalette: false,
            )
            ->add(
                'focus_next',
                fn () => $this->focusCoordinator->cycle(1),
                keyBound: true,
                inPalette: false,
                when: fn (): bool => !$this->menuBar->isOpen() && !$this->goToFile->isOpen() && !$this->findBar->isOpen(),
            )
            ->add(
                'focus_previous',
                fn () => $this->focusCoordinator->cycle(-1),
                keyBound: true,
                inPalette: false,
                when: fn (): bool => !$this->menuBar->isOpen() && !$this->goToFile->isOpen() && !$this->findBar->isOpen(),
            )
            ->add(
                'show_context_menu',
                fn () => $this->contextMenuOpener->openForFocus(),
                keyBound: true,
                inPalette: false,
                when: fn (): bool => !$this->views->isGitOpen() && !$this->views->isSettingsOpen() && !$this->menuBar->isOpen(),
            )
            ->add('resize_pane_grow', fn () => $this->paneResizer->resize(1), keyBound: true, inPalette: false, when: fn (): bool => $this->paneResizer->canResize())
            ->add('resize_pane_shrink', fn () => $this->paneResizer->resize(-1), keyBound: true, inPalette: false, when: fn (): bool => $this->paneResizer->canResize())
            ->add('ide_command_palette', fn () => $this->workbench->openCommandPalette(), keyBound: true, inPalette: false)
            ->add(
                'ide_search_everywhere',
                function (): void {
                    if ($this->bufferService->ensureTextEditorActive()) {
                        $this->searchEverywhere->open();
                    }
                },
                keyBound: true,
                inPalette: false,
            )
            ->add('ide_local_history', fn () => $this->localHistory->show(), keyBound: true, inPalette: false)
            ->add('ide_run_last', fn () => $this->run->runLast(), keyBound: true, inPalette: false)
            ->add('ide_menu', fn () => $this->workbench->openMenu(), keyBound: true, inPalette: false);
    }
}
