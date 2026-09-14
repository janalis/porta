<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Editor\EditorPanes;
use Porta\Layout\ViewRegions;
use Porta\Layout\WorkspaceLayout;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Assembles the {@see MenuOverlayPanes} value from the autowired widgets, the
 * layout's separators and the view registry — the pane lists and view-open
 * closures a dumped container definition cannot express directly.
 */
final readonly class MenuOverlayPanesFactory
{
    public function __construct(
        private FileTreeWidget $tree,
        private WorkspaceLayout $layout,
        private ViewRegions $regions,
        private TabBarWidget $tabBar,
        private CodeEditorWidget $editor,
        private EditorPanes $panes,
        private BreadcrumbsWidget $breadcrumbs,
        private DockTabsWidget $dockTabs,
        private ResultsPanelWidget $resultsPanel,
        private TerminalPanelWidget $terminalPanel,
        private DebugPanelWidget $debugPanel,
        private ViewRegistry $views,
    ) {}

    /**
     * The open view's panes: whatever it stacked in its region, in visual
     * order, minus anything that cannot host a dropdown.
     *
     * Two lists stood here, one per view core knew, each naming that view's
     * widgets. A view's panes are whatever is in its region — which it fills
     * itself, per mode — so the answer is computed when the question is asked.
     *
     * @return list<AbstractWidget&MenuOverlayHost>
     */
    private function openViewPanes(): array
    {
        $region = $this->regions->find((string) $this->views->activeId());
        if (null === $region) {
            return [];
        }
        $widgets = [...$region->sidebar()->all(), $region->separator(), ...$region->content()->all()];

        return \array_values(\array_filter($widgets, static fn (AbstractWidget $widget): bool => $widget instanceof MenuOverlayHost));
    }

    public function create(): MenuOverlayPanes
    {
        return new MenuOverlayPanes(
            // The dock widgets host too, so a dock-strip tab's context menu
            // can composite over the strip and the visible bottom panel.
            [
                $this->tree,
                $this->layout->separator,
                $this->tabBar,
                $this->editor,
                $this->breadcrumbs,
                $this->dockTabs,
                $this->resultsPanel,
                $this->terminalPanel,
                $this->debugPanel,
            ],
            $this->openViewPanes(...),
            $this->panes,
        );
    }
}
