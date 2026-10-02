<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;
use Porta\Tree\TreeOperations;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\ContextMenuBuilder;
use Porta\Workbench\Menu\ContextMenuOpener;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Menu\ViewMenuSources;
use Porta\Workbench\ProjectSwitcher;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Right-click routing, split out of {@see MouseRouter}: it resolves WHICH item
 * list the pointer is over — editor, tab strip, file tree, dock tab, results
 * or debugger row, git changes row, git commit, status-bar branch or file —
 * and hands it to the existing
 * {@see ContextMenuOpener}. No menu applies to the pointer's surface, or a
 * floating picker owns input, means a "no menu here" flash.
 *
 * Extracted because it exclusively owned four of the router's collaborators
 * (the status bar, the tree operations, the item builder and the opener) and
 * touches none of its cross-event drag/click state — the same shape that moved
 * {@see WheelRouter} out. The ordered walk below is the same precedence the
 * router's own spine uses: the topmost surface under the pointer wins.
 */
final readonly class ContextMenuRouter
{
    public function __construct(
        private HitTester $hit,
        private Tui $tui,
        private EditorLayoutPanes $panes,
        private MenuBarWidget $menuBar,
        private DockTabsWidget $dockTabs,
        private BufferManager $buffers,
        private TreeOperations $treeOps,
        private ContextMenuBuilder $contextItems,
        private ViewMenuSources $viewMenus,
        private ViewRegistry $views,
        private ContextMenuOpener $opener,
        private HoverRouter $hover,
        private StatusBarWidget $statusBar,
        private ResultsPanelWidget $resultsPanel,
        private DebugPanelWidget $debugPanel,
        private ProjectPaths $paths,
        private ProjectSwitcher $projects,
    ) {}

    public function route(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        $this->menuBar->close();

        // A floating picker (list overlay / Search Everywhere / prompt) owns
        // input while open, so a right-click opens no menu over it.
        if ($this->panes->editor->isAnyPickerOpen()) {
            $this->opener->flashNoMenu();

            return;
        }

        $items = null;
        $handled = false;
        if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->editor);
            // Keep an existing selection (so Cut/Copy act on it); otherwise place
            // the caret at the click, like PhpStorm.
            if (!$this->panes->editor->getDocument()->hasSelection()) {
                $this->panes->editor->clearExtraCarets();
                $this->panes->editor->clickAt($rel['row'], $rel['col']);
            }
            $items = $this->contextItems->editorItems();
        }
        if (!$handled && null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
            $index = $this->panes->tabBar->tabIndexAt($rel['row'], $rel['col']);
            $buffers = $this->buffers->all();
            if (null === $index || !\array_key_exists($index, $buffers)) {
                $this->opener->flashNoMenu(); // the strip's empty tail

                return;
            }
            $items = $this->contextItems->tabItems($buffers[$index]->path);
        }
        if (!$handled && null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->tree);
            // The project name: the folders beside it, to switch to one.
            $node = 0 === $rel['row'] ? null : $this->panes->tree->nodeAt($rel['row']);
            if (0 !== $rel['row'] && null === $node) {
                $this->opener->flashNoMenu(); // below the last node

                return;
            }
            // A FILE row also offers what plugins put on the file surface; a
            // directory does not (the surface names a file, and core's own
            // folder verbs are the ones that make sense there).
            $items = match (true) {
                null === $node => $this->projects->siblingItems(),
                $node->isDir => $this->treeOps->contextItems($node),
                default => [...$this->treeOps->contextItems($node), ...$this->contextItems->fileItems($node->path)],
            };
        }
        $stripCol = $this->hover->dockStripRelCol($mouse);
        if (!$handled && null !== $stripCol) {
            $handled = true;
            $hit = $this->dockTabs->tabAt($stripCol);
            if (null === $hit) {
                $this->opener->flashNoMenu(); // the strip's empty tail

                return;
            }
            $items = $this->contextItems->dockTabItems($hit['tab'], $hit['key']);
        }
        // A results or debugger row names a file: the copy-path verbs the tree
        // offers for it, and whatever plugins put on the file surface.
        if (!$handled) {
            $items = $this->dockRowItems($mouse);
            $handled = null !== $items;
        }
        // Whatever view owns the main area answers for its own panes. Two
        // git-shaped branches stood here, each pulling a selected row out of
        // the git router so core could build git's menu from it.
        if (!$handled) {
            $items = $this->viewMenus->of($this->views->activeId())?->contextItemsAt($mouse->row, $mouse->col);
        }
        // The status bar's file part is a way back to the tree: select the
        // file there, or copy the path the tree would.
        $statusCol = $this->statusCol($mouse);
        $file = null === $statusCol ? null : $this->statusBar->fileAt($statusCol);
        if (!$handled && null === $items && null !== $file) {
            $items = [...$this->contextItems->statusFileItems(), MenuItem::separator(), ...$this->treeOps->copyPathItems($file)];
        }
        // A right-click on a status cell offers whatever that cell put there.
        // This branch used to read one hardcoded span and pair it with the git
        // branch menu; the cell carries its own menu now, so nothing here knows
        // which cell was clicked.
        if (!$handled && null === $items) {
            $items = $this->statusMenuItems($mouse);
        }
        if (null === $items) {
            $this->opener->flashNoMenu();

            return;
        }

        $this->opener->openAt($items, $mouse->row, $mouse->col);
    }

    /**
     * A LEFT click on a status cell that offers a menu opens it: the cell
     * wears the select's `▾`, and a select opens on a click. True when the
     * click was one; anywhere else is left to the other routers.
     */
    public function openStatusMenu(MouseEvent $mouse): bool
    {
        $items = $this->statusMenuItems($mouse);
        if (null === $items) {
            return false;
        }
        $this->menuBar->close();
        $this->opener->openAt($items, $mouse->row, $mouse->col);

        return true;
    }

    /**
     * The menu of the results or debugger row under the pointer: null off
     * those panels, and on a row that names no file — which then flashes "no
     * menu here" like any other surface.
     *
     * @return list<MenuItem>|null
     */
    private function dockRowItems(MouseEvent $mouse): ?array
    {
        foreach ([$this->resultsPanel, $this->debugPanel] as $panel) {
            $rect = $this->hit->rect($panel);
            if (null === $rect || !$rect->contains($mouse->row, $mouse->col)) {
                continue;
            }
            $path = $panel->pathAt($rect->toRelative($mouse->row, $mouse->col)['row']);
            if (null === $path) {
                return null;
            }
            $absolute = $this->paths->absolute($path);

            return [...$this->treeOps->copyPathItems($absolute), ...$this->contextItems->fileItems($absolute)];
        }

        return null;
    }

    /** The status-bar column under the pointer, or null off the row. */
    private function statusCol(MouseEvent $mouse): ?int
    {
        $statusRect = $this->hit->rect($this->statusBar);
        if (null === $statusRect || !$statusRect->contains($mouse->row, $mouse->col)) {
            return null;
        }

        return $statusRect->toRelative($mouse->row, $mouse->col)['col'];
    }

    /** @return list<MenuItem>|null the menu of the status cell under the pointer */
    private function statusMenuItems(MouseEvent $mouse): ?array
    {
        $col = $this->statusCol($mouse);
        $menu = null === $col ? null : $this->statusBar->menuAt($col);

        return null === $menu ? null : $menu();
    }
}
