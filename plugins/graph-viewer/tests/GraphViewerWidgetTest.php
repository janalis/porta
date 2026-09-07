<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\GraphViewer\Graph\DotJsonParser;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Glyphs;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class GraphViewerWidgetTest extends TestCase
{
    private FakeLayoutEngine $engine;
    private FakeLayoutEngine $plantUml;
    private FakeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->engine = new FakeLayoutEngine();
        $this->plantUml = new FakeLayoutEngine();
        $this->plantUml->baseDpi = 96; // PlantUML's own 1:1
        $this->scheduler = new FakeScheduler();
    }

    private function widget(): GraphViewerWidget
    {
        return new GraphViewerWidget($this->engine, new Translator('en'), $this->plantUml, scheduler: $this->scheduler);
    }

    /** Let the bitmap debounce elapse, as the event loop would. */
    private function settle(): void
    {
        if ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }

    private static function layout(string $fixture = 'small.json'): GraphLayout
    {
        return DotJsonParser::parse((string) file_get_contents(\dirname(__DIR__, levels: 3).'/tests/Support/fixtures/graph/'.$fixture));
    }

    private static function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testItBindsExactlyTheActionsThePluginDeclares(): void
    {
        // Both halves belong to this plugin now: plugin.php declares the ids and
        // labels Settings → Keymap lists, the pane binds the keys. Core sees
        // neither, so this is the only place the pair can be pinned — and each
        // direction catches a real defect the other cannot see: a declared
        // action nothing binds is a keymap row that does nothing, and a bound
        // key nothing declares is a key the user can never find or rebind.
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = new PluginApi('graph-viewer', $manager, '/p', new PluginSettings('/p', []));
        $plugin = require \dirname(__DIR__).'/plugin.php';
        $plugin->activate($api);

        $declared = array_column($api->bufferedActions(), 'id');
        /** @var array<string, list<string>> $bound */
        $bound = Probe::invokeStatic(GraphViewerWidget::class, 'getDefaultKeybindings');
        // Scoped to the ids CORE does not declare — a pane also binds core
        // actions (goto_file, next_buffer…), which are the catalog's rows. What
        // must match is the remainder: the actions that exist only because this
        // plugin does.
        $ours = array_keys(array_diff_key($bound, ActionCatalog::ACTIONS));

        $this->assertNotSame([], $declared);
        sort($declared);
        sort($ours);
        $this->assertSame($ours, $declared, 'every declared action is bound, and every key core does not declare is declared here');
    }

    public function testTheStatusSegmentNamesTheGraphsOwnKeys(): void
    {
        // n/p select, which the image pane has no equivalent for — the reason a
        // pane owns its hint text instead of the status bar branching on kind.
        $widget = $this->widget();

        $this->assertStringContainsString('n/p select', $widget->statusRight());
        $this->assertSame('graph', $widget->paneId());
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

    /**
     * The rows are the picker's whole content, and the DEGREE is what a list
     * can say that the picture cannot — the hub you are looking for is drawn
     * exactly like every other box.
     */
    public function testEveryNodeIsOfferedAsAPickerRowWithItsDegree(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));

        $choices = $widget->nodeChoices();

        $this->assertSame(['A', 'B', 'C'], array_column($choices, 'label'), 'every node, in the n/p order');
        // The fixture is the chain A -> B -> C, so the ends disagree about
        // which way their single edge points — swapping in for out swaps A's
        // hint with C's.
        $this->assertSame(['0 in · 1 out', '1 in · 1 out', '1 in · 0 out'], array_column($choices, 'hint'));
        $this->assertSame([], array_filter($choices, static fn (array $row): bool => '' === $row['value']), 'every row carries a node id');
    }

    public function testWithNoLayoutThereIsNothingToPickAndNoPickerIsAsked(): void
    {
        $asked = 0;
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, onFindNode: static function () use (&$asked): void {
            ++$asked;
        });

        $this->assertSame([], $widget->nodeChoices(), 'a pane showing nothing offers nothing');
        $widget->handleInput('/');
        $this->assertSame(0, $asked, 'and the key is a no-op rather than an empty overlay');
    }

    /**
     * The key and the picker are separate halves on purpose: the widget knows
     * the key was pressed, the plugin owns the overlay. This is the seam.
     */
    public function testTheFindKeyAsksForThePickerAndTheResultSelectsAndPans(): void
    {
        $this->engine->nextResult = self::layout();
        $asked = 0;
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, onFindNode: static function () use (&$asked): void {
            ++$asked;
        });
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));

        $widget->handleInput('/');
        $this->assertSame(1, $asked);

        $last = $widget->nodeChoices()[2];
        $widget->focusNode((int) $last['value']);
        $this->assertStringContainsString('· '.$last['label'], $widget->statusInfo(), 'picking a row selects that node');
    }

    public function testFocusingANodeThatIsNotThereChangesNothing(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'dot');
        $widget->render(new RenderContext(80, 24));
        $widget->handleInput('n');

        $widget->focusNode(9999);

        $this->assertStringContainsString('· A', $widget->statusInfo(), 'a stale id must not clear the selection');
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

    public function testSelectionAndZoomAreInertWithoutALayout(): void
    {
        $widget = $this->widget();
        $widget->handleInput('n');
        $widget->handleInput('p');
        $widget->zoomAt(1, 0, 0);
        $widget->magnifyAt(1.08, 0, 0); // a stray wheel burst before the layout lands
        $this->assertSame('', $widget->statusInfo());
        $this->assertSame('', $widget->zoomLabel());
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

    public function testShowsTheBitmapDotRenders(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = PngFixture::bytes(260, 36);
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');
        $this->settle();

        $raw = implode("\n", $widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('▀', $raw, 'the picture paints as half-blocks');
        $this->assertStringContainsString('38;2;200;40;40', $raw, "and it is dot's pixels, not ours");
        $this->assertStringNotContainsString('┌', self::plain($widget->render(new RenderContext(80, 24))), 'the cell rendering stepped aside');
        $this->assertSame([72], $this->engine->renderDpis, '72 dpi: one point per pixel');
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo(), 'the geometry still answers for the status bar');
    }

    public function testTheCellRenderingCoversTheGapUntilTheBitmapArrives(): void
    {
        $this->engine->nextResult = self::layout();
        $this->engine->nextPng = null; // dot has not answered yet
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'digraph G { A -> B; }');

        $this->assertStringContainsString('┌', self::plain($widget->render(new RenderContext(80, 24))), 'never a blank pane');
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
        $this->assertStringContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))));

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

        $this->assertStringNotContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))), 'a stale picture never reaches the pane');
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

        $raw = implode("\n", $widget->render(new RenderContext(80, 24)));
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
        foreach (range(1, end: 3) as $ignored) {
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
        $bare = implode("\n", $widget->render(new RenderContext(80, 24)));
        $this->assertStringNotContainsString(Glyphs::FRAME_VERTICAL, $bare, 'nothing framed while nothing is selected');

        $widget->handleInput('n'); // select A
        $lines = $widget->render(new RenderContext(80, 24));
        $framed = implode("\n", $lines);

        $this->assertStringContainsString(Glyphs::FRAME_HORIZONTAL, $framed, 'the selected node is framed');
        $this->assertStringContainsString('▀', $framed, 'over the picture, which is still there');
        foreach ($lines as $line) {
            $this->assertSame(80, AnsiUtils::visibleWidth($line), 'splicing kept every line pane-wide');
        }

        $widget->selectAt(0, 0); // deselect on empty space
        $this->assertStringNotContainsString(Glyphs::FRAME_VERTICAL, implode("\n", $widget->render(new RenderContext(80, 24))));
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

        $this->assertStringNotContainsString(Glyphs::FRAME_VERTICAL, implode("\n", $lines), 'an off-pane selection frames nothing');
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
        $this->assertStringContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))));

        $widget->handleInput('v');
        $screen = self::plain($widget->render(new RenderContext(80, 24)));
        $this->assertStringContainsString('┌', $screen, 'the cells are back');
        $this->assertStringContainsString('calls', $screen, 'with labels as real text');

        $widget->handleInput('v');
        $this->assertStringContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))), 'and back to the picture');
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
        $this->assertStringContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))));
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
        $this->assertStringContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))), 'the picture, as configured');

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

        $this->assertStringContainsString('▀', implode("\n", $widget->render(new RenderContext(80, 24))));
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

    public function testAPlantUmlTabIsThePictureAndNothingElse(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(120, 80);
        $widget = $this->widget();
        $widget->show('/p/seq.puml', "@startuml\nBob -> Alice\n@enduml");
        $this->settle();

        $this->assertSame(0, $this->engine->layoutCalls, 'nothing to lay out — dot is not involved');
        $this->assertSame([96], $this->plantUml->renderDpis, "asked at PlantUML's own 1:1, not graphviz's 72");
        $raw = implode("\n", $widget->render(new RenderContext(80, 24)));
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
        $before = implode("\n", $widget->render(new RenderContext(80, 24)));

        $widget->handleInput('n'); // select next node
        $widget->handleInput('p');
        $widget->selectAt(4, 4); // click-select
        $widget->handleInput('v'); // the cell rendering

        $this->assertSame($before, implode("\n", $widget->render(new RenderContext(80, 24))), 'none of the geometry keys do anything');
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
