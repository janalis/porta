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

        $items = $this->itemsAt($mouse, $treeRect, $tabsRect, $editorRect);
        if (null === $items) {
            $this->opener->flashNoMenu();

            return;
        }

        $this->opener->openAt($items, $mouse->row, $mouse->col);
    }

    /**
     * The menu for the surface under the pointer — the topmost one that
     * claims it — or null where none applies, which flashes "no menu here".
     *
     * A surface that claims the pointer answers for it even with nothing to
     * offer (the tab strip's empty tail, the tree below its last node): each
     * arm answers, rather than setting a "handled" flag every later surface had
     * to test and an `$items` each could overwrite.
     *
     * @return list<MenuItem>|null
     */
    private function itemsAt(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): ?array
    {
        $editorRel = self::relativeIn($editorRect, $mouse);
        $tabsRel = self::relativeIn($tabsRect, $mouse);
        $treeRel = self::relativeIn($treeRect, $mouse);

        return match (true) {
            null !== $editorRel => $this->editorItems($editorRel),
            null !== $tabsRel => $this->tabItems($tabsRel),
            null !== $treeRel => $this->treeItems($treeRel['row']),
            default => $this->dockItemsAt($mouse),
        };
    }

    /** @return array{row: int, col: int}|null the pointer relative to the rect, or null outside it */
    private static function relativeIn(?WidgetRect $rect, MouseEvent $mouse): ?array
    {
        return null !== $rect && $rect->contains($mouse->row, $mouse->col) ? $rect->toRelative($mouse->row, $mouse->col) : null;
    }

    /**
     * Below the panes: a dock tab, a results or debugger row, a view's own
     * panes, then the status bar.
     *
     * @return list<MenuItem>|null
     */
    private function dockItemsAt(MouseEvent $mouse): ?array
    {
        $stripCol = $this->hover->dockStripRelCol($mouse);
        if (null !== $stripCol) {
            $hit = $this->dockTabs->tabAt($stripCol);

            return null === $hit ? null : $this->contextItems->dockTabItems($hit['tab'], $hit['key']);
        }

        // A results or debugger row names a file: the copy-path verbs the tree
        // offers for it, and whatever plugins put on the file surface. Then
        // whatever view owns the main area answers for its own panes. Two
        // git-shaped branches stood here, each pulling a selected row out of
        // the git router so core could build git's menu from it.
        return $this->dockRowItems($mouse) ?? $this->viewMenus->of($this->views->activeId())?->contextItemsAt($mouse->row, $mouse->col) ?? $this->statusItems($mouse);
    }

    /**
     * Keep an existing selection (so Cut/Copy act on it); otherwise place the
     * caret at the click, like PhpStorm. A click on an inlay hint is about
     * the hint, not the code: its own menu, and the caret stays put.
     *
     * @param array{row: int, col: int} $rel
     *
     * @return list<MenuItem>
     */
    private function editorItems(array $rel): array
    {
        $this->tui->setFocus($this->panes->editor);
        $hint = $this->panes->editor->mouse->inlayHintAt($rel['row'], $rel['col'], $this->panes->editor->viewport());
        if (null === $hint && !$this->panes->editor->getDocument()->hasSelection()) {
            $this->panes->editor->clearExtraCarets();
            $this->panes->editor->clickAt($rel['row'], $rel['col']);
        }

        return null === $hint ? $this->contextItems->editorItems() : $this->contextItems->inlayHintItems($hint);
    }

    /**
     * @param array{row: int, col: int} $rel
     *
     * @return list<MenuItem>|null null on the strip's empty tail
     */
    private function tabItems(array $rel): ?array
    {
        $index = $this->panes->tabBar->tabIndexAt($rel['row'], $rel['col']);
        $buffers = $this->buffers->all();
        if (null === $index || !\array_key_exists($index, $buffers)) {
            return null;
        }

        return $this->contextItems->tabItems($buffers[$index]->path);
    }

    /**
     * The project name (row 0): the folders beside it, to switch to one. A
     * FILE row also offers what plugins put on the file surface; a directory
     * does not (the surface names a file, and core's own folder verbs are the
     * ones that make sense there).
     *
     * @return list<MenuItem>|null null below the last node
     */
    private function treeItems(int $row): ?array
    {
        $this->tui->setFocus($this->panes->tree);
        if (0 === $row) {
            return $this->projects->siblingItems();
        }
        $node = $this->panes->tree->nodeAt($row);

        return match (true) {
            null === $node => null,
            $node->isDir => $this->treeOps->contextItems($node),
            default => [...$this->treeOps->contextItems($node), ...$this->contextItems->fileItems($node->path)],
        };
    }

    /**
     * The status bar's file part is a way back to the tree: select the file
     * there, or copy the path the tree would. A right-click on any other
     * status cell offers whatever that cell put there — the cell carries its
     * own menu, so nothing here knows which cell was clicked.
     *
     * @return list<MenuItem>|null
     */
    private function statusItems(MouseEvent $mouse): ?array
    {
        $statusCol = $this->statusCol($mouse);
        $file = null === $statusCol ? null : $this->statusBar->fileAt($statusCol);
        if (null !== $file) {
            return [...$this->contextItems->statusFileItems(), MenuItem::separator(), ...$this->treeOps->copyPathItems($file)];
        }

        return $this->statusMenuItems($mouse);
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
        $items = null;
        foreach ([$this->resultsPanel, $this->debugPanel] as $panel) {
            $rel = self::relativeIn($this->hit->rect($panel), $mouse);
            if (null === $rel) {
                continue;
            }
            $path = $panel->pathAt($rel['row']);
            if (null !== $path) {
                $absolute = $this->paths->absolute($path);
                $items = [...$this->treeOps->copyPathItems($absolute), ...$this->contextItems->fileItems($absolute)];
            }

            break;
        }

        return $items;
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
