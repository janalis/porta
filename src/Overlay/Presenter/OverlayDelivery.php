<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Overlay\Region\PaneBounds;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Overlay\State\FloatingLayer;
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
 *
 * Under a right-to-left locale the boxes go to the {@see FloatingLayer}
 * instead, and the panes keep only the backdrop dim: a surface composited into
 * a pane that is then mirrored away from it leaves a blank hole behind.
 */
final class OverlayDelivery
{
    use StylesDefault;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuOverlayPanes $panes,
        private readonly Tui $tui,
        ?Styles $styles = null,
        // Whether the screen is mirrored: a mirrored surface casts its shadow
        // the other way before the flip, so it still lands bottom-right.
        private readonly Translator $translator = new Translator('en'),
        private readonly FloatingLayer $layer = new FloatingLayer(),
    ) {
        $this->initStyles($styles);
    }

    /**
     * The direction a surface reaches the screen in: mirrored under a
     * right-to-left locale, unless the surface is verbatim left-to-right.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the box is verbatim is the one fact this answers on.
    public function shown(bool $verbatim = false): BaseDirection
    {
        return $this->translator->isRtl() && !$verbatim ? BaseDirection::Rtl : BaseDirection::Ltr;
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
     * Push a view window's boxes through every current pane's window slot —
     * the lowest floating layer, so a picker opened over it floats above —
     * dimming the panes beneath. The modal slot is left alone: a picker
     * open over the window keeps its own box and dim.
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int, ltr?: bool}> $boxes
     */
    public function deliverWindow(array $boxes): void
    {
        $mirrored = $this->translator->isRtl();
        foreach ($this->panes->current() as $pane) {
            $pane->setWindowOverlay($mirrored ? [] : $this->spans($boxes, $this->renderer->getWidgetRect($pane)));
            $pane->setWindowBackdrop(true);
        }
        $this->onLayer($this->layer->setWindow($mirrored ? $this->spans($boxes, FloatingLayer::rect()) : []));
    }

    /** Clear the window slot and its backdrop on every pane. */
    public function clearWindow(): void
    {
        foreach ($this->panes->current() as $pane) {
            $pane->setWindowOverlay([]);
            $pane->setWindowBackdrop(false);
        }
        $this->onLayer($this->layer->setWindow([]));
    }

    /**
     * Push the boxes through every current pane's modal span slot, dimming
     * the panes beneath them. A box marked `ltr` is verbatim, never mirrored,
     * and casts its shadow as it would left-to-right.
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int, ltr?: bool}> $boxes
     */
    public function deliverModal(array $boxes): void
    {
        $mirrored = $this->translator->isRtl();
        foreach ($this->panes->current() as $pane) {
            $pane->setModalOverlay($mirrored ? [] : $this->spans($boxes, $this->renderer->getWidgetRect($pane)));
            $pane->setModalBackdrop(true);
        }
        $this->onLayer($this->layer->setModal($mirrored ? $this->spans($boxes, FloatingLayer::rect()) : []));
    }

    /** Clear the modal slot and the backdrop on every pane set. */
    public function clearModal(): void
    {
        foreach ($this->panes->current() as $pane) {
            $pane->setModalOverlay([]);
            $pane->setModalBackdrop(false);
        }
        $this->onLayer($this->layer->setModal([]));
    }

    /**
     * Push the boxes through the toast span slot — above the modal spans,
     * below the menu dropdowns, and never dimming (toasts are passive).
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int}> $boxes
     */
    public function deliverToast(array $boxes): void
    {
        $mirrored = $this->translator->isRtl();
        foreach ($this->panes->current() as $pane) {
            $pane->setToastOverlay($mirrored ? [] : $this->spans($boxes, $this->renderer->getWidgetRect($pane)));
        }
        $this->onLayer($this->layer->setToast($mirrored ? $this->spans($boxes, FloatingLayer::rect()) : []));
    }

    /** Clear the toast slot on every pane set. */
    public function clearToast(): void
    {
        foreach ($this->panes->current() as $pane) {
            $pane->setToastOverlay([]);
        }
        $this->onLayer($this->layer->setToast([]));
    }

    /**
     * Push the boxes through the tooltip span slot — above the toasts, below
     * the menu dropdowns, casting no shadow: a card flush against its element
     * would lay the shadow over the very thing it explains.
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int}> $boxes
     */
    public function deliverTooltip(array $boxes): void
    {
        $mirrored = $this->translator->isRtl();
        foreach ($this->panes->current() as $pane) {
            $pane->setTooltipOverlay($mirrored ? [] : $this->spans($boxes, $this->renderer->getWidgetRect($pane), shadow: false));
        }
        $this->onLayer($this->layer->setTooltip($mirrored ? $this->spans($boxes, FloatingLayer::rect(), shadow: false) : []));
    }

    /** Clear the tooltip slot on every pane set. */
    public function clearTooltip(): void
    {
        foreach ($this->panes->current() as $pane) {
            $pane->setTooltipOverlay([]);
        }
        $this->onLayer($this->layer->setTooltip([]));
    }

    /** A changed layer needs the frame a pane would have asked for by invalidating itself. */
    // @mago-expect lint:no-boolean-flag-parameter -- the one fact: whether the layer's slot changed.
    private function onLayer(bool $changed): void
    {
        if ($changed) {
            $this->tui->requestRender();
        }
    }

    /**
     * One pane's slice of every box: the box rows clipped to the pane's rect
     * plus the shadow bands, empty while the pane has no rect yet.
     *
     * @param list<array{rows: list<string>, top: int, col: int, width: int, ltr?: bool}> $boxes
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether a surface casts a shadow is the one thing the slots differ in here.
    private function spans(array $boxes, ?WidgetRect $rect, bool $shadow = true): array
    {
        if (null === $rect) {
            return [];
        }
        $spans = [];
        foreach ($boxes as $box) {
            $spans = [
                ...$spans,
                ...SpanSlicer::localSpans($box['rows'], $rect, $box['top'], $box['col'], $box['width']),
                ...($shadow ? ModalShadow::spans($rect, $box['top'], $box['col'], $box['width'], \count($box['rows']), $this->styles, $this->shown($box['ltr'] ?? false)) : []),
            ];
        }

        return $spans;
    }
}
