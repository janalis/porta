<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the floating windows that own no pane rect of their own:
 * the Welcome modal, and the view window's FRAME (its title bar, edges and
 * ×; what the window holds answers through the open view's own pointer,
 * whose widgets do have rects). Owns their handles so {@see MouseRouter}
 * does not, and delegates to each one's box geometry
 * ({@see \Porta\Overlay\Region\ModalBox}).
 *
 * Both behave as every window does: the × and a press outside close them,
 * the title bar moves them, the edges resize them. The Welcome modal is
 * app-modal besides — while it is open every wheel and left press is its
 * (the menu branches run first in the router, so the menu bar stays
 * reachable).
 */
final readonly class ModalWindowRouter implements ViewPointer
{
    public function __construct(
        private WelcomeModalController $modal,
        private ViewWindowPresenter $window,
        private Tui $tui,
    ) {}

    /** Whether the Welcome modal is open — the router's swallow-everything gate. */
    public function isModalOpen(): bool
    {
        return $this->modal->isOpen();
    }

    /** Wheel while the modal is open: over the box it scrolls the cards; consumed everywhere. */
    #[\Override]
    public function wheel(WheelGesture $gesture): bool
    {
        $mouse = $gesture->mouse;

        return $this->modal->wheelAt($mouse->row, $mouse->col, $gesture->step);
    }

    /**
     * A left press: the Welcome modal's while it is open, else the view
     * window's frame — false on the window's content, which the view's own
     * pointer answers, and when no window is open.
     */
    #[\Override]
    public function press(MouseEvent $mouse): bool
    {
        if ($this->modal->isOpen()) {
            $this->tui->requestRender();

            return $this->modal->clickAt($mouse->row, $mouse->col);
        }

        return $this->window->press($mouse->row, $mouse->col);
    }

    /** Light the card, the × or the handle under the pointer; a miss clears it. */
    #[\Override]
    public function hover(MouseEvent $mouse): void
    {
        $this->hoverAt($mouse->row, $mouse->col);
    }

    /** The pointer left the windows (a menu took it): whatever was lit on them goes out. */
    public function leave(): void
    {
        $this->hoverAt(-1, -1);
    }

    private function hoverAt(int $row, int $col): void
    {
        if (!$this->modal->isOpen()) {
            $this->window->hover($row, $col);

            return;
        }
        if ($this->modal->hoverAt($row, $col)) {
            $this->tui->requestRender();
        }
    }

    /** Continue an armed move or resize of either window; disarms on release. */
    #[\Override]
    public function continueDrag(MouseEvent $mouse): bool
    {
        if (!$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }

        return $this->modal->continueDragTo($mouse->row, $mouse->col, $mouse->isLeftRelease()) || $this->window->dragTo($mouse->row, $mouse->col, $mouse->isLeftRelease());
    }

    /** A fresh press anywhere disarms a stale drag (the router's cancel sweep). */
    #[\Override]
    public function cancelDrag(): void
    {
        $this->modal->cancelDrag();
        $this->window->cancelDrag();
    }
}
