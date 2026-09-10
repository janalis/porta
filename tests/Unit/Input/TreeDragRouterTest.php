<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Event\TreeDropRequested;
use Porta\Event\TreeFileActivated;
use Porta\Input\MouseEvent;
use Porta\Input\TreeDragRouter;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    /** Held so the destructor does not remove the fixture mid-test. */
    private TempProject $project;
    private FileTreeWidget $tree;
    private EventDispatcher $treeEvents;
    /** @var list<TreeDropRequested> */
    private array $drops = [];

    /** The tree rect used throughout: 30 columns wide, starting at the top-left. */
    private const int ROWS = 12;

    private function router(): TreeDragRouter
    {
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

        return new TreeDragRouter($this->tree, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)));
    }

    private static function rect(): WidgetRect
    {
        return new WidgetRect(row: 0, col: 0, columns: 30, rows: self::ROWS);
    }

    /** SGR is 1-based; the widget-relative row is what the tree indexes by. */
    private function at(int $code, int $relRow, string $end = 'M', int $col = 2): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<%d;%d;%d%s", $code, $col + 1, $relRow + 1, $end));
        $this->assertNotNull($event);

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
}
