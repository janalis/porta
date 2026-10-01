<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Editor\EditorPanes;
use Porta\Layout\PreviewSplit;
use Porta\Layout\WorkspaceLayout;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;

/**
 * Assembles the {@see MenuOverlayPanes} value from the autowired widgets and
 * the layout's separators — the pane list a dumped container definition
 * cannot express directly.
 */
final readonly class MenuOverlayPanesFactory
{
    public function __construct(
        private FileTreeWidget $tree,
        private WorkspaceLayout $layout,
        private TabBarWidget $tabBar,
        private CodeEditorWidget $editor,
        private EditorPanes $panes,
        private BreadcrumbsWidget $breadcrumbs,
        private FindBarWidget $findBar,
        private DockTabsWidget $dockTabs,
        private ResultsPanelWidget $resultsPanel,
        private TerminalPanelWidget $terminalPanel,
        private DebugPanelWidget $debugPanel,
        private PreviewSplit $previewSplit,
    ) {}

    public function create(): MenuOverlayPanes
    {
        return new MenuOverlayPanes(
            // The dock widgets host too, so a dock-strip tab's context menu
            // can composite over the strip and the visible bottom panel.
            [
                $this->tree,
                $this->layout->separator,
                // The preview split's divider — detached, and so skipped,
                // whenever no previewed file shows in Split.
                $this->previewSplit->separator,
                $this->tabBar,
                $this->editor,
                $this->breadcrumbs,
                $this->findBar,
                $this->dockTabs,
                $this->resultsPanel,
                $this->terminalPanel,
                $this->debugPanel,
            ],
            $this->panes,
            // The edge rules sit under whichever view is open; the app menu's
            // dropdown hangs from its bar across the top one. They take rows
            // the workspace used to, so the pane union — the modals' bounds —
            // is what it was.
            [$this->layout->topRule, $this->layout->bottomRule],
        );
    }
}
