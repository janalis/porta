<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Ui\BidiTui;
use Porta\Workbench\DropZone;
use Porta\Workbench\View\ViewRegistry;

/**
 * What lies under a screen cell for a drop from outside: the zone, the tree
 * folder, and the caret placed where an insert would go.
 *
 * Both ways a terminal can say WHERE a drop is come through here. kitty
 * reports the pointer all through the drag ({@see DragDropProtocol}), so
 * {@see aim()} runs on every motion and the tree highlight, the spring-loaded
 * folders and the caret follow it. Ghostty reports nothing during the drag,
 * but the pointer's position arrives a moment after the paste that carries the
 * paths ({@see PasteDrop}), so the drop still lands where it was let go.
 */
#[AsIdeService]
final class DropTarget
{
    /** The tree folder the drag is over, from its last aim; null off the tree. */
    private ?string $folder = null;

    /** @var array{doc: Document, line: int, col: int, range: array{startLine: int, startCol: int, endLine: int, endCol: int}|null}|null the caret an aim over the text moved, to put back */
    private ?array $caret = null;

    public function __construct(
        private readonly HitTester $hit,
        private readonly EditorLayoutPanes $panes,
        private readonly ViewRegistry $views,
        private readonly WelcomeModalController $welcome,
        private readonly BufferManager $buffers,
        private readonly BidiTui $bidi,
        private readonly TreeDragRouter $treeDrag,
        private readonly string $rootDir,
    ) {}

    /**
     * The zone under a screen cell, or null where a drop does nothing: the
     * dock, the status bar, the menu. A modal takes it whole wherever it lands
     * — a picker or a view such as Settings types the paths into the field
     * that has the keys, and the Welcome card opens them, for the empty editor
     * it stands in for.
     *
     * Aiming is also the hover. Over the tree the highlight and the spring
     * follow; anywhere else the drag is not over the tree, which ends them.
     * Over an open file the caret moves under the pointer, and anywhere else
     * it goes back where it was.
     */
    public function aim(int $row, int $screenCol): ?DropZone
    {
        $zone = $this->zoneAt($row, $screenCol);
        $rel = DropZone::Editor === $zone ? $this->hit->hit($this->panes->editor, $row, $this->bidi->logicalColumn($row, $screenCol)) : null;
        if (null === $rel) {
            $this->restoreCaret();

            return $zone;
        }
        $editor = $this->panes->editor;
        $doc = $editor->getDocument();
        $this->caret ??= ['doc' => $doc, 'line' => $doc->cursorLine, 'col' => $doc->cursorCol, 'range' => $doc->getSelectionRange()];
        // A mouse press's placement, its empty selection dropped — not a
        // click, which on the error stripe jumps and on a lens row runs it.
        if ($rel['col'] < $editor->viewport()->stripeCol) {
            $editor->beginSelectionAt($rel['row'], $rel['col']);
            $doc->clearSelection();
        }

        return $zone;
    }

    /** The drag left the window: no highlight, no folders held open, the caret back. */
    public function leave(): void
    {
        $this->treeDrag->endExternal();
        $this->restoreCaret();
    }

    /**
     * The drag was let go in $zone, as the last {@see aim()} found it: end the
     * hover, keep the caret where it now is, and say which tree folder it
     * landed in — null off the tree. Below the last row is still the tree:
     * the project root is the folder that holds everything shown there.
     */
    public function land(?DropZone $zone): ?string
    {
        $folder = DropZone::Tree === $zone ? $this->folder ?? $this->rootDir : null;
        $this->treeDrag->endExternal($folder);
        $this->caret = null;

        return $folder;
    }

    /**
     * Put back the caret — and the selection — an aim over the text moved.
     * Into the document it was taken from, so a file switched away from
     * meanwhile gets its own caret back for when it is shown again.
     */
    private function restoreCaret(): void
    {
        if (null === $this->caret) {
            return;
        }
        ['doc' => $doc, 'line' => $line, 'col' => $col, 'range' => $range] = $this->caret;
        $this->caret = null;
        null === $range
            ? $doc->setCursor($line, $col)
            : $doc->setSelectionRange($range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']);
        $this->panes->editor->invalidate();
    }

    private function zoneAt(int $row, int $screenCol): ?DropZone
    {
        $this->folder = null;
        $zone = $this->welcome->isOpen() ? DropZone::Empty : $this->zoneUnder($row, $this->bidi->logicalColumn($row, $screenCol));
        if (DropZone::Tree !== $zone) {
            $this->treeDrag->endExternal();
        }

        return $zone;
    }

    /** The zone under a logical column, the welcome modal being shut. */
    private function zoneUnder(int $row, int $col): ?DropZone
    {
        $pane = $this->panes->pane();

        return match (true) {
            $this->views->isAnyOpen() || $this->panes->editor->isAnyPickerOpen() => DropZone::Text,
            $this->hoverTree($row, $col) => DropZone::Tree,
            null !== $this->hit->hit($this->panes->tabBar, $row, $col) => DropZone::Empty,
            null === $this->hit->hit($this->panes->editor, $row, $col) && (null === $pane || null === $this->hit->hit($pane, $row, $col)) => null,
            null === $pane && null !== $this->buffers->active() => DropZone::Editor,
            default => DropZone::Empty,
        };
    }

    /** True over the tree, the folder under the pointer then the drop's target. */
    private function hoverTree(int $row, int $col): bool
    {
        $treeRect = $this->hit->rect($this->panes->tree);
        if (null === $treeRect || !$treeRect->contains($row, $col)) {
            return false;
        }
        $this->folder = $this->treeDrag->hoverExternal($row, $col, $treeRect);

        return true;
    }
}
