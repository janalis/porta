<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use Janalis\Viz\GraphViz\DotJsonParser;
use Janalis\Viz\Layout\GraphLayout;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
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

    /**
     * The selection is stroked into the picture, to the pixel. A frame of
     * glyphs lands on whole cells, a cell off the node, and blanks the picture
     * in every cell it covers.
     */
    public function testASelectedNodeIsOutlinedInThePictureItself(): void
    {
        $this->settle();
        $this->widget->render(new RenderContext(80, 24));
        $this->terminal->clearOutput();
        $this->widget->handleInput('n');

        $lines = \implode("\n", $this->widget->render(new RenderContext(80, 24)));

        $this->assertStringNotContainsString('╭', $lines, 'no glyph frame over the picture');
        $this->assertCount(1, PixelGraphics::imageIdsIn($lines), 'the picture fills its cells, the selected node\'s included');
        $images = PixelGraphics::sentImages($this->terminal);
        $this->assertCount(1, $images, 'the picture is sent again, outlined');
        $this->assertGreaterThan(0, self::accentPixels($images[0]), 'the outline is in the accent colour');

        $this->terminal->clearOutput();
        $this->widget->handleInput('n'); // the next node
        $this->widget->render(new RenderContext(80, 24));
        $this->assertCount(1, PixelGraphics::sentImages($this->terminal), 'another selection, another picture');
    }

    /**
     * The outline is the selected node's own box, at the picture's scale: a
     * graph shorter than the pane shows all four of its sides. Fit puts the
     * 416.5-point drawing across 640 pixels, 1.54 a point; each box below is
     * the node's, so scaled, with the stroke's 3 to 4 pixels either side.
     */
    public function testTheOutlineIsTheSelectedNodesBoxAtThePicturesScale(): void
    {
        $engine = new FakeLayoutEngine();
        $engine->nextResult = DotJsonParser::parse((string) \file_get_contents(\dirname(__DIR__, levels: 3).'/tests/Support/fixtures/graph/clustered.json'));
        $engine->nextPng = PngFixture::bytes(1250, 295); // three pixels a point, as asked
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: 8);
        $widget = new GraphViewerWidget($engine, new Translator('en'), scheduler: $this->scheduler, graphics: $graphics);
        $widget->show('/p/arch.dot', 'clustered');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $this->settle();

        $boxes = [];
        foreach (['API', 'Service'] as $node) {
            $terminal->clearOutput();
            $widget->handleInput('n');
            $widget->render(new RenderContext(80, 24));
            $boxes[$node] = self::accentBox(PixelGraphics::sentImages($terminal)[0]);
        }

        // Both 36 points tall, centred 59 points down: rows 63 to 118, stroked.
        $this->assertSame(['API' => [21, 111, 59, 122], 'Service' => [160, 255, 59, 122]], $boxes);
    }

    /** @return array{int, int, int, int} the accent pixels' bounds: x from, x to, y from, y to */
    private static function accentBox(\GdImage $image): array
    {
        $rgb = Styles::dark()->of(Role::TabAccent)->getColor()?->toRgb() ?? ['r' => -1, 'g' => -1, 'b' => -1];
        $accent = ($rgb['r'] << 16) | ($rgb['g'] << 8) | $rgb['b'];
        $xs = [];
        $ys = [];
        for ($x = 0; $x < \imagesx($image); ++$x) {
            for ($y = 0; $y < \imagesy($image); ++$y) {
                if ((\imagecolorat($image, $x, $y) & 0xFF_FF_FF) !== $accent) {
                    continue;
                }
                $xs[] = $x;
                $ys[] = $y;
            }
        }
        self::assertNotSame([], $xs, 'an outline was drawn');

        return [\min($xs), \max($xs), \min($ys), \max($ys)];
    }

    public function testAPictureWithNothingSelectedHasNoOutline(): void
    {
        $this->settle();
        $this->widget->render(new RenderContext(80, 24));

        $this->assertSame(0, self::accentPixels(PixelGraphics::sentImages($this->terminal)[0]));
    }

    /** How many of $image's pixels are the theme's accent — the outline's colour. */
    private static function accentPixels(\GdImage $image): int
    {
        $rgb = Styles::dark()->of(Role::TabAccent)->getColor()?->toRgb() ?? ['r' => -1, 'g' => -1, 'b' => -1];
        $accent = ($rgb['r'] << 16) | ($rgb['g'] << 8) | $rgb['b'];
        $count = 0;
        for ($x = 0; $x < \imagesx($image); ++$x) {
            for ($y = 0; $y < \imagesy($image); ++$y) {
                $count += (\imagecolorat($image, $x, $y) & 0xFF_FF_FF) === $accent ? 1 : 0;
            }
        }

        return $count;
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
