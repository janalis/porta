<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use Janalis\Viz\GraphViz\DotJsonParser;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Tests\Support\DrivesGraphViewer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What the keys do to the graph pane's view, measured against the model they
 * drive, and what the pane says on its top row. Split from
 * {@see GraphViewerWidgetTest}, whose key tests only asked that SOMETHING
 * changed: a pan of the wrong step, or a key that did nothing, passed there.
 */
final class GraphViewerViewTest extends TestCase
{
    use DrivesGraphViewer;

    /**
     * Each key against the model doing the same by hand, from a view panned
     * off its corner so a step either way has room to show.
     */
    public function testEachKeyMovesTheViewExactlyAsTheModelWould(): void
    {
        $widget = $this->shown(40, 6);
        $view = self::view($widget);
        $offCorner = static function () use ($view): void {
            $view->fit();
            $view->actualSize(40, 12);
            $view->panBy(60, 4, 40, 12);
        };

        $keys = [
            "\x1b[D" => static fn (ZoomPanModel $v) => $v->panBy(-2, 0, 40, 12),
            "\x1b[C" => static fn (ZoomPanModel $v) => $v->panBy(2, 0, 40, 12),
            "\x1b[A" => static fn (ZoomPanModel $v) => $v->panBy(0, -4, 40, 12),
            "\x1b[B" => static fn (ZoomPanModel $v) => $v->panBy(0, 4, 40, 12),
            "\x1b[1;2D" => static fn (ZoomPanModel $v) => $v->panBy(-10, 0, 40, 12),
            "\x1b[1;2C" => static fn (ZoomPanModel $v) => $v->panBy(10, 0, 40, 12),
            "\x1b[1;2A" => static fn (ZoomPanModel $v) => $v->panBy(0, -20, 40, 12),
            "\x1b[1;2B" => static fn (ZoomPanModel $v) => $v->panBy(0, 20, 40, 12),
            '=' => static fn (ZoomPanModel $v) => $v->zoomIn(40, 12),
            '+' => static fn (ZoomPanModel $v) => $v->zoomIn(40, 12),
            '-' => static fn (ZoomPanModel $v) => $v->zoomOut(40, 12),
            'f' => static fn (ZoomPanModel $v) => $v->fit(),
        ];
        foreach ($keys as $key => $same) {
            $offCorner();
            $expected = clone $view;
            $same($expected);
            $this->assertNotSame($view->stateKey(), $expected->stateKey(), 'the premise: '.\json_encode($key, \JSON_THROW_ON_ERROR).' has room to move the view');

            $widget->handleInput($key);

            $this->assertSame($expected->stateKey(), $view->stateKey(), \json_encode($key, \JSON_THROW_ON_ERROR).' moved the view by its own step');
        }

        $view->fit();
        $expected = clone $view;
        $expected->actualSize(40, 12);
        $widget->handleInput('1');
        $this->assertSame($expected->stateKey(), $view->stateKey(), '1 is the size of the pane it was pressed in');
    }

    /** The node picked is brought to the middle of the pane, as near as the clamp allows. */
    public function testFocusingANodeCentresIt(): void
    {
        $widget = $this->shown(40, 12);
        $widget->handleInput('1'); // 100%: one point a column, one a half-row

        $widget->focusNode(2); // C, at (232.75, 18)

        $rect = self::view($widget)->visibleRect(40, 24);
        // 232.75 less half the pane's 40 columns, rounded; 18 less half its 24 half-rows.
        $this->assertSame([213, 6], [$rect['srcX'], $rect['srcY']]);
    }

    /** Zoomed in, the same: the node's centre lands mid-pane, to within the point a cell spans. */
    public function testFocusingANodeCentresItAtAnyZoom(): void
    {
        $widget = $this->shown(40, 12);
        $widget->handleInput('1');
        $widget->handleInput('=');
        $view = self::view($widget);
        $scale = $view->effectiveScale(40, 24);
        $this->assertGreaterThan(1.0, $scale, 'the premise: zoomed past 1:1');

        $widget->focusNode(2); // C, at (232.75, 18)

        $rect = $view->visibleRect(40, 24);
        $this->assertEqualsWithDelta(232.75, $rect['srcX'] + (20 / $scale), 1.0, 'across');
        $this->assertEqualsWithDelta(18.0, $rect['srcY'] + (12 / $scale), 1.0, 'down');
    }

    /** `p` with nothing selected starts from the far end, `n` from the near one. */
    public function testThePreviousKeyWithNothingSelectedPicksTheLastNode(): void
    {
        $widget = $this->shown(80, 24);

        $widget->handleInput('p');
        $this->assertSame('3 nodes · 2 edges · C', $widget->statusInfo());
        $widget->handleInput('p');
        $this->assertSame('3 nodes · 2 edges · B', $widget->statusInfo());
    }

    public function testThePickerCountsEveryEdgeOfANode(): void
    {
        $this->engine->nextResult = self::layout('clustered.json');
        $widget = $this->widget();
        $widget->show('/p/arch.dot', 'clustered');

        $hints = \array_column($widget->nodeChoices(), 'hint', 'label');

        $this->assertSame('0 in · 2 out', $hints['API']);
        $this->assertSame('2 in · 0 out', $hints['Database']);
    }

    /** A view is as big as the drawing, rounded UP: a fraction of a point is still drawn. */
    public function testTheViewSpansTheWholeDrawing(): void
    {
        $json = \json_decode((string) \file_get_contents(\dirname(__DIR__, levels: 3).'/tests/Support/fixtures/graph/small.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($json), description: 'the fixture is a JSON object');
        $json['bb'] = '0,0,259.25,36.25';
        $this->engine->nextResult = DotJsonParser::parse(\json_encode($json, \JSON_THROW_ON_ERROR));
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');

        $this->assertSame([260, 37], self::view($widget)->dimensions());
    }

    public function testTheLayoutHintIsTheWholeTopRow(): void
    {
        $widget = $this->shown(30, 8);
        $this->engine->deferred = true;
        $widget->show('/p/g.dot', 'dot v2');

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(30, 8)));

        $hint = ' '.Glyphs::WORKING.' layout… ';
        $this->assertSame($hint.\str_repeat(' ', 30 - AnsiUtils::visibleWidth($hint)), $rows[0]);
        $this->assertStringNotContainsString('layout', $rows[1]);
    }

    /** The tool's own failure is the picture's to say even beside the text, and on the top row. */
    public function testAToolFailureIsBanneredOnTheTopRowEvenBesideTheText(): void
    {
        $widget = $this->shown(40, 10);
        $widget->textBeside(true);
        $this->engine->nextResult = 'dot produced invalid JSON';
        $widget->show('/p/g.dot', 'broken');

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(40, 10)));

        $this->assertStringStartsWith(' ✗ dot produced invalid JSON ', $rows[0]);
        $this->assertStringNotContainsString('invalid JSON', $rows[1]);
    }

    /** With no tab handler, the editor's keys are simply nobody's. */
    public function testTheEditorsKeysWithoutAHandlerDoNothing(): void
    {
        $widget = $this->shown(40, 10);
        $before = self::view($widget)->stateKey();

        foreach (["\x1b[1;5C", "\x1b[1;5D", "\x1bw", "\x10", "\x1b\x13"] as $key) {
            $widget->handleInput($key);
        }

        $this->assertSame($before, self::view($widget)->stateKey());
    }

    public function testEveryEditorKeyTheMapNamesReachesTheHandler(): void
    {
        $widget = $this->widget();
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);

        foreach (["\x1b[1;5C", "\x1b[1;5D", "\x1b[1;5S", "\x1bw", "\x1b\x13"] as $key) {
            $widget->handleInput($key);
        }

        $this->assertSame(['cycle:1', 'cycle:-1', 'close', 'close', 'save_all'], $handler->events); // Go to File is the IDE's own key, not the pane's
    }

    /** A layout that lands later repaints the pane and tells the tab. */
    public function testALateLayoutRepaintsAndTellsTheTab(): void
    {
        $this->engine->deferred = true;
        $widget = $this->widget();
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/g.dot', 'dot');
        $revision = $widget->getRenderRevision();

        $done = $this->engine->pendingDone;
        $this->assertNotNull($done);
        $done(self::layout());

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
        $this->assertSame(['view'], $handler->events);
    }

    public function testALateFailureRepaintsAndTellsTheTab(): void
    {
        $widget = $this->shown(40, 10);
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $this->engine->deferred = true;
        $widget->show('/p/g.dot', 'dot v2');
        $revision = $widget->getRenderRevision();

        $error = $this->engine->pendingError;
        $this->assertNotNull($error);
        $error('dot crashed');

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
        $this->assertSame(['view'], $handler->events);
    }

    public function testTheTextMovingBesideThePaneAndAThemeSwitchRepaint(): void
    {
        $widget = $this->shown(40, 10);

        $revision = $widget->getRenderRevision();
        $widget->textBeside(true);
        $this->assertGreaterThan($revision, $widget->getRenderRevision());

        $revision = $widget->getRenderRevision();
        $widget->refreshTheme();
        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    private function shown(int $cols, int $rows): GraphViewerWidget
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext($cols, $rows));

        return $widget;
    }

    private static function view(GraphViewerWidget $widget): ZoomPanModel
    {
        $view = Probe::get($widget, 'view');
        self::assertInstanceOf(ZoomPanModel::class, $view);

        return $view;
    }
}
