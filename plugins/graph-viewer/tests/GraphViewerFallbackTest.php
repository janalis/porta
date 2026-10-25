<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidPng;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Tests\Support\DrivesGraphViewer;
use Porta\I18n\Translator;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What the pane says when the picture's tool is missing or fails and the
 * pane falls back on its own drawing — both reasons, in order, never one
 * swallowing the other — and when a new picture keeps the view it had.
 */
final class GraphViewerFallbackTest extends TestCase
{
    use DrivesGraphViewer;

    private const string NO_MMDC = 'mmdc not found — install mermaid-cli, or set the "mermaidPath" setting';

    public function testWithNoMmdcABrokenSourceSaysBothWhatIsMissingAndWhatIsWrong(): void
    {
        $widget = $this->mermaidWithoutCli();
        $widget->show('/p/a.mmd', 'graph TD; A[');
        $this->drain();

        $error = (string) Probe::get($widget, 'error');
        $this->assertStringStartsWith(self::NO_MMDC.' · ', $error);
        $this->assertGreaterThan(\strlen(self::NO_MMDC.' · '), \strlen($error), 'and the reason follows it');
    }

    public function testWithNoMmdcAFailedRasterSaysBothAndRepaints(): void
    {
        $widget = $this->mermaidWithoutCli(new NativeMermaidRenderer(new MermaidTask($this->scheduler), new MermaidPipeline(png: new MermaidPng(gdAvailable: false))));
        $widget->show('/p/a.mmd', 'graph TD; A[Native]');

        $this->assertGreaterThan(0, $this->revisionsSpentOnLastTimer($widget));
        $error = (string) Probe::get($widget, 'error');
        $this->assertStringStartsWith(self::NO_MMDC.' · ', $error);
        $this->assertStringContainsString('GD extension', $error);
    }

    public function testWithNoMmdcTheOwnRasterRepaintsWhenItLands(): void
    {
        $widget = $this->mermaidWithoutCli();
        $widget->show('/p/a.mmd', 'graph TD; A[Native]');

        $this->assertGreaterThan(0, $this->revisionsSpentOnLastTimer($widget));
        $this->assertTrue(Probe::get($widget, 'nativeBitmap'));
    }

    /** An edit drawn the same size keeps the zoom; taller or wider alone, the view starts again. */
    public function testAPictureKeepsItsViewUntilEitherSideChanges(): void
    {
        $mermaid = new FakeLayoutEngine();
        $mermaid->baseDpi = 96;
        $mermaid->nextPng = PngFixture::bytes(120, 80);
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, renderMode: 'bitmap', mermaid: $mermaid);
        $widget->show('/p/a.mmd', 'flowchart LR');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('1'); // 1:1, so every picture below is asked for at mermaid's own 96 dpi
        $this->settle();

        foreach (['same' => [120, 80, true], 'taller' => [120, 81, false], 'wider' => [121, 81, false]] as $what => [$width, $height, $kept]) {
            $before = Probe::get($widget, 'view');
            $mermaid->nextPng = PngFixture::bytes($width, $height);
            $widget->show('/p/a.mmd', "flowchart LR\n%% {$what}");
            $this->settle();

            $after = Probe::get($widget, 'view');
            $this->assertInstanceOf(ZoomPanModel::class, $after);
            $this->assertSame($kept, $before === $after, "{$what}: the view is ".($kept ? 'kept' : 'new'));
            $this->assertSame([$width, $height], $after->dimensions());
        }
        $this->assertSame([96], \array_values(\array_unique($mermaid->renderDpis)));
    }

    /** dot's picture landing, failing to decode, or failing outright: each repaints, and one that lands tells the tab. */
    public function testEveryAnswerOfThePictureRepaints(): void
    {
        foreach (['lands' => PngFixture::bytes(260, 36), 'is no picture' => 'garbage', 'fails' => null] as $what => $answer) {
            $this->engine->nextResult = self::layout();
            $this->engine->deferred = false;
            $widget = $this->widget();
            $handler = new RecordingViewerTabHandler();
            $widget->show('/p/g.dot', 'dot');
            $widget->setHandler($handler);
            $this->engine->deferred = true;
            $this->settle();
            $revision = $widget->getRenderRevision();

            null === $answer ? ($this->engine->pendingPngError)('dot crashed') : ($this->engine->pendingPng)($answer);

            $this->assertGreaterThan($revision, $widget->getRenderRevision(), $what);
            $this->assertSame(null === $answer || 'garbage' === $answer ? [] : ['view'], $handler->events, $what);
        }
    }

    /** Laid out again by the native renderer at the same size, the view is kept; at another size it starts again. */
    public function testANativeRelayoutKeepsItsViewUntilTheSizeChanges(): void
    {
        $widget = new GraphViewerWidget(
            $this->engine,
            new Translator('en'),
            scheduler: $this->scheduler,
            renderMode: 'cells',
            mermaid: new FakeLayoutEngine(),
            native: new NativeMermaidRenderer(new MermaidTask($this->scheduler)),
        );
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/a.mmd', 'graph TD; A[One]');
        $this->drain();
        $view = Probe::get($widget, 'view');

        $handler->events = [];
        $widget->show('/p/a.mmd', 'graph TD; A[Two]');
        $revision = $widget->getRenderRevision();
        $this->drain();
        $this->assertSame($view, Probe::get($widget, 'view'), 'same size: the zoom and pan stay');
        $this->assertGreaterThan($revision, $widget->getRenderRevision());
        $this->assertSame(['view'], $handler->events);

        $widget->show('/p/a.mmd', 'graph TD; A[Two] --> B[Two]');
        $this->drain();
        $taller = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $taller);
        $this->assertNotSame($view, $taller, 'taller: a fresh view');
        $this->assertSame($view->dimensions()[0], $taller->dimensions()[0], 'the premise: only taller');
    }

    private function mermaidWithoutCli(?NativeMermaidRenderer $native = null): GraphViewerWidget
    {
        $mermaid = new FakeLayoutEngine();
        $mermaid->binary = null;

        return new GraphViewerWidget(
            $this->engine,
            new Translator('en'),
            scheduler: $this->scheduler,
            mermaid: $mermaid,
            native: $native ?? new NativeMermaidRenderer(new MermaidTask($this->scheduler)),
        );
    }

    private function drain(): void
    {
        while ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }

    /** Fire the timers one by one; how far the last one moved the render revision. */
    private function revisionsSpentOnLastTimer(GraphViewerWidget $widget): int
    {
        $spent = 0;
        while ([] !== $this->scheduler->scheduled) {
            $before = $widget->getRenderRevision();
            $this->scheduler->fire();
            $spent = $widget->getRenderRevision() - $before;
        }

        return $spent;
    }
}
