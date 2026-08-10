<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Tui;

/**
 * The Shift+F11/F12 resize nudge for whichever resizable pane holds the
 * focus. Sidebars move 2 columns per press, bottom panels 1 row; a
 * non-resizable focus answers false so the chord falls through to the widget.
 */
final class PaneResizer
{
    public function __construct(
        private readonly Tui $tui,
        private readonly LayoutController $layout,
        private readonly FileTreeWidget $tree,
        private readonly BottomDock $dock,
        private readonly ViewRegions $regions,
    ) {}

    public function canResize(): bool
    {
        return null !== $this->resizer(0);
    }

    public function resize(int $direction): void
    {
        if (null !== ($resize = $this->resizer($direction))) {
            $resize();
        }
    }

    /**
     * The view whose sidebar holds $focus, or null.
     *
     * Two hardcoded three-widget lists stood here, one per view core happened
     * to know. A region's sidebar IS the list — whatever the view stacked in
     * it — so a contributed view's panes answer the same question.
     */
    private function viewOwning(?object $focus): ?string
    {
        // No null guard: a sidebar's children are widgets, so a null focus
        // matches nothing and falls out of the loop on its own.
        foreach ($this->regions->all() as $region) {
            if (null !== $region->sidebarPanes() && \in_array($focus, $region->sidebar()->all(), strict: true)) {
                return $region->id;
            }
        }

        return null;
    }

    private function resizer(int $direction): ?\Closure
    {
        $focus = $this->tui->getFocus();

        return match (true) {
            $focus === $this->tree => fn () => $this->layout->nudgeSidebar(2 * $direction),
            null !== $focus && $focus === $this->dock->visiblePanel() => fn () => $this->layout->nudgeBottomPanel($direction),
            null !== ($view = $this->viewOwning($focus)) => fn () => $this->layout->nudgeViewSidebar($view, 2 * $direction),
            default => null,
        };
    }
}
