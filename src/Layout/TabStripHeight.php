<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Ui\BidiTui;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\TabBarWidget;

/**
 * How tall the tab strips are: folder tabs, {@see TabStripRenderer::ROWS}
 * rows, on a terminal that can spare the rows, and one row below
 * {@see TabStripRenderer::COMPACT_BELOW} — where every row given to chrome is
 * a line of code lost.
 *
 * A strip's render context carries its PARENT's height, not the terminal's,
 * so the decision is made here, before each frame, and pushed into the two
 * core strips. The frame arithmetic that counts chrome rows reads it too.
 */
#[AsEager]
final class TabStripHeight
{
    private int $rows = 1;

    public function __construct(
        private readonly BidiTui $tui,
        private readonly TabBarWidget $tabBar,
        private readonly DockTabsWidget $dockTabs,
    ) {
        $tui->onBeforeFrame($this->apply(...));
    }

    /** The strips' height for the terminal as it is now. */
    public function rows(): int
    {
        return $this->rows;
    }

    public function apply(): void
    {
        $rows = TabStripRenderer::rowsFor($this->tui->getTerminal()->getRows());
        if ($rows === $this->rows) {
            return;
        }
        $this->rows = $rows;
        $this->tabBar->setStripRows($rows);
        $this->dockTabs->setStripRows($rows);
    }
}
