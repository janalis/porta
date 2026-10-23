<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Event\TreeDropRequested;
use Porta\Event\TreeFileActivated;
use Porta\I18n\Translator;
use Porta\Input\MouseEvent;
use Porta\Input\TreeDragRouter;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The file-tree drag as a state machine: arm, track, spend, disarm.
 *
 * Driven through real {@see MouseEvent}s built from SGR bytes, the idiom
 * {@see \Porta\Tests\Unit\Layout\LayoutControllerResizeTest} uses — the
 * classification (`isLeftDrag` vs `isLeftRelease` vs neither) is half of what
 * is under test, so synthesising the verdict would test nothing.
 */
final class TreeDragRouterTest extends TestCase
{
    use StatusReads;

    /** Held so the destructor does not remove the fixture mid-test. */
    private TempProject $project;
    private FileTreeWidget $tree;
    private EventDispatcher $treeEvents;
    /** @var list<TreeDropRequested> */
    private array $drops = [];
    private FakeScheduler $clock;
    private StatusBarWidget $statusBar;

    /** The tree rect used throughout: 30 columns wide, starting at the top-left. */
    private const int ROWS = 12;

    private function router(): TreeDragRouter
    {
        $this->clock = new FakeScheduler();
        $this->statusBar = new StatusBarWidget();
        $this->project = new TempProject(['src/a.php' => "<?php\n", 'top.php' => "<?php\n"]);
        $this->treeEvents = new EventDispatcher();
        $events = $this->treeEvents;
        $events->addListener(TreeDropRequested::class, function (TreeDropRequested $event): void {
            $this->drops[] = $event;
        });
        $this->tree = new FileTreeWidget($this->project->dir, events: $events);
        AttachedStyles::attach($this->tree);
        $this->tree->clickRow(1); // expand src/ — rows: 1 src/, 2 src/a.php, 3 top.php
        $this->tree->render(new RenderContext(30, self::ROWS));
        $this->drops = [];

        return new TreeDragRouter($this->tree, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)), $this->clock, $this->statusBar, new Translator('en'));
    }

    /** The two timers a drag runs, told apart by their interval. */
    private const float SCROLL_TICK = 0.06;
    private const float SPRING_DWELL = 0.5;

    /**
     * Pending timer ids at one interval.
     *
     * A live drag runs two clocks at once — the edge scroll and the
     * spring-load dwell — so "nothing is pending" has to be asked of one of
     * them, or a test about scrolling starts failing when a dwell is armed
     * beside it.
     *
     * @return list<string>
     */
    private function pending(float $seconds): array
    {
        $ids = [];
        foreach (\array_keys($this->clock->scheduled) as $id) {
            if ($this->clock->delays[$id] !== $seconds) {
                continue;
            }

            $ids[] = (string) $id;
        }

        return $ids;
    }

    /** A pane six rows high showing five of twelve collapsed directories. */
    private const int TALL_ROWS = 6;

    /**
     * Twelve directories in a pane that fits five of them — the shape every
     * scrolling case needs, and the one the fixture above deliberately is not.
     * Directories rather than files so that the row under the pointer IS the
     * drop target, and scrolling therefore changes it.
     */
    private function tallRouter(): TreeDragRouter
    {
        $this->clock = new FakeScheduler();
        $this->statusBar = new StatusBarWidget();
        $files = [];
        for ($i = 0; $i < 12; ++$i) {
            $files[\sprintf('d%02d/keep.php', $i)] = "<?php\n";
        }
        $this->project = new TempProject($files);
        $this->treeEvents = new EventDispatcher();
        $this->tree = new FileTreeWidget($this->project->dir, events: $this->treeEvents);
        AttachedStyles::attach($this->tree);
        $this->tree->render(new RenderContext(30, self::TALL_ROWS));
        $this->drops = [];

        return new TreeDragRouter($this->tree, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)), $this->clock, $this->statusBar, new Translator('en'));
    }

    private static function tallRect(): WidgetRect
    {
        return new WidgetRect(row: 0, col: 0, columns: 30, rows: self::TALL_ROWS);
    }

    private static function rect(): WidgetRect
    {
        return new WidgetRect(row: 0, col: 0, columns: 30, rows: self::ROWS);
    }

    /** SGR is 1-based; the widget-relative row is what the tree indexes by. */
    private function at(int $code, int $relRow, string $end = 'M', int $col = 2): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<%d;%d;%d%s", $code, $col + 1, $relRow + 1, $end));
        $this->assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    private function drag(int $col, int $relRow): MouseEvent
    {
        return $this->at(32, $relRow, col: $col);
    }

    private function release(int $col, int $relRow): MouseEvent
    {
        return $this->at(0, $relRow, 'm', $col);
    }

    /** @return \Closure(): list<string> every path opened so far, in order */
    private function recordOpens(): \Closure
    {
        $opened = [];
        $this->treeEvents->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened[] = $event->path;
        });

        // By reference, and not an arrow function: an arrow fn captures at
        // DEFINITION, so it would freeze the list while it is still empty and
        // every "nothing was opened" assertion below would pass for free. The
        // same trap the suite's await() rule names.
        return static function () use (&$opened): array {
            return $opened;
        };
    }

    public function testADragWithNothingArmedIsRefused(): void
    {
        $this->assertFalse($this->router()->continueDrag($this->at(32, 1), self::rect(), null), 'the chain falls through to everything else');
    }

    /** Arm, cross a row, release on a folder: the drop is reported once. */
    public function testAGrabDraggedOntoAFolderReportsTheDrop(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        $this->assertTrue($router->continueDrag($this->at(32, 1), self::rect(), null), 'the drag is consumed');
        $this->assertTrue($router->continueDrag($this->at(0, 1, 'm'), self::rect(), null), 'so is the release');

        $this->assertCount(1, $this->drops);
        $this->assertSame([$this->project->path('top.php')], $this->drops[0]->sources);
        $this->assertSame($this->project->path('src'), $this->drops[0]->targetDir);
        $this->assertFalse($this->drops[0]->copy);
    }

    /**
     * A press and release on one spot is the CLICK that already ran. Acting
     * again would move a file because someone selected it.
     */
    public function testAReleaseWithoutMotionIsNotADrop(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        $this->assertTrue($router->continueDrag($this->at(0, 1, 'm'), self::rect(), null));

        $this->assertSame([], $this->drops);
    }

    /** Alt at the RELEASE asks for a copy — the chord that started the drag never decides. */
    public function testAltOnTheReleaseAsksForACopy(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->at(32, 1), self::rect(), null);
        // SGR 8 is the alt bit; 0 + 8 on a release.
        $router->continueDrag($this->at(8, 1, 'm'), self::rect(), null);

        $this->assertCount(1, $this->drops);
        $this->assertTrue($this->drops[0]->copy);
    }

    /** Released over the editor instead: the file opens and nothing moves. */
    public function testAReleaseOverTheEditorOpensInsteadOfMoving(): void
    {
        $router = $this->router();
        $opened = $this->recordOpens();
        $router->arm([$this->project->path('top.php')]);
        $editor = new WidgetRect(row: 0, col: 40, columns: 40, rows: self::ROWS);
        $router->continueDrag($this->drag(60, 3), self::rect(), $editor);
        $router->continueDrag($this->release(60, 3), self::rect(), $editor);

        $this->assertSame([$this->project->path('top.php')], $opened());
        $this->assertSame([], $this->drops, 'nothing was moved');
    }

    /**
     * A folder has nothing to open: dropping one on the editor is a miss, not
     * an instruction — and it does not stop the files beside it from opening.
     *
     * The folder goes FIRST in the set, which is the whole point: with it last,
     * skipping it and giving up at it are the same answer, and a `break` where
     * the code says `continue` passes.
     */
    public function testAFolderDroppedOnTheEditorIsSkippedAndTheFilesBesideItOpen(): void
    {
        $router = $this->router();
        $opened = $this->recordOpens();
        $router->arm([$this->project->path('src'), $this->project->path('top.php')]);
        $editor = new WidgetRect(row: 0, col: 40, columns: 40, rows: self::ROWS);
        $router->continueDrag($this->drag(60, 3), self::rect(), $editor);
        $router->continueDrag($this->release(60, 3), self::rect(), $editor);

        $this->assertSame([$this->project->path('top.php')], $opened(), 'the folder was skipped, not a full stop');
        $this->assertSame([], $this->drops);
    }

    /** Released over neither the tree nor the editor: the drag is spent and nothing happens. */
    public function testAReleaseOverNothingDropsNothing(): void
    {
        $router = $this->router();
        $opened = $this->recordOpens();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(60, 3), self::rect(), null);
        $router->continueDrag($this->release(60, 3), self::rect(), null);

        $this->assertSame([], $this->drops);
        // Both halves, because "nothing happened" has two ways to be false and
        // asserting one of them is how a mutant that opens every source on a
        // release into empty space survives.
        $this->assertSame([], $opened(), 'and nothing was opened either');
    }

    /**
     * Only a drag or a release is the drag's business.
     *
     * The guard reads `!isLeftDrag() && !isLeftRelease()`, and its job is to
     * let everything ELSE fall through the router's `||` chain to whatever owns
     * it. Asserted with a drag armed, because the `null === $drag` arm
     * short-circuits the whole condition and makes the interesting half
     * unreachable — which is exactly how a negated version of it survives.
     */
    public function testAnEventThatIsNeitherADragNorAReleaseFallsThrough(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        // SGR 2 is the right button: a press, but not the left one.
        $this->assertFalse($router->continueDrag($this->at(2, 1), self::rect(), null), 'a right-press is not the drag');
        // Button 3 with motion — a bare hover-move under mode 1003.
        $this->assertFalse($router->continueDrag($this->at(35, 1), self::rect(), null), 'nor is a hover-move');
        $this->assertNull(Probe::get($this->tree, 'dropTarget'), 'and neither of them moved the highlight');
    }

    /** The highlight follows the pointer: that is the only feedback a drop target gets. */
    public function testTheHighlightFollowsThePointerAcrossTheTree(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->at(32, 1), self::rect(), null); // over src/
        $this->assertSame($this->project->path('src'), Probe::get($this->tree, 'dropTarget'));

        $router->continueDrag($this->drag(60, 3), self::rect(), null); // off the tree entirely
        $this->assertNull(Probe::get($this->tree, 'dropTarget'), 'leaving the tree takes the highlight with it');
    }

    /**
     * The release SPENDS the drag: it is disarmed before it is acted on.
     *
     * Without that, the gesture stays armed after the drop and the next release
     * anywhere moves the same files a second time — silently, because every
     * other symptom of the first move is indistinguishable from the second.
     */
    public function testAReleaseDisarmsTheDragSoItCannotBeSpentTwice(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->at(32, 1), self::rect(), null);
        $router->continueDrag($this->at(0, 1, 'm'), self::rect(), null);
        $this->assertCount(1, $this->drops);

        $this->assertFalse($router->continueDrag($this->at(0, 1, 'm'), self::rect(), null), 'the second release finds nothing armed');
        $this->assertCount(1, $this->drops, 'and moves nothing again');
        $this->assertNull(Probe::get($this->tree, 'dropTarget'), 'the highlight went with it');
    }

    /**
     * A drop abandons the toggle its press deferred.
     *
     * Pressing a folder does not expand it any more — the expansion waits for a
     * release that never moved, or the row would collapse under the pointer
     * mid-drag. A drop has to throw that pending toggle away, or it survives to
     * be paid by the NEXT click that happens to end without motion, and a
     * folder nobody touched opens or closes on its own.
     */
    public function testADropAbandonsTheToggleThePressDeferred(): void
    {
        $router = $this->router();
        $this->tree->pressRow(1); // press src/ — the toggle is deferred, not applied
        $this->assertSame(1, Probe::get($this->tree, 'pendingToggle'), 'the press really did defer one');

        $router->arm([$this->project->path('src')]);
        $router->continueDrag($this->at(32, 3), self::rect(), null);
        $router->continueDrag($this->at(0, 3, 'm'), self::rect(), null);

        $this->assertNull(Probe::get($this->tree, 'pendingToggle'), 'and the drop threw it away');
    }

    /**
     * Cancelling a drag also abandons the toggle its press deferred.
     *
     * Pressing a folder no longer expands it — the expansion waits for a
     * release that never moved. Escape ends the drag, but the button is still
     * DOWN, so the release that follows finds nothing armed and is discarded by
     * the router: `commitPress()` never runs and the deferred toggle survives
     * the whole gesture. The next click that happens to end without motion pays
     * it, and a folder nobody touched opens or closes on its own.
     */
    public function testCancellingAlsoAbandonsTheDeferredToggle(): void
    {
        $router = $this->router();
        $this->tree->pressRow(1); // press src/: the toggle is deferred
        $router->arm([$this->project->path('src')]);
        $router->continueDrag($this->at(32, 2), self::rect(), null);
        $this->assertSame(1, Probe::get($this->tree, 'pendingToggle'), 'the press really did defer one');

        $router->cancel(); // Escape

        $this->assertNull(Probe::get($this->tree, 'pendingToggle'), 'the abandoned drag took it along');
    }

    /** Cancelling forgets the drag and takes its highlight with it. */
    public function testCancelDisarmsSoALaterReleaseIsRefused(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->at(32, 1), self::rect(), null);

        $router->cancel();

        $this->assertFalse($router->continueDrag($this->at(0, 1, 'm'), self::rect(), null));
        $this->assertSame([], $this->drops);
    }

    /**
     * A drag resting on a collapsed folder opens it, so the drag can reach the
     * rows inside.
     *
     * Dropping ON a collapsed folder already worked. What was unreachable was
     * anything INSIDE one — and a tree opens collapsed, so on a fresh session
     * that is most of the project.
     */
    public function testRestingOnACollapsedFolderOpensIt(): void
    {
        $router = $this->router();
        $this->tree->clickRow(1); // collapse src/ again
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->drag(2, 1), self::rect(), null);

        $this->assertCount(1, $this->pending(self::SPRING_DWELL), 'the dwell is running');
        $this->clock->fire($this->pending(self::SPRING_DWELL)[0]);
        $this->assertTrue(Probe::get($this->tree, 'expanded')[$this->project->path('src')] ?? false);
    }

    /**
     * Moving WITHIN the same folder does not restart the dwell.
     *
     * The guard that matters. A pointer is never perfectly still, and every
     * jitter is a motion event — restart the dwell on each one and the folder
     * never opens at all, which is the failure mode that looks exactly like the
     * feature not existing.
     */
    public function testStayingOnTheSameFolderLetsTheDwellRunOut(): void
    {
        $router = $this->router();
        $this->tree->clickRow(1); // collapse src/
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->drag(2, 1), self::rect(), null);
        $armed = $this->pending(self::SPRING_DWELL);
        $router->continueDrag($this->drag(3, 1), self::rect(), null); // jitter, same row

        $this->assertSame($armed, $this->pending(self::SPRING_DWELL), 'the same timer, still counting');
        $this->assertNotContains($armed[0], $this->clock->cancelled, 'the dwell was left alone');
    }

    /** Moving to a different folder starts the dwell again for that one. */
    public function testMovingToAnotherFolderRestartsTheDwell(): void
    {
        $router = $this->router();
        $this->tree->clickRow(1); // collapse src/
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->drag(2, 1), self::rect(), null); // src/
        $first = $this->pending(self::SPRING_DWELL)[0];
        $router->continueDrag($this->drag(2, 2), self::rect(), null); // top.php → the root

        $this->assertContains($first, $this->clock->cancelled, 'the old dwell was dropped');
        $this->assertNotSame([$first], $this->pending(self::SPRING_DWELL));
    }

    /** What the status bar is showing right now, ANSI stripped. */
    /**
     * The drag says what it is carrying, and names the modifier that changes
     * what it will do.
     *
     * Alt-to-copy is read from the RELEASE, so it is still an offer while the
     * drag is in flight — and it appeared nowhere in the UI at all, only in
     * docs/editor.md, which is not where anyone is looking mid-gesture.
     */
    public function testTheFirstMovementSaysWhatIsMovingAndHowToCopy(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php'), $this->project->path('src/a.php')]);

        $router->continueDrag($this->drag(2, 2), self::rect(), null);

        $this->assertStringContainsString('Moving 2 files', $this->statusRow($this->statusBar));
        $this->assertStringContainsString('Alt', $this->statusRow($this->statusBar), 'and the modifier is offered');
    }

    /**
     * The hint is posted ONCE, on the first movement — not on every motion
     * event for as long as the drag lasts.
     *
     * A pointer in flight produces a steady stream of motion events. Re-posting
     * on each one would wipe anything else the status bar had to say, over and
     * over, for the whole drag — so the guard is tested by putting a message up
     * mid-drag and moving again.
     */
    public function testTheHintDoesNotKeepWipingWhateverElseTheStatusBarSays(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 2), self::rect(), null);

        $this->statusBar->flash('Saved');
        $router->continueDrag($this->drag(2, 1), self::rect(), null);

        $this->assertStringContainsString('Saved', $this->statusRow($this->statusBar), 'the hint had already had its say');
    }

    /**
     * The payload reaches the ROW, not just the status bar.
     *
     * The widget renders whatever label it is handed and the router is what
     * hands it over; with the two tested only separately, a drag that passes no
     * label at all leaves both sides passing and the row saying nothing.
     */
    public function testTheDropTargetRowShowsWhatIsComing(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->drag(2, 1), self::rect(), null);

        $rows = AnsiUtils::stripAnsiCodes(\implode("\n", $this->tree->render(new RenderContext(40, self::ROWS))));
        // The arrow and its spacing included: asserting only the count leaves
        // the label free to arrive backwards or run into the filename.
        $this->assertStringContainsString("\u{2190} 1 file", $rows);
    }

    /** A press that never moved is a click, and says nothing. */
    public function testAClickSaysNothing(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->release(2, 2), self::rect(), null);

        $this->assertStringNotContainsString('Moving', $this->statusRow($this->statusBar));
    }

    /**
     * The in-flight hint goes when the drag does.
     *
     * It describes something HAPPENING, so it stops being true at the release —
     * and sitting out its remaining seconds would cover what the drop itself
     * has to say. On a drop into the editor that is the name of the file just
     * opened, which is the only confirmation the gesture gets.
     */
    public function testTheInFlightHintIsTakenBackAtTheRelease(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 2), self::rect(), null);
        $this->assertStringContainsString('Moving', $this->statusRow($this->statusBar));

        $router->continueDrag($this->release(2, 1), self::rect(), null);

        $this->assertStringNotContainsString('Moving', $this->statusRow($this->statusBar), 'the status bar is free again');
    }

    /** Escape takes it back too, through the same disarm. */
    public function testCancellingTakesTheHintBack(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 2), self::rect(), null);

        $router->cancel();

        $this->assertStringNotContainsString('Moving', $this->statusRow($this->statusBar));
    }

    /**
     * A message posted SINCE the hint is not swallowed with it.
     *
     * The withdrawal is guarded by the text, so a drag that ends after
     * something more important has been said leaves that standing.
     */
    public function testAMessagePostedOverTheHintSurvivesTheRelease(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 2), self::rect(), null);
        $this->statusBar->flash('Saved');

        $router->cancel();

        $this->assertStringContainsString('Saved', $this->statusRow($this->statusBar));
    }

    /**
     * A drop that landed on nothing says so.
     *
     * Releasing over the tab strip, the dock or the status bar was swallowed
     * without a word, which reads as the move having silently failed rather
     * than having missed — and the two call for opposite reactions.
     */
    public function testADropOnNeitherTargetExplainsItself(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(60, 20), self::rect(), null); // off the tree
        $router->continueDrag($this->release(60, 20), self::rect(), null);

        $this->assertStringContainsString('Drop on a folder', $this->statusRow($this->statusBar));
        $this->assertSame([], $this->drops, 'and nothing moved');
    }

    /** A drop that DID land says nothing extra — the move speaks for itself. */
    public function testASuccessfulDropDoesNotExplainItself(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);
        $router->continueDrag($this->release(2, 1), self::rect(), null);

        $this->assertStringNotContainsString('Drop on a folder', $this->statusRow($this->statusBar));
        $this->assertCount(1, $this->drops);
    }

    /**
     * A second drag onto the SAME folder still springs it.
     *
     * The dwell remembers which folder it is counting for, so that jitter does
     * not restart it. That memory has to be dropped when the drag ends, or the
     * next drag onto the same folder is mistaken for the same dwell still
     * running and never arms one at all — a feature that works once per folder
     * per session.
     */
    public function testASecondDragOntoTheSameFolderSpringsItAgain(): void
    {
        $router = $this->router();
        $this->tree->clickRow(1); // collapse src/
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);
        $router->cancel();

        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);

        $this->assertCount(1, $this->pending(self::SPRING_DWELL), 'the second drag armed its own dwell');
    }

    /**
     * A folder the drag sprang open stays open under a drop INSIDE it — the
     * user watches the file arrive there — and closes again the moment the
     * drag moves off it.
     */
    public function testASprungFolderStaysOpenForADropInsideIt(): void
    {
        $router = $this->router();
        $src = $this->project->path('src');
        $this->tree->clickRow(1); // collapse src/ — rows: 1 src/, 2 top.php
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);
        $this->clock->fire($this->pending(self::SPRING_DWELL)[0]);
        $this->assertTrue(Probe::get($this->tree, 'expanded')[$src], 'sprung open — rows: 1 src/, 2 src/a.php, 3 top.php');

        $router->continueDrag($this->drag(2, 2), self::rect(), null);
        $router->continueDrag($this->release(2, 2), self::rect(), null);

        $this->assertSame($src, $this->drops[0]->targetDir ?? null);
        $this->assertTrue(Probe::get($this->tree, 'expanded')[$src], 'still open where the file landed');

        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 3), self::rect(), null);
        $this->assertTrue(Probe::get($this->tree, 'expanded')[$src], 'the user\'s tree now: the next drag passing by leaves it open');
    }

    public function testASprungFolderClosesOnceTheDragMovesOffIt(): void
    {
        $router = $this->router();
        $src = $this->project->path('src');
        $this->tree->clickRow(1);
        $router->arm([$this->project->path('src/a.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);
        $this->clock->fire($this->pending(self::SPRING_DWELL)[0]);

        $router->continueDrag($this->drag(2, 3), self::rect(), null); // top.php: the project root

        $this->assertFalse(Probe::get($this->tree, 'expanded')[$src]);
        $this->assertSame($this->project->path('top.php'), $this->tree->nodeAtRow(2)?->path, 'its rows are gone from the tree');
    }

    /** A pointer off the tree cancels the dwell rather than leaving it counting. */
    public function testLeavingTheTreeCancelsTheDwell(): void
    {
        $router = $this->router();
        $this->tree->clickRow(1);
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);

        $router->continueDrag($this->drag(60, 1), self::rect(), null);

        $this->assertSame([], $this->pending(self::SPRING_DWELL));
    }

    /** And the drag ending takes it with it. */
    public function testTheDwellStopsWhenTheDragEnds(): void
    {
        $router = $this->router();
        $this->tree->clickRow(1);
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, 1), self::rect(), null);

        $router->cancel();

        $this->assertSame([], $this->pending(self::SPRING_DWELL));
    }

    /**
     * A drag held at the bottom edge scrolls the tree, so a folder below the
     * fold can be dropped on.
     *
     * The rest of this file drags within a tree that fits, where the pointer
     * can always reach its target and the edge is never touched. On a real
     * project it is the other way round: the folder being aimed at is usually
     * the one off the bottom.
     *
     * The tick is a timer rather than a motion event because a pointer resting
     * on the edge sends NOTHING — the terminal reports motion, not position, so
     * without a clock the scroll would stop the instant the user stopped
     * moving, which is exactly when they are waiting for it.
     */
    public function testAPointerHeldAtTheBottomEdgeScrollsTheTree(): void
    {
        $router = $this->tallRouter();
        $router->arm([$this->project->path('d00')]);

        $router->continueDrag($this->drag(2, self::TALL_ROWS - 1), self::tallRect(), null);

        $this->assertCount(1, $this->pending(self::SCROLL_TICK), 'the edge armed a tick');
        $before = $this->tree->getRenderRevision();
        $this->clock->fire($this->pending(self::SCROLL_TICK)[0]);
        $this->assertNotSame($before, $this->tree->getRenderRevision(), 'the tick scrolled the tree');
        $this->assertCount(1, $this->pending(self::SCROLL_TICK), 'and armed the next one, so it repeats');
    }

    /** The top edge scrolls the other way. */
    public function testAPointerHeldAtTheTopEdgeScrollsBackUp(): void
    {
        $router = $this->tallRouter();
        $router->arm([$this->project->path('d00')]);
        $this->tree->scrollDuringDrag(2); // somewhere to scroll back from
        $before = $this->tree->getRenderRevision();

        $router->continueDrag($this->drag(2, 1), self::tallRect(), null);
        $this->assertCount(1, $this->pending(self::SCROLL_TICK), 'the top edge armed a tick');
        $this->clock->fire($this->pending(self::SCROLL_TICK)[0]);

        $this->assertNotSame($before, $this->tree->getRenderRevision());
    }

    /**
     * The edge zone is exactly one row deep at each end.
     *
     * The cases either side of this one drag ON the edge and in the MIDDLE,
     * which between them leave the zone free to be any size at all — a zone two
     * rows deep starts scrolling while the pointer is still over a row the user
     * is aiming at, and the tree slides out from under the drop.
     */
    public function testTheRowJustInsideTheEdgeIsNotTheEdge(): void
    {
        $router = $this->tallRouter();
        $router->arm([$this->project->path('d00')]);

        $router->continueDrag($this->drag(2, self::TALL_ROWS - 2), self::tallRect(), null);

        $this->assertSame([], $this->pending(self::SCROLL_TICK), 'one row in from the bottom is still ordinary tree');
    }

    /** Away from the edges nothing is armed — the common case pays nothing. */
    public function testADragInTheMiddleArmsNoTick(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);

        $router->continueDrag($this->drag(2, 3), self::rect(), null);

        $this->assertSame([], $this->pending(self::SCROLL_TICK));
    }

    /** Leaving the edge stops the repeat rather than letting it run on. */
    public function testMovingOffTheEdgeStopsTheScroll(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, self::ROWS - 1), self::rect(), null);

        $router->continueDrag($this->drag(2, 3), self::rect(), null);

        $this->assertSame([], $this->pending(self::SCROLL_TICK), 'the pending tick was cancelled');
    }

    /** A pointer that leaves the tree altogether stops it too. */
    public function testLeavingTheTreeStopsTheScroll(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, self::ROWS - 1), self::rect(), null);

        $router->continueDrag($this->drag(60, self::ROWS - 1), self::rect(), null);

        $this->assertSame([], $this->pending(self::SCROLL_TICK));
    }

    /**
     * The release stops the repeat.
     *
     * Without this the tree would go on scrolling after the drop, driven by a
     * timer nothing owns any more.
     */
    public function testTheReleaseStopsTheScroll(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, self::ROWS - 1), self::rect(), null);

        $router->continueDrag($this->release(2, self::ROWS - 1), self::rect(), null);

        $this->assertSame([], $this->pending(self::SCROLL_TICK));
    }

    /** And so does Escape, through the same disarm. */
    public function testCancellingStopsTheScroll(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(2, self::ROWS - 1), self::rect(), null);

        $router->cancel();

        $this->assertSame([], $this->pending(self::SCROLL_TICK));
    }

    /**
     * The tree reaching its end stops the repeat, rather than ticking forever
     * against a window that cannot move.
     */
    public function testScrollingStopsAtTheEndOfTheTree(): void
    {
        $router = $this->tallRouter();
        $router->arm([$this->project->path('d00')]);
        $router->continueDrag($this->drag(2, 1), self::tallRect(), null); // top edge, already at the top

        $this->assertCount(1, $this->pending(self::SCROLL_TICK), 'the edge armed a tick');
        $this->clock->fire($this->pending(self::SCROLL_TICK)[0]);

        $this->assertSame([], $this->pending(self::SCROLL_TICK), 'nowhere to go, so it stopped');
    }

    /**
     * After a tick the rows have moved under a stationary pointer, so the drop
     * target is resolved again from where the pointer still is.
     *
     * Skipping this leaves the highlight — and the drop — pointing at whatever
     * row USED to be under the pointer, which is the row the user deliberately
     * scrolled away from.
     */
    public function testATickReResolvesTheTargetUnderTheStationaryPointer(): void
    {
        $router = $this->tallRouter();
        $router->arm([$this->project->path('d00')]);

        // The bottom visible row is d04; one scrolled row puts d05 there.
        $router->continueDrag($this->drag(2, self::TALL_ROWS - 1), self::tallRect(), null);
        $this->assertSame($this->project->path('d04'), Probe::get($this->tree, 'dropTarget'));

        $this->assertCount(1, $this->pending(self::SCROLL_TICK), 'the edge armed a tick');
        $this->clock->fire($this->pending(self::SCROLL_TICK)[0]);

        $this->assertSame($this->project->path('d05'), Probe::get($this->tree, 'dropTarget'), 'resolved again, not left behind');
    }
}
