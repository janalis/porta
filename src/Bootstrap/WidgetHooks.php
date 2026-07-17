<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Help\HelpViewerActions;
use Porta\Image\ImageViewerActions;
use Porta\Overlay\MenuOverlayController;
use Porta\Run\RunController;
use Porta\Ui\BottomDock;
use Porta\Ui\EditorArea;
use Porta\Ui\StatusBarProvider;
use Porta\Ui\TabListProvider;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * Registers the widgets' runtime hooks — the callables symfony/tui widgets
 * accept at runtime rather than through construction: content providers
 * (tabs, breadcrumbs, status segments), the tree's open hook, the editor's
 * callback groups and viewer handlers, panel input forwarding + focus
 * restoration, and the menu/context overlay refresh + close-focus flow.
 * One wire() call from Ide, once every target service exists.
 */
final class WidgetHooks
{
    public function __construct(
        private readonly Tui $tui,
        private readonly FileTreeWidget $tree,
        private readonly FileOpener $files,
        private readonly CodeEditorWidget $editor,
        private readonly EditorCallbacks $editorCallbacks,
        private readonly ImageViewerWidget $imageViewer,
        private readonly ImageViewerActions $imageViewerActions,
        private readonly HelpViewerWidget $helpViewer,
        private readonly HelpViewerActions $helpViewerActions,
        private readonly TabBarWidget $tabBar,
        private readonly TabListProvider $tabs,
        private readonly BreadcrumbsWidget $breadcrumbs,
        private readonly BreadcrumbTrailProvider $trail,
        private readonly StatusBarWidget $statusBar,
        private readonly StatusBarProvider $statusSegments,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly RunController $run,
        private readonly BottomDock $panels,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly MenuOverlayController $overlay,
        private readonly WorkspaceViews $views,
        private readonly EditorArea $editorArea,
        private readonly string $docsDir,
    ) {}

    public function wire(): void
    {
        $this->tree->onOpenFile(fn (string $path) => $this->files->openFile($path, focus: OpenFocus::Editor));

        $this->editor->setCallbacks($this->editorCallbacks);
        $this->imageViewer->setHandler($this->imageViewerActions);
        $this->helpViewer->setDocsRoot($this->docsDir);
        $this->helpViewer->setHandler($this->helpViewerActions);

        $this->tabBar->setProvider($this->tabs->tabs(...));
        $this->breadcrumbs->setProvider($this->trail->trail(...));
        $this->statusBar->setProvider($this->statusSegments->segments(...));

        // The dock hooks (single-visible invariant, MRU restore, focus return)
        // register on all three bottom panels here.
        $this->panels->wire();
        $this->panels->onTerminalReveal(fn () => $this->run->showActiveSession());
        $this->panels->onTerminalSessionClose(fn (int $index) => $this->run->closeSession($index));
        $terminalPanel = $this->terminalPanel;
        $terminalPanel->onInputForward(fn (string $data) => $this->run->forwardInput($data));
        $terminalPanel->onSessionClosed(fn (int $index) => $this->run->closeSession($index));
        $terminalPanel->onSessionCycled(fn (int $delta) => $this->run->cycleSession($delta));

        $this->menuBar->onNavigate(function (): void {
            $this->overlay->refreshMenu();
        });
        $this->menuBar->onClose(function (): void {
            $this->overlay->refreshMenu();
            $this->tui->setFocus(match (true) {
                null !== ($target = $this->views->focusTarget()) => $target,
                default => $this->editorArea->widget(),
            });
            $this->tui->requestRender();
        });
        $this->contextMenu->onNavigate(function (): void {
            $this->overlay->refreshContextMenu();
        });
        $this->contextMenu->onClose(function (): void {
            $this->overlay->refreshContextMenu();
            $this->tui->requestRender();
        });
    }
}
