<?php

declare(strict_types=1);

namespace Porta\Ui\Menu;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Terminal\TerminalSessions;
use Porta\Tree\TreeOperations;
use Porta\Ui\Dock\DockTab;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Opens the context menu from the keyboard (Ctrl+F10) for whatever holds the
 * focus: the editor's menu anchored at the caret, the tree's at its selected
 * node, and the image/help viewers' tab menu under the active tab. It shares
 * the item lists with the mouse router's right-click path ({@see
 * ContextMenuBuilder}); what it adds is the anchor-from-focus geometry. Both
 * chains finish here — {@see openAt()} and {@see flashNoMenu()} — so the two
 * cannot disagree about what opening a menu, or failing to, looks like.
 *
 * THE RULE, for both chains: a context menu is built from its target and never
 * invents one. A pane that CARRIES its target as its selection moves the
 * selection to it, because that is the only channel it has (the tree, the git
 * changes list); a pane that can PASS its target does not (the tab strip, the
 * dock strip — they hand over a path or a key). The editor is the same rule
 * for text: an existing selection IS the target, so a right-click keeps it and
 * only an empty selection moves the caret.
 *
 * This class moving nothing is not an exception to that rule — it is the rule
 * with a zero-length move, because the focused pane's target is already its
 * selection.
 */
final class ContextMenuOpener
{
    public function __construct(
        private readonly Tui $tui,
        private readonly Renderer $renderer,
        private readonly ContextMenu $contextMenu,
        private readonly MenuOverlayController $overlay,
        private readonly ContextMenuBuilder $items,
        private readonly TreeOperations $treeOps,
        private readonly MenuBarWidget $menuBar,
        private readonly CodeEditorWidget $editor,
        private readonly FileTreeWidget $tree,
        private readonly TabBarWidget $tabBar,
        private readonly ImageViewerWidget $imageViewer,
        private readonly HelpViewerWidget $helpViewer,
        private readonly BufferManager $buffers,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly DockTabsWidget $dockTabs,
        private readonly TerminalSessions $sessions,
        private readonly ChangesListWidget $changes,
        private readonly HistoryListWidget $history,
    ) {}

    /** Open the focused pane's context menu, or flash when the pane has none. */
    public function openForFocus(): void
    {
        $focus = $this->tui->getFocus();

        if ($focus === $this->editor) {
            $rect = $this->renderer->getWidgetRect($this->editor);
            if (null !== $rect) {
                $cell = $this->editor->caretScreenCell();
                // One row below the caret, so the caret line stays readable.
                $this->openAt($this->items->editorItems(), $rect->row + $cell['row'] + 1, $rect->col + $cell['col']);
            }

            return;
        }

        if ($focus === $this->tree) {
            $node = $this->tree->selectedNode();
            $rect = $this->renderer->getWidgetRect($this->tree);
            if (null !== $node && null !== $rect) {
                $this->openAt($this->treeOps->contextItems($node), $rect->row + $this->tree->selectedRow() + 1, $rect->col + 2);
            }

            return;
        }

        if (($focus === $this->imageViewer || $focus === $this->helpViewer) && null !== ($active = $this->buffers->active())) {
            $rect = $this->renderer->getWidgetRect($this->tabBar);
            $index = array_search($active, $this->buffers->all(), strict: true);
            // A scrolled-out active tab falls back to the strip's origin cell.
            $anchor = (false === $index ? null : $this->tabBar->tabAnchor($index)) ?? ['row' => 0, 'col' => 0];
            // One row below the tab; a bottom strip anchors on its own row so the
            // overlay's overflow flip opens the menu above it instead.
            $row = TabBarPosition::Bottom === $this->tabBar->position() ? $anchor['row'] : $anchor['row'] + 1;
            $this->openAt($this->items->tabItems($active->path), (null === $rect ? 0 : $rect->row) + $row, (null === $rect ? 0 : $rect->col) + $anchor['col']);

            return;
        }

        if ($focus === $this->terminalPanel && null !== ($session = $this->sessions->active())) {
            $rect = $this->renderer->getWidgetRect($this->dockTabs);
            // Anchor on the strip row itself: the overlay's overflow flip
            // opens the menu above the bottom strip.
            $col = $this->dockTabs->anchorFor(DockTab::Terminal, $session->id) ?? 0;
            $this->openAt($this->items->dockTabItems(DockTab::Terminal, $session->id), null === $rect ? 0 : $rect->row, (null === $rect ? 0 : $rect->col) + $col);

            return;
        }

        if ($focus === $this->changes) {
            $entry = $this->changes->selectedEntry();
            $rect = $this->renderer->getWidgetRect($this->changes);
            if (null !== $entry && null !== $rect) {
                $this->openAt($this->items->gitChangeItems($entry), $rect->row + $this->changes->selectedRow() + 1, $rect->col + 2);
            }

            return;
        }

        if ($focus === $this->history) {
            $commit = $this->history->selectedCommit();
            $rect = $this->renderer->getWidgetRect($this->history);
            if (null !== $commit && null !== $rect) {
                $this->openAt($this->items->gitCommitItems($commit), $rect->row + $this->history->selectedCommitRow() + 1, $rect->col + 2);
            }

            return;
        }

        $this->flashNoMenu();
    }

    /**
     * Say so when a pane has no menu. Both chains use it: a right-click on a
     * menu-less pane used to do nothing at all while Ctrl+F10 explained
     * itself, which left the mouse looking broken rather than answered.
     */
    public function flashNoMenu(): void
    {
        // A status-bar hint, not a toast: "nothing here" is transient state
        // whispering, not an event worth a card or a log entry.
        $this->notifier->hint($this->translator->trans('No context menu here'));
    }

    /**
     * Open a built menu at a screen cell: close the app menu, anchor, open,
     * repaint. The single finish for both the keyboard and the mouse.
     *
     * @param list<MenuItem> $items
     */
    public function openAt(array $items, int $row, int $col): void
    {
        $this->menuBar->close();
        $this->overlay->setContextAnchor($row, $col);
        $this->contextMenu->open($items);
        $this->tui->requestRender();
    }
}
