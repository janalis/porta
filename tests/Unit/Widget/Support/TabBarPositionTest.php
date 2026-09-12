<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Support;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Support\TabBarPosition;

final class TabBarPositionTest extends TestCase
{
    public function testMirroringSwapsTheSides(): void
    {
        $this->assertSame(TabBarPosition::Right, TabBarPosition::Left->mirrored());
        $this->assertSame(TabBarPosition::Left, TabBarPosition::Right->mirrored());
    }

    public function testMirroringLeavesTopAndBottomAlone(): void
    {
        // Mirroring a workspace is horizontal: a bar above the editor stays
        // above it. Swapping these would move the strip for no reason.
        $this->assertSame(TabBarPosition::Top, TabBarPosition::Top->mirrored());
        $this->assertSame(TabBarPosition::Bottom, TabBarPosition::Bottom->mirrored());
    }

    public function testMirroringTwiceIsTheIdentity(): void
    {
        foreach (TabBarPosition::cases() as $position) {
            $this->assertSame($position, $position->mirrored()->mirrored(), $position->value);
        }
    }
}
