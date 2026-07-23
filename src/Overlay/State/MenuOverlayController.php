<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsIdeService;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\Region\PaneBounds;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Support\IntMath;
use Porta\Ui\Theme\Styles;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\ModalShadow;
use Porta\Widget\Render\PopupFrame;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Positions the app-menu dropdown and the right-click context menu as floating
 * overlays and composites them onto the panes they cover, so an open menu never
 * reflows the layout. On every open/navigate/close it computes the panel
 * geometry (anchored under its title / at the click point, clamped to the editor
 * pane, flipping up on bottom overflow), frames it in the modal language's
 * {@see PopupFrame} (rounded border + drop shadow), slices it into per-widget
 * spans via {@see localSpans}, and pushes them onto each pane through
 * `setMenuOverlay`. The tracked dropdown rectangles are exposed for the mouse
 * router's hit-testing; the RTL segmenter sees shadow-widened copies so the
 * painted bands reorder with the box.
 */
#[AsIdeService]
final class MenuOverlayController implements FloatingRegions
{
    private ?DropdownRegion $menuDropdown = null;
    private ?DropdownRegion $contextMenuDropdown = null;
    private ?DropdownRegion $menuShadowRegion = null;
    private ?DropdownRegion $contextShadowRegion = null;
    private int $contextAnchorRow = 0;
    private int $contextAnchorCol = 0;

    private readonly Styles $styles;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly MenuOverlayPanes $panes,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
    }

    /** The app-menu dropdown box (framed), for click/hover hit-testing; null when closed. */
    public function menuDropdown(): ?DropdownRegion
    {
        return $this->menuDropdown;
    }

    /** The context-menu dropdown box (framed), for click hit-testing; null when closed. */
    public function contextMenuDropdown(): ?DropdownRegion
    {
        return $this->contextMenuDropdown;
    }

    public function regions(): array
    {
        return array_values(array_filter([$this->menuShadowRegion, $this->contextShadowRegion]));
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
            $this->menuShadowRegion = null;
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
        // menu-bar-local), clamped to the menu row's own span. The frame adds
        // a border cell each side; the top border sits directly under the
        // title, reading as the title opening the box.
        $width = min($this->menuBar->panelWidth() + PopupFrame::CHROME_COLS, $menuRect->columns);
        $anchor = max(
            $menuRect->col,
            min($menuRect->col + $this->menuBar->titleStart($this->menuBar->openIndex()), $menuRect->col + $menuRect->columns - $width),
        );
        $top = $menuRect->row + $menuRect->rows;
        $rows = PopupFrame::wrapMenu($this->menuBar->dropdownSegments($width - PopupFrame::CHROME_COLS), $width - PopupFrame::CHROME_COLS, $this->styles);

        $this->menuDropdown = new DropdownRegion($top, $anchor, $width, \count($rows), framed: true);
        $this->menuShadowRegion = $this->shadowWidened($top, $anchor, $width, \count($rows));
        $this->pushOverlays($rows, $top, $anchor, $width, $this->panes->current());
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
            $this->contextShadowRegion = null;
            // Clear every mode's pane set: a menu may close across a view swap.
            $this->clearOverlays($this->panes->all());

            return;
        }

        // Clamp/flip against the union of the current view's pane rects: a
        // slice outside every overlay host would simply not render. The menu
        // only ever opens from a right-click / Ctrl+F10, i.e. after at least
        // one frame, so the current panes always have rects.
        [, , $screenBottom, $screenRight] = PaneBounds::union($this->panes->current(), $this->renderer) ?? [0, 0, 0, 0];
        $width = min($this->contextMenu->panelWidth() + PopupFrame::CHROME_COLS, $screenRight);
        $col = IntMath::clamp($this->contextAnchorCol, 0, $screenRight - $width);
        $rows = PopupFrame::wrapMenu($this->contextMenu->segments($width - PopupFrame::CHROME_COLS), $width - PopupFrame::CHROME_COLS, $this->styles);
        $top = ($this->contextAnchorRow + \count($rows)) > $screenBottom
            ? max(0, $this->contextAnchorRow - \count($rows))
            : $this->contextAnchorRow;

        $this->contextMenuDropdown = new DropdownRegion($top, $col, $width, \count($rows), framed: true);
        $this->contextShadowRegion = $this->shadowWidened($top, $col, $width, \count($rows));
        // A pane set includes its separator columns and conditionally hidden
        // rows (e.g. the breadcrumb), so a menu spilling across a pane
        // boundary still composites gaplessly.
        $this->pushOverlays($rows, $top, $col, $width, $this->panes->current());
    }

    /**
     * The RTL segmenter's copy of a dropdown box: covers only the shadow
     * bands that actually paint (a box flush against the pane union's edge
     * casts none there), so the segmenter never claims cells outside the
     * dropdown — the Welcome modal's precedent.
     */
    private function shadowWidened(int $top, int $col, int $width, int $rows): DropdownRegion
    {
        [, , $bottom, $right] = PaneBounds::union($this->panes->current(), $this->renderer) ?? [0, 0, 0, 0];

        return new DropdownRegion($top, $col, max(1, min($width + 1, $right - $col)), max(1, min($rows + 1, $bottom - $top)));
    }

    /** @param list<AbstractWidget&MenuOverlayHost> $panes */
    private function clearOverlays(array $panes): void
    {
        foreach ($panes as $pane) {
            $pane->setMenuOverlay([]);
        }
    }

    /**
     * Slice the framed dropdown rows and their drop shadow into per-pane local
     * spans and push them; panes without a rect this frame (e.g. the
     * breadcrumb hidden outside every scope) clear.
     *
     * @param list<string>                         $rows
     * @param list<AbstractWidget&MenuOverlayHost> $panes
     */
    private function pushOverlays(array $rows, int $top, int $anchor, int $width, array $panes): void
    {
        foreach ($panes as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            $pane->setMenuOverlay(null !== $rect ? $this->localSpans($rows, $rect, $top, $anchor, $width) : []);
        }
    }

    /**
     * Translate absolute framed rows to $rect-local overlay spans — the shared
     * {@see SpanSlicer} math plus the {@see ModalShadow} bands every floating
     * surface casts.
     *
     * @param list<string> $rows
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    private function localSpans(array $rows, WidgetRect $rect, int $top, int $anchor, int $width): array
    {
        return [
            ...SpanSlicer::localSpans($rows, $rect, $top, $anchor, $width),
            ...ModalShadow::spans($rect, $top, $anchor, $width, \count($rows), $this->styles),
        ];
    }
}
