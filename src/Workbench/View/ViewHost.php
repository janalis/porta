<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Container\LayoutSlot;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\ViewRegion;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The main-area swap, done once for every view.
 *
 * Taking over the workspace and giving it back was written out in each view
 * controller: close any open menu dropdown so its tracked hit region cannot go
 * stale across the swap, clear `main`, add the region — and in reverse, restore
 * the editor layout's panes in their RTL-aware order and hand focus back. Two
 * copies, identical line for line, and four constructor dependencies apiece
 * that exist for nothing else.
 *
 * A view keeps its own open()/close(), because only it knows whether it can
 * open at all — the git view refuses outside a repository — and what state to
 * set up once it has. What it no longer has to know is how the workspace is
 * assembled.
 */
#[AsIdeService]
final readonly class ViewHost
{
    public function __construct(
        #[LayoutSlot('main')]
        private ContainerWidget $main,
        private MenuBarWidget $menuBar,
        private EditorAreaPanes $editArea,
        private EditorArea $editorArea,
        private Tui $tui,
    ) {}

    /** Swap $region into the main area. */
    public function show(ViewRegion $region): void
    {
        // The menu bar is global and full-width, so it stays put; only an open
        // dropdown has to go.
        $this->menuBar->close();
        $this->main->clear();
        $this->main->add($region->area());
    }

    /** Give the main area back to the editor layout and return focus to it. */
    public function restore(): void
    {
        $this->menuBar->close();
        $this->main->clear();
        foreach ($this->editArea->orderedEditorPanes() as $pane) {
            $this->main->add($pane);
        }
        $this->editorArea->focus();
    }

    /** Focus one of the view's own widgets. */
    public function focus(AbstractWidget&FocusableInterface $widget): void
    {
        $this->tui->setFocus($widget);
    }

    /** Ask for a frame. */
    public function invalidate(): void
    {
        $this->tui->requestRender();
    }
}
