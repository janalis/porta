<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Overlay\Region\PaneBounds;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\ModalShadow;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * The delivery half every overlay presenter shares: the pane-union bounds,
 * the per-pane slice loop (local spans + shadow bands) and the clear sweep.
 * The presenters keep what differs — their open gate, their clear-once
 * `$shown` guard (each clears only what it painted; the modal slot is shared
 * under the view registry's interlock) and their state source; this owns only
 * "push these boxes onto the panes". Delivery targets the CURRENT pane set,
 * clearing targets ALL panes — a surface may close across a view swap.
 */
final class OverlayDelivery
{
    use StylesDefault;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuOverlayPanes $panes,
        private readonly Tui $tui,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
    }

    /**
     * The pane union the floating surfaces lay out against, or null before
     * the first frame's rects land — in which case another frame is
     * requested, so the caller can simply return and retry on the next one.
     *
     * @return array{int, int, int, int}|null [top, left, bottom, right)
     */
    public function bounds(): ?array
    {
        $bounds = PaneBounds::union($this->panes->current(), $this->renderer);
        if (null === $bounds) {
            // No rects yet: keep asking for frames until the first one lands.
            $this->tui->requestRender();
        }

        return $bounds;
    }

    /**
     * Push the boxes through every current pane's modal span slot, dimming
     * the panes beneath them.
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int}> $boxes
     */
    public function deliverModal(array $boxes): void
    {
        foreach ($this->panes->current() as $pane) {
            $pane->setModalOverlay($this->spans($boxes, $this->renderer->getWidgetRect($pane)));
            $pane->setModalBackdrop(true);
        }
    }

    /** Clear the modal slot and the backdrop on every pane set. */
    public function clearModal(): void
    {
        foreach ($this->panes->all() as $pane) {
            $pane->setModalOverlay([]);
            $pane->setModalBackdrop(false);
        }
    }

    /**
     * Push the boxes through the toast span slot — above the modal spans,
     * below the menu dropdowns, and never dimming (toasts are passive).
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int}> $boxes
     */
    public function deliverToast(array $boxes): void
    {
        foreach ($this->panes->current() as $pane) {
            $pane->setToastOverlay($this->spans($boxes, $this->renderer->getWidgetRect($pane)));
        }
    }

    /** Clear the toast slot on every pane set. */
    public function clearToast(): void
    {
        foreach ($this->panes->all() as $pane) {
            $pane->setToastOverlay([]);
        }
    }

    /**
     * One pane's slice of every box: the box rows clipped to the pane's rect
     * plus the shadow bands, empty while the pane has no rect yet.
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int}> $boxes
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    private function spans(array $boxes, ?WidgetRect $rect): array
    {
        if (null === $rect) {
            return [];
        }
        $spans = [];
        foreach ($boxes as $box) {
            $spans = [
                ...$spans,
                ...SpanSlicer::localSpans($box['rows'], $rect, $box['top'], $box['col'], $box['width']),
                ...ModalShadow::spans($rect, $box['top'], $box['col'], $box['width'], \count($box['rows']), $this->styles),
            ];
        }

        return $spans;
    }
}
