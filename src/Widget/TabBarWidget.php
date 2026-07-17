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
use Porta\Widget\Render\TabCell;
use Porta\Widget\Render\TabCloseGlyph;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStyle;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
    use HoverTrait;
    use MenuOverlayHostTrait;

    /** Display columns of the vertical (left/right) tab list. */
    public const VERTICAL_WIDTH = 24;

    private readonly Styles $styles;

    private TabBarPosition $position = TabBarPosition::Top;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? Styles::dark();
    }

    /** @var (callable(): list<array{name: string, dirty: bool, active: bool}>)|null */
    private $provider = null;

    private string $lastFingerprint = '';

    /** @var list<array{index: int, row: int, start: int, width: int, closeStart: int, closeWidth: int}> layout from the last render */
    private array $lastLayout = [];

    /** Index of the first visible tab — the manual scroll offset, clamped at render. */
    private int $scroll = 0;

    /** The active index the window last followed, so a manual scroll survives parked frames. */
    private ?int $lastActive = null;

    /** @var list<array{row: int, col: int, width: int, step: int}> clickable overflow-indicator zones from the last render */
    private array $indicatorZones = [];

    /** @param callable(): list<array{name: string, dirty: bool, active: bool}> $provider */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Scroll the strip by $tabs (mouse wheel / overflow indicators); negative
     * scrolls toward the first tab. Clamping against the tab widths happens at
     * render time, where the geometry is known.
     */
    public function scrollBy(int $tabs): void
    {
        $this->scroll = max(0, $this->scroll + $tabs);
        $this->invalidate();
    }

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

    /**
     * The scroll step of the overflow indicator at the given widget-relative
     * cell (negative pages toward the first tab), or null when the cell is
     * not a clickable indicator. Checked before the close/tab hit zones.
     */
    public function scrollIndicatorAt(int $row, int $col): ?int
    {
        foreach ($this->indicatorZones as $zone) {
            if ($zone['row'] === $row && $col >= $zone['col'] && $col < ($zone['col'] + $zone['width'])) {
                return $zone['step'];
            }
        }

        return null;
    }

    public function position(): TabBarPosition
    {
        return $this->position;
    }

    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        $fingerprint = (string) json_encode(($this->provider)());
        if ($fingerprint !== $this->lastFingerprint) {
            $this->lastFingerprint = $fingerprint;
            $this->clearHover(); // the strip changed under the pointer
            $this->invalidate();
        }
    }

    /**
     * Tint the tab under the pointer (visual only); returns whether it
     * changed. The strip's hover key is a cell, not a row, so it maps the
     * pointer itself and only the state comes from {@see HoverTrait}.
     */
    public function hoverTab(int $row, int $col): bool
    {
        return $this->setHovered($this->tabIndexAt($row, $col));
    }

    public function render(RenderContext $context): array
    {
        $this->indicatorZones = [];
        if ($this->position->isSide()) {
            return $this->renderVertical($context);
        }

        $columns = $context->getColumns();
        $tabs = null !== $this->provider ? ($this->provider)() : [];

        if ([] === $tabs) {
            return $this->withMenuOverlay([str_repeat(' ', max(0, $columns))], $columns);
        }

        // Make sure the active tab is visible: drop leading tabs if needed. Each
        // tab ends with a clickable close ×; the dirty ● (when present) sits just
        // before it. closeOffset is the ×'s display column within the label.
        /** @var list<array{text: string, active: bool, width: int, closeOffset: int}> $parts */
        $parts = [];
        foreach ($tabs as $tab) {
            $parts[] = ['active' => $tab['active']] + TabCell::measure($tab['name'], $tab['dirty'] ? Glyphs::DIRTY : '');
        }

        $activeIndex = 0;
        foreach ($parts as $i => $candidate) {
            if (!$candidate['active']) {
                continue;
            }

            $activeIndex = $i;
            break;
        }
        // An overflowing strip reserves one column per side for the ‹/›
        // scroll indicators, so the tab geometry never depends on which
        // sides happen to be clipped.
        $overflow = $columns > 2 && array_sum(array_column($parts, 'width')) > $columns;
        $budget = $overflow ? $columns - 2 : $columns;

        // The window starts at the manual scroll offset, clamped so the strip
        // never shows a blank tail, then follows the active tab — but only
        // when it changed, so a user's scroll survives parked frames.
        $this->scroll = min($this->scroll, self::maxStart($parts, $budget));
        if ($activeIndex !== $this->lastActive) {
            $this->lastActive = $activeIndex;
            $this->scroll = min($this->scroll, $activeIndex);
            while ($this->scroll < $activeIndex && array_sum(array_column(\array_slice($parts, $this->scroll, $activeIndex - $this->scroll + 1), 'width')) > $budget) {
                ++$this->scroll;
            }
        }
        $start = $this->scroll;

        $line = '';
        $used = 0;
        $last = $start;
        $this->lastLayout = [];
        if ($overflow) {
            $line .= $start > 0 ? $this->indicatorCell(Glyphs::SCROLL_PREV, step: -1, col: 0) : ' ';
            $used = 1;
        }
        foreach (\array_slice($parts, $start, length: null, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > ($overflow ? $columns - 1 : $columns)) {
                break;
            }
            $line .= TabCell::render($part['text'], $this->styles, TabState::of($part['active'], $index === $this->hoveredIndex));
            $this->lastLayout[] = [
                'index' => $index,
                'row' => 0,
                'start' => $used,
                'width' => $part['width'],
                'closeStart' => $used + $part['closeOffset'],
                'closeWidth' => TabCell::CLOSE_WIDTH,
            ];
            $used += $part['width'];
            $last = $index;
        }
        if ($overflow) {
            $line = AnsiText::padRight($line, $columns - 1);
            $line .= $last < (\count($parts) - 1) ? $this->indicatorCell(Glyphs::SCROLL_NEXT, step: 1, col: $columns - 1) : ' ';
        }

        // A Toolbar-background base under the whole strip, matching the other tab strips.
        return $this->withMenuOverlay([$this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($line, $columns))], $columns);
    }

    /** One clickable 1-column ‹/› scroll cell of the horizontal strip. */
    private function indicatorCell(string $glyph, int $step, int $col): string
    {
        $this->indicatorZones[] = ['row' => 0, 'col' => $col, 'width' => 1, 'step' => $step];

        return $this->styles->of(Role::Faint)->apply($glyph);
    }

    /**
     * The largest sensible window start: the smallest index whose trailing
     * tabs still fill the strip, so scrolling never leaves a blank tail.
     * When even the last tab alone overflows, it is the last index.
     *
     * @param list<array{text: string, active: bool, width: int, closeOffset: int}> $parts
     */
    private static function maxStart(array $parts, int $columns): int
    {
        $start = \count($parts) - 1;
        $tail = 0;
        for ($i = \count($parts) - 1; $i >= 0; --$i) {
            $tail += $parts[$i]['width'];
            if ($tail > $columns) {
                break;
            }
            $start = $i;
        }

        return max(0, $start);
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
        $rows = max(1, $context->getRows());
        $tabs = null !== $this->provider ? ($this->provider)() : [];

        $this->lastLayout = [];
        $lines = [];
        if ([] !== $tabs) {
            $activeIndex = 0;
            foreach ($tabs as $i => $candidate) {
                if (!$candidate['active']) {
                    continue;
                }

                $activeIndex = $i;
                break;
            }
            // An overflowing list reserves its first and last rows for the
            // ▲/▼ scroll indicators (blank when that side is not clipped).
            $overflow = $rows > 2 && \count($tabs) > $rows;
            $budget = $overflow ? $rows - 2 : $rows;
            // Rows are uniform height, so the window is the manual scroll
            // offset (clamped against a blank tail) that follows the active
            // tab only when it changed — a user's scroll survives otherwise.
            $this->scroll = min($this->scroll, max(0, \count($tabs) - $budget));
            if ($activeIndex !== $this->lastActive) {
                $this->lastActive = $activeIndex;
                $this->scroll = min($this->scroll, $activeIndex);
                $this->scroll = max($this->scroll, $activeIndex - $budget + 1);
            }
            $start = $this->scroll;
            if ($overflow) {
                $lines[] = $start > 0 ? $this->indicatorRow(Glyphs::SCROLL_UP, step: -3, row: 0) : str_repeat(' ', self::VERTICAL_WIDTH);
            }
            // ' name…    ●× ': the dirty ● and the close × are right-aligned.
            $nameWidth = self::VERTICAL_WIDTH - 5;
            foreach (\array_slice($tabs, $start, $budget, preserve_keys: true) as $index => $tab) {
                $name = AnsiUtils::truncateToWidth($tab['name'], $nameWidth, '…', pad: true);
                $marker = $tab['dirty'] ? Glyphs::DIRTY : ' ';
                $close = $tab['active'] ? TabCloseGlyph::active($this->styles) : TabCloseGlyph::inactive($this->styles);
                $lines[] = TabStyle::of($this->styles, TabState::of($tab['active'], $index === $this->hoveredIndex))->apply(' '.$name.' '.$marker.$close.' ');
                $this->lastLayout[] = [
                    'index' => $index,
                    'row' => \count($lines) - 1,
                    'start' => 0,
                    'width' => self::VERTICAL_WIDTH,
                    'closeStart' => self::VERTICAL_WIDTH - TabCell::CLOSE_WIDTH,
                    'closeWidth' => TabCell::CLOSE_WIDTH,
                ];
            }
            if ($overflow) {
                $lines[] = ($start + $budget) < \count($tabs) ? $this->indicatorRow(Glyphs::SCROLL_DOWN, step: 3, row: $rows - 1) : str_repeat(' ', self::VERTICAL_WIDTH);
            }
        }
        while (\count($lines) < $rows) {
            $lines[] = str_repeat(' ', self::VERTICAL_WIDTH);
        }

        return $this->withMenuOverlay($lines, self::VERTICAL_WIDTH);
    }

    /** One clickable full-width ▲/▼ scroll row of the vertical list. */
    private function indicatorRow(string $glyph, int $step, int $row): string
    {
        $this->indicatorZones[] = ['row' => $row, 'col' => 0, 'width' => self::VERTICAL_WIDTH, 'step' => $step];
        $left = str_repeat(' ', times: max(0, intdiv(self::VERTICAL_WIDTH, num2: 2) - 1));

        return $this->styles->of(Role::Faint)->apply(AnsiText::padRight($left.$glyph, self::VERTICAL_WIDTH));
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
}
