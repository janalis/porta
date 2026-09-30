<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\OverlayHost;
use Porta\Ui\FrameHooks;

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
 * (`$shown`).
 *
 * Resolved eagerly by {@see \Porta\Ide} — nothing else references it, and
 * without the eager resolve the pickers would never paint.
 *
 * Reads {@see OverlayHost} directly rather than through the editor: these boxes
 * float over the workspace, so whether the EDITOR happens to be attached is not
 * their business. It used to be, and the pickers went dark under a full-screen
 * view.
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
        private readonly OverlayHost $overlays,
        ?FrameHooks $frameHooks = null,
    ) {
        $frameHooks?->onBeforeFrame($this->sync(...));
    }

    /**
     * The painted boxes (shadow bands included) as bidi floating regions, each
     * its own run: under RTL the title, the legends, the notes and the rows'
     * layout mirror with the locale, as the toasts and Welcome do. A path or a
     * symbol is a Latin run inside its row, and the bidi algorithm keeps a
     * Latin run in its own order — the label stays readable, only its place
     * in the row moves to where an RTL reader starts.
     *
     * @return list<DropdownRegion>
     */
    public function regions(): array
    {
        return $this->overlays->anyOpen() ? $this->shadowRegions : [];
    }

    /**
     * The per-frame delivery: lay the pickers out against the pane union and
     * push their spans, shadows and the backdrop; clear them all exactly once
     * after the last one closes (never every frame — the full modals share
     * the span slot).
     */
    public function sync(): void
    {
        if (!$this->overlays->anyOpen()) {
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
        $placed = $this->overlays->layout($bounds);

        $this->delivery->deliverModal($placed);
        $this->shown = true;
        $shown = $this->delivery->shown();
        $this->shadowRegions = \array_map(
            static fn (array $box): DropdownRegion => DropdownRegion::shadowClamped($box['top'], $box['col'], $box['width'], \count($box['rows']), $bounds, shown: $shown),
            $placed,
        );
    }
}
