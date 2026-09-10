<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Event\TreeDropRequested;
use Porta\Event\TreeFileActivated;
use Porta\Input\MouseEvent;
use Porta\Input\TreeDragRouter;
use Porta\Tests\Support\AttachedStyles;
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
        $opened = [];
        $this->treeEvents->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened[] = $event->path;
        });
        $router->arm([$this->project->path('top.php')]);
        $editor = new WidgetRect(row: 0, col: 40, columns: 40, rows: self::ROWS);
        $router->continueDrag($this->drag(60, 3), self::rect(), $editor);
        $router->continueDrag($this->release(60, 3), self::rect(), $editor);

        $this->assertSame([$this->project->path('top.php')], $opened);
        $this->assertSame([], $this->drops, 'nothing was moved');
    }

    /** A folder has nothing to open: dropping one on the editor is a miss, not an instruction. */
    public function testAFolderDroppedOnTheEditorOpensNothing(): void
    {
        $router = $this->router();
        $opened = [];
        $this->treeEvents->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened[] = $event->path;
        });
        $router->arm([$this->project->path('src')]);
        $editor = new WidgetRect(row: 0, col: 40, columns: 40, rows: self::ROWS);
        $router->continueDrag($this->drag(60, 3), self::rect(), $editor);
        $router->continueDrag($this->release(60, 3), self::rect(), $editor);

        $this->assertSame([], $opened);
        $this->assertSame([], $this->drops);
    }

    /** Released over neither the tree nor the editor: the drag is spent and nothing happens. */
    public function testAReleaseOverNothingDropsNothing(): void
    {
        $router = $this->router();
        $router->arm([$this->project->path('top.php')]);
        $router->continueDrag($this->drag(60, 3), self::rect(), null);
        $router->continueDrag($this->release(60, 3), self::rect(), null);

        $this->assertSame([], $this->drops);
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
