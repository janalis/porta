<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\I18n\BaseDirection;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EdgeIndicator;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\SelectionBar;
use Porta\Widget\Render\StripEdge;
use Porta\Widget\Render\TabCloseGlyph;
use Porta\Widget\Render\TabShape;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Render\TabStripTail;
use Porta\Widget\Render\TabStyle;
use Porta\Widget\Support\PaneFocusProbe;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StripScroll;
use Porta\Widget\Support\StylesDefault;
use Porta\Widget\Support\TabBarPosition;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The editor tab strip. Content comes from a provider closure so it always
 * reflects the BufferManager state. Renders as a horizontal bar (top/bottom
 * positions) or as a fixed-width vertical list (left/right positions): folder
 * tabs {@see TabStripRenderer::ROWS} rows tall where the screen is roomy, one
 * row each where it is compact.
 */
#[AsWidget('tabs', flex: 0)]
final class TabBarWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;
    use PaneFocusProbe;

    /** @use ProviderFingerprint<list<array{name: string, dirty: bool, active: bool, closable?: bool}>> */
    use ProviderFingerprint;
    use StripScroll;
    use StylesDefault;

    /** Display columns of the vertical (left/right) tab list, when the pane can seat them. */
    public const int VERTICAL_WIDTH = 24;

    /**
     * Below this the strip has no room for a name beside its dirty dot and its
     * close glyph, so it draws blank rather than a truncated mess. A pane that
     * narrow is one the responsive rule takes the side strip away from anyway.
     */
    private const int VERTICAL_MIN = 8;

    private TabBarPosition $position = TabBarPosition::Top;

    /** The workspace's reading direction: under RTL a side strip's row reverses, so its inner edge is the other logical end. */
    private BaseDirection $direction = BaseDirection::Ltr;

    public function __construct(?Styles $styles = null)
    {
        $this->initStyles($styles);
    }

    /** @var list<array{index: int, row: int, rows: int, labelRow: int, start: int, width: int, closeStart: int, closeWidth: int}> layout from the last render: each tab's top row, its height, and the row its × is on */
    private array $lastLayout = [];

    /** @var positive-int the strip's height — a side strip's tab's: one row, or {@see TabStripRenderer::ROWS} for folder tabs */
    private int $stripRows = 1;

    /** The pane focus the last render drew, so a focus change repaints the lid. */
    private bool $drawnFocus = false;

    /** The active index the window last followed, so a manual scroll survives parked frames. */
    private ?int $lastActive = null;

    /**
     * The pointer's cell over the strip (visual hover), or null. Stored as a
     * CELL, not a resolved tab index, and re-resolved against each frame's
     * geometry in render — so a strip change (a dirty dot flipping on save,
     * the tab set shrinking) can't leave a stale tint on the wrong tab, and a
     * still-correct tint survives without waiting for a fresh motion event.
     *
     * @var array{row: int, col: int}|null
     */
    private ?array $hoverCell = null;
    /** The tab the cell last resolved to — repaint change-detection at tab granularity. */
    private ?int $hoverResolved = null;

    /** @var list<string>|null vertical strip memo — in side position the layout engine's intrinsic measure renders this widget a second time per frame */
    private ?array $memoLines = null;

    /** @var array{int, int, int} the [revision, rows, width] the memo was rendered for — the WIDTH belongs in it since the strip narrows with its pane */
    private array $memoKey = [-1, -1, -1];

    /** @var list<array{row: int, col: int, width: int, step: int}> the memoized render's ▲/▼ zones */
    private array $memoZones = [];

    public function setPosition(TabBarPosition $position): void
    {
        if ($position === $this->position) {
            return;
        }
        $this->position = $position;
        $this->lastLayout = [];
        $this->indicatorZones = [];
        $this->scroll = 0;
        $this->lastActive = null;
        $this->invalidate();
    }

    public function position(): TabBarPosition
    {
        return $this->position;
    }

    /**
     * The workspace's reading direction. A setter, as on
     * {@see MenuBarWidget::setTextDirection()}: it arrives from the locale
     * after the widget is built, and changes while it is alive.
     */
    public function setTextDirection(BaseDirection $direction): void
    {
        if ($direction !== $this->direction) {
            $this->direction = $direction;
            $this->invalidate();
        }
    }

    /** @param positive-int $rows the horizontal strip's height, from the terminal's ({@see TabStripRenderer::rowsFor()}) */
    public function setStripRows(int $rows): void
    {
        if ($rows !== $this->stripRows) {
            $this->stripRows = $rows;
            $this->lastLayout = [];
            $this->invalidate();
        }
    }

    #[\Override]
    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        // The hover cell re-resolves against the new geometry in render, so a
        // strip change needs no clearHover() here — a still-correct tint stays
        // put, a stale one falls off whatever tab moved out from under the
        // pointer.
        $this->refreshFingerprint(($this->provider)());
        if ($this->paneFocused() !== $this->drawnFocus) {
            $this->invalidate(); // the active lid's weight follows the pane's focus
        }
    }

    /**
     * Scroll the side list by $rows screen rows (a wheel notch's worth): a
     * row is a tab on the one-row list, and a folder tab is
     * {@see TabStripRenderer::ROWS} of them, so a notch moves the list the
     * same distance on screen at either density.
     */
    public function scrollByRows(int $rows): void
    {
        $this->scrollBy(\intdiv($rows, $this->stripRows > 1 ? TabStripRenderer::ROWS : 1));
    }

    /**
     * Tint the tab under the pointer (visual only). Stores the cell for
     * per-frame re-resolution, but reports change at TAB granularity (resolved
     * against the last frame) so a move within one tab does not repaint —
     * matching {@see DockTabsWidget}.
     */
    public function hoverTab(int $row, int $col): bool
    {
        $this->hoverCell = ['row' => $row, 'col' => $col];
        $resolved = $this->tabIndexAt($row, $col);
        if ($resolved === $this->hoverResolved) {
            return false;
        }
        $this->hoverResolved = $resolved;
        $this->invalidate();

        return true;
    }

    /** The pointer left the strip: drop the hover tint. Returns whether it changed. */
    public function clearHover(): bool
    {
        if (null === $this->hoverCell) {
            return false;
        }
        $this->hoverCell = null;
        $this->hoverResolved = null;
        $this->invalidate();

        return true;
    }

    /** Whether the stored hover cell falls inside a side tab of $height rows from $row. */
    private function isHoverCell(int $row, int $height): bool
    {
        return null !== $this->hoverCell && $this->hoverCell['row'] >= $row && $this->hoverCell['row'] < ($row + $height);
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->indicatorZones = [];
        $this->drawnFocus = $this->paneFocused();
        if ($this->position->isSide()) {
            return $this->renderVertical($context);
        }

        $columns = $context->getColumns();
        $tabs = null !== $this->provider ? ($this->provider)() : [];

        if ([] === $tabs) {
            // The empty strip keeps its Toolbar base and its height: closing
            // the last tab must neither drop the bar to the terminal default
            // nor move the code under it.
            return $this->withMenuOverlay(\array_fill(start_index: 0, count: $this->stripRows, value: $this->styles->of(Role::Toolbar)->apply(AnsiText::blank($columns))), $columns);
        }

        // The shared strip: the overflow window (reserve ‹/› cells, clamp,
        // follow-active-on-change), the cells and their hit map, exactly as the
        // dock and the git view draw theirs. Each tab ends with a clickable ×
        // unless it is pinned; the dirty ● (when present) sits just before it.
        $bottom = TabBarPosition::Bottom === $this->position;
        $strip = TabStripRenderer::render(
            \array_map(fn (array $tab): array => [
                'label' => $tab['name'],
                'active' => $tab['active'],
                'marker' => $tab['dirty'] ? $this->styles->of(Role::Modified)->apply(Glyphs::DIRTY) : '',
                'closable' => $tab['closable'] ?? true,
            ], $tabs),
            $columns,
            $this->styles,
            $this->hoverCell['col'] ?? null,
            $this->scroll,
            $this->lastActive,
            $this->stripRows,
            $bottom ? StripEdge::Bottom : StripEdge::Top,
            $this->drawnFocus,
            $this->hoverCell['row'] ?? null,
        );
        $this->scroll = $strip['scroll'];
        $this->lastActive = $strip['lastActive'];
        $this->indicatorZones = $strip['indicatorZones'];
        $shape = ['row' => 0, 'rows' => \count($strip['lines']), 'labelRow' => $strip['labelRow']];
        $this->lastLayout = \array_map(static fn (array $zone): array => $shape + $zone, $strip['hitMap']);

        // A Toolbar-background base under the whole strip, matching the other
        // tab strips. Folder tabs stand on a baseline that runs to the edge.
        return $this->withMenuOverlay(TabStripTail::pad($strip, $columns, $this->styles), $columns);
    }

    /**
     * The vertical (left/right) tab list: a folder tab of
     * {@see TabStripRenderer::ROWS} rows each, or one row each on a compact
     * screen, every line exactly
     * {@see VERTICAL_WIDTH} columns so the layout engine measures a stable
     * intrinsic width, blank rows filling the pane below the last tab.
     *
     * @return list<string>
     */
    private function renderVertical(RenderContext $context): array
    {
        $rows = \max(1, $context->getRows());
        // NEVER the constant: a widget may not emit a line wider than the pane
        // it was handed — the renderer throws on it, and that exception escapes
        // the render loop. The strip asks for VERTICAL_WIDTH and takes what it
        // is given, which on a narrow terminal is less.
        $width = $this->verticalWidth($context);

        // The FileTreeWidget idiom: in side position the layout engine's
        // intrinsic-width measure renders this widget and clears the vendor
        // cache, so the real pass rendered it AGAIN every frame. Content
        // changes miss via the beforeRender fingerprint's invalidate. Only the
        // ▲/▼ zones need restoring on a hit — render() clears them before
        // dispatching here; the hit-test layout is untouched since the miss
        // that built it.
        $key = [$this->getRenderRevision(), $rows, $width];
        if (null !== $this->memoLines && $key === $this->memoKey) {
            $this->indicatorZones = $this->memoZones;

            return $this->memoLines;
        }
        $this->memoKey = $key;
        $tabs = null !== $this->provider ? ($this->provider)() : [];

        $this->lastLayout = [];
        $lines = [];
        if ($width < self::VERTICAL_MIN) {
            // Too narrow for a tab; blank rows of the right width beat a
            // truncated one, and beat an exception by more.
            return $this->memoLines = \array_fill(start_index: 0, count: $rows, value: AnsiText::blank($width));
        }
        if ([] !== $tabs) {
            $activeIndex = 0;
            foreach ($tabs as $i => $candidate) {
                if (!$candidate['active']) {
                    continue;
                }

                $activeIndex = $i;
                break; // equivalent mutant, for the reason given on the horizontal strip above
            }
            // An overflowing list reserves its first and last rows for the
            // ▲/▼ scroll indicators (blank when that side is not clipped).
            $tall = $this->stripRows > 1;
            $height = $tall ? TabStripRenderer::ROWS : 1;
            $overflow = $rows > 2 && (\count($tabs) * $height) > $rows;
            $budget = \max(1, \intdiv($overflow ? $rows - 2 : $rows, $height));
            // Tabs are uniform height, so the window is the manual scroll
            // offset (clamped against a blank tail) that follows the active
            // tab only when it changed — a user's scroll survives otherwise.
            $this->scroll = \min($this->scroll, \max(0, \count($tabs) - $budget));
            if ($activeIndex !== $this->lastActive) {
                $this->lastActive = $activeIndex;
                $this->scroll = \min($this->scroll, $activeIndex);
                $this->scroll = \max($this->scroll, $activeIndex - $budget + 1);
            }
            $start = $this->scroll;
            if ($overflow) {
                $lines[] = $start > 0 ? $this->indicatorRow(Glyphs::SCROLL_UP, step: -3, row: 0, width: $width) : $this->sideRow($width);
            }
            foreach (\array_slice($tabs, $start, $budget, preserve_keys: true) as $index => $tab) {
                $top = \count($lines);
                $state = TabState::of($tab['active'], $this->isHoverCell($top, $height));
                \array_push($lines, ...$tall ? $this->sideTab($tab, $state, $width) : [$this->listRow($tab, $state, $width)]);
                $this->lastLayout[] = [
                    'index' => $index,
                    'row' => $top,
                    'rows' => $height,
                    'labelRow' => $top + ($tall ? 1 : 0), // the middle row
                    'start' => 0,
                    'width' => $width,
                    // The × is right-aligned: a gutter column before a tall
                    // tab's outline, where the one-row list has none.
                    'closeStart' => $width - TabStripRenderer::CLOSE_WIDTH - ($tall ? 1 : 0),
                    'closeWidth' => $tab['closable'] ?? true ? TabStripRenderer::CLOSE_WIDTH : 0,
                ];
            }
            if ($overflow) {
                // The ▼ is the strip's last row, where its zone is: whole
                // folder tabs rarely fill the rows between, and the gap is
                // the rule's.
                while (\count($lines) < ($rows - 1)) {
                    $lines[] = $this->sideRow($width);
                }
                $lines[] = ($start + $budget) < \count($tabs) ? $this->indicatorRow(Glyphs::SCROLL_DOWN, step: 3, row: $rows - 1, width: $width) : $this->sideRow($width);
            }
        }
        // Past the last tab the rule runs on; with no tab at all the strip is
        // blank, as the horizontal one is.
        while (\count($lines) < $rows) {
            $lines[] = [] === $tabs ? AnsiText::blank($width) : $this->sideRow($width);
        }
        // A pane shorter than one tall tab and its indicators keeps its height.
        $lines = \array_slice($lines, offset: 0, length: $rows);

        $this->memoZones = $this->indicatorZones;

        return $this->memoLines = $lines;
    }

    /**
     * A one-row list entry: '▌name…    ●× ', the dirty ● and the close ×
     * right-aligned, and the active tab wearing the lists' selection bar — a
     * column list, read the way the tree beside it is: `▌` while its pane has
     * the keys, `▎` while it does not.
     *
     * @param array{name: string, dirty: bool, active: bool, closable?: bool} $tab
     */
    private function listRow(array $tab, TabState $state, int $width): string
    {
        return TabStyle::of($this->styles, $state)->apply(SelectionBar::of($tab['active'], $this->drawnFocus)->value.$this->tabBody($tab, $width - 1));
    }

    /**
     * A folder tab stood on its side: its outline three rows tall, the inner
     * edge a rule down the strip that opens beside the shown tab alone
     * ({@see TabShape::side()}). The shape is the state, as on the
     * horizontal strip, so the selection bar has no work to do here.
     *
     * @param array{name: string, dirty: bool, active: bool, closable?: bool} $tab
     *
     * @return list<string>
     */
    private function sideTab(array $tab, TabState $state, int $width): array
    {
        $shape = TabShape::of($tab['active'], $this->drawnFocus);
        $outline = $this->styles->of($shape->role());
        $side = $shape->side($width, $this->ruleAtEnd());
        $body = TabStyle::of($this->styles, $state)->apply(' '.$this->tabBody($tab, $width - 3));
        $middle = $this->ruleAtEnd()
            ? $outline->apply($side['outer']).$body.$outline->apply($side['inner'])
            : $outline->apply($side['inner']).$body.$outline->apply($side['outer']);

        return [$outline->apply($side['top']), $middle, $outline->apply($side['bottom'])];
    }

    /**
     * `name…  ●× ` in $width columns: the name truncated, then the dirty ●
     * (or a blank) and the × (a blank on a pinned tab) at the end.
     *
     * @param array{name: string, dirty: bool, active: bool, closable?: bool} $tab
     */
    private function tabBody(array $tab, int $width): string
    {
        $marker = $tab['dirty'] ? $this->styles->of(Role::Modified)->apply(Glyphs::DIRTY) : ' ';
        $close = match (true) {
            !($tab['closable'] ?? true) => ' ',
            $tab['active'] => TabCloseGlyph::active($this->styles),
            default => TabCloseGlyph::inactive($this->styles),
        };

        return AnsiText::truncate($tab['name'], $width - 4, pad: true).' '.$marker.$close.' ';
    }

    /**
     * Whether a side strip's inner edge — the one facing the editor — is its
     * last column. The position is physical and an RTL row reverses, so a
     * strip at the left reads its inner edge at the end only left to right.
     */
    private function ruleAtEnd(): bool
    {
        return (TabBarPosition::Left === $this->position) === (BaseDirection::Ltr === $this->direction);
    }

    /**
     * A side strip's row with no tab on it — blank, or a ▲/▼ $glyph — and on
     * a roomy screen the inner rule running on beside it, so the edge facing
     * the editor is one line from the strip's top to its bottom.
     */
    private function sideRow(int $width, ?string $glyph = null): string
    {
        $cells = 1 === $this->stripRows ? $width : $width - 1;
        $row = null === $glyph ? AnsiText::blank($cells) : EdgeIndicator::row($glyph, $cells, $this->styles);
        if (1 === $this->stripRows) {
            return $row;
        }
        $rule = $this->styles->of(Role::Rule)->apply(Glyphs::FRAME_VERTICAL);

        return $this->ruleAtEnd() ? $row.$rule : $rule.$row;
    }

    /**
     * One clickable full-width ▲/▼ scroll row of the vertical list.
     *
     * $width is the pane's, NOT {@see self::VERTICAL_WIDTH}: this row used to
     * pad to the constant while every other row in the same strip took the
     * clamped width {@see renderVertical()} computes for exactly this reason.
     * A strip in a pane narrower than the constant then emitted one row of 24
     * columns among rows of 16, the renderer threw, and the exception escaped
     * the render loop — the blank fallbacks beside both call sites had it right
     * with `AnsiText::blank($width)` all along.
     */
    private function indicatorRow(string $glyph, int $step, int $row, int $width): string
    {
        $this->indicatorZones[] = ['row' => $row, 'col' => 0, 'width' => $width, 'step' => $step];

        return $this->sideRow($width, $glyph);
    }

    /**
     * Which tab index (into the full provider list) sits at the given
     * widget-relative cell of the last-rendered strip, or null when the click
     * missed every tab. The horizontal strip ignores the row (a folder tab is
     * the whole of its shape, and a reorder-drag may wander off the strip);
     * the vertical list matches it against each tab's rows.
     */
    public function tabIndexAt(int $row, int $col): ?int
    {
        foreach ($this->lastLayout as $entry) {
            if ($this->position->isSide() && ($row < $entry['row'] || $row >= ($entry['row'] + $entry['rows']))) {
                continue;
            }
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $entry['index'];
            }
        }

        return null;
    }

    /**
     * The widget-relative cell where tab $index starts in the last-rendered
     * layout, and how many rows it is tall, or null when that tab is scrolled
     * out of view. Anchors the keyboard-opened tab context menu.
     *
     * @return array{row: int, col: int, rows: int}|null
     */
    public function tabAnchor(int $index): ?array
    {
        foreach ($this->lastLayout as $entry) {
            if ($entry['index'] === $index) {
                return ['row' => $entry['row'], 'col' => $entry['start'], 'rows' => $entry['rows']];
            }
        }

        return null;
    }

    /**
     * Which tab's close × zone sits at the given widget-relative cell, or
     * null. Checked before {@see tabIndexAt} so a click on the × closes the
     * tab instead of switching to it.
     */
    public function closeIndexAt(int $row, int $col): ?int
    {
        foreach ($this->lastLayout as $entry) {
            // Every orientation: a folder tab's × is on its label row, and
            // the outline around it is the tab's, not the ×'s.
            if ($entry['labelRow'] !== $row) {
                continue;
            }
            if ($col >= $entry['closeStart'] && $col < ($entry['closeStart'] + $entry['closeWidth'])) {
                return $entry['index'];
            }
        }

        return null;
    }

    /** A side strip is a fixed-width list, however much room the layout offers. */
    private function verticalWidth(RenderContext $context): int
    {
        return \min(self::VERTICAL_WIDTH, \max(0, $context->getColumns()));
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return $this->position->isSide() ? $this->verticalWidth($context) : $context->getColumns();
    }
}
