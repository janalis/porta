<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Widget\DiffCharSelection;

final class DiffCharSelectionTest extends TestCase
{
    public function testADragDownAndLeftIsAlreadyInOrder(): void
    {
        $selection = new DiffCharSelection(1, 5, 2, 1, null);

        $this->assertSame($selection, $selection->normalized(), 'a later row comes after, whatever its column');
    }

    public function testADragBackwardsIsTurnedRound(): void
    {
        $normalized = new DiffCharSelection(2, 1, 1, 5, null)->normalized();

        $this->assertSame([1, 5, 2, 1], [$normalized?->fromRow, $normalized?->fromCol, $normalized?->toRow, $normalized?->toCol]);
        $this->assertNull(new DiffCharSelection(3, 4, 3, 4, null)->normalized(), 'a click, not a selection');
    }
}
