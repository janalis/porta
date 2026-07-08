<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\SelectionModel;

final class SelectionModelTest extends TestCase
{
    public function testBeginIsIdempotentAndClearResets(): void
    {
        $selection = new SelectionModel();
        $this->assertNull($selection->anchorLine());

        $selection->begin(2, 5);
        $selection->begin(9, 9); // no-op: already active
        $this->assertSame(2, $selection->anchorLine());
        $this->assertSame(5, $selection->anchorCol());

        $selection->clear();
        $this->assertNull($selection->anchorLine());
        $this->assertNull($selection->anchorCol());
    }

    public function testRangeIsNullWhenInactiveOrEmpty(): void
    {
        $selection = new SelectionModel();
        $this->assertNull($selection->range(0, 0), 'no anchor -> no range');

        $selection->set(3, 4);
        $this->assertNull($selection->range(3, 4), 'anchor === cursor -> empty');
    }

    public function testRangeNormalizesEitherDirection(): void
    {
        $selection = new SelectionModel();
        $selection->set(1, 2);

        $this->assertSame(['startLine' => 1, 'startCol' => 2, 'endLine' => 4, 'endCol' => 0], $selection->range(4, 0));
        $this->assertSame(['startLine' => 0, 'startCol' => 1, 'endLine' => 1, 'endCol' => 2], $selection->range(0, 1));
    }

    public function testShiftLineAndColClampAndNoOpWhenInactive(): void
    {
        $selection = new SelectionModel();
        $selection->shiftLine(3); // no-op while inactive
        $selection->shiftColClamped(3);
        $this->assertNull($selection->anchorLine());

        $selection->set(2, 1);
        $selection->shiftLine(2);
        $selection->shiftColClamped(-5); // clamps at the left margin
        $this->assertSame(4, $selection->anchorLine());
        $this->assertSame(0, $selection->anchorCol());
    }

    public function testSnapshotAndRestoreRoundTrip(): void
    {
        $selection = new SelectionModel();
        $selection->set(7, 3);

        $snap = $selection->snapshot();
        $selection->clear();
        $this->assertNull($selection->snapshot());

        $selection->restore($snap);
        $this->assertSame(7, $selection->anchorLine());
        $this->assertSame(3, $selection->anchorCol());
    }
}
