<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\TreeDropRequested;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Ui\ClickIntent;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What the tree answers a drag: what a grab would carry, where a drop would
 * land, and what it says when one happens.
 *
 * The questions are the widget's because the data is — the visible rows, the
 * multi-selection, which rows are directories. The router that tracks the
 * gesture knows none of it.
 */
final class FileTreeDragTest extends TestCase
{
    use RendersWidget;

    /** Held so the destructor does not remove the fixture mid-test. */
    private TempProject $project;

    private function tree(EventDispatcher $events = new EventDispatcher()): FileTreeWidget
    {
        // Rows under the header: 1 = src/ (expanded), 2 = src/a.php,
        // 3 = src/b.php, 4 = top.php.
        $this->project = new TempProject([
            'src/a.php' => "<?php\n",
            'src/b.php' => "<?php\n",
            'top.php' => "<?php\n",
        ]);
        $tree = new FileTreeWidget($this->project->dir, events: $events);
        AttachedStyles::attach($tree);
        $tree->clickRow(1); // expand src/
        $tree->render(new RenderContext(40, 12));

        return $tree;
    }

    public function testAGrabOnAnUnselectedRowCarriesThatRowAlone(): void
    {
        $tree = $this->tree();

        $this->assertSame([$this->project->path('src/a.php')], $tree->dragSourcesAt(2, modified: false));
    }

    /**
     * Dragging a row that is IN the selection carries the whole selection —
     * that is what the selection is for, and every file manager agrees.
     */
    public function testAGrabInsideTheSelectionCarriesAllOfIt(): void
    {
        $tree = $this->tree();
        $tree->clickRow(2);
        $tree->clickRow(3, ClickIntent::Add);

        $sources = $tree->dragSourcesAt(3, modified: false);

        $this->assertSame([$this->project->path('src/a.php'), $this->project->path('src/b.php')], $sources);
    }

    /**
     * A directory always travels alone: `selectedPaths()` answers with files
     * only, so a folder is never in the set a selection would carry.
     */
    public function testADirectoryIsDraggedAlone(): void
    {
        $tree = $this->tree();
        $tree->clickRow(2);
        $tree->clickRow(3, ClickIntent::Add);

        $this->assertSame([$this->project->path('src')], $tree->dragSourcesAt(1, modified: false));
    }

    /** Ctrl and Shift are building a selection, not grabbing one. */
    public function testAModifiedPressGrabsNothing(): void
    {
        $this->assertSame([], $this->tree()->dragSourcesAt(2, modified: true));
    }

    public function testTheHeaderRowGrabsNothing(): void
    {
        $this->assertSame([], $this->tree()->dragSourcesAt(0, modified: false));
    }

    /**
     * Dropping onto a FILE lands in that file's folder — PhpStorm's rule, and
     * the one that makes a tree mostly made of files droppable at all.
     */
    public function testADropOntoAFileTargetsItsFolder(): void
    {
        $tree = $this->tree();

        $this->assertSame($this->project->path('src'), $tree->dropDirAt(2), 'a file row');
        $this->assertSame($this->project->path('src'), $tree->dropDirAt(1), 'the folder row itself');
        $this->assertNull($tree->dropDirAt(0), 'the header is not a target');
    }

    /** The widget owns the gesture and no model: it reports the drop rather than performing it. */
    public function testADropIsReportedAsACommandEvent(): void
    {
        $events = new EventDispatcher();
        $seen = [];
        $events->addListener(TreeDropRequested::class, static function (TreeDropRequested $event) use (&$seen): void {
            $seen[] = [$event->sources, $event->targetDir, $event->copy];
        });

        $this->tree($events)->requestDrop(['/p/a.php'], '/p/src', copy: true);

        $this->assertSame([[['/p/a.php'], '/p/src', true]], $seen);
    }

    /**
     * The drop target outranks the cursor row: during a drag the pointer is
     * what the eye follows, and the cursor is wherever the press left it.
     */
    public function testTheDropTargetIsPaintedAndCleared(): void
    {
        $tree = $this->tree();
        // Raw, not stripped: the target is marked with a STYLE, so the plain
        // text is identical either way and a stripped comparison would pass
        // for a change that never happened.
        $raw = static fn (): string => implode("\n", $tree->render(new RenderContext(40, 12)));

        $before = $raw();
        $tree->setDropTarget($this->project->path('src'));
        $during = $raw();
        $tree->setDropTarget(null);

        $this->assertNotSame($before, $during, 'the target row is drawn differently');
        $this->assertSame($before, $raw(), 'and clearing puts it back');
    }
}
