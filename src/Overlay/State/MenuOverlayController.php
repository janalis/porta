<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\Region\PaneBounds;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Support\IntMath;
use Porta\Ui\FrameHooks;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\ModalShadow;
use Porta\Widget\Render\PopupFrame;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;
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
#[AsEager]
#[AsIdeService]
final class MenuOverlayController implements FloatingRegions
{
    use StylesDefault;

    private ?DropdownRegion $menuDropdown = null;
    private ?DropdownRegion $contextMenuDropdown = null;
    private ?DropdownRegion $menuShadowRegion = null;
    private ?DropdownRegion $contextShadowRegion = null;
    private int $contextAnchorRow = 0;
    private int $contextAnchorCol = 0;
    /** Where a panel that has to flip up ends: the row above it (a select's own top row), or the anchor row. */
    private ?int $contextAnchorAbove = null;

    /** @var array{int, int, int, int}|null the pane bounds the open menus were last laid out against */
    private ?array $laidOutFor = null;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly MenuOverlayPanes $panes,
        private readonly Translator $translator = new Translator(),
        ?Styles $styles = null,
        ?FrameHooks $frameHooks = null,
        private readonly ?Tui $tui = null,
        // A mirrored frame's surfaces, which the panes must not carry.
        private readonly FloatingLayer $layer = new FloatingLayer(),
    ) {
        $this->initStyles($styles);
        $frameHooks?->onBeforeFrame($this->followResize(...));
    }

    /**
     * The per-tick check ({@see FrameHooks::onBeforeFrame}) that an open menu
     * still fits: a menu is laid out on open and on every move, and a
     * terminal resized under it used to keep the old screen's box — running
     * off a shrunk one, or cut short on a grown one — until the next key.
     * The pane rects a hook sees are the last frame's, so the frame a resize
     * forces still draws the old box; the tick after it sees the new rects,
     * lays the menu out again and asks for the frame that shows it.
     */
    private function followResize(): void
    {
        if (!$this->menuBar->isDropdownShown() && !$this->contextMenu->isOpen()) {
            return;
        }
        if (PaneBounds::union($this->panes->current(), $this->renderer) === $this->laidOutFor) {
            return;
        }
        if ($this->menuBar->isDropdownShown()) {
            $this->refreshMenu();
        }
        if ($this->contextMenu->isOpen()) {
            $this->refreshContextMenu();
        }
        $this->tui?->requestRender();
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

    #[\Override]
    public function regions(): array
    {
        return \array_values(\array_filter([$this->menuShadowRegion, $this->contextShadowRegion]));
    }

    /**
     * Remember the point the context menu anchors at: the right-click cell, or
     * the row below a select's box. $above is where a panel that must flip up
     * ends instead — a select's top border, so an upward list never covers
     * the control it belongs to.
     */
    public function setContextAnchor(int $row, int $col, ?int $above = null): void
    {
        $this->contextAnchorRow = $row;
        $this->contextAnchorCol = $col;
        $this->contextAnchorAbove = $above;
    }

    /**
     * Position the open menu's dropdown as a floating overlay over the panes
     * beneath the (full-width) menu row so it never reflows the layout. Called
     * on every open/navigate/close; clears the overlay when no menu is open.
     */
    public function refreshMenu(): void
    {
        // An armed bar (F10, nothing dropped yet) has no dropdown to place.
        if (!$this->menuBar->isDropdownShown()) {
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
        // title and is left open there, so the title's rails run on into
        // the box and the two read as one outline.
        $width = \min($this->menuBar->panelWidth() + PopupFrame::CHROME_COLS, $menuRect->columns);
        $anchor = \max(
            $menuRect->col,
            \min($menuRect->col + $this->menuBar->titleStart($this->menuBar->openIndex()), $menuRect->col + $menuRect->columns - $width),
        );
        $top = $menuRect->row + $menuRect->rows;
        $title = $this->menuBar->openTitle();
        $opening = null !== $title ? ['offset' => $menuRect->col + $title['start'] - $anchor, 'width' => $title['width']] : null;
        $inner = $width - PopupFrame::CHROME_COLS;
        $items = $this->menuBar->dropdownSegments($inner);
        // The box ends where the panes do, above the status bar: a menu
        // taller than that shows a window of its items that follows the
        // selection, ▲ and ▼ in the border where more run on past it.
        [, , $bottom] = PaneBounds::union($this->panes->current(), $this->renderer) ?? [0, 0, $top + \count($items) + PopupFrame::CHROME_ROWS];
        $visible = \max(1, \min(\count($items), $bottom - $top - PopupFrame::CHROME_ROWS));
        $first = $this->menuBar->dropdownScroll($visible);
        $rows = $this->framedWindow($items, $inner, $first, $visible, $opening);

        $this->menuDropdown = new DropdownRegion($top, $anchor, $width, \count($rows), framed: true, first: $first);
        $this->menuShadowRegion = $this->shadowWidened($top, $anchor, $width, \count($rows));
        $this->pushOverlays($rows, $top, $anchor, $width, $this->panes->current());
        $this->laidOutFor = PaneBounds::union($this->panes->current(), $this->renderer);
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
        [$screenTop, , $screenBottom, $screenRight] = PaneBounds::union($this->panes->current(), $this->renderer) ?? [0, 0, 0, 0];
        $width = \min($this->contextMenu->panelWidth() + PopupFrame::CHROME_COLS, $screenRight);
        $col = IntMath::clamp($this->contextAnchorCol, 0, $screenRight - $width);
        $inner = $width - PopupFrame::CHROME_COLS;
        $items = $this->contextMenu->segments($inner);
        // Under the anchor when it fits, else above it when it fits there;
        // a list that fits neither side takes the roomier one and scrolls.
        $above = $this->contextAnchorAbove ?? $this->contextAnchorRow;
        $roomBelow = $screenBottom - $this->contextAnchorRow;
        $roomAbove = $above - $screenTop;
        $down = (\count($items) + PopupFrame::CHROME_ROWS) <= $roomBelow || $roomBelow >= $roomAbove;
        $visible = \max(1, \min(\count($items), ($down ? $roomBelow : $roomAbove) - PopupFrame::CHROME_ROWS));
        $first = $this->contextMenu->dropdownScroll($visible);
        $rows = $this->framedWindow($items, $inner, $first, $visible);
        $top = $down ? $this->contextAnchorRow : \max($screenTop, $above - \count($rows));

        $this->contextMenuDropdown = new DropdownRegion($top, $col, $width, \count($rows), framed: true, first: $first);
        $this->contextShadowRegion = $this->shadowWidened($top, $col, $width, \count($rows));
        // A pane set includes its separator columns and conditionally hidden
        // rows (e.g. the breadcrumb), so a menu spilling across a pane
        // boundary still composites gaplessly.
        $this->pushOverlays($rows, $top, $col, $width, $this->panes->current());
        $this->laidOutFor = PaneBounds::union($this->panes->current(), $this->renderer);
    }

    /**
     * The $visible items from $first on, framed — an open menu, or a select's
     * open list, holds the keys: the heavy frame, the form controls' focus —
     * with ▲ and ▼ in the border where the list runs on past the box.
     *
     * @param list<array{segment: string, separator: bool}> $items
     * @param ?array{offset: int, width: int}               $opening
     *
     * @return list<string>
     */
    private function framedWindow(array $items, int $inner, int $first, int $visible, ?array $opening = null): array
    {
        return PopupFrame::wrapMenu(
            \array_slice($items, $first, $visible),
            $inner,
            $this->styles,
            FieldState::Focused,
            $opening,
            $first > 0 ? Glyphs::SCROLL_UP : '',
            ($first + $visible) < \count($items) ? Glyphs::SCROLL_DOWN : '',
        );
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
        // Under a right-to-left locale the whole rectangle is mirrored with
        // the frame, so the shadow is cast to the LEFT before the flip
        // ({@see ModalShadow::spans()}) and claimed there: the mirror puts it
        // bottom-right and keeps the box under the title it hangs from.
        $shown = $this->translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr;

        return DropdownRegion::shadowClamped($top, $col, $width, $rows, [0, 0, $bottom, $right], shown: $shown);
    }

    /** @param list<AbstractWidget&MenuOverlayHost> $panes */
    private function clearOverlays(array $panes): void
    {
        foreach ($panes as $pane) {
            $pane->setMenuOverlay([]);
        }
        $this->onLayer($this->layer->setMenu([]));
    }

    /** A changed layer needs the frame a pane would have asked for by invalidating itself. */
    // @mago-expect lint:no-boolean-flag-parameter -- the one fact: whether the layer's slot changed.
    private function onLayer(bool $changed): void
    {
        if ($changed) {
            $this->tui?->requestRender();
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
        if ($this->translator->isRtl()) {
            // Mirrored, the dropdown rides the floating layer and the panes
            // beneath keep their own text ({@see FloatingLayer} says why).
            foreach ($panes as $pane) {
                $pane->setMenuOverlay([]);
            }
            $this->onLayer($this->layer->setMenu($this->localSpans($rows, FloatingLayer::rect(), $top, $anchor, $width)));

            return;
        }
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
            ...ModalShadow::spans($rect, $top, $anchor, $width, \count($rows), $this->styles, $this->translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr),
        ];
    }
}
