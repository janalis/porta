<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\PaneBounds;
use Porta\Overlay\Region\SpanSlicer;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Ui\BidiTui;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Render\ModalShadow;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Presents the editor's pickers — the list overlay, Search Everywhere and the
 * mini prompt — over the whole workspace: the same per-pane span path the
 * Welcome and Find-in-Files modals ride, so a picker centers on the screen
 * (not off-center inside the editor pane) and dims the workspace beneath it.
 * {@see \Porta\Overlay\State\OverlayHost} keeps all state, layout and
 * hit-testing; this presenter only delivers: boxes → spans + shadow, plus the
 * pane-level backdrop dim.
 *
 * Re-composed on every frame while open ({@see BidiTui::onBeforeFrame}) — the
 * query caret blinks and search rows stream in asynchronously, matching the
 * cost the pickers had as editor-composited overlays. The span slot is shared
 * with the two full modals: opening a picker closes them (the
 * CodeEditorWidget interlock) and each presenter clears only what it painted
 * (`$shown`). While the editor is detached (image tab, git/settings view) the
 * pickers stay invisible and the presenter paints nothing — mirroring the
 * InputDispatcher guards that keep an invisible modal from trapping keys.
 *
 * Resolved eagerly by {@see \Porta\Ide} — nothing else references it, and
 * without the eager resolve the pickers would never paint.
 */
#[AsEager(priority: 200)]
final class PickerModalPresenter
{
    /** Whether this presenter's spans are currently painted (its clear-once guard). */
    private bool $shown = false;

    /** @var list<DropdownRegion> */
    private array $shadowRegions = [];

    private readonly Styles $styles;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MenuOverlayPanes $panes,
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        if ($this->tui instanceof BidiTui) {
            $this->tui->onBeforeFrame($this->sync(...));
        }
    }

    /**
     * The painted boxes (shadow bands included) as LTR floating regions: the
     * pickers carry file paths and code, so under RTL they stay verbatim —
     * their own runs, never mirrored with the prose panes beneath.
     *
     * @return list<DropdownRegion>
     */
    public function regions(): array
    {
        return null !== $this->editor->getContext() && $this->editor->isAnyPickerOpen() ? $this->shadowRegions : [];
    }

    /**
     * The per-frame delivery: lay the pickers out against the pane union and
     * push their spans, shadows and the backdrop; clear them all exactly once
     * after the last one closes (never every frame — the full modals share
     * the span slot).
     */
    public function sync(): void
    {
        if (null === $this->editor->getContext() || !$this->editor->isAnyPickerOpen()) {
            if ($this->shown) {
                $this->shown = false;
                $this->shadowRegions = [];
                foreach ($this->panes->all() as $pane) {
                    $pane->setModalOverlay([]);
                    $pane->setModalBackdrop(false);
                }
            }

            return;
        }

        $bounds = PaneBounds::union($this->panes->current(), $this->renderer);
        if (null === $bounds) {
            // No rects yet: keep asking for frames until the first one lands.
            $this->tui->requestRender();

            return;
        }
        $placed = $this->editor->layoutPickers($bounds);
        [, , $boundBottom, $boundRight] = $bounds;

        foreach ($this->panes->current() as $pane) {
            $rect = $this->renderer->getWidgetRect($pane);
            $spans = [];
            if (null !== $rect) {
                foreach ($placed as $box) {
                    $spans = [
                        ...$spans,
                        ...SpanSlicer::localSpans($box['rows'], $rect, $box['top'], $box['col'], $box['width']),
                        ...ModalShadow::spans($rect, $box['top'], $box['col'], $box['width'], \count($box['rows']), $this->styles),
                    ];
                }
            }
            $pane->setModalOverlay($spans);
            $pane->setModalBackdrop(true);
        }
        $this->shown = true;
        // Cover only the shadow bands that actually paint, so the RTL
        // segmenter never claims cells outside a box.
        $this->shadowRegions = array_map(
            fn (array $box): DropdownRegion => new DropdownRegion(
                $box['top'],
                $box['col'],
                min($box['width'] + 1, $boundRight - $box['col']),
                min(\count($box['rows']) + 1, $boundBottom - $box['top']),
                ltr: true,
            ),
            $placed,
        );
    }
}
