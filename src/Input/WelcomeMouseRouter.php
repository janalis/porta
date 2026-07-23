<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\WelcomeModalController;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the Welcome modal. Owns the controller handle so
 * {@see MouseRouter} does not, mirroring its view-router peers — but where
 * those hit-test swapped-in pane rects, this one delegates to the modal's own
 * box geometry ({@see \Porta\Overlay\Region\ModalBox}), because the modal is a
 * composited overlay with no rect of its own.
 *
 * Modality lives here: while the modal is open every wheel and left press is
 * consumed (the menu branches run first in the router, so the menu bar stays
 * reachable), and an outside click is swallowed without dismissing — the ×
 * and Esc are the ways out.
 */
final class WelcomeMouseRouter
{
    public function __construct(
        private readonly WelcomeModalController $modal,
        private readonly Tui $tui,
    ) {}

    /** Whether the modal is open — the router's swallow-everything gate. */
    public function isModalOpen(): bool
    {
        return $this->modal->isOpen();
    }

    /** Wheel while the modal is open: over the box it scrolls the cards; consumed everywhere. */
    public function handleWheel(MouseEvent $mouse): bool
    {
        return $this->modal->wheelAt($mouse->row, $mouse->col, WheelStep::rows($mouse));
    }

    /** A left press while the modal is open: ×/drag-arm/select, swallowed everywhere else. */
    public function tryModalClick(MouseEvent $mouse): bool
    {
        if (!$this->modal->clickAt($mouse->row, $mouse->col)) {
            return false;
        }
        $this->tui->requestRender();

        return true;
    }

    /** Tint the card or the × under the pointer; a miss clears the tint. */
    public function hover(MouseEvent $mouse): void
    {
        if ($this->modal->hoverAt($mouse->row, $mouse->col)) {
            $this->tui->requestRender();
        }
    }

    /** Continue an armed title-bar drag (repositioning the box); disarms on release. */
    public function continueDrag(MouseEvent $mouse): bool
    {
        if (!$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }

        return $this->modal->continueDragTo($mouse->row, $mouse->col, $mouse->isLeftRelease());
    }

    /** A fresh press anywhere disarms a stale drag (the router's cancel sweep). */
    public function cancelDrag(): void
    {
        $this->modal->cancelDrag();
    }
}
