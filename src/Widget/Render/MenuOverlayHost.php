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
}
