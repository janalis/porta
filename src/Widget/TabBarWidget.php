<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\TabCloseGlyph;
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
        $this->scroll = 0;
        $this->lastActive = null;
        $this->invalidate();
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
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
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
            $prefix = ' '.$tab['name'].' ';
            $marker = $tab['dirty'] ? '●' : '';
            $closeOffset = mb_strwidth($prefix) + mb_strwidth($marker);
            $label = $prefix.$marker.'×'.' ';
            $parts[] = [
                'text' => $label,
                'active' => $tab['active'],
                'width' => mb_strwidth($label) + 1,
                'closeOffset' => $closeOffset,
            ];
        }

        $activeIndex = 0;
        foreach ($parts as $i => $candidate) {
            if (!$candidate['active']) {
                continue;
            }

            $activeIndex = $i;
            break;
        }
        // The window starts at the manual scroll offset, clamped so the strip
        // never shows a blank tail, then follows the active tab — but only
        // when it changed, so a user's scroll survives parked frames.
        $this->scroll = min($this->scroll, self::maxStart($parts, $columns));
        if ($activeIndex !== $this->lastActive) {
            $this->lastActive = $activeIndex;
            $this->scroll = min($this->scroll, $activeIndex);
            while ($this->scroll < $activeIndex && array_sum(array_column(\array_slice($parts, $this->scroll, $activeIndex - $this->scroll + 1), 'width')) > $columns) {
                ++$this->scroll;
            }
        }
        $start = $this->scroll;

        $line = '';
        $used = 0;
        $this->lastLayout = [];
        foreach (\array_slice($parts, $start, length: null, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > $columns) {
                break;
            }
            $style = $part['active'] ? $this->styles->merge(Role::InlayPopupBg, Role::Bright)->withBold() : $this->styles->of(Role::Subtle);
            $text = str_replace('×', $part['active'] ? TabCloseGlyph::active($this->styles) : TabCloseGlyph::inactive($this->styles), $part['text']);
            $line .= $style->apply($text).$this->styles->of(Role::Placeholder)->apply('│');
            $this->lastLayout[] = [
                'index' => $index,
                'row' => 0,
                'start' => $used,
                'width' => $part['width'],
                'closeStart' => $used + $part['closeOffset'],
                'closeWidth' => 2, // the × cell plus its trailing space
            ];
            $used += $part['width'];
        }

        return $this->withMenuOverlay([AnsiText::padRight($line, $columns)], $columns);
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
            // Rows are uniform height, so the window is the manual scroll
            // offset (clamped against a blank tail) that follows the active
            // tab only when it changed — a user's scroll survives otherwise.
            $this->scroll = min($this->scroll, max(0, \count($tabs) - $rows));
            if ($activeIndex !== $this->lastActive) {
                $this->lastActive = $activeIndex;
                $this->scroll = min($this->scroll, $activeIndex);
                $this->scroll = max($this->scroll, $activeIndex - $rows + 1);
            }
            $start = $this->scroll;
            // ' name…    ●× ': the dirty ● and the close × are right-aligned.
            $nameWidth = self::VERTICAL_WIDTH - 5;
            foreach (\array_slice($tabs, $start, $rows, preserve_keys: true) as $index => $tab) {
                $name = AnsiUtils::truncateToWidth($tab['name'], $nameWidth, '…', pad: true);
                $marker = $tab['dirty'] ? '●' : ' ';
                $style = $tab['active'] ? $this->styles->merge(Role::InlayPopupBg, Role::Bright)->withBold() : $this->styles->of(Role::Subtle);
                $close = $tab['active'] ? TabCloseGlyph::active($this->styles) : TabCloseGlyph::inactive($this->styles);
                $lines[] = $style->apply(' '.$name.' '.$marker.$close.' ');
                $this->lastLayout[] = [
                    'index' => $index,
                    'row' => \count($lines) - 1,
                    'start' => 0,
                    'width' => self::VERTICAL_WIDTH,
                    'closeStart' => self::VERTICAL_WIDTH - 2,
                    'closeWidth' => 2, // the × cell plus its trailing space
                ];
            }
        }
        while (\count($lines) < $rows) {
            $lines[] = str_repeat(' ', self::VERTICAL_WIDTH);
        }

        return $this->withMenuOverlay($lines, self::VERTICAL_WIDTH);
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
