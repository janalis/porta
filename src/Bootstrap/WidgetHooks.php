<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Container\AsIdeService;
use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\BufferManager;
use Porta\Editor\PreviewMode;
use Porta\Help\HelpViewerActions;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Run\RunController;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\TabListProvider;
use Porta\Workbench\EditorArea;
use Porta\Workbench\StatusBarProvider;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Registers the widgets' runtime hooks — the callables symfony/tui widgets
 * accept at runtime rather than through construction: content providers
 * (tabs, breadcrumbs, status segments), the tree's open hook, the editor's
 * callback groups and viewer handlers, panel input forwarding + focus
 * restoration, and the menu/context overlay refresh + close-focus flow.
 * One wire() call from Ide, once every target service exists.
 */
#[AsIdeService]
final readonly class WidgetHooks
{
    public function __construct(
        private Tui $tui,
        private CodeEditorWidget $editor,
        private EditorCallbacks $editorCallbacks,
        private HelpViewerWidget $helpViewer,
        private HelpViewerActions $helpViewerActions,
        private TabBarWidget $tabBar,
        private TabListProvider $tabs,
        private BreadcrumbsWidget $breadcrumbs,
        private BreadcrumbTrailProvider $trail,
        private StatusBarWidget $statusBar,
        private StatusBarProvider $statusSegments,
        private TerminalPanelWidget $terminalPanel,
        private RunController $run,
        private BottomDock $panels,
        private MenuBarWidget $menuBar,
        private ContextMenu $contextMenu,
        private MenuOverlayController $overlay,
        private ViewRegistry $views,
        private EditorArea $editorArea,
        private BufferManager $buffers,
        private string $docsDir,
        private PluginInstaller $plugins,
    ) {}

    public function wire(): void
    {
        $this->editor->setCallbacks($this->editorCallbacks);
        $this->helpViewer->setDocsRoot($this->docsDir);
        // Each plugin carries its own docs; Porta's pages link into them.
        $this->helpViewer->setMoreRoots($this->plugins->docsRoots(...));
        $this->helpViewer->setHandler($this->helpViewerActions);

        $this->tabBar->setProvider($this->tabs->tabs(...));
        // The active tab's lid goes heavy while the pane it shows has the keys.
        $this->tabBar->setFocusProbe(function (): bool {
            $pane = $this->editorArea->widget();

            return $pane instanceof FocusableInterface && $pane->isFocused();
        });
        $this->breadcrumbs->setProvider($this->trail->trail(...));
        $this->breadcrumbs->setPreviewProvider(fn (): ?PreviewMode => $this->buffers->active()?->previewArrangement());
        $this->statusBar->setProvider($this->statusSegments->segments(...));

        // The dock hooks (single-visible invariant, MRU restore, focus return)
        // register on all three bottom panels here.
        $this->panels->wire();
        // Reveal, close and cycle are events the run controller listens for;
        // what is left here is the byte transport, which is one call per
        // keystroke and has no business on a bus.
        $this->terminalPanel->onInputForward(fn (string $data) => $this->run->forwardInput($data));

        $this->menuBar->onNavigate(function (): void {
            $this->overlay->refreshMenu();
        });
        $this->menuBar->onClose(function (): void {
            $this->overlay->refreshMenu();
            $this->tui->setFocus($this->views->focusTarget() ?? $this->editorArea->widget());
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
