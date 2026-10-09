<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the Documentation window: the page (links, the header's
 * arrows and crumbs, the wheel) and its own find bar. Its rects exist only
 * while the window is open, so its hit tests miss otherwise.
 */
final readonly class HelpMouseRouter implements ViewPointer
{
    public function __construct(
        private HitTester $hit,
        private Tui $tui,
        private HelpViewerWidget $page,
        private FindBarWidget $find,
    ) {}

    /** The wheel scrolls the page, as it did as a tab. */
    #[\Override]
    public function wheel(WheelGesture $gesture): bool
    {
        if (WheelAxis::Horizontal === $gesture->axis || null === $this->hit->hit($this->page, $gesture->mouse->row, $gesture->mouse->col)) {
            return false;
        }
        $this->page->wheelBy($gesture->step);

        return true;
    }

    #[\Override]
    public function press(MouseEvent $mouse): bool
    {
        $onFind = $this->hit->hit($this->find, $mouse->row, $mouse->col);
        $onPage = null === $onFind ? $this->hit->hit($this->page, $mouse->row, $mouse->col) : null;
        if (null !== $onFind) {
            $this->tui->setFocus($this->find);
            $this->find->handleClick($onFind['row'], $onFind['col']);
        }
        if (null !== $onPage) {
            $this->tui->setFocus($this->page);
            $this->page->press($onPage['row'], $onPage['col']);
        }
        $pressed = null !== $onFind || null !== $onPage;
        if ($pressed) {
            $this->tui->requestRender();
        }

        return $pressed;
    }

    /** Nothing in the window arms a drag. */
    #[\Override]
    public function continueDrag(MouseEvent $mouse): bool
    {
        return false;
    }

    #[\Override]
    public function cancelDrag(): void {}

    /** The find bar's buttons light under the pointer. */
    #[\Override]
    public function hover(MouseEvent $mouse): void
    {
        $rel = $this->hit->hit($this->find, $mouse->row, $mouse->col);
        if (null === $rel ? $this->find->clearHover() : $this->find->hoverAt($rel['row'], $rel['col'])) {
            $this->tui->requestRender();
        }
    }
}
