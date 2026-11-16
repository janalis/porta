<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\PopupMetrics;

final class PopupMetricsTest extends TestCase
{
    /** A third of the free rows above the box, half the free columns beside it, measured from the union's own corner. */
    public function testACenteredBoxSitsAThirdDownAndCenteredAcross(): void
    {
        $this->assertSame([2 + 6, 10 + 20], PopupMetrics::centered([2, 10, 32, 110], boxRows: 12, boxWidth: 60));
    }

    /** A box bigger than the union starts at its corner rather than off it. */
    public function testABoxTooBigForTheUnionStartsAtItsCorner(): void
    {
        $this->assertSame([2, 10], PopupMetrics::centered([2, 10, 12, 50], boxRows: 30, boxWidth: 90));
    }
}
