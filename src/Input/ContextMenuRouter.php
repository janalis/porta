<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Editor\BufferManager;
use Porta\Tree\TreeOperations;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\ContextMenuBuilder;
use Porta\Workbench\Menu\ContextMenuOpener;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Right-click routing, split out of {@see MouseRouter}: it resolves WHICH item
 * list the pointer is over — editor, tab strip, file tree, dock tab, git
 * changes row, git commit, status-bar branch — and hands it to the existing
 * {@see ContextMenuOpener}. No menu applies to the pointer's surface, or a
 * floating picker owns input, means a "no menu here" flash.
 *
 * Extracted because it exclusively owned four of the router's collaborators
 * (the status bar, the tree operations, the item builder and the opener) and
 * touches none of its cross-event drag/click state — the same shape that moved
 * {@see WheelRouter} out. The ordered walk below is the same precedence the
 * router's own spine uses: the topmost surface under the pointer wins.
 */
final class ContextMenuRouter
{
    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly EditorLayoutPanes $panes,
        private readonly MenuBarWidget $menuBar,
        private readonly DockTabsWidget $dockTabs,
        private readonly BufferManager $buffers,
        private readonly TreeOperations $treeOps,
        private readonly ContextMenuBuilder $contextItems,
        private readonly ContextMenuOpener $opener,
        private readonly GitMouseRouter $git,
        private readonly HoverRouter $hover,
        private readonly StatusBarWidget $statusBar,
    ) {}

    public function route(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        $this->menuBar->close();

        // A floating picker (list overlay / Search Everywhere / prompt) owns
        // input while open, so a right-click opens no menu over it.
        if ($this->panes->editor()->isAnyPickerOpen()) {
            $this->opener->flashNoMenu();

            return;
        }

        $items = null;
        $handled = false;
        if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->editor());
            // Keep an existing selection (so Cut/Copy act on it); otherwise place
            // the caret at the click, like PhpStorm.
            if (!$this->panes->editor()->getDocument()->hasSelection()) {
                $this->panes->editor()->clearExtraCarets();
                $this->panes->editor()->clickAt($rel['row'], $rel['col']);
            }
            $items = $this->contextItems->editorItems();
        }
        if (!$handled && null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
            $index = $this->panes->tabBar()->tabIndexAt($rel['row'], $rel['col']);
            $buffers = $this->buffers->all();
            if (null === $index || !array_key_exists($index, $buffers)) {
                $this->opener->flashNoMenu(); // the strip's empty tail

                return;
            }
            $items = $this->contextItems->tabItems($buffers[$index]->path);
        }
        if (!$handled && null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->tree());
            $node = $this->panes->tree()->nodeAt($rel['row']);
            if (null === $node) {
                $this->opener->flashNoMenu(); // below the last node

                return;
            }
            // A FILE row also offers what plugins put on the file surface; a
            // directory does not (the surface names a file, and core's own
            // folder verbs are the ones that make sense there).
            $items = $node->isDir
                ? $this->treeOps->contextItems($node)
                : [...$this->treeOps->contextItems($node), ...$this->contextItems->fileItems($node->path)];
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
        if (!$handled && null !== ($entry = $this->git->changesRowAt($mouse))) {
            $items = $this->contextItems->gitChangeItems($entry);
        }
        if (!$handled && null === $items && null !== ($commit = $this->git->historyCommitAt($mouse))) {
            $items = $this->contextItems->gitCommitItems($commit);
        }
        // A right-click on the status-bar branch name offers the branch actions.
        if (!$handled && null === $items) {
            $statusRect = $this->hit->rect($this->statusBar);
            $span = $this->statusBar->branchColumnSpan();
            if (null !== $statusRect && null !== $span && $statusRect->contains($mouse->row, $mouse->col)) {
                $relCol = $statusRect->toRelative($mouse->row, $mouse->col)['col'];
                if ($relCol >= $span[0] && $relCol < $span[1]) {
                    $items = $this->contextItems->branchItems();
                }
            }
        }
        if (null === $items) {
            $this->opener->flashNoMenu();

            return;
        }

        $this->opener->openAt($items, $mouse->row, $mouse->col);
    }
}
