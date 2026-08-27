<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Event\PluginContributionsActivated;
use Porta\Event\PluginContributionsRetracted;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneLayout;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Drives the view registrar from the plugin lifecycle's activation facts — a
 * listener rather than a lifecycle dependency because the direct edge is a
 * container cycle: PluginLifecycle → ViewRegistrar → ViewHost → PaneLayout →
 * the overlay stack → WelcomeSteps → PluginEnablement, which is the lifecycle
 * again. Dispatch is synchronous, so the view is built or retracted before the
 * lifecycle's own tail (key rebuild, menu rebuild, repaint) runs.
 */
#[AsIdeService]
final readonly class ViewContributionListener
{
    public function __construct(
        private ViewRegistrar $views,
        private PaneLayout $paneLayout,
        private LayoutController $layout,
    ) {}

    #[AsEventListener]
    public function onActivated(PluginContributionsActivated $event): void
    {
        if (!$this->views->registerFor($event->plugin)) {
            return;
        }
        // The mirror of Ide's boot tail, in the same order: the arrangement
        // mounts the minted region's widgets, and only then can the persisted
        // sidebar width land on them.
        $this->paneLayout->applyDirection();
        $this->layout->applyLayout();
    }

    #[AsEventListener]
    public function onRetracted(PluginContributionsRetracted $event): void
    {
        $this->views->forget($event->plugin);
    }
}
