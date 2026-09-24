<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Ui\BidiTui;
use Porta\Ui\Density;
use Porta\Ui\ScreenDensity;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\ChromeInvalidator;

/**
 * How much chrome the terminal affords, decided before each frame: the
 * {@see ScreenDensity} every boxed control and folder-tab strip reads, from
 * the terminal's rows ({@see Density}).
 *
 * A widget's render context carries its PARENT's height, not the terminal's,
 * so the decision is made here and pushed: the two core strips take their
 * height, and a flip clears every chrome cache — a widget caches on its own
 * size, which a resize across the threshold may leave unchanged.
 */
#[AsEager]
final readonly class ChromeHeight
{
    public function __construct(
        private BidiTui $tui,
        private ScreenDensity $density,
        private TabBarWidget $tabBar,
        private DockTabsWidget $dockTabs,
        private ChromeInvalidator $chrome,
    ) {
        $density->observe($tui->getTerminal()->getRows());
        $this->push();
        $tui->onBeforeFrame($this->apply(...));
    }

    public function apply(): void
    {
        if (!$this->density->observe($this->tui->getTerminal()->getRows())) {
            return;
        }
        $this->push();
        $this->chrome->invalidateAll();
    }

    private function push(): void
    {
        $rows = TabStripRenderer::rowsFor($this->density->current());
        $this->tabBar->setStripRows($rows);
        $this->dockTabs->setStripRows($rows);
    }
}
