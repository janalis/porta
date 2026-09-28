<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\StripWindow;
use Porta\Widget\Render\TabCloseGlyph;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Render\TabStyle;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StripScroll;
use Porta\Widget\Support\StylesDefault;
use Porta\Widget\Support\TabBarPosition;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The editor tab strip. Content comes from a provider closure so it always
 * reflects the BufferManager state. Renders as a one-row horizontal bar
 * (top/bottom positions) or as a fixed-width vertical list with one tab per
 * row (left/right positions).
 */
#[AsWidget('tabs', flex: 0)]
final class TabBarWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;

    /** @use ProviderFingerprint<list<array{name: string, dirty: bool, active: bool}>> */
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

    public function __construct(?Styles $styles = null)
    {
        $this->initStyles($styles);
    }

    /** @var list<array{index: int, row: int, start: int, width: int, closeStart: int, closeWidth: int}> layout from the last render */
    private array $lastLayout = [];

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

    /** Whether the stored hover cell falls inside a tab occupying [start, start+width) on $row. */
    private function isHoverCell(int $row, int $startCol, int $width): bool
    {
        return null !== $this->hoverCell && $this->hoverCell['row'] === $row && $this->hoverCell['col'] >= $startCol && $this->hoverCell['col'] < ($startCol + $width);
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->indicatorZones = [];
        if ($this->position->isSide()) {
            return $this->renderVertical($context);
        }

        $columns = $context->getColumns();
        $tabs = null !== $this->provider ? ($this->provider)() : [];

        if ([] === $tabs) {
            // The empty strip keeps its Toolbar base: closing the last tab must
            // not drop the bar to the terminal default for one frame-height row.
            return [$this->styles->of(Role::Toolbar)->apply(AnsiText::blank($columns))];
        }

        // Make sure the active tab is visible: drop leading tabs if needed. Each
        // tab ends with a clickable close ×; the dirty ● (when present) sits just
        // before it. closeOffset is the ×'s display column within the label.
        /** @var list<array{text: string, active: bool, width: int, closeOffset: int}> $parts */
        $parts = [];
        foreach ($tabs as $tab) {
            $parts[] = ['active' => $tab['active']] + TabStripRenderer::measureCell($tab['name'], $tab['dirty'] ? $this->styles->of(Role::Modified)->apply(Glyphs::DIRTY) : '');
        }

        $activeIndex = 0;
        foreach ($parts as $i => $candidate) {
            if (!$candidate['active']) {
                continue;
            }

            $activeIndex = $i;
            // `continue` here is an EQUIVALENT mutant — carried by the loop-skip
            // census as a gap. A tab is active by IDENTITY against the one active
            // buffer (`$b === $this->buffers->active()` in TabListProvider), so
            // two actives is not a reachable state and "first" and "last" name
            // the same tab. The break is an early exit, not a tiebreak.
            break;
        }
        // The overflow window (reserve ‹/› cells, clamp, follow-active-on-change)
        // is shared with the dock strip so the two cannot drift apart.
        $window = StripWindow::of(\array_column($parts, 'width'), $columns, $this->scroll, $activeIndex, $this->lastActive);
        $this->scroll = $window['scroll'];
        $this->lastActive = $window['lastActive'];
        $overflow = $window['overflow'];
        $start = $window['start'];

        $line = '';
        $used = 0;
        $last = $start;
        $this->lastLayout = [];
        if ($overflow) {
            $line .= $start > 0 ? $this->indicatorCell(Glyphs::SCROLL_PREV, step: -1, col: 0) : ' ';
            $used = 1;
        }
        foreach (\array_slice($parts, $start, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > ($overflow ? $columns - 1 : $columns)) {
                break;
            }
            $line .= TabStripRenderer::renderCell($part['text'], $part['closeOffset'], $this->styles, TabState::of($part['active'], $this->isHoverCell(0, $used, $part['width'])));
            $this->lastLayout[] = [
                'index' => $index,
                'row' => 0,
                'start' => $used,
                'width' => $part['width'],
                'closeStart' => $used + $part['closeOffset'],
                'closeWidth' => TabStripRenderer::CLOSE_WIDTH,
            ];
            $used += $part['width'];
            $last = $index;
        }
        if ($overflow) {
            $line = AnsiText::padRight($line, $columns - 1);
            $line .= $last < (\count($parts) - 1) ? $this->indicatorCell(Glyphs::SCROLL_NEXT, step: 1, col: $columns - 1) : ' ';
        }

        // A Toolbar-background base under the whole strip, matching the other tab strips.
        return [$this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($line, $columns))];
    }

    /** One clickable 1-column ‹/› scroll cell of the horizontal strip. */
    private function indicatorCell(string $glyph, int $step, int $col): string
    {
        $this->indicatorZones[] = ['row' => 0, 'col' => $col, 'width' => 1, 'step' => $step];

        return $this->styles->of(Role::Faint)->apply($glyph);
    }

    /**
     * The vertical (left/right) tab list: one tab per row, every line exactly
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
            $overflow = $rows > 2 && \count($tabs) > $rows;
            $budget = $overflow ? $rows - 2 : $rows;
            // Rows are uniform height, so the window is the manual scroll
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
                $lines[] = $start > 0 ? $this->indicatorRow(Glyphs::SCROLL_UP, step: -3, row: 0, width: $width) : AnsiText::blank($width);
            }
            // ' name…    ●× ': the dirty ● and the close × are right-aligned.
            $nameWidth = $width - 5;
            foreach (\array_slice($tabs, $start, $budget, preserve_keys: true) as $index => $tab) {
                $name = AnsiText::truncate($tab['name'], $nameWidth, pad: true);
                $marker = $tab['dirty'] ? $this->styles->of(Role::Modified)->apply(Glyphs::DIRTY) : ' ';
                $close = $tab['active'] ? TabCloseGlyph::active($this->styles) : TabCloseGlyph::inactive($this->styles);
                $rowIndex = \count($lines);
                $lines[] = TabStyle::of($this->styles, TabState::of($tab['active'], $this->isHoverCell($rowIndex, 0, $width)))->apply(' '.$name.' '.$marker.$close.' ');
                $this->lastLayout[] = [
                    'index' => $index,
                    'row' => \count($lines) - 1,
                    'start' => 0,
                    'width' => $width,
                    'closeStart' => $width - TabStripRenderer::CLOSE_WIDTH,
                    'closeWidth' => TabStripRenderer::CLOSE_WIDTH,
                ];
            }
            if ($overflow) {
                $lines[] = ($start + $budget) < \count($tabs) ? $this->indicatorRow(Glyphs::SCROLL_DOWN, step: 3, row: $rows - 1, width: $width) : AnsiText::blank($width);
            }
        }
        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
        }

        $this->memoZones = $this->indicatorZones;

        return $this->memoLines = $lines;
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
        $left = \str_repeat(' ', times: \max(0, \intdiv($width, num2: 2) - 1));

        return $this->styles->of(Role::Faint)->apply(AnsiText::truncate($left.$glyph, $width, pad: true, ellipsis: ''));
    }

    /**
     * Which tab index (into the full provider list) sits at the given
     * widget-relative cell of the last-rendered strip, or null when the click
     * missed every tab. The horizontal strip ignores the row (it is one row
     * tall, and a reorder-drag may wander off it); the vertical list matches it.
     */
    public function tabIndexAt(int $row, int $col): ?int
    {
        foreach ($this->lastLayout as $entry) {
            if ($this->position->isSide() && $entry['row'] !== $row) {
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
     * layout, or null when that tab is scrolled out of view. Anchors the
     * keyboard-opened tab context menu.
     *
     * @return array{row: int, col: int}|null
     */
    public function tabAnchor(int $index): ?array
    {
        foreach ($this->lastLayout as $entry) {
            if ($entry['index'] === $index) {
                return ['row' => $entry['row'], 'col' => $entry['start']];
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
            if ($this->position->isSide() && $entry['row'] !== $row) {
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
