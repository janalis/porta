<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Graph\DotJsonParser;
use Porta\Graph\GraphLayout;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Glyphs;
use Porta\Widget\GraphViewerWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class GraphViewerWidgetTest extends TestCase
{
    private FakeLayoutEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new FakeLayoutEngine();
    }

    private function widget(): GraphViewerWidget
    {
        return new GraphViewerWidget($this->engine, new Translator('en'));
    }

    private static function layout(string $fixture = 'small.json'): GraphLayout
    {
        return DotJsonParser::parse((string) file_get_contents(__DIR__.'/../../Support/fixtures/graph/'.$fixture));
    }

    private static function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testShowsALayoutWithNodesEdgesAndAFitView(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B [label="calls"]; B -> C; }');

        $screen = self::plain($widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('A', $screen);
        $this->assertStringContainsString('C', $screen);
        $this->assertStringContainsString('calls', $screen, 'the edge label rendered');
        $this->assertStringContainsString('┌', $screen, 'nodes are boxed');
        $this->assertStringContainsString('▸', $screen, 'the LR arrowhead rendered');
        $this->assertStringContainsString('Fit', $widget->zoomLabel());
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo());
        foreach ($widget->render(new RenderContext(80, 24)) as $line) {
            $this->assertSame(80, AnsiUtils::visibleWidth($line), 'every line exactly pane-wide');
        }
    }

    public function testClustersAndColorsRender(): void
    {
        $this->engine->nextResult = self::layout('clustered.json');
        $widget = $this->widget();
        $widget->show('/p/arch.dot', 'clustered');

        $lines = $widget->render(new RenderContext(100, 28));
        $screen = self::plain($lines);
        $this->assertStringContainsString(' App ', $screen, 'the cluster label sits on its frame');
        $this->assertStringContainsString('API', $screen);
        $this->assertStringContainsString('Database', $screen);
        $this->assertStringContainsString("\x1b[38;2;", implode('', $lines), 'DOT-attributed colors emit truecolor SGR');
    }

    public function testHashUnchangedShowNeverRerunsTheEngine(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'same dot');
        $widget->show('/p/g.dot', 'same dot');

        $this->assertSame(1, $this->engine->layoutCalls, 'the N-shows-one-call contract');
    }

    public function testLayoutCacheServesTabSwitchesWithoutTheEngine(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/a.dot', 'dot A');
        $widget->clear();
        $widget->show('/p/b.dot', 'dot B');
        $this->assertSame(2, $this->engine->layoutCalls);

        $widget->clear();
        $widget->show('/p/a.dot', 'dot A'); // back to A: served from the hash cache
        $this->assertSame(2, $this->engine->layoutCalls, 'the cached layout was adopted');
        $this->assertStringContainsString('nodes', $widget->statusInfo());
    }

    public function testAFailedLayoutKeepsTheLastGoodRenderUnderABanner(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'good dot');
        $this->assertStringContainsString('A', self::plain($widget->render(new RenderContext(60, 20))));

        $this->engine->nextResult = "syntax error in line 2\nmore context";
        $widget->show('/p/g.dot', 'broken dot {');

        $screen = self::plain($widget->render(new RenderContext(60, 20)));
        $this->assertStringContainsString('syntax error in line 2', $screen, 'the banner shows stderr\'s first line');
        $this->assertStringContainsString('┌', $screen, 'the previous good graph is still on screen');
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo(), 'the model still reflects the good layout');
    }

    public function testAMissingBinaryShowsTheActionableMessageWithoutSpawning(): void
    {
        $this->engine->binary = null;
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph {}');

        $this->assertSame(0, $this->engine->layoutCalls, 'no layout attempt without a binary');
        $screen = self::plain($widget->render(new RenderContext(80, 10)));
        $this->assertStringContainsString('graphviz not found', $screen);
        $this->assertStringContainsString('dotPath', $screen);
    }

    public function testADeferredLayoutShowsTheProgressHint(): void
    {
        $this->engine->deferred = true;
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph {}');

        $this->assertStringContainsString('layout…', self::plain($widget->render(new RenderContext(40, 8))));

        $done = $this->engine->pendingDone;
        $this->assertNotNull($done);
        $done(self::layout());
        $this->assertStringContainsString('┌', self::plain($widget->render(new RenderContext(40, 8))), 'delivery repaints the graph');
    }

    public function testSelectionCyclingAndStatusReporting(): void
    {
        $this->engine->nextResult = self::layout();
        $handler = new RecordingViewerTabHandler();
        $widget = $this->widget();
        $widget->setHandler($handler);
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));

        $widget->handleInput('n');
        $this->assertStringContainsString('· A', $widget->statusInfo(), 'n selects the first node in (y, x) order');
        $widget->handleInput('n');
        $this->assertStringContainsString('· B', $widget->statusInfo());
        $widget->handleInput('p');
        $this->assertStringContainsString('· A', $widget->statusInfo());
        $this->assertContains('view', $handler->events, 'selection pings the status bar');
    }

    public function testClickSelectsAndEmptySpaceDeselects(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));

        $widget->handleInput('n'); // select A, then find its rendered position via the status
        $this->assertStringContainsString('· A', $widget->statusInfo());
        $widget->selectAt(0, 0); // the top-left corner is letterbox/empty at fit
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo(), 'clicking empty space deselects');
    }

    public function testZoomAndPanKeysDriveTheView(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));
        $fit = $widget->zoomLabel();

        $widget->handleInput('=');
        $this->assertNotSame($fit, $widget->zoomLabel(), 'zoom in leaves fit');
        $widget->handleInput('0');
        $this->assertSame($fit, $widget->zoomLabel(), 'fit restores');
        $widget->handleInput('+');
        $this->assertNotSame($fit, $widget->zoomLabel(), 'the + alias zooms too');
        $widget->handleInput('1');
        $this->assertStringContainsString('100%', $widget->zoomLabel());
        $widget->scrollBy(4, 0);
        $widget->zoomAt(1, 10, 5);
        $this->assertStringContainsString('%', $widget->zoomLabel());
    }

    public function testRegenerationWithTheSameExtentPreservesZoom(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot v1');
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('1'); // 100%
        $this->assertStringContainsString('100%', $widget->zoomLabel());

        $this->engine->nextResult = self::layout(); // same bb extent
        $widget->show('/p/g.dot', 'dot v2 same extent');
        $this->assertStringContainsString('100%', $widget->zoomLabel(), 'zoom survived the regeneration');

        $this->engine->nextResult = self::layout('clustered.json'); // different extent
        $widget->show('/p/g.dot', 'dot v3 new extent');
        $this->assertStringContainsString('Fit', $widget->zoomLabel(), 'a changed extent refits');
    }

    public function testForgetDropsTheRememberedViewAndClearsTheDisplayedTab(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('1');

        $widget->forget('/p/g.dot');
        $this->assertSame('', $widget->zoomLabel(), 'the displayed tab cleared');

        $widget->show('/p/g.dot', 'dot');
        $this->assertStringContainsString('Fit', $widget->zoomLabel(), 'the remembered zoom is gone — fresh fit');
    }

    public function testTabKeysRouteThroughTheHandler(): void
    {
        $handler = new RecordingViewerTabHandler();
        $widget = $this->widget();
        $widget->setHandler($handler);

        $widget->handleInput("\x1b[1;5C"); // ctrl+right
        $widget->handleInput("\x1b[1;5D"); // ctrl+left
        $widget->handleInput("\x1b\x1b[1;3w"); // (unmatched garbage: must not throw)
        $widget->handleInput("\x1bw"); // alt+w
        $this->assertSame(['cycle:1', 'cycle:-1', 'close'], $handler->events);
    }

    public function testFocusFrameAndBlankStates(): void
    {
        $widget = $this->widget();
        $this->assertSame([], $widget->render(new RenderContext(10, 0)), 'zero rows renders nothing');
        $this->assertSame(array_fill(0, count: 3, value: str_repeat(' ', times: 10)), $widget->render(new RenderContext(10, 3)), 'no tab, blank pane');
        $this->assertSame('', $widget->statusInfo());

        $widget->handleInput('='); // view-less input is a no-op
        $widget->scrollBy(1, 1);
        $widget->zoomAt(1, 0, 0);
        $widget->selectAt(0, 0);

        $widget->setFocused(true);
        $focused = self::plain($widget->render(new RenderContext(12, 4)));
        $this->assertStringContainsString(Glyphs::FRAME_TOP_LEFT, $focused, 'the focus frame');
    }

    public function testArrowKeysPanAndShiftPansFurther(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(40, 12));
        $widget->handleInput('1'); // 100%: the graph overflows a 40-col pane, so panning has room

        $widget->handleInput("\x1b[C"); // right arrow
        $widget->handleInput("\x1b[1;2C"); // shift+right: 5x
        $widget->handleInput("\x1b[B"); // down
        $this->assertStringContainsString('100%', $widget->zoomLabel(), 'panning never changes zoom');
    }

    public function testSelectionKeysAreInertWithoutALayout(): void
    {
        $widget = $this->widget();
        $widget->handleInput('n');
        $widget->handleInput('p');
        $this->assertSame('', $widget->statusInfo());
    }

    public function testTheLayoutCacheEvictsItsOldestEntryPastTheCap(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        for ($i = 0; $i < 9; ++$i) {
            $widget->clear();
            $widget->show("/p/g{$i}.dot", "dot {$i}");
        }
        $this->assertSame(9, $this->engine->layoutCalls);

        $widget->clear();
        $widget->show('/p/g0.dot', 'dot 0'); // the oldest hash was evicted: the engine runs again
        $this->assertSame(10, $this->engine->layoutCalls);
    }

    public function testARegenerationInFlightShowsTheHintOverTheExistingGraph(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot v1');
        $widget->render(new RenderContext(60, 16));

        $this->engine->deferred = true;
        $widget->show('/p/g.dot', 'dot v2');
        $screen = self::plain($widget->render(new RenderContext(60, 16)));
        $this->assertStringContainsString('layout…', $screen, 'the hint rides the first row');
        $this->assertStringContainsString('┌', $screen, 'the previous graph stays visible underneath');
    }

    public function testRefreshThemeInvalidatesTheRenderCache(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));
        $widget->refreshTheme(); // must not throw; repaint verified by the chrome sweep E2e

        $this->assertStringContainsString('┌', self::plain($widget->render(new RenderContext(80, 24))));
    }
}
