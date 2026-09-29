<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\DotJsonParser;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/** The graph pane when the terminal draws the bitmap itself. */
final class GraphViewerPixelsTest extends TestCase
{
    private FakeLayoutEngine $engine;
    private FakeScheduler $scheduler;
    private VirtualTerminal $terminal;
    private GraphViewerWidget $widget;

    protected function setUp(): void
    {
        $this->engine = new FakeLayoutEngine();
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $this->scheduler = new FakeScheduler();
        [$graphics, $this->terminal] = PixelGraphics::answered(cellWidth: 8);
        $this->widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, graphics: $graphics);
        $this->widget->show('/p/g.dot', 'digraph G { A -> B; }');
    }

    private static function layout(): GraphLayout
    {
        return DotJsonParser::parse((string) \file_get_contents(\dirname(__DIR__, levels: 3).'/tests/Support/fixtures/graph/small.json'));
    }

    /** Let the bitmap debounce elapse, as the event loop would. */
    private function settle(): void
    {
        while ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }

    public function testTheBitmapIsDrawnByTheTerminalAtTheScreensResolution(): void
    {
        $this->settle();
        $lines = \implode('', $this->widget->render(new RenderContext(80, 24)));
        $this->settle();

        $this->assertSame([1000], PixelGraphics::imageIdsIn($lines));
        $this->assertStringNotContainsString('▀', $lines);
        // Fit is ~31% of a column per point, and a column is 8 screen pixels:
        // 2.5 pixels per point, ceiled to 3 — 216 dpi, asked for straight away
        // rather than after a 72-dpi first picture.
        $this->assertSame([216], $this->engine->renderDpis);
    }

    public function testASelectedNodeIsFramedOverThePicture(): void
    {
        $this->settle();
        $this->widget->render(new RenderContext(80, 24));
        $this->widget->handleInput('n');

        $lines = \implode("\n", $this->widget->render(new RenderContext(80, 24)));

        $this->assertStringContainsString('╭', $lines, 'the selection box splices over the cells like any overlay');
        $this->assertSame([1000], PixelGraphics::imageIdsIn($lines));
    }

    public function testAnotherPaneTakingTheSlotFreesThePictureAndItIsSentAgainOnReturn(): void
    {
        $this->settle();
        $this->widget->render(new RenderContext(80, 24));
        $this->terminal->clearOutput();

        $this->widget->hide();
        $this->assertStringContainsString("\x1b_Ga=d,d=I,i=1000,q=2\x1b\\", $this->terminal->getOutput());

        $this->terminal->clearOutput();
        $this->widget->render(new RenderContext(80, 24));
        $this->assertStringContainsString("\x1b_Ga=t,", $this->terminal->getOutput());
    }

    public function testClearingFreesThePicture(): void
    {
        $this->settle();
        $this->widget->render(new RenderContext(80, 24));
        $this->terminal->clearOutput();

        $this->widget->clear();

        $this->assertStringContainsString("\x1b_Ga=d,d=I,i=1000,q=2\x1b\\", $this->terminal->getOutput());
    }
}
