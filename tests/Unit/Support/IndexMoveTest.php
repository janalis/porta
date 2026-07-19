<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\IndexMove;

final class IndexMoveTest extends TestCase
{
    public function testTargetRejectsAnOutOfRangeSource(): void
    {
        $this->assertNull(IndexMove::target(-1, 2, 5));
        $this->assertNull(IndexMove::target(5, 2, 5));
    }

    public function testTargetClampsTheDestinationAndRejectsANoOp(): void
    {
        $this->assertSame(4, IndexMove::target(0, 9, 5), 'clamped to the last index');
        $this->assertSame(0, IndexMove::target(3, -2, 5), 'clamped to the first index');
        $this->assertNull(IndexMove::target(2, 2, 5), 'already there');
        $this->assertNull(IndexMove::target(4, 9, 5), 'clamped destination equals the source');
    }

    public function testApplyMovesForwardAndBackward(): void
    {
        $this->assertSame(['b', 'c', 'a', 'd'], IndexMove::apply(['a', 'b', 'c', 'd'], 0, 2));
        $this->assertSame(['d', 'a', 'b', 'c'], IndexMove::apply(['a', 'b', 'c', 'd'], 3, 0));
    }

    public function testReindexActiveKeepsThePointerOnTheSameElement(): void
    {
        $this->assertSame(5, IndexMove::reindexActive(2, 2, 5), 'the moved element carries the pointer');
        $this->assertSame(2, IndexMove::reindexActive(3, 1, 4), 'an element dragged from before shifts the pointer left');
        $this->assertSame(4, IndexMove::reindexActive(3, 5, 1), 'an element dragged from after shifts the pointer right');
        $this->assertSame(1, IndexMove::reindexActive(1, 5, 6), 'a move outside the pointer leaves it put');
    }
}
