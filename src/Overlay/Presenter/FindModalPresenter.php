<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Ui\CaretVisibility;
use Porta\Ui\FrameHooks;
use Porta\Widget\CodeEditorWidget;

/**
 * Presents the Find-in-Files modal over the whole workspace — the same
 * per-pane span path the Welcome modal uses, so the box floats (and drags)
 * over the tree, the tab bar and the dock instead of being clipped to the
 * editor pane it used to be composited into. The modal itself
 * ({@see \Porta\Overlay\State\FindInFilesModal}) keeps all state, layout
 * and hit-testing; this presenter only delivers: panel → spans + shadow,
 * plus the pane-level backdrop dim.
 *
 * Re-composed on every frame while open ({@see FrameHooks::onBeforeFrame}) —
 * the modal's content streams in asynchronously (search results) and its
 * caret blinks, so a per-frame rebuild matches the cost it had as an
 * editor-composited overlay. The two floating modals share the pane span
 * slot safely: the view registry's interlock means they are never open
 * together, and each clears only what it painted (`$shown`).
 *
 * Resolved eagerly by {@see \Porta\Ide} — nothing else references it, and
 * without the eager resolve the modal would never paint.
 */
#[AsEager(priority: 300)]
final class FindModalPresenter
{
    /** Whether this presenter's spans are currently painted (its clear-once guard). */
    private bool $shown = false;

    private ?DropdownRegion $shadowRegion = null;

    public function __construct(
        private readonly OverlayDelivery $delivery,
        private readonly CodeEditorWidget $editor,
        ?FrameHooks $frameHooks = null,
    ) {
        $frameHooks?->onBeforeFrame($this->sync(...));
    }

    /**
     * The box (shadow bands included) as an LTR floating region: the modal
     * carries code content, so under RTL it stays verbatim — its own run,
     * never mirrored with the prose panes beneath.
     */
    public function region(): ?DropdownRegion
    {
        return $this->editor->isFindModalOpen() ? $this->shadowRegion : null;
    }

    /**
     * The per-frame delivery: lay the modal out against the pane union and
     * push its spans, shadow and backdrop; clear them all exactly once after
     * it closes (never every frame — the Welcome modal shares the span slot).
     */
    public function sync(): void
    {
        if (!$this->editor->isFindModalOpen()) {
            if ($this->shown) {
                $this->shown = false;
                $this->shadowRegion = null;
                $this->delivery->clearModal();
            }

            return;
        }

        $bounds = $this->delivery->bounds();
        if (null === $bounds) {
            return;
        }
        // The modal owns the caret while open, wherever pane focus sits.
        $placed = $this->editor->findModal()->layout($bounds, CaretVisibility::Shown);
        \assert(null !== $placed, description: 'layout() is non-null while the modal is open — checked above');
        $width = $this->editor->findModal()->lastBox()->width ?? 0;

        $this->delivery->deliverModal([['rows' => $placed['rows'], 'top' => $placed['top'], 'col' => $placed['col'], 'width' => $width]]);
        $this->shown = true;
        $this->shadowRegion = DropdownRegion::shadowClamped($placed['top'], $placed['col'], $width, \count($placed['rows']), $bounds, ltr: true);
    }
}
