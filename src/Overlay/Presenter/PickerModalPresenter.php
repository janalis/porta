<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Ui\FrameHooks;
use Porta\Widget\CodeEditorWidget;

/**
 * Presents the editor's pickers — the list overlay, Search Everywhere and the
 * mini prompt — over the whole workspace: the same per-pane span path the
 * Welcome and Find-in-Files modals ride, so a picker centers on the screen
 * (not off-center inside the editor pane) and dims the workspace beneath it.
 * {@see \Porta\Overlay\State\OverlayHost} keeps all state, layout and
 * hit-testing; this presenter only delivers: boxes → spans + shadow, plus the
 * pane-level backdrop dim.
 *
 * Re-composed on every frame while open ({@see FrameHooks::onBeforeFrame}) — the
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

    public function __construct(
        private readonly OverlayDelivery $delivery,
        private readonly CodeEditorWidget $editor,
        ?FrameHooks $frameHooks = null,
    ) {
        $frameHooks?->onBeforeFrame($this->sync(...));
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
                $this->delivery->clearModal();
            }

            return;
        }

        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
            return;
        }
        $placed = $this->editor->layoutPickers($bounds);

        $this->delivery->deliverModal($placed);
        $this->shown = true;
        $this->shadowRegions = array_map(
            static fn (array $box): DropdownRegion => DropdownRegion::shadowClamped($box['top'], $box['col'], $box['width'], \count($box['rows']), $bounds, ltr: true),
            $placed,
        );
    }
}
