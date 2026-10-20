<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Support\IntMath;
use Porta\Ui\Theme\Styles;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Two panes side by side with a drag divider between them: the editor beside
 * its live preview ({@see PreviewSplit}), a view's columns
 * ({@see ViewRegion::split()}).
 *
 * The two sides are sized by FLEX WEIGHT, not by a width on either widget.
 * A fixed width would mean a `flex: 0` side, and the layout engine measures an
 * intrinsic side by rendering it — the editor, the most expensive widget on
 * screen, twice a frame with its render cache cleared in between. Weights cost
 * nothing to lay out, keep the split's proportion across a terminal resize,
 * and are exactly the persisted value: the lead side's share, in thousandths.
 *
 * Neither side's widget is owned here; they are lent by {@see mount()} and
 * handed back by {@see release()}.
 */
final class PaneSplit
{
    /** The narrowest either side may be dragged to, and below twice which the split gives up its divider. */
    public const int MIN_SIDE = 20;

    public readonly ContainerWidget $area;

    public readonly SeparatorWidget $separator;

    private readonly ContainerWidget $leadSide;

    private readonly ContainerWidget $restSide;

    /** The lead side's share of the columns beside the divider, in thousandths. */
    private int $share;

    private bool $mirrored = false;

    /** True while the area is too narrow for two usable sides. */
    private bool $narrow = false;

    private ?AbstractWidget $lead = null;

    private ?AbstractWidget $rest = null;

    /** @var list<\Closure(bool): void> */
    private array $fitListeners = [];

    /** @var list<\Closure(): void> */
    private array $moveListeners = [];

    /**
     * @param int $share   the lead side's share before anything was dragged, in thousandths
     * @param int $minSide the narrowest either side may be dragged to
     */
    public function __construct(
        string $id,
        Styles $styles,
        private readonly NarrowSplit $whenNarrow = NarrowSplit::LeadAlone,
        private readonly ?Renderer $renderer = null,
        private readonly ?Tui $tui = null,
        int $share = 500,
        private readonly int $minSide = self::MIN_SIDE,
    ) {
        $this->share = IntMath::clamp($share, 10, 990);
        $this->area = LayoutWidgets::container($id, new Style(direction: Direction::Horizontal, gap: 0, flex: 1));
        $this->leadSide = LayoutWidgets::container($id.'-lead', new Style());
        $this->restSide = LayoutWidgets::container($id.'-rest', new Style());
        $this->separator = LayoutWidgets::separator($styles, $id.'-separator');
        $this->arrange();
    }

    /**
     * Lend the two widgets to the split. Each must already be detached from
     * wherever it sat: a widget has one parent, and adding it here does not
     * take it from the last one.
     */
    public function mount(AbstractWidget $lead, AbstractWidget $rest): void
    {
        if ($lead === $this->lead && $rest === $this->rest) {
            return;
        }
        $this->release();
        $this->lead = $lead;
        $this->rest = $rest;
        $this->leadSide->add($lead);
        $this->restSide->add($rest);
    }

    /** Hand the lent widgets back — detached, so the caller can seat them elsewhere. */
    public function release(): void
    {
        $this->leadSide->clear();
        $this->restSide->clear();
        $this->lead = null;
        $this->rest = null;
    }

    /** The lead widget lent to the split right now, or null when nothing is mounted. */
    public function lead(): ?AbstractWidget
    {
        return $this->lead;
    }

    /** The other widget lent to the split right now, or null when nothing is mounted. */
    public function rest(): ?AbstractWidget
    {
        return $this->rest;
    }

    /** The lead side's share of the split, as the percent a layout file keeps. */
    public function percent(): int
    {
        return (int) \round($this->share / 10);
    }

    /** Restore a persisted proportion; out-of-range values are clamped rather than refused. */
    public function setPercent(int $percent): void
    {
        $this->setShare(IntMath::clamp($percent, 1, 99) * 10);
    }

    /**
     * The columns the lead side is laid out with in an area $columns wide —
     * the layout engine's own floor of its weight, so a widget lining itself
     * up with the divider ({@see \Porta\Layout\ViewRegion::header()}'s
     * toolbar) lands on it before the frame that draws it.
     */
    public function leadColumns(int $columns): int
    {
        return \intdiv(\max(0, $columns - 1) * $this->share, num2: 1000);
    }

    /**
     * Drag the divider to screen column $col over the area's rect: the lead
     * side takes everything from its anchored edge to the pointer, clamped so
     * both sides stay usable. The lead is anchored on the left, and on the
     * right in a mirrored workspace — the side {@see arrange()} seats it on.
     */
    public function dragTo(int $col, WidgetRect $area): void
    {
        $leadCols = $this->mirrored ? $area->col + $area->columns - 1 - $col : $col - $area->col;
        $this->setColumns($leadCols, $area->columns);
    }

    /** Move the divider by $delta lead columns (the keyboard nudge). */
    public function nudge(int $delta, WidgetRect $area): void
    {
        $this->setColumns($this->leadColumns($area->columns) + $delta, $area->columns);
    }

    /** The area's last rect while it is on screen, else null — a detached area keeps a stale one. */
    public function rect(): ?WidgetRect
    {
        return null === $this->area->getContext() ? null : $this->renderer?->getWidgetRect($this->area);
    }

    /** Whether the area is too narrow for two sides, and shows them {@see NarrowSplit} instead. */
    public function isNarrow(): bool
    {
        return $this->narrow;
    }

    /** Hear when the split gives up its divider (true) or takes it back (false). */
    public function onNarrow(\Closure $listener): void
    {
        $this->fitListeners[] = $listener;
    }

    /**
     * Hear when the divider moves — a drag, a nudge, a restored share. A
     * widget that lines itself up with it ({@see leadColumns()}) caches its
     * paint like any other, and the move is news only the split has.
     */
    public function onMove(\Closure $listener): void
    {
        $this->moveListeners[] = $listener;
    }

    /**
     * Seat the lead on the reading direction's leading side: left, or right
     * in a mirrored workspace — the order every other pane pair follows.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain: "this workspace reads right-to-left", as WorkspaceLayout::setMirrored().
    public function setMirrored(bool $mirrored): void
    {
        if ($mirrored === $this->mirrored) {
            return;
        }
        $this->mirrored = $mirrored;
        $this->arrange();
    }

    /**
     * Before each frame: give up the divider while the area cannot give both
     * sides their minimum, and take it back when it can. Read off the last
     * frame's rect, which is the area's whole width either way — the
     * children's order changes, never the area's size.
     */
    public function fit(): void
    {
        $rect = $this->rect();
        if (null === $rect) {
            return;
        }
        $narrow = $rect->columns < ((2 * $this->minSide) + 1);
        if ($narrow !== $this->narrow) {
            $this->narrow = $narrow;
            $this->arrange();
            foreach ($this->fitListeners as $listener) {
                $listener($narrow);
            }
        }
    }

    private function setColumns(int $leadCols, int $columns): void
    {
        $room = $columns - 1; // the divider's column
        if ($room < (2 * $this->minSide)) {
            return; // the divider is given up: there is nothing to move
        }
        $this->setShare((int) \round((IntMath::clamp($leadCols, $this->minSide, $room - $this->minSide) * 1000) / $room));
    }

    private function setShare(int $share): void
    {
        if ($share === $this->share) {
            return;
        }
        $this->share = $share;
        $this->applyShare();
        foreach ($this->moveListeners as $listener) {
            $listener();
        }
    }

    /**
     * The sides' weights: the share across, or — stacked — the lead as tall
     * as it draws and the rest taking what is left.
     */
    private function applyShare(): void
    {
        $stacked = $this->narrow && NarrowSplit::Stacked === $this->whenNarrow;
        $this->leadSide->setStyle(new Style(flex: $stacked ? 0 : $this->share));
        $this->restSide->setStyle(new Style(flex: $stacked ? 1 : 1000 - $this->share));
    }

    /**
     * Re-seat the sides. Clearing the area detaches whatever inside it had
     * the keys, and the focus manager hands them to the first focusable it
     * knows — so the focus is put back: on the same widget when it is still
     * on screen, on the lead when it was the other side that stepped aside.
     */
    private function arrange(): void
    {
        $focus = $this->tui?->getFocus();
        $stacked = $this->narrow && NarrowSplit::Stacked === $this->whenNarrow;
        $this->area->setStyle(new Style(direction: $stacked ? Direction::Vertical : Direction::Horizontal, gap: 0, flex: 1));
        $this->applyShare();
        $sides = match (true) {
            $stacked => [$this->leadSide, $this->restSide],
            $this->narrow => [$this->leadSide],
            default => [$this->leadSide, $this->separator, $this->restSide],
        };
        $this->area->clear();
        foreach ($this->mirrored && !$stacked ? \array_reverse($sides) : $sides as $side) {
            $this->area->add($side);
        }
        if (null === $focus || ($focus !== $this->lead && $focus !== $this->rest)) {
            return;
        }
        $this->tui?->setFocus(null !== $focus->getContext() ? $focus : $this->lead);
    }
}
