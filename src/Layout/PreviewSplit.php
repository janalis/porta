<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Ui\FrameHooks;
use Porta\Ui\Theme\Styles;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The editor beside its live preview: one {@see PaneSplit} holding the code
 * editor, a drag divider and the preview pane, which {@see
 * \Porta\Editor\BufferService} mounts into the editor body's content slot for
 * a previewed buffer in Split.
 *
 * What is the preview's own: there is one, for every previewed file, so it is
 * a service the layout controller persists; and a split too narrow for both
 * sides steps the preview aside, where a view's columns would stack.
 */
#[AsEager]
#[AsIdeService]
final readonly class PreviewSplit
{
    /** The narrowest either side may be dragged to, and below twice which the preview steps aside. */
    public const int MIN_SIDE = PaneSplit::MIN_SIDE;

    public ContainerWidget $area;

    public SeparatorWidget $separator;

    private PaneSplit $split;

    public function __construct(
        Styles $styles,
        ?FrameHooks $frames = null,
        ?Renderer $renderer = null,
        ?Tui $tui = null,
    ) {
        $this->split = new PaneSplit('preview-split', $styles, NarrowSplit::LeadAlone, $renderer, $tui);
        $this->area = $this->split->area;
        $this->separator = $this->split->separator;
        $frames?->onBeforeFrame($this->split->fit(...));
    }

    /** Lend the editor and its preview to the split (see {@see PaneSplit::mount()}). */
    public function mount(AbstractWidget $editor, AbstractWidget $preview): void
    {
        $this->split->mount($editor, $preview);
    }

    /** Hand the lent widgets back — detached, so the caller can seat them elsewhere. */
    public function release(): void
    {
        $this->split->release();
    }

    /** The pane lent to the split right now, or null when nothing is mounted. */
    public function preview(): ?AbstractWidget
    {
        return $this->split->rest();
    }

    /** The editor's share of the split, as the percent the layout file keeps. */
    public function percent(): int
    {
        return $this->split->percent();
    }

    /** Restore a persisted proportion; out-of-range values are clamped rather than refused. */
    public function setPercent(int $percent): void
    {
        $this->split->setPercent($percent);
    }

    /** Drag the divider to screen column $col over the area's rect. */
    public function dragTo(int $col, WidgetRect $area): void
    {
        $this->split->dragTo($col, $area);
    }

    /** Move the divider by $delta editor columns (the keyboard nudge). */
    public function nudge(int $delta, WidgetRect $area): void
    {
        $this->split->nudge($delta, $area);
    }

    /** The area's last rect while it is on screen, else null. */
    public function rect(): ?WidgetRect
    {
        return $this->split->rect();
    }

    /** Seat the editor on the reading direction's leading side. */
    public function setMirrored(bool $mirrored): void
    {
        $this->split->setMirrored($mirrored);
    }

    /** Before each frame: step the preview aside while the area is too narrow for both sides. */
    public function fit(): void
    {
        $this->split->fit();
    }
}
