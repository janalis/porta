<?php

declare(strict_types=1);

namespace Porta\Overlay;

use Porta\Support\IntMath;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Positions the app-menu dropdown and the right-click context menu as floating
 * overlays and composites them onto the panes they cover, so an open menu never
 * reflows the layout. On every open/navigate/close it computes the panel
 * geometry (anchored under its title / at the click point, clamped to the editor
 * pane, flipping up on bottom overflow), slices it into per-widget spans via
 * {@see localSpans}, and pushes them onto each pane through `setMenuOverlay`.
 * The tracked dropdown rectangles are exposed for the mouse router's hit-testing.
 */
final class MenuOverlayController implements FloatingRegions
{
    private ?DropdownRegion $menuDropdown = null;
    private ?DropdownRegion $contextMenuDropdown = null;
    private int $contextAnchorRow = 0;
    private int $contextAnchorCol = 0;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly MenuOverlayPanes $panes,
    ) {}

    /** The app-menu dropdown box, for click/hover hit-testing; null when closed. */
    public function menuDropdown(): ?DropdownRegion
    {
        return $this->menuDropdown;
    }

    /** The context-menu dropdown box, for click hit-testing; null when closed. */
    public function contextMenuDropdown(): ?DropdownRegion
    {
        return $this->contextMenuDropdown;
    }

    public function regions(): array
    {
        return array_values(array_filter([$this->menuDropdown(), $this->contextMenuDropdown()]));
    }

    /** Remember the right-click point the context menu anchors at. */
    public function setContextAnchor(int $row, int $col): void
    {
        $this->contextAnchorRow = $row;
        $this->contextAnchorCol = $col;
    }

    /**
     * Position the open menu's dropdown as a floating overlay over the panes
     * beneath the (full-width) menu row so it never reflows the layout. Called
     * on every open/navigate/close; clears the overlay when no menu is open.
     */
    public function refreshMenu(): void
    {
        if (!$this->menuBar->isOpen()) {
            $this->menuDropdown = null;
            // Clear every mode's pane set: clearing a detached widget is harmless
            // and a dropdown may close across a view swap.
            $this->clearOverlays($this->panes->all());

            return;
        }

        $menuRect = $this->renderer->getWidgetRect($this->menuBar);
        if (null === $menuRect) {
            return; // no frame rendered yet; the next navigate will place it
        }

        // The menu bar is the screen's full-width top row in both views, so
        // anchor each dropdown directly under its title (titleStart is
        // menu-bar-local), clamped to the menu row's own span.
        $panelWidth = min($this->menuBar->panelWidth(), $menuRect->columns);
        $anchor = max(
            $menuRect->col,
            min($menuRect->col + $this->menuBar->titleStart($this->menuBar->openIndex()), $menuRect->col + $menuRect->columns - $panelWidth),
        );
        $top = $menuRect->row + $menuRect->rows;
        $segments = $this->menuBar->dropdownSegments($panelWidth);

        $this->menuDropdown = new DropdownRegion($top, $anchor, $panelWidth, \count($segments));
        $this->pushOverlays($segments, $top, $anchor, $panelWidth, $this->panes->current());
    }

    /**
     * Position the open context menu as a floating overlay at the right-click
     * point, composited over the current view's panes so it can appear over
     * any of them (the editor layout's, the git view's, the settings view's).
     * Clamped to the screen's right/bottom edges, flipping up when it would
     * overflow the bottom. Clears the overlay when the menu is closed.
     */
    public function refreshContextMenu(): void
    {
        if (!$this->contextMenu->isOpen()) {
            $this->contextMenuDropdown = null;
            // Clear every mode's pane set: a menu may close across a view swap.
            $this->clearOverlays($this->panes->all());

            return;
        }

        // Clamp/flip against the union of the current view's pane rects: a
        // slice outside every overlay host would simply not render. The menu
        // only ever opens from a right-click / Ctrl+F10, i.e. after at least
        // one frame, so the current panes always have rects.
        $screenRight = 0;
        $screenBottom = 0;
        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            if (null === $rect) {
                continue;
            }
            $screenRight = max($screenRight, $rect->col + $rect->columns);
            $screenBottom = max($screenBottom, $rect->row + $rect->rows);
        }
        $panelWidth = min($this->contextMenu->panelWidth(), $screenRight);
        $col = IntMath::clamp($this->contextAnchorCol, 0, $screenRight - $panelWidth);
        $count = $this->contextMenu->itemCount();
        $top = ($this->contextAnchorRow + $count) > $screenBottom
            ? max(0, $this->contextAnchorRow - $count)
            : $this->contextAnchorRow;

        $segments = $this->contextMenu->segments($panelWidth);
        $this->contextMenuDropdown = new DropdownRegion($top, $col, $panelWidth, \count($segments));
        // A pane set includes its separator columns and conditionally hidden
        // rows (e.g. the breadcrumb), so a menu spilling across a pane
        // boundary still composites gaplessly.
        $this->pushOverlays($segments, $top, $col, $panelWidth, $this->panes->current());
    }

    /** @param list<AbstractWidget&MenuOverlayHost> $panes */
    private function clearOverlays(array $panes): void
    {
        foreach ($panes as $pane) {
            $pane->setMenuOverlay([]);
        }
    }

    /**
     * Slice the dropdown into per-pane local spans and push them; panes without
     * a rect this frame (e.g. the breadcrumb hidden outside every scope) clear.
     *
     * @param list<array{segment: string, separator: bool}> $segments
     * @param list<AbstractWidget&MenuOverlayHost>           $panes
     */
    private function pushOverlays(array $segments, int $top, int $anchor, int $panelWidth, array $panes): void
    {
        foreach ($panes as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            $pane->setMenuOverlay(null !== $rect ? $this->localSpans($segments, $rect, $top, $anchor, $panelWidth) : []);
        }
    }

    /**
     * Translate absolute dropdown segments to $rect-local overlay spans, keeping
     * only the rows that fall inside the widget and clipping each segment to the
     * widget's columns. Clipping matters for context menus, whose panel can be
     * anchored over one pane (e.g. the narrow tree) while spilling toward another;
     * without it a segment wider than the host widget would blow out its line
     * width and wreck the layout. Panels that do not overlap the widget at all
     * (e.g. the editor menu vs. the tree) yield no spans.
     *
     * @param list<array{segment: string, separator: bool}> $segments
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    private function localSpans(array $segments, WidgetRect $rect, int $top, int $anchor, int $panelWidth): array
    {
        // Horizontal overlap of the panel [anchor, anchor+panelWidth) with the rect.
        $start = max($anchor, $rect->col);
        $end = min($anchor + $panelWidth, $rect->col + $rect->columns);
        if ($start >= $end) {
            return []; // the panel does not touch this widget
        }
        $clipOffset = $start - $anchor;
        $clipWidth = $end - $start;
        $localCol = $start - $rect->col;
        $clipped = $clipOffset > 0 || $clipWidth < $panelWidth;

        $spans = [];
        foreach ($segments as $i => $segment) {
            $localRow = $top + $i - $rect->row;
            if ($localRow >= 0 && $localRow < $rect->rows) {
                $spans[] = [
                    'row' => $localRow,
                    'col' => $localCol,
                    'width' => $clipWidth,
                    'segment' => $clipped
                        ? AnsiUtils::sliceByColumn($segment['segment'], $clipOffset, $clipWidth)
                        : $segment['segment'],
                ];
            }
        }

        return $spans;
    }
}
