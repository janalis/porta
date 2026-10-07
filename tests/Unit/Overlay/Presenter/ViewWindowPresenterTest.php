<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Layout\ViewRegion;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\BuildsLayoutController;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Widget\Render\ModalFrame;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\ViewWindow;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The view window's per-frame funnel, below the E2e level: what it does
 * before there is anything to lay out against, and once there is.
 */
final class ViewWindowPresenterTest extends TestCase
{
    use BuildsLayoutController;

    private TestOverlayPane $pane;

    private ViewWindow $window;

    private function presenter(): ViewWindowPresenter
    {
        $layout = $this->controller();
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $this->window = new ViewWindow(new ContainerWidget());

        return new ViewWindowPresenter(
            $this->window,
            new ViewRegistry(),
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui),
            $this->renderer,
            $this->tui,
            $layout,
        );
    }

    public function testNothingIsDrawnBeforeTheFirstFrameLaysTheWorkspaceOut(): void
    {
        $presenter = $this->presenter();
        $this->window->open($this->regions->mint('settings'), 'settings');

        $presenter->compose();

        $this->assertNull($presenter->box(), 'no pane has a rect yet: there is nothing to centre against');
        $this->assertSame([], $this->pane->windowSpans);
    }

    public function testOnceAFrameHasLandedTheWindowIsDrawnAndClosingClearsIt(): void
    {
        $presenter = $this->presenter();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $this->window->open($this->regions->mint('settings'), 'settings');

        $presenter->compose();
        $this->assertInstanceOf(ModalBox::class, $presenter->box());
        $this->assertNotSame([], $this->pane->windowSpans, 'the window reached the pane beneath it');

        $this->window->close();
        $presenter->compose();
        $this->assertNull($presenter->box());
        $this->assertSame([], $presenter->regions());
    }

    /**
     * The IDE's hosting, by hand: the frame inside a hidden host, a first
     * frame landed, and a region with $leaf as its content and $side as its
     * sidebar, arranged and open in the window.
     */
    private function hosted(TestOverlayPane $leaf, ?TestOverlayPane $side = null): ViewWindowPresenter
    {
        $layout = $this->controller();
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $host = new ContainerWidget();
        $host->setStyle(new Style(hidden: true));
        $frame = new ContainerWidget();
        $host->add($frame);
        $this->tui->add($host);
        $window = new ViewWindow($frame);
        $presenter = new ViewWindowPresenter(
            $window,
            new ViewRegistry(),
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane]), $this->tui),
            $this->renderer,
            $this->tui,
            $layout,
        );
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $region = $this->regions->mint('demo');
        $region->content()->add($leaf);
        if (null !== $side) {
            $region->sidebar()->add($side);
        }
        foreach ($region->panes() as $pane) {
            $region->area()->add($pane); // what PaneLayout::arrangeViewAreas() does in the IDE
        }
        $window->open($region, 'demo');

        return $presenter;
    }

    /**
     * The two premises the hosting rests on, pinned so a symfony/tui bump
     * that breaks either fails here first: rendered before the frame through
     * the tree renderer, a region's widget records its rect at the cell it is
     * drawn at; and the frame itself, which skips the hidden host, never
     * draws the region.
     */
    public function testTheRegionsWidgetsHitTestAtTheirScreenCellsAndTheFrameNeverDrawsThem(): void
    {
        $leaf = new TestOverlayPane();
        $presenter = $this->hosted($leaf);

        $presenter->compose();
        $box = $presenter->box();
        $this->assertInstanceOf(ModalBox::class, $box);
        $rect = $this->renderer->getWidgetRect($leaf);
        $this->assertInstanceOf(WidgetRect::class, $rect, 'the leaf recorded a rect');
        $this->assertSame($box->startRow + ModalFrame::CHROME_ROWS_TOP, $rect->row, 'on the window\'s first content row');
        $this->assertGreaterThan($box->col, $rect->col, 'inside the window, past its rail');
        $this->assertLessThanOrEqual($box->col + $box->width - 2, $rect->col + $rect->columns, 'and short of its far rail');

        $this->tui->requestRender(force: true);
        $this->tui->processRender();
        $this->assertSame([$rect->row, $rect->col], [$this->renderer->getWidgetRect($leaf)?->row, $this->renderer->getWidgetRect($leaf)?->col], 'a frame does not move it: the host is never laid out');
    }

    /**
     * A flush region's content takes the frame's padding row, right under the
     * title bar; its sidebar and separator keep it as an inset, so they stay
     * where an ordinary region draws them, and the window is no taller.
     */
    public function testAFlushRegionsContentTakesThePaddingRowAndItsSidebarKeepsIt(): void
    {
        $leaf = new TestOverlayPane();
        $side = new TestOverlayPane();
        $presenter = $this->hosted($leaf, $side);
        $region = $this->regions->find('demo');
        $this->assertInstanceOf(ViewRegion::class, $region);
        $region->flushContentTop();

        $presenter->compose();
        $box = $presenter->box();
        $this->assertInstanceOf(ModalBox::class, $box);
        $this->assertSame($box->startRow + ModalFrame::CHROME_ROWS_TOP - 1, $this->renderer->getWidgetRect($leaf)?->row, 'the content starts on the padding row');
        $this->assertSame($box->startRow + ModalFrame::CHROME_ROWS_TOP, $this->renderer->getWidgetRect($side)?->row, 'the sidebar keeps the padding as its inset');
        $rows = \array_values(\array_unique(\array_column($this->pane->windowSpans, 'row')));
        // The box's rows, then the shadow band under them.
        $this->assertSame(\range($box->startRow, $box->startRow + $box->rows), $rows, 'the window is exactly its box: no row added for the content');
        $padding = AnsiUtils::stripAnsiCodes(\implode('', \array_column(\array_filter($this->pane->windowSpans, static fn (array $span): bool => $span['row'] === ($box->startRow + 1)), 'segment')));
        $sideRect = $this->renderer->getWidgetRect($side);
        $this->assertInstanceOf(WidgetRect::class, $sideRect);
        $this->assertStringContainsString('.', $padding, 'the content is drawn on the padding row');
        $this->assertSame(\str_repeat(' ', $sideRect->columns), \mb_substr($padding, $sideRect->col - $box->col, $sideRect->columns), 'the sidebar is not');
        $this->assertSame(2, \mb_substr_count($padding, needle: '│'), 'nor the separator: the padding row has only the rails');
    }
}
