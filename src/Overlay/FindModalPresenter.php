<?php

declare(strict_types=1);

namespace Porta\Overlay;

use Porta\Ui\BidiTui;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Render\ModalShadow;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Presents the Find-in-Files modal over the whole workspace — the same
 * per-pane span path the Welcome modal uses, so the box floats (and drags)
 * over the tree, the tab bar and the dock instead of being clipped to the
 * editor pane it used to be composited into. The modal itself
 * ({@see \Porta\Widget\Overlay\FindInFilesModal}) keeps all state, layout
 * and hit-testing; this presenter only delivers: panel → spans + shadow,
 * plus the pane-level backdrop dim.
 *
 * Re-composed on every frame while open ({@see BidiTui::onBeforeFrame}) —
 * the modal's content streams in asynchronously (search results) and its
 * caret blinks, so a per-frame rebuild matches the cost it had as an
 * editor-composited overlay. The two floating modals share the pane span
 * slot safely: the WorkspaceViews interlock means they are never open
 * together, and each clears only what it painted (`$shown`).
 *
 * Resolved eagerly by {@see \Porta\Ide} — nothing else references it, and
 * without the eager resolve the modal would never paint.
 */
final class FindModalPresenter
{
    /** Whether this presenter's spans are currently painted (its clear-once guard). */
    private bool $shown = false;

    private ?DropdownRegion $shadowRegion = null;

    private readonly Styles $styles;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuOverlayPanes $panes,
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        if ($this->tui instanceof BidiTui) {
            $this->tui->onBeforeFrame($this->sync(...));
        }
    }

    /**
     * The box (shadow bands included) as an LTR floating region: the modal
     * carries code content, so under RTL it stays verbatim — its own run,
     * never mirrored with the prose panes beneath.
     */
    public function region(): ?DropdownRegion
    {
        return $this->editor->isFindModalOpen() ? $this->shadowRegion : null;
    }

    /**
     * The per-frame delivery: lay the modal out against the pane union and
     * push its spans, shadow and backdrop; clear them all exactly once after
     * it closes (never every frame — the Welcome modal shares the span slot).
     */
    public function sync(): void
    {
        if (!$this->editor->isFindModalOpen()) {
            if ($this->shown) {
                $this->shown = false;
                $this->shadowRegion = null;
                foreach ($this->panes->all() as $pane) {
                    $pane->setModalOverlay([]);
                    $pane->setModalBackdrop(false);
                }
            }

            return;
        }

        $bounds = $this->paneBounds();
        if (null === $bounds) {
            // No rects yet: keep asking for frames until the first one lands.
            $this->tui->requestRender();

            return;
        }
        // The modal owns the caret while open, wherever pane focus sits.
        $placed = $this->editor->findModal()->layout($bounds, CaretVisibility::Shown);
        \assert(null !== $placed, description: 'layout() is non-null while the modal is open — checked above');
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $width = $this->editor->findModal()->lastBox()?->width ?? 0;
        $rows = \count($placed['rows']);

        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            $pane->setModalOverlay(
                null !== $rect
                    ? [
                        ...SpanSlicer::localSpans($placed['rows'], $rect, $placed['top'], $placed['col'], $width),
                        ...ModalShadow::spans($rect, $placed['top'], $placed['col'], $width, $rows, $this->styles),
                    ] : [],
            );
            $pane->setModalBackdrop(true);
        }
        $this->shown = true;
        // Cover only the shadow bands that actually paint, so the RTL
        // segmenter never claims cells outside the modal.
        $this->shadowRegion = new DropdownRegion(
            $placed['top'],
            $placed['col'],
            min($width + 1, $boundRight - $placed['col']),
            min($rows + 1, $boundBottom - $placed['top']),
            ltr: true,
        );
    }

    /**
     * The union of the current pane set's rects — the paintable screen area.
     *
     * @return array{int, int, int, int}|null [top, left, bottom, right), or null before the first frame
     */
    private function paneBounds(): ?array
    {
        $top = PHP_INT_MAX;
        $left = PHP_INT_MAX;
        $bottom = 0;
        $right = 0;
        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            if (null === $rect) {
                continue;
            }
            $top = min($top, $rect->row);
            $left = min($left, $rect->col);
            $bottom = max($bottom, $rect->row + $rect->rows);
            $right = max($right, $rect->col + $rect->columns);
        }

        return $bottom > $top && $right > $left ? [$top, $left, $bottom, $right] : null;
    }
}
