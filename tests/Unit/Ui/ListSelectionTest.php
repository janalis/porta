<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ListSelection;

final class ListSelectionTest extends TestCase
{
    public function testStartsAtTop(): void
    {
        $list = new ListSelection();
        $this->assertSame(0, $list->selected());
        $this->assertSame(0, $list->scroll());
    }

    public function testMoveUpStopsAtFirstEntry(): void
    {
        $list = new ListSelection();
        $list->moveUp();
        $this->assertSame(0, $list->selected());

        $list->select(3);
        $list->moveUp();
        $this->assertSame(2, $list->selected());
    }

    public function testMoveDownStopsAtLastEntry(): void
    {
        $list = new ListSelection();
        $list->moveDown(count: 3);
        $this->assertSame(1, $list->selected());
        $list->moveDown(count: 3);
        $list->moveDown(count: 3);
        $this->assertSame(2, $list->selected());
    }

    public function testMoveDownOnEmptyListStaysAtZero(): void
    {
        $list = new ListSelection();
        $list->moveDown(count: 0);
        $this->assertSame(0, $list->selected());
    }

    public function testPageStepsClampAtBothEnds(): void
    {
        $list = new ListSelection();
        $list->moveDown(count: 10, step: 24);
        $this->assertSame(9, $list->selected());
        $list->moveUp(step: 24);
        $this->assertSame(0, $list->selected());
    }

    public function testClampAfterListShrank(): void
    {
        $list = new ListSelection();
        $list->select(7);
        $list->clamp(3);
        $this->assertSame(2, $list->selected());

        $list->clamp(0);
        $this->assertSame(0, $list->selected());
    }

    public function testResetReturnsToTop(): void
    {
        $list = new ListSelection();
        $list->select(5);
        $list->follow(2); // scroll to 4
        $list->reset();
        $this->assertSame(0, $list->selected());
        $this->assertSame(0, $list->scroll());
    }

    public function testFollowKeepsSelectionInsideTheWindow(): void
    {
        $list = new ListSelection();

        // Selection inside the window: scroll untouched.
        $list->select(2);
        $this->assertSame(0, $list->follow(5));

        // Below the window: shift down so the selection is the last row.
        $list->select(7);
        $this->assertSame(3, $list->follow(5));

        // Above the window: shift up so the selection is the first row.
        $list->select(1);
        $this->assertSame(1, $list->follow(5));
    }

    public function testFollowBoundaryRowsDoNotShift(): void
    {
        $list = new ListSelection();
        $list->select(4);
        $this->assertSame(0, $list->follow(5)); // exactly the last visible row
        $list->select(0);
        $this->assertSame(0, $list->follow(5)); // exactly the first visible row
    }

    public function testSelectIfChangedIgnoresNullAndSameRow(): void
    {
        $list = new ListSelection();
        $this->assertFalse($list->selectIfChanged(null), 'outside the rows');
        $this->assertFalse($list->selectIfChanged(0), 'already selected');
        $this->assertTrue($list->selectIfChanged(3));
        $this->assertSame(3, $list->selected());
    }

    public function testFollowHeightsKeepsAsManyItemsAboveTheSelectionAsFit(): void
    {
        $list = new ListSelection();
        $heights = [3, 3, 3, 3];

        // Everything fits: scroll untouched.
        $list->select(1);
        $this->assertSame(0, $list->followHeights($heights, 12));

        // Selecting the last with room for two cards: scroll to the third.
        $list->select(3);
        $this->assertSame(2, $list->followHeights($heights, 7));
    }

    public function testFollowHeightsScrollsBackUpWhenTheSelectionMovesAboveTheWindow(): void
    {
        $list = new ListSelection();
        $heights = [3, 3, 3, 3];
        $list->select(3);
        $list->followHeights($heights, 7); // window starts at 2

        $list->select(0);
        $this->assertSame(0, $list->followHeights($heights, 7));
    }

    /**
     * `countFitting()` is `followHeights()`'s question from the other end: how
     * many entries a window holds, which is what a page is for a list measured
     * in entries and drawn in rows.
     */
    public function testCountFittingCountsTheEntriesAWindowHolds(): void
    {
        $list = new ListSelection();
        $heights = [3, 3, 3, 3];

        $this->assertSame(3, $list->countFitting($heights, from: 0, availableRows: 9), 'three three-row cards in nine rows');
        $this->assertSame(3, $list->countFitting($heights, from: 0, availableRows: 11), 'and a fourth needs twelve');
        $this->assertSame(2, $list->countFitting($heights, from: 0, availableRows: 8), 'one row short of the third');
        $this->assertSame(2, $list->countFitting($heights, from: 2, availableRows: 9), 'counted from where the window starts');
        $this->assertSame(1, $list->countFitting($heights, from: 3, availableRows: 9), 'and never past the end');
    }

    /**
     * Never zero. A card taller than the whole window still has to be
     * reachable, and a page of zero turns Page Down into a key that does
     * nothing at all.
     */
    public function testCountFittingIsNeverLessThanOne(): void
    {
        $list = new ListSelection();

        $this->assertSame(1, $list->countFitting([9], from: 0, availableRows: 4), 'an entry taller than the window');
        $this->assertSame(1, $list->countFitting([3, 3], from: 0, availableRows: 0), 'no room at all');
        $this->assertSame(1, $list->countFitting([], from: 0, availableRows: 9), 'nothing to count');
        $this->assertSame(1, $list->countFitting([3, 3], from: 9, availableRows: 9), 'past the end');
    }

    /** A selection taller than the viewport pins the window to itself. */
    public function testFollowHeightsPinsAnOversizedSelection(): void
    {
        $list = new ListSelection();
        $list->select(2);

        $this->assertSame(2, $list->followHeights([2, 2, 9], 5));
    }

    /**
     * The window can be moved on its own, for a drag that has to reach a row
     * off the bottom of the pane.
     *
     * Every other mover here moves the SELECTION and lets {@see
     * ListSelection::follow()} drag the window after it. A pointer drag is the
     * one gesture that cannot: the row the user is aiming at is off-screen, and
     * moving the selection to it would both change what is selected and, in the
     * tree, change what the next click acts on.
     */
    public function testTheWindowMovesOnItsOwn(): void
    {
        $list = new ListSelection();

        $this->assertTrue($list->scrollWindow(3, count: 20, visibleRows: 5));

        $this->assertSame(3, $list->scroll());
        $this->assertSame(3, $list->follow(5), 'and the next render leaves it there');
    }

    /**
     * The selection comes along only when it would otherwise fall outside.
     *
     * This is the whole reason the method exists rather than a bare setter.
     * `follow()` runs on every render and pulls the window back to contain the
     * selection, so a window moved past the selection would snap straight back
     * and the drag would never reach anything.
     */
    public function testTheSelectionIsDraggedAlongOnlyWhenTheWindowLeavesItBehind(): void
    {
        $list = new ListSelection();
        $list->select(4);

        // Still inside [2, 6]: untouched.
        $list->scrollWindow(2, count: 20, visibleRows: 5);
        $this->assertSame(4, $list->selected(), 'the selection was still on screen');

        // The window moves past it, so it comes to the top row.
        $list->scrollWindow(4, count: 20, visibleRows: 5);
        $this->assertSame(6, $list->scroll());
        $this->assertSame(6, $list->selected(), 'pulled to the first visible row');
        $this->assertSame(6, $list->follow(5), 'so the render does not undo the scroll');
    }

    /** Scrolling back up hands the selection to the LAST visible row. */
    public function testScrollingUpPastTheSelectionPullsItToTheBottomRow(): void
    {
        $list = new ListSelection();
        $list->select(15);
        $list->scrollWindow(15, count: 20, visibleRows: 5);

        $list->scrollWindow(-10, count: 20, visibleRows: 5);

        $this->assertSame(5, $list->scroll());
        $this->assertSame(9, $list->selected(), 'the bottom of [5, 9]');
        $this->assertSame(5, $list->follow(5));
    }

    /**
     * The window stops at both ends, and says when it did not move.
     *
     * The return value is what lets a caller skip a repaint, so "I refused"
     * has to be distinguishable from "I scrolled".
     */
    public function testTheWindowStopsAtBothEndsOfTheList(): void
    {
        $list = new ListSelection();

        $this->assertFalse($list->scrollWindow(-1, count: 20, visibleRows: 5), 'already at the top');
        $this->assertSame(0, $list->scroll());

        $this->assertTrue($list->scrollWindow(99, count: 20, visibleRows: 5));
        $this->assertSame(15, $list->scroll(), 'the last full window, not past the end');
        $this->assertFalse($list->scrollWindow(1, count: 20, visibleRows: 5), 'and it stays there');
    }

    /** A list shorter than the window has nowhere to scroll. */
    public function testAListThatFitsDoesNotScroll(): void
    {
        $list = new ListSelection();

        $this->assertFalse($list->scrollWindow(5, count: 3, visibleRows: 10));
        $this->assertSame(0, $list->scroll());
    }
}
