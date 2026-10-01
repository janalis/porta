<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Layout\ViewRegion;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Opening a view in the view window and giving the workspace back, done once
 * for every view.
 *
 * A view keeps its own open()/close(), because only it knows whether it can
 * open at all — the git view refuses outside a repository — and what state to
 * set up once it has. What it does not have to know is where it is drawn: the
 * editor layout stays mounted, dimmed behind the window
 * ({@see ViewWindow}, drawn by {@see \Porta\Overlay\Presenter\ViewWindowPresenter}).
 */
#[AsIdeService]
final readonly class ViewHost
{
    public function __construct(
        private ViewWindow $window,
        private MenuBarWidget $menuBar,
        private EditorArea $editorArea,
        private Tui $tui,
        private ?ViewRegistry $views = null,
    ) {}

    /**
     * Open view $viewId's $region in the window; every other view yields
     * first — however this one came to open, through the registry or not (an
     * agent's proposal, a git tool launch).
     */
    public function show(ViewRegion $region, string $viewId): void
    {
        $this->views?->yieldTo($viewId);
        // The menu bar is global and full-width, so it stays put; only an open
        // dropdown has to go.
        $this->menuBar->close();
        $this->window->open($region, $viewId);
        $this->tui->requestRender();
    }

    /** Close the window and return focus to the editor behind it. */
    public function restore(): void
    {
        $this->menuBar->close();
        $this->window->close();
        $this->editorArea->focus();
        $this->tui->requestRender();
    }

    /** Focus one of the view's own widgets. */
    public function focus(AbstractWidget&FocusableInterface $widget): void
    {
        $this->tui->setFocus($widget);
    }

    /**
     * What holds focus right now, so a view can find its place in its own ring.
     *
     * A view cycling its panes has to know which one it is on, and the answer
     * is the runtime's, not the region's — the region holds the widgets but not
     * which of them the terminal is typing into. This exists rather than
     * handing a view the whole `Tui`: every other thing the git view did with
     * one was `setFocus` or `requestRender`, which are already here.
     */
    public function focused(): ?AbstractWidget
    {
        return $this->tui->getFocus();
    }

    /** Ask for a frame. */
    public function invalidate(): void
    {
        $this->tui->requestRender();
    }
}
