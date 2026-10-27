<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Tests\Support\DrivesGraphViewer;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Glyphs;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class GraphViewerWidgetTest extends TestCase
{
    use DrivesGraphViewer;

    public function testItBindsExactlyTheActionsThePluginDeclares(): void
    {
        // Both halves belong to this plugin now: plugin.php declares the ids and
        // labels Settings → Keymap lists, the pane binds the keys. Core sees
        // neither, so this is the only place the pair can be pinned — and each
        // direction catches a real defect the other cannot see: a declared
        // action nothing binds is a keymap row that does nothing, and a bound
        // key nothing declares is a key the user can never find or rebind.
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = $manager->api('graph-viewer', '/p', new PluginSettings('/p', []));
        $plugin = require \dirname(__DIR__).'/plugin.php';
        $plugin->activate($api);

        $declared = \array_column($api->contributions()->actions->all(), 'id');
        /** @var array<string, list<string>> $bound */
        $bound = Probe::invokeStatic(GraphViewerWidget::class, 'getDefaultKeybindings');
        // Scoped to the ids CORE does not declare — a pane also binds core
        // actions (goto_file, next_buffer…), which are the catalog's rows. What
        // must match is the remainder: the actions that exist only because this
        // plugin does.
        $ours = \array_keys(\array_diff_key($bound, ActionCatalog::ACTIONS));

        $this->assertNotSame([], $declared);
        \sort($declared);
        \sort($ours);
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
        $this->assertStringContainsString("\x1b[38;2;", \implode('', $lines), 'DOT-attributed colors emit truecolor SGR');
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

    public function testAToolFailureKeepsTheLastGoodRenderUnderABanner(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'good dot');
        $this->assertStringContainsString('A', self::plain($widget->render(new RenderContext(60, 20))));

        // Not dot's report on the source, which the editor shows: the tool
        // itself failed, and only the pane can say so.
        $this->engine->nextResult = "dot produced invalid JSON: Syntax error\nmore context";
        $widget->show('/p/g.dot', 'broken dot {');

        $screen = self::plain($widget->render(new RenderContext(60, 20)));
        $this->assertStringContainsString('dot produced invalid JSON', $screen, 'the banner shows the first line');
        $this->assertStringContainsString('┌', $screen, 'the previous good graph is still on screen');
        $this->assertSame('3 nodes · 2 edges', $widget->statusInfo(), 'the model still reflects the good layout');
        $this->assertSame([], $widget->render(new RenderContext(0, 20)), 'no room, no banner either');
    }

    /**
     * What dot says is wrong with the source goes to the editor, like any
     * other analyser's errors, and the picture stays clear: the last good graph
     * with no banner over it. Laid out again, the source has none.
     */
    public function testASourceErrorGoesToTheEditorNotOverThePicture(): void
    {
        $told = [];
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), $this->plantUml, scheduler: $this->scheduler, onSourceErrors: static function (string $path, array $errors) use (
            &$told,
        ): void {
            $told[] = [$path, $errors];
        });
        $widget->textBeside(true); // Split: the editor is on screen
        $this->engine->nextResult = self::layout();
        $widget->show('/p/g.dot', "digraph G {\n a -> b;\n}");
        $this->assertSame([], $told, 'a clean source that was never wrong is not re-told');

        $this->engine->nextResult = "Error: <stdin>: syntax error in line 2 near '->'\n";
        $widget->show('/p/g.dot', "digraph G {\n a -> ;\n}");

        $this->assertSame([['/p/g.dot', [['line' => 1, 'message' => "syntax error near '->'", 'severity' => 1, 'column' => 3]]]], $told);
        $screen = self::plain($widget->render(new RenderContext(60, 20)));
        $this->assertStringNotContainsString('syntax error', $screen, 'no banner over the picture');
        $this->assertStringContainsString('┌', $screen, 'the last good graph stays');

        $this->engine->nextResult = self::layout();
        $widget->show('/p/g.dot', "digraph G {\n a -> c;\n}");
        $this->assertSame(['/p/g.dot', []], $told[1], 'fixed: the errors are cleared');
        $this->assertCount(2, $told);
    }

    /**
     * Shown alone — Preview, or a viewer tab — the editor's diagnostics are
     * off screen, so the picture says it: the first error, over the last good
     * graph. Beside its text (Split) it leaves that to the editor.
     */
    public function testShownWithoutItsTextTheFirstSourceErrorIsBanneredOnThePicture(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', 'good');
        $this->engine->nextResult = "Error: <stdin>: syntax error in line 2 near '->'\nError: <stdin>: syntax error in line 5\n";
        $widget->show('/p/g.dot', "digraph G {\n a -> ;\n}");

        $alone = self::plain($widget->render(new RenderContext(60, 20)));
        $this->assertStringContainsString("Line 2: syntax error near '->'", $alone, 'alone: the first error, on the picture');
        $this->assertStringNotContainsString('Line 5', $alone);
        $this->assertStringContainsString('┌', $alone, 'over the last good graph');

        $widget->textBeside(true);
        $this->assertStringNotContainsString('syntax error', self::plain($widget->render(new RenderContext(60, 20))), 'beside the text: the editor says it');

        $widget->textBeside(false);
        $this->engine->nextResult = self::layout();
        $widget->show('/p/g.dot', 'fixed');
        $this->assertStringNotContainsString('syntax error', self::plain($widget->render(new RenderContext(60, 20))), 'fixed: gone');
    }

    public function testAnotherFileDoesNotInheritTheBanner(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/a.dot', 'good');
        $this->engine->nextResult = "Error: <stdin>: syntax error in line 1\n";
        $widget->show('/p/a.dot', 'bad');
        $this->engine->deferred = true;

        $widget->show('/p/b.dot', 'other'); // laying out
        $this->assertStringNotContainsString('syntax error', self::plain($widget->render(new RenderContext(60, 20))));

        $this->engine->deferred = false;
        $this->engine->nextResult = "Error: <stdin>: syntax error in line 1\n";
        $widget->show('/p/a.dot', 'bad again');
        $widget->clear();
        $this->engine->nextResult = self::layout();
        $widget->show('/p/c.dot', 'c');
        $this->assertStringNotContainsString('syntax error', self::plain($widget->render(new RenderContext(60, 20))), 'nor after a clear');
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

        $this->assertSame(['A', 'B', 'C'], \array_column($choices, 'label'), 'every node, in the n/p order');
        // The fixture is the chain A -> B -> C, so the ends disagree about
        // which way their single edge points — swapping in for out swaps A's
        // hint with C's.
        $this->assertSame(['0 in · 1 out', '1 in · 1 out', '1 in · 0 out'], \array_column($choices, 'hint'));
        $this->assertSame([], \array_filter($choices, static fn (array $row): bool => '' === $row['value']), 'every row carries a node id');
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
        $this->assertSame(\array_fill(0, count: 3, value: \str_repeat(' ', times: 10)), $widget->render(new RenderContext(10, 3)), 'no tab, blank pane');
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
        $widget->show('/p/g1.dot', 'dot 1'); // the cap's eight, g1 to g8, are all still held
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

    public function testAClickOnANodeRevealsWhereTheTextWritesIt(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = new GraphViewerWidget($this->engine, new Translator('en'), scheduler: $this->scheduler, renderMode: 'cells');
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/g.dot', "digraph G {\n  A -> B;\n  B -> C;\n}\n");
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(80, 24)));
        $row = (int) \array_key_first(\array_filter($rows, static fn (string $r): bool => 1 === \preg_match('/\bC\b/', $r)));
        $handler->events = [];

        $widget->selectAt($row, (int) \mb_strpos($rows[$row], needle: 'C')); // a column: the frame is multibyte
        $widget->selectAt(0, 0); // empty space: deselected, nothing to reveal

        $this->assertSame(['view', 'reveal:2:7', 'view'], $handler->events, 'C is first written on line 2, after "B -> "');
    }

    public function testTheCaretOnANodesLineSelectsAndCentresIt(): void
    {
        $this->engine->nextResult = self::layout();
        $widget = $this->widget();
        $widget->show('/p/g.dot', "digraph G {\n  A -> B;\n  B -> C;\n}\n");
        $widget->render(new RenderContext(80, 24));
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);

        $widget->followSource(2, 7);
        $this->assertStringContainsString('· C', $widget->statusInfo());
        $widget->followSource(2, 8); // the same node: nothing to move
        $widget->followSource(0, 0); // a line naming no node: the selection stays

        $this->assertStringContainsString('· C', $widget->statusInfo());
        $this->assertSame(['view'], $handler->events, 'one pan, for the one node reached');
    }

    public function testAPictureWithNoLayoutHasNoNodeToFollow(): void
    {
        $this->plantUml->nextPng = PngFixture::bytes(120, 80);
        $widget = $this->widget();
        $widget->show('/p/seq.puml', "@startuml\nA -> B\n@enduml");
        $this->settle();
        $widget->render(new RenderContext(80, 24));

        $widget->followSource(1, 0);

        $this->assertSame('', $widget->statusInfo());
    }
}
