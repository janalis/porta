<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\SeparatorWidget;

final class SeparatorTooltipTest extends TestCase
{
    /** One key the whole column long, anchored on the pointer's row, so the card follows the pointer down the rule. */
    public function testTheDividerSaysItIsOneWhereverThePointerIs(): void
    {
        $divider = new SeparatorWidget();

        $top = $divider->tooltipAt(2, 0);
        $low = $divider->tooltipAt(9, 0);

        $this->assertSame(['separator', 'Drag to resize', 2, 0, 1], [$top?->key, $top?->text, $top?->row, $top?->colStart, $top?->colEnd]);
        $this->assertSame(['separator', 9], [$low?->key, $low?->row]);
    }
}
