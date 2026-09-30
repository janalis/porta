<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Support\IntMath;
use Porta\Ui\FrameHooks;
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
 * The editor beside its live preview: one horizontal area holding the code
 * editor, a drag divider and the preview pane, which {@see
 * \Porta\Editor\BufferService} mounts into the editor body's content slot for
 * a previewed buffer in Split.
 *
 * The two sides are sized by FLEX WEIGHT, not by a width on either widget.
 * A fixed width would mean a `flex: 0` side, and the layout engine measures an
 * intrinsic side by rendering it — the editor, the most expensive widget on
 * screen, twice a frame with its render cache cleared in between. Weights cost
 * nothing to lay out, keep the split's proportion across a terminal resize,
 * and are exactly the persisted value: the editor's share, in thousandths.
 *
 * Neither the editor nor the pane is owned here; they are lent for as long as
 * the buffer shows in Split, and handed back by {@see release()}.
 */
#[AsEager]
#[AsIdeService]
final class PreviewSplit
{
    /** The narrowest either side may be dragged to, and below twice which the preview steps aside. */
    public const int MIN_SIDE = 20;

    /** The editor's share of the split, in thousandths, before anything was dragged. */
    private const int DEFAULT_SHARE = 500;

    public readonly ContainerWidget $area;

    public readonly SeparatorWidget $separator;

    private readonly ContainerWidget $editorSide;

    private readonly ContainerWidget $previewSide;

    /** The editor's share of the columns beside the divider, in thousandths. */
    private int $share = self::DEFAULT_SHARE;

    private bool $mirrored = false;

    /** True while the area is too narrow for two usable sides, and shows the editor alone. */
    private bool $narrow = false;

    private ?AbstractWidget $editor = null;

    private ?AbstractWidget $preview = null;

    public function __construct(
        Styles $styles,
        ?FrameHooks $frames = null,
        private readonly ?Renderer $renderer = null,
        private readonly ?Tui $tui = null,
    ) {
        $this->area = LayoutWidgets::container('preview-split', new Style(direction: Direction::Horizontal, gap: 0, flex: 1));
        $this->editorSide = LayoutWidgets::container('preview-split-editor', new Style());
        $this->previewSide = LayoutWidgets::container('preview-split-pane', new Style());
        $this->separator = LayoutWidgets::separator($styles, 'preview-split-separator');
        $this->applyShare();
        $this->arrange();
        $frames?->onBeforeFrame($this->fit(...));
    }

    /**
     * Lend the two widgets to the split. Each must already be detached from
     * wherever it sat: a widget has one parent, and adding it here does not
     * take it from the last one.
     */
    public function mount(AbstractWidget $editor, AbstractWidget $preview): void
    {
        if ($editor === $this->editor && $preview === $this->preview) {
            return;
        }
        $this->release();
        $this->editor = $editor;
        $this->preview = $preview;
        $this->editorSide->add($editor);
        $this->previewSide->add($preview);
    }

    /** Hand the lent widgets back — detached, so the caller can seat them elsewhere. */
    public function release(): void
    {
        $this->editorSide->clear();
        $this->previewSide->clear();
        $this->editor = null;
        $this->preview = null;
    }

    /** The pane lent to the split right now, or null when nothing is mounted. */
    public function preview(): ?AbstractWidget
    {
        return $this->preview;
    }

    /** The editor's share of the split, as the percent the layout file keeps. */
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
     * Drag the divider to screen column $col over the area's rect: the editor
     * side takes everything from its anchored edge to the pointer, clamped so
     * both sides stay usable. The editor is anchored on the left, and on the
     * right in a mirrored workspace — the side {@see arrange()} seats it on.
     */
    public function dragTo(int $col, WidgetRect $area): void
    {
        $editorCols = $this->mirrored ? $area->col + $area->columns - 1 - $col : $col - $area->col;
        $this->setColumns($editorCols, $area->columns);
    }

    /** Move the divider by $delta editor columns (the keyboard nudge). */
    public function nudge(int $delta, WidgetRect $area): void
    {
        $this->setColumns($this->editorColumns($area->columns) + $delta, $area->columns);
    }

    /** The area's last rect while it is on screen, else null — a detached area keeps a stale one. */
    public function rect(): ?WidgetRect
    {
        return null === $this->area->getContext() ? null : $this->renderer?->getWidgetRect($this->area);
    }

    /**
     * Seat the editor on the reading direction's leading side: left, or right
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
     * Before each frame: step the preview aside while the area cannot give
     * both sides {@see MIN_SIDE} columns, and bring it back when it can. Read
     * off the last frame's rect, which is the area's whole width either way —
     * the children's order changes, never the area's size.
     */
    public function fit(): void
    {
        $rect = $this->rect();
        if (null === $rect) {
            return;
        }
        $narrow = $rect->columns < ((2 * self::MIN_SIDE) + 1);
        if ($narrow !== $this->narrow) {
            $this->narrow = $narrow;
            $this->arrange();
        }
    }

    /** The columns the editor side draws in an area $columns wide. */
    private function editorColumns(int $columns): int
    {
        return (int) \round((($columns - 1) * $this->share) / 1000);
    }

    private function setColumns(int $editorCols, int $columns): void
    {
        $room = $columns - 1; // the divider's column
        if ($room < (2 * self::MIN_SIDE)) {
            return; // the preview has stepped aside: there is no divider to move
        }
        $this->setShare((int) \round((IntMath::clamp($editorCols, self::MIN_SIDE, $room - self::MIN_SIDE) * 1000) / $room));
    }

    private function setShare(int $share): void
    {
        if ($share === $this->share) {
            return;
        }
        $this->share = $share;
        $this->applyShare();
    }

    private function applyShare(): void
    {
        $this->editorSide->setStyle(new Style(flex: $this->share));
        $this->previewSide->setStyle(new Style(flex: 1000 - $this->share));
    }

    /**
     * Re-seat the sides. Clearing the area detaches whatever inside it had
     * the keys, and the focus manager hands them to the first focusable it
     * knows — so the focus is put back: on the same widget when it is still
     * on screen, on the editor when it was the preview that stepped aside.
     */
    private function arrange(): void
    {
        $focus = $this->tui?->getFocus();
        $sides = $this->narrow ? [$this->editorSide] : [$this->editorSide, $this->separator, $this->previewSide];
        $this->area->clear();
        foreach ($this->mirrored ? \array_reverse($sides) : $sides as $side) {
            $this->area->add($side);
        }
        if (null === $focus || $focus !== $this->editor && $focus !== $this->preview) {
            return;
        }
        $this->tui?->setFocus(null !== $focus->getContext() ? $focus : $this->editor);
    }
}
