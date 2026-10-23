<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * A widget the menu/context-menu dropdown can float over: the overlay
 * coordinator pushes widget-local styled spans into it and the widget
 * composites them onto its rendered rows (see {@see MenuOverlay}).
 */
interface MenuOverlayHost
{
    /**
     * Floating dropdown spans (widget-local coords) to composite, or [] for none.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setMenuOverlay(array $spans): void;

    /**
     * A view window's spans (Settings, Git, Review), the lowest floating
     * layer: every modal, toast, tooltip and menu composites over a window —
     * a picker opened from one floats above it.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setWindowOverlay(array $spans): void;

    /** Whether this pane renders faint beneath the window spans (the window's backdrop dim). */
    // @mago-expect lint:no-boolean-flag-parameter -- a setter of stored state: whether a view window's backdrop dims this pane.
    public function setWindowBackdrop(bool $on): void;

    /**
     * Floating modal spans (the Welcome modal), composited *under* the menu
     * spans so an open dropdown still renders above the modal.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setModalOverlay(array $spans): void;

    /** Whether this pane renders faint beneath the modal spans (the backdrop dim). */
    // @mago-expect lint:no-boolean-flag-parameter -- a setter of stored state: whether a modal's backdrop dims this pane.
    public function setModalBackdrop(bool $on): void;

    /**
     * Floating toast spans (corner notifications), composited *above* the
     * modal spans (a toast must stay readable over a dimmed modal frame) but
     * *under* the menu dropdowns (interactive chrome wins).
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setToastOverlay(array $spans): void;

    /**
     * The floating tooltip card's spans, composited above the toasts (it
     * explains whatever the pointer rests on, a toast's ✖ included) and under
     * the menu dropdowns, which close any card by owning the pointer.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setTooltipOverlay(array $spans): void;
}
