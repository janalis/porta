<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;

final class CaretTest extends TestCase
{
    public function testAPointCaretHasNoSelection(): void
    {
        $caret = Caret::point(3, 5);

        $this->assertFalse($caret->hasSelection());
    }

    public function testASameLineDifferentColumnCaretHasASelection(): void
    {
        $caret = new Caret(line: 3, col: 5, anchorLine: 3, anchorCol: 2);

        $this->assertTrue($caret->hasSelection());
    }

    public function testADifferentLineSameColumnCaretHasASelection(): void
    {
        $caret = new Caret(line: 4, col: 5, anchorLine: 2, anchorCol: 5);

        $this->assertTrue($caret->hasSelection());
    }

    public function testOrderedRangeOfAPointCaretIsZeroWidthAtItsOwnPosition(): void
    {
        $caret = Caret::point(3, 5);

        $this->assertSame([3, 5, 3, 5], $caret->orderedRange());
    }

    public function testOrderedRangeWithTheAnchorBeforeTheCursor(): void
    {
        $caret = new Caret(line: 5, col: 2, anchorLine: 1, anchorCol: 0);

        $this->assertSame([1, 0, 5, 2], $caret->orderedRange());
    }

    public function testOrderedRangeWithTheCursorBeforeTheAnchor(): void
    {
        $caret = new Caret(line: 1, col: 0, anchorLine: 5, anchorCol: 2);

        $this->assertSame([1, 0, 5, 2], $caret->orderedRange());
    }

    public function testOrderedRangeOnTheSameLineOrdersByColumn(): void
    {
        $caret = new Caret(line: 3, col: 1, anchorLine: 3, anchorCol: 7);

        $this->assertSame([3, 1, 3, 7], $caret->orderedRange());
    }
}
