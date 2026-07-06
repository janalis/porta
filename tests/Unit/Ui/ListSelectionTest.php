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
}
