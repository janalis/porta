<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Tree\TreeOperations;
use Porta\Ui\BidiTui;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Render\PopupFrame;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\DockTab;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;

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
final readonly class ContextMenuOpener
{
    public function __construct(
        private BidiTui $tui,
        private Renderer $renderer,
        private ContextMenu $contextMenu,
        private MenuOverlayController $overlay,
        private ContextMenuBuilder $items,
        private TreeOperations $treeOps,
        private MenuBarWidget $menuBar,
        // Deliberately concrete: caretScreenCell (the keyboard-invoked menu's
        // anchor) lives in no role interface — a one-consumer role is not worth one.
        private CodeEditorWidget $editor,
        private FileTreeWidget $tree,
        private TabBarWidget $tabBar,
        private BufferManager $buffers,
        private Notifier $notifier,
        private Translator $translator,
        private TerminalPanelWidget $terminalPanel,
        private DockTabsWidget $dockTabs,
        private TerminalSessions $sessions,
        private ViewMenuSources $viewMenus,
        private ViewRegistry $views,
        private StatusBarWidget $statusBar,
        private BreadcrumbsWidget $breadcrumbs,
    ) {}

    /**
     * PhpStorm's Jump to Navigation Bar (Alt+Home): the breadcrumb trail as a
     * menu under the bar, each crumb doing what a click on it does — a folder
     * or file is shown in the tree, a symbol is gone to. From the keyboard the
     * focus follows, so the next key acts where you went.
     */
    public function openNavigationBar(): void
    {
        $rect = $this->renderer->getWidgetRect($this->breadcrumbs);
        $trail = $this->breadcrumbs->trail();
        if (null === $rect || [] === $trail) {
            $this->flashNoMenu();

            return;
        }
        $items = \array_map(fn (array $crumb): MenuItem => new MenuItem($crumb['label'], '', function () use ($crumb): void {
            $path = $crumb['path'] ?? null;
            if (null !== $path) {
                $this->tree->revealPath($path);
                $this->tui->setFocus($this->tree);

                return;
            }
            if (null !== $crumb['line']) {
                $this->tui->setFocus($this->editor);
                $this->editor->jumpTo($crumb['line'], 0);
            }
        }), $trail);
        $this->openAt($items, $rect->row + 1, $rect->col);
    }

    /**
     * The status bar's menus from the keyboard: every cell's, in one menu,
     * each group under the cell's own text — the branch's, a server's, a
     * participant's — above the status bar as a click on one would open it.
     */
    public function openStatusMenus(): void
    {
        $rect = $this->renderer->getWidgetRect($this->statusBar);
        $items = [];
        foreach ($this->statusBar->menus() as $span) {
            if ([] !== $items) {
                $items[] = MenuItem::separator();
            }
            $items[] = new MenuItem($span->label(), '', null, isEnabled: static fn (): bool => false);
            \array_push($items, ...($span->items)());
        }
        if (null === $rect || [] === $items) {
            $this->flashNoMenu();

            return;
        }
        $this->openAt($items, $rect->row, $rect->col + 1);
    }

    /** Open the focused pane's context menu, or flash when the pane has none. */
    public function openForFocus(): void
    {
        $focus = $this->tui->getFocus();
        $active = null;
        $session = null;

        match (true) {
            $focus === $this->editor => $this->openForEditor(),
            $focus === $this->tree => $this->openForTree(),
            // ANY focused pane, not two of the three: a right-click over a focused
            // graph pane used to fall through and open nothing, because this list
            // named the image and help viewers and was never revisited.
            $focus instanceof EditorPaneWidget && null !== ($active = $this->buffers->active()) => $this->openForTab($active),
            $focus === $this->terminalPanel && null !== ($session = $this->sessions->active()) => $this->openForSession($session),
            // The open view answers for its own focused pane, anchor included.
            // Two branches stood here comparing focus against a git widget by
            // identity and doing that view's anchor arithmetic in core.
            default => $this->openForView(),
        };
    }

    private function openForEditor(): void
    {
        $rect = $this->renderer->getWidgetRect($this->editor);
        if (null !== $rect) {
            $cell = $this->editor->caretScreenCell();
            // One row below the caret, so the caret line stays readable.
            $this->openAt($this->items->editorItems(), $rect->row + $cell['row'] + 1, $rect->col + $cell['col']);
        }
    }

    private function openForTree(): void
    {
        $node = $this->tree->selectedNode();
        $rect = $this->renderer->getWidgetRect($this->tree);
        if (null !== $node && null !== $rect) {
            $this->openAt($this->treeOps->contextItems($node), $rect->row + $this->tree->selectedRow() + 1, $rect->col + 2);
        }
    }

    private function openForTab(Buffer $active): void
    {
        $rect = $this->renderer->getWidgetRect($this->tabBar);
        $index = \array_search($active, $this->buffers->all(), strict: true);
        // A scrolled-out active tab falls back to the strip's origin cell.
        $anchor = (false === $index ? null : $this->tabBar->tabAnchor($index)) ?? ['row' => 0, 'col' => 0, 'rows' => 1];
        // One row below the tab; a bottom strip anchors on its own top row so
        // the overlay's overflow flip opens the menu above it instead.
        $row = TabBarPosition::Bottom === $this->tabBar->position() ? $anchor['row'] : $anchor['row'] + $anchor['rows'];
        $this->openAt($this->items->tabItems($active->path), (null === $rect ? 0 : $rect->row) + $row, (null === $rect ? 0 : $rect->col) + $anchor['col']);
    }

    private function openForSession(TerminalSession $session): void
    {
        $rect = $this->renderer->getWidgetRect($this->dockTabs);
        // Anchor on the strip row itself: the overlay's overflow flip
        // opens the menu above the bottom strip.
        $col = $this->dockTabs->anchorFor(DockTab::Terminal, $session->id) ?? 0;
        $this->openAt($this->items->dockTabItems(DockTab::Terminal, $session->id), null === $rect ? 0 : $rect->row, (null === $rect ? 0 : $rect->col) + $col);
    }

    private function openForView(): void
    {
        $menu = $this->viewMenus->of($this->views->activeId())?->focusedContextMenu();
        null !== $menu ? $this->openAt($menu->items, $menu->row, $menu->col) : $this->flashNoMenu();
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
        // The anchor is a LOGICAL cell (the router maps the pointer back);
        // under a right-to-left frame the menu, mirrored as a whole, must be
        // anchored where that cell was DRAWN — it opened across the screen
        // from the pointer, at the far edge.
        $this->overlay->setContextAnchor($row, $this->tui->floatingColumn($row, $col, 1, MenuPanel::width($items) + PopupFrame::CHROME_COLS));
        $this->contextMenu->open($items);
        $this->tui->requestRender();
    }
}
