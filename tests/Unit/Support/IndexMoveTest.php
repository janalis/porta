<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\IndexMove;

final class IndexMoveTest extends TestCase
{
    public function testTargetRejectsAnOutOfRangeSource(): void
    {
        $this->assertNull(IndexMove::target(-1, to: 2, count: 5));
        $this->assertNull(IndexMove::target(5, to: 2, count: 5));
    }

    public function testTargetClampsTheDestinationAndRejectsANoOp(): void
    {
        $this->assertSame(4, IndexMove::target(0, to: 9, count: 5), 'clamped to the last index');
        $this->assertSame(0, IndexMove::target(3, -2, count: 5), 'clamped to the first index');
        $this->assertNull(IndexMove::target(2, to: 2, count: 5), 'already there');
        $this->assertNull(IndexMove::target(4, to: 9, count: 5), 'clamped destination equals the source');
    }

    public function testApplyMovesForwardAndBackward(): void
    {
        $this->assertSame(['b', 'c', 'a', 'd'], IndexMove::apply(['a', 'b', 'c', 'd'], from: 0, to: 2));
        $this->assertSame(['d', 'a', 'b', 'c'], IndexMove::apply(['a', 'b', 'c', 'd'], from: 3, to: 0));
    }

    public function testReindexActiveKeepsThePointerOnTheSameElement(): void
    {
        $this->assertSame(5, IndexMove::reindexActive(2, from: 2, to: 5), 'the moved element carries the pointer');
        $this->assertSame(2, IndexMove::reindexActive(3, from: 1, to: 4), 'an element dragged from before shifts the pointer left');
        $this->assertSame(4, IndexMove::reindexActive(3, from: 5, to: 1), 'an element dragged from after shifts the pointer right');
        $this->assertSame(1, IndexMove::reindexActive(1, from: 5, to: 6), 'a move outside the pointer leaves it put');
    }
}
