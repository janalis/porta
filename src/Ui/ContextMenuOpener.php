<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Overlay\MenuOverlayController;
use Porta\Tree\TreeOperations;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Opens the context menu from the keyboard (Ctrl+F10) for whatever holds the
 * focus: the editor's menu anchored at the caret, the tree's at its selected
 * node, and the image/help viewers' tab menu under the active tab. The mouse
 * router's right-click path builds the same item lists; this class only adds
 * the anchor-from-focus geometry, then finishes exactly like a right-click
 * (anchor → open → repaint), so the modal keyboard routing and the overlay
 * compositing need no changes. Unlike a right-click, the caret and selection
 * are never moved.
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

        $this->notifier->flash($this->translator->trans('No context menu here'));
    }

    /**
     * Finish exactly like the mouse path: close the app menu, anchor, open, repaint.
     *
     * @param list<MenuItem> $items
     */
    private function openAt(array $items, int $row, int $col): void
    {
        $this->menuBar->close();
        $this->overlay->setContextAnchor($row, $col);
        $this->contextMenu->open($items);
        $this->tui->requestRender();
    }
}
