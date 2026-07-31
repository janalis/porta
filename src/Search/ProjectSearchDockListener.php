<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Container\AsIdeService;
use Porta\Event\DockToolBound;
use Porta\Event\DockToolClosed;
use Porta\Workbench\Dock\ResultsPanelMode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Project search's two reactions to the dock strip: adopt a Search tab's frozen
 * query when it comes on display, and record it into history when it closes.
 *
 * These were the dock's own closure lists — the one place in core that had
 * grown a hand-rolled multicast observer, with `onToolBound()`/`onToolClosed()`
 * setters and two `foreach` fan-outs. The dock dispatches now, so the reactions
 * live with the thing that reacts.
 */
#[AsIdeService]
final readonly class ProjectSearchDockListener
{
    public function __construct(
        private ProjectSearchController $search,
    ) {}

    /**
     * A Search tab is on display: F5, replace and export must act on ITS query,
     * not the last one typed.
     */
    #[AsEventListener]
    public function onToolBound(DockToolBound $event): void
    {
        if (ResultsPanelMode::Search === $event->tab->mode) {
            $this->search->adopt($event->tab);
        }
    }

    /** ×ing a Search tab is what records its query and mask into history. */
    #[AsEventListener]
    public function onToolClosed(DockToolClosed $event): void
    {
        if (null !== $event->tab->searchState) {
            $this->search->closeSearch($event->tab->searchState);
        }
    }
}
