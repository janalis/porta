<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\TreeDropRequested;
use Porta\Event\TreeFileActivated;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Ui\ClickIntent;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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

    /**
     * A press holds back a folder's toggle — and nothing else's.
     *
     * The guard is three conditions wide and only one of its paths had a test.
     * Each row here is a different way through it: a folder grabbed to be
     * dragged (defer), a file (activate now, as this tree has always done), a
     * folder pressed with Ctrl (that press is building a selection, not
     * grabbing one), and the header, which is no row at all.
     */
    public function testOnlyAPlainPressOnAFolderDefersItsToggle(): void
    {
        $tree = $this->tree();
        $tree->pressRow(1); // src/, plain press
        $this->assertSame(1, Probe::get($tree, 'pendingToggle'), 'a folder grabbed for a drag waits');

        $tree = $this->tree($events = new EventDispatcher());
        $activated = [];
        $events->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$activated): void {
            $activated[] = $event->path;
        });
        $tree->pressRow(2); // src/a.php
        $this->assertNull(Probe::get($tree, 'pendingToggle'), 'a file has no toggle to hold back');
        // The other half: "nothing was deferred" is equally true of a press
        // that does nothing at all, so say what DID happen.
        $this->assertSame([$this->project->path('src/a.php')], $activated, 'it opened on the press, as this tree has always done');

        $tree = $this->tree();
        $tree->pressRow(1, ClickIntent::Add); // Ctrl on src/
        $this->assertNull(Probe::get($tree, 'pendingToggle'), 'Ctrl is building a selection, not grabbing one');

        $tree = $this->tree();
        $tree->pressRow(0); // the header: no node under it
        $this->assertNull(Probe::get($tree, 'pendingToggle'), 'and there is nothing to defer on a row that is not one');
    }

    /**
     * A press that defers a toggle always hands back something to drag.
     *
     * The invariant that makes the deferral safe, stated once over every row
     * the tree has and every intent a press can carry. It matters because the
     * toggle is only ever paid by a release the DRAG router routes: arm it with
     * nothing and `continueDrag()` refuses the release, the router discards it,
     * and the toggle survives to be paid by some later click — which is exactly
     * the bug Escape mid-drag used to cause.
     *
     * `pressRow()` returning its own sources makes this true by construction
     * rather than by two callers agreeing on what "modified" means. This says
     * so out loud, so the next intent added to that `match` has to keep it.
     */
    public function testAPressThatDefersAToggleAlwaysArmsSomething(): void
    {
        foreach ([0, 1, 2, 3, 4, 5] as $relRow) {
            foreach (ClickIntent::cases() as $intent) {
                $tree = $this->tree();
                $sources = $tree->pressRow($relRow, $intent);
                $where = \sprintf('row %d, %s', $relRow, $intent->name);

                if (null === Probe::get($tree, 'pendingToggle')) {
                    continue;
                }

                $this->assertNotSame([], $sources, $where.' deferred a toggle with no drag armed to spend it');
            }
        }
        // The loop above is satisfied by a tree that never defers at all, so
        // say that at least one row does.
        $tree = $this->tree();
        $tree->pressRow(1);
        $this->assertSame(1, Probe::get($tree, 'pendingToggle'), 'a plain press on a folder still defers');
    }

    /**
     * The other half of the same agreement: a MODIFIED press arms nothing.
     *
     * Ctrl and Shift are building a selection, not grabbing one, so no drag
     * starts from them — `dragSourcesAt()` has said so since it was written,
     * but nothing checked that `pressRow()` asks it the right question. It is
     * the only caller now, so this is the only place that can.
     */
    public function testAModifiedPressArmsNothing(): void
    {
        foreach ([ClickIntent::Add, ClickIntent::Range] as $intent) {
            foreach ([1, 2, 4] as $relRow) { // a folder, a file inside it, a file beside it
                $this->assertSame(
                    [],
                    $this->tree()->pressRow($relRow, $intent),
                    \sprintf('row %d with %s started a drag', $relRow, $intent->name),
                );
            }
        }

        // The control, or "arms nothing" would be satisfied by a press that
        // never arms anything at all.
        $this->assertNotSame([], $this->tree()->pressRow(4), 'an unmodified press on a file still does');
    }

    /**
     * The deferred toggle is really deferred, and really paid.
     *
     * Both halves in one test because either alone is satisfied by doing
     * nothing: "the folder did not expand on the press" passes for a press that
     * does nothing at all, and it is the pair that says the toggle was
     * postponed rather than lost.
     */
    public function testTheDeferredToggleIsPaidByTheClickAndDroppedByTheDrag(): void
    {
        $rows = static fn (FileTreeWidget $tree): int => \count(array_filter(
            $tree->render(new RenderContext(40, 12)),
            static fn (string $line): bool => '' !== trim(AnsiUtils::stripAnsiCodes($line)),
        ));

        $tree = $this->tree(); // src/ starts EXPANDED, so the toggle collapses it
        $before = $rows($tree);
        $tree->pressRow(1);
        $this->assertSame($before, $rows($tree), 'the press alone left the tree standing');

        $tree->commitPress();
        $this->assertLessThan($before, $rows($tree), 'and the click paid the toggle it owed');

        $tree = $this->tree();
        $tree->pressRow(1);
        $tree->forgetPress();
        $tree->commitPress();
        $this->assertSame($before, $rows($tree), 'a drag abandons it, and a later click owes nothing');
    }

    /** Pressing a folder still moves the cursor to it, exactly as a click would. */
    public function testPressingAFolderSelectsItEvenWhileTheToggleWaits(): void
    {
        $tree = $this->tree();
        $row = static fn (): string => $tree->render(new RenderContext(40, 12))[1];

        $tree->clickRow(4); // cursor on top.php, far away
        $asDirectoryOnly = $row();

        $tree->pressRow(1); // press src/

        // Compared against the SAME row a moment earlier, not against plain
        // text: `src/` is a directory, so the chain's directory arm paints it
        // whether the cursor is on it or not, and a stripped comparison would
        // call that a pass.
        $this->assertNotSame($asDirectoryOnly, $row(), 'the press moved the cursor onto it');
        $this->assertSame(
            [$this->project->path('src')],
            $tree->dragSourcesAt(1, modified: false),
            'and a drag from there carries that folder alone',
        );
    }

    /**
     * Re-declaring the same drop target does not invalidate the widget.
     *
     * Mode 1003 delivers a button-held drag as a flood of motion events, and
     * the router hands every one of them to `setDropTarget()` — so without the
     * guard the tree's memo is dropped on each, and a drag across one folder
     * re-renders the whole tree dozens of times for a picture that never
     * changed. The same contract {@see FileTreeWidget::hoverRow()} states in
     * its return value, for the same flood.
     */
    public function testRepeatingTheDropTargetDoesNotDropTheMemo(): void
    {
        $tree = $this->tree();
        $src = $this->project->path('src');

        $tree->setDropTarget($src);
        $afterFirst = $tree->getRenderRevision();
        $tree->setDropTarget($src);
        $this->assertSame($afterFirst, $tree->getRenderRevision(), 'the same target again changes nothing to redraw');

        $tree->setDropTarget(null);
        $this->assertNotSame($afterFirst, $tree->getRenderRevision(), 'and a real change still does');
    }

    /**
     * The row-style precedence chain, pinned as a whole.
     *
     * Every arm of it is a STYLE and nothing else — the plain text is identical
     * whichever fires — so a stripped comparison grades every one of them as
     * passing. Removing an arm makes its state fall through to a lower one, and
     * the only thing that notices is a raw comparison between the states, which
     * is what this is: five states of one file row, required to be five
     * different strings.
     *
     * Written after a mutation run over this session's own work escaped two
     * MatchArmRemovals in this chain. The drop-target arm was the one arm that
     * already had this test; the rest of the chain, which predates it, had
     * never been graded at all.
     */
    public function testEachArmOfTheRowStyleChainPaintsDifferently(): void
    {
        $tree = $this->tree();
        $top = $this->project->path('top.php');
        // Row 4 is top.php throughout; the cursor is parked on row 2 except
        // where being the cursor row is the state under test.
        $row = static fn (): string => $tree->render(new RenderContext(40, 12))[4];

        $tree->clickRow(2); // cursor + selection on src/a.php, far from row 4
        $painted = ['plain' => $row()];

        $tree->hoverRow(4);
        $painted['hovered'] = $row();

        $tree->clearHover();
        $tree->clickRow(4, ClickIntent::Add); // into the selection, cursor lands here…
        $tree->clickRow(3, ClickIntent::Add); // …and moves off again
        $painted['selected, not the cursor'] = $row();

        $tree->clickRow(4); // now it IS the cursor row
        $painted['the cursor row'] = $row();

        $tree->clickRow(2); // cursor away again
        $tree->setDropTarget($top);
        $painted['the drop target'] = $row();

        $this->assertCount(
            \count($painted),
            array_unique($painted),
            'two states of the same row rendered identically, so at least one arm of the chain is unreachable: '.implode(', ', array_keys($painted)),
        );
    }

    /**
     * A directory is painted as one even when it is nothing else — not the
     * cursor, not selected, not hovered, not a drop target.
     *
     * The last arm before `default`, and the one a mutation run showed nothing
     * was holding. The file row is the control: in the very same state it must
     * carry no styling at all, or "the directory is styled" would just be
     * saying that the tree styles its rows.
     */
    public function testADirectoryRowIsPaintedWhenItIsNothingElse(): void
    {
        $tree = $this->tree();
        $tree->clickRow(2); // cursor and selection on src/a.php — row 1 and row 4 are plain
        $lines = $tree->render(new RenderContext(40, 12));

        $this->assertNotSame(AnsiUtils::stripAnsiCodes($lines[1]), $lines[1], 'src/ is a directory and reads as one');
        $this->assertSame(AnsiUtils::stripAnsiCodes($lines[4]), $lines[4], 'top.php, in the same state, carries nothing');
    }
}
