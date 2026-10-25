<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use Janalis\Viz\Layout\GraphLayout;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\GraphViewer\Tests\Support\DrivesGraphViewer;
use Porta\I18n\Translator;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The graph pane as a PICTURE: the bitmap dot and PlantUML render, the
 * resolution it asks for as the view zooms, the render-mode switch between the
 * picture and the cells, and Mermaid's own engine. Split from
 * {@see GraphViewerWidgetTest}, which keeps the laid-out cells, selection and
 * tabs, before it reached the E2e size ratchet.
 */
final class GraphViewerPictureTest extends TestCase
{
    use DrivesGraphViewer;

    public function testShowsTheBitmapDotRenders(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();

        $raw = \implode("\n", $widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('▀', $raw, 'the picture paints as half-blocks');
        $this->assertStringContainsString('38;2;200;40;40', $raw, "and it is dot's pixels, not ours");
        $this->assertStringNotContainsString('┌', self::plain($widget->render(new RenderContext(80, 24))), 'the cell rendering stepped aside');
        $this->assertSame([72], $this->engine->renderDpis, '72 dpi: one point per pixel');
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo(), 'the geometry still answers for the status bar');
    }

    /**
     * Over dot's own picture a click selects what the picture shows in that
     * cell, not what the cell drawing's boxes, rounded outward, would claim:
     * those reach a cell into the empty space around each node, where a click
     * picked a neighbour.
     */
    public function testOverThePictureAClickSelectsTheNodeThePictureShowsThere(): void
    {
        $layout = self::layout();
        $this->engine->nextResult = $layout;
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);

        $differ = [];
        for ($row = 0; $row < 24; ++$row) {
            for ($col = 0; $col < 80; ++$col) {
                $shown = GraphRenderer::nodeShownAt($layout, $view, 80, 24, $row, $col);
                if ($shown !== GraphRenderer::nodeAt($layout, $view, 80, 24, $row, $col)) {
                    $differ[] = [$row, $col, $shown];
                }
            }
        }
        $this->assertNotSame([], $differ, 'the premise: the two disagree somewhere');
        foreach ($differ as [$row, $col, $shown]) {
            $widget->selectAt($row, $col);
            $this->assertSame(
                null === $shown ? '3 nodes · 2 edges' : '3 nodes · 2 edges · '.$layout->nodes[$shown]->label,
                $widget->statusInfo(),
                \sprintf('cell %d,%d', $row, $col),
            );
        }
    }

    public function testTheCellRenderingCoversTheGapUntilTheBitmapArrives(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = null; // dot has not answered yet
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');

        $this->assertStringContainsString('┌', self::plain($widget->render(new RenderContext(80, 24))), 'never a blank pane');
    }

    /**
     * The picture is read at its own density — dpi over the tool's 1:1 — so
     * all of it is on screen at fit: both halves, each at its own end.
     */
    public function testThePictureIsReadAtTheDensityItWasAskedFor(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36, [200, 40, 40], right: [40, 40, 200]);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();

        $rows = $widget->render(new RenderContext(80, 24));
        $middle = $rows[\intdiv(\count($rows), num2: 2)];

        $this->assertSame([72], $this->engine->renderDpis);
        $this->assertStringStartsWith("\x1b[38;2;200;40;40", $middle, 'the left half at the left edge');
        $this->assertStringContainsString('38;2;40;40;200', AnsiUtils::sliceToWidth($middle, 70, 10), 'the right half at the right one');
    }

    /** PlantUML's 1:1 is 96 dpi, not graphviz's 72: read at 72, a third of its picture would be lost. */
    public function testAPlantUmlPictureIsReadAtItsOwnDensity(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(260, 36, [200, 40, 40], right: [40, 40, 200]);
        $widget = $this->widget();
        $widget->show('/p/seq.puml', '@startuml');
        $this->settle();

        $rows = $widget->render(new RenderContext(80, 24));

        $middle = $rows[\intdiv(\count($rows), num2: 2)];
        $this->assertSame([96], $this->plantUml->renderDpis);
        // A slice re-opens the style in force at its start, so what counts is a colour a cell is DRAWN in.
        $this->assertDoesNotMatchRegularExpression('/38;2;40;40;200[;0-9]*m▀/', AnsiUtils::sliceToWidth($middle, 0, 40), 'the halves meet mid-pane');
        $this->assertDoesNotMatchRegularExpression('/38;2;200;40;40[;0-9]*m▀/', AnsiUtils::sliceToWidth($middle, 40, 40));
    }

    public function testAFailedRenderKeepsTheCellsUnderABanner(): void
    {
        // What a graphviz built without libgd says — the case the fallback exists for.
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = 'Format: "png" not recognized.';
        $this->engine->pngIsError = true;
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();

        $screen = self::plain($widget->render(new RenderContext(60, 16)));
        $this->assertStringContainsString('not recognized', $screen, 'the banner says why there is no picture');
        $this->assertStringContainsString('┌', $screen, 'and the cell rendering carries the graph');
    }

    public function testUndecodableBytesReportInsteadOfBlanking(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = 'this is not a PNG';
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();

        $screen = self::plain($widget->render(new RenderContext(60, 16)));
        $this->assertStringContainsString('could not be decoded', $screen);
        $this->assertStringContainsString('┌', $screen);
    }

    public function testATabSwitchNeverPaintsThePreviousTabsPicture(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/a.dot', 'digraph A { A -> B; }');
        $this->settle();
        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))));

        $this->engine->nextPng = null; // the new tab's picture has not arrived
        $widget->show('/p/b.dot', 'digraph B { X -> Y; }');
        $this->settle();
        $screen = self::plain($widget->render(new RenderContext(80, 24)));

        $this->assertStringContainsString('┌', $screen, 'the new tab falls back to cells rather than showing the old picture');
    }

    public function testALateBitmapForAnAbandonedTabIsDropped(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->deferred = true;
        $widget = $this->widget();
        $widget->show('/p/a.dot', 'digraph A { A -> B; }');
        ($this->engine->pendingDone)(self::layout()); // the layout lands, so a bitmap is requested
        $this->settle();
        $deliverA = $this->engine->pendingPng;
        $this->assertNotNull($deliverA);

        $widget->show('/p/b.dot', 'digraph B { X -> Y; }'); // the tab moves on
        $deliverA(PngFixture::bytes(260, 36)); // dot answers for the tab that left

        $this->assertStringNotContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))), 'a stale picture never reaches the pane');
    }

    public function testAZoomStepAsksForASharperBitmap(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $this->assertSame([72], $this->engine->renderDpis);

        // Fit is ~31%, so the first two steps (50%, 100%) still want 1:1 — only
        // magnifying past it needs more pixels than the layout has points.
        $widget->handleInput('=');
        $widget->handleInput('=');
        $this->assertSame([], $this->scheduler->scheduled, 'nothing to re-render at or below 1:1');

        $widget->handleInput('='); // 200%
        $this->settle();
        $this->assertSame([72, 144], $this->engine->renderDpis, 'the picture is re-rendered at twice the resolution');

        $raw = \implode("\n", $widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('▀', $raw, 'and the sharper bitmap is what paints');
    }

    public function testAGlideBetweenTheRungsAsksForOneSharperBitmap(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('1'); // 100%, so a glide crosses into oversampling
        $this->settle();
        $this->assertSame([72], $this->engine->renderDpis, '1:1 wants the same resolution as fit did — nothing to re-render');

        // A Ctrl+scroll burst: several fractional steps, one resolution.
        foreach (\range(1, end: 3) as $ignored) {
            $widget->magnifyAt(1.08, 40, 12);
        }
        $this->assertSame('126%', $widget->zoomLabel(), 'the zoom itself is continuous');
        $this->settle();
        $this->assertSame([72, 144], $this->engine->renderDpis, 'but the dpi ceilings to the next whole multiple');
    }

    public function testAPanNeverAsksForANewBitmap(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $widget->render(new RenderContext(80, 24));

        $widget->handleInput("\x1b[C"); // right
        $widget->scrollBy(0, 4);

        $this->assertSame([], $this->scheduler->scheduled, 'panning changes no resolution');
        $this->assertSame([72], $this->engine->renderDpis);
    }

    public function testTheResolutionIsCappedSoAHugeGraphStaysDecodable(): void
    {
        // 20000 × 20000 points: at 72 dpi that is 400M pixels, 25× over the
        // decoder's ceiling, so the request is capped rather than refused.
        $this->engine->nextResult = new GraphLayout(20_000.0, 20_000.0, [], [], []);
        $this->engine->nextPng = PngFixture::bytes(40, 40);
        $widget = $this->widget();
        $widget->show('/p/huge.dot', 'digraph Huge {}');
        $this->settle();

        $this->assertSame([14], $this->engine->renderDpis, '72 × sqrt(16M / 400M), floored');
    }

    public function testAClearedTabDropsItsPendingRerender(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->assertNotSame([], $this->scheduler->scheduled, 'a render is pending');

        $widget->clear();

        $this->assertSame([], $this->scheduler->scheduled, 'the pane went away; so did the request');
        $this->assertNotSame([], $this->scheduler->cancelled);
    }

    public function testTheSelectionIsFramedOverTheBitmap(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $bare = \implode("\n", $widget->render(new RenderContext(80, 24)));
        $this->assertStringNotContainsString(Glyphs::FRAME_VERTICAL, $bare, 'nothing framed while nothing is selected');

        $widget->handleInput('n'); // select A
        $lines = $widget->render(new RenderContext(80, 24));
        $framed = \implode("\n", $lines);

        $this->assertStringContainsString(Glyphs::FRAME_HORIZONTAL, $framed, 'the selected node is framed');
        $this->assertStringContainsString('▀', $framed, 'over the picture, which is still there');
        foreach ($lines as $line) {
            $this->assertSame(80, AnsiUtils::visibleWidth($line), 'splicing kept every line pane-wide');
        }

        $widget->selectAt(0, 0); // deselect on empty space
        $this->assertStringNotContainsString(Glyphs::FRAME_VERTICAL, \implode("\n", $widget->render(new RenderContext(80, 24))));
    }

    public function testAFramedNodeScrolledOutOfThePaneDrawsNothing(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('n'); // select A, the leftmost node

        $widget->handleInput('1'); // actual size, so the graph overflows the pane
        $widget->scrollBy(400, 0); // pan far past it
        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertStringNotContainsString(Glyphs::FRAME_VERTICAL, \implode("\n", $lines), 'an off-pane selection frames nothing');
        foreach ($lines as $line) {
            $this->assertSame(80, AnsiUtils::visibleWidth($line));
        }
    }

    public function testTheRenderModeKeyFlipsBetweenThePictureAndTheCells(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))));

        $widget->handleInput('v');
        $screen = self::plain($widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('┌', $screen, 'the cells are back');
        $this->assertStringContainsString('calls', $screen, 'with labels as real text');

        $widget->handleInput('v');
        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))), 'and back to the picture');
    }

    public function testThePersistedCellsDefaultNeverAsksDotForAPicture(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, renderMode: 'cells');
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $widget->render(new RenderContext(80, 24));

        $this->assertSame([], $this->scheduler->scheduled, 'no bitmap is even scheduled');
        $this->assertSame([], $this->engine->renderDpis);

        // Asking for the picture is what makes it render.
        $widget->handleInput('v');
        $this->settle();
        $this->assertSame([72], $this->engine->renderDpis);
        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))));
    }

    public function testTheRenderModeSettingAppliesToAStandingPane(): void
    {
        // The Settings page writes `renderMode`; the plugin pushes it here, so
        // the pane already on screen changes without a restart.
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();
        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))), 'the picture, as configured');

        $widget->setRenderMode('cells');
        $this->settle();

        $screen = self::plain($widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('┌', $screen, 'the live edit switched it to cells');
        $this->assertStringContainsString('calls', $screen, 'with labels as real text');
    }

    public function testSettingTheRenderModeItAlreadyHasChangesNothing(): void
    {
        // A no-op must stay one: repainting and re-scheduling a refine on every
        // unrelated settings write would be work per keystroke on the page.
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, renderMode: 'cells');
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $widget->render(new RenderContext(80, 24));
        $this->scheduler->scheduled = [];

        $widget->setRenderMode('cells');

        $this->assertSame([], $this->scheduler->scheduled, 'nothing was scheduled for a mode it already had');
    }

    public function testAnUnknownRenderModeReadsAsThePicture(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, renderMode: 'nonsense');
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();

        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))));
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

    public function testMermaidUsesItsOwnEngineAndRetainsAGoodPictureOnInvalidEdits(): void
    {
        $mermaid = new FakeLayoutEngine();
        $mermaid->baseDpi = 96;
        $mermaid->nextPng = PngFixture::bytes(120, 80);
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, renderMode: 'bitmap', mermaid: $mermaid);
        $widget->show('/p/diagram.mmd', 'flowchart LR');
        $this->settle();
        $this->assertSame([96], $mermaid->renderDpis);
        $this->assertSame(0, $this->engine->layoutCalls);
        $this->assertStringContainsString('▀', \implode("\n", $widget->render(new RenderContext(80, 24))));
        $this->assertSame([], $widget->nodeChoices());
        $widget->handleInput('n');
        $widget->selectAt(4, 4);
        $this->assertSame('', $widget->statusInfo());

        $mermaid->pngIsError = true;
        $mermaid->nextPng = 'parse error at line 2';
        $widget->show('/p/diagram.mmd', 'broken');
        $this->settle();
        $screen = $widget->render(new RenderContext(80, 24));
        $this->assertStringContainsString('parse error at line 2', self::plain($screen));
        $this->assertStringContainsString('▀', \implode("\n", $screen));

        $oldView = Probe::get($widget, 'view');
        $mermaid->pngIsError = false;
        $mermaid->nextPng = PngFixture::bytes(240, 160);
        $widget->show('/p/diagram.mmd', 'flowchart TD');
        $this->settle();
        $this->assertStringNotContainsString('parse error', self::plain($widget->render(new RenderContext(80, 24))));
        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);
        $this->assertNotSame($oldView, $view, 'a larger successful edit replaces the old fit and pan bounds');
        $this->assertSame([240, 160], $view->dimensions());
        $widget->handleInput('1');
        $this->settle();
        $mermaid->nextPng = PngFixture::bytes(480, 320);
        $widget->handleInput('=');
        $this->settle();
        $this->assertSame($view, Probe::get($widget, 'view'), 'resolution-only changes preserve zoom and logical geometry');
        $this->assertSame([96, 96, 96, 192], $mermaid->renderDpis);
        $widget->clear();
    }

    public function testTheMissingMermaidMessageStaysTranslatedAfterTheRendererAnswers(): void
    {
        $translator = new Translator('fr');
        $translator->addCatalogDirectory(\dirname(__DIR__).'/translations');
        $mermaid = new FakeLayoutEngine();
        $mermaid->binary = null;
        $mermaid->pngIsError = true;
        $mermaid->nextPng = 'mmdc not found — install mermaid-cli, or set the "mermaidPath" setting';
        $widget = new GraphViewerWidget($this->engine, $translator, scheduler: $this->scheduler, mermaid: $mermaid);
        $widget->show('/p/diagram.mmd', 'flowchart TD');
        $this->assertStringContainsString('mmdc introuvable', self::plain($widget->render(new RenderContext(100, 10))));
        $this->settle();
        $this->assertStringContainsString('mmdc introuvable', self::plain($widget->render(new RenderContext(100, 10))));
    }

    public function testMermaidMissingBinaryHasAnActionableMessage(): void
    {
        $mermaid = new FakeLayoutEngine();
        $mermaid->binary = null;
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, mermaid: $mermaid);
        $widget->show('/p/diagram.mermaid', 'flowchart TD');
        $screen = self::plain($widget->render(new RenderContext(100, 10)));
        $this->assertStringContainsString('mmdc not found', $screen);
        $this->assertStringContainsString('"mermaidPath" setting', $screen);
    }

    public function testAPlantUmlTabIsThePictureAndNothingElse(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(120, 80);
        $widget = $this->widget();
        $widget->show('/p/seq.puml', "@startuml\nBob -> Alice\n@enduml");
        $this->settle();

        $this->assertSame(0, $this->engine->layoutCalls, 'nothing to lay out — dot is not involved');
        $this->assertSame([96], $this->plantUml->renderDpis, "asked at PlantUML's own 1:1, not graphviz's 72");
        $raw = \implode("\n", $widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('▀', $raw, 'the picture paints');
        $this->assertSame('', $widget->statusInfo(), 'there are no nodes to count');
        $this->assertStringContainsString('Fit', $widget->zoomLabel(), 'and the probe established its extent');
    }

    public function testTheLayoutFedKeysAreInertOnAPlantUmlTab(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(120, 80);
        $widget = $this->widget();
        $widget->show('/p/seq.puml', '@startuml');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $before = \implode("\n", $widget->render(new RenderContext(80, 24)));

        $widget->handleInput('n'); // select next node
        $widget->handleInput('p');
        $widget->selectAt(4, 4); // click-select
        $widget->handleInput('v'); // the cell rendering

        $this->assertSame($before, \implode("\n", $widget->render(new RenderContext(80, 24))), 'none of the geometry keys do anything');
        $this->assertSame('', $widget->statusInfo());
    }

    public function testAPlantUmlZoomReRendersAtTheHigherDpi(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(120, 80);
        $widget = $this->widget();
        $widget->show('/p/seq.puml', '@startuml');
        $this->settle();
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('1'); // actual size
        $this->settle();
        $this->assertSame([96], $this->plantUml->renderDpis, '1:1 is what the probe already produced');

        $widget->handleInput('='); // 200%
        $this->plantUml->nextPng = PngFixture::bytes(240, 160);
        $this->settle();

        $this->assertSame([96, 192], $this->plantUml->renderDpis, 'twice the resolution, in PlantUML units');
    }

    public function testAFailedPlantUmlRenderShowsItsReason(): void
    {
        $this->plantUml->nextPng = 'Syntax Error? (line 2)';
        $this->plantUml->pngIsError = true;
        $widget = $this->widget();
        $widget->show('/p/seq.puml', "@startuml\nbroken");
        $this->settle();

        $this->assertStringContainsString('Syntax Error', self::plain($widget->render(new RenderContext(60, 12))));
    }

    public function testWithoutPlantumlThePaneSaysSo(): void
    {
        $this->plantUml->binary = null;
        $widget = $this->widget();
        $widget->show('/p/seq.puml', '@startuml');

        $screen = self::plain($widget->render(new RenderContext(80, 10)));
        $this->assertStringContainsString('plantuml not found', $screen);
        $this->assertStringContainsString('"plantumlPath" setting', $screen, 'and says what to do about it');
    }

    public function testADotTabAfterAPlantUmlOneStillLaysOut(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(120, 80);
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/seq.puml', '@startuml');
        $this->settle();

        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->assertSame(1, $this->engine->layoutCalls, 'the geometry path is untouched');
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo());
        $this->assertStringContainsString('┌', self::plain($widget->render(new RenderContext(80, 24))));
    }
}
