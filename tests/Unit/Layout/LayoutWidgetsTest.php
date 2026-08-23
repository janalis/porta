<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\LayoutWidgets;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * The two widget shapes {@see \Porta\Layout\WorkspaceLayout} and
 * {@see \Porta\Layout\ViewRegions} each mint through here instead of
 * hand-rolling — nothing exercised these factory methods on their own before.
 */
final class LayoutWidgetsTest extends TestCase
{
    public function testContainerAppliesTheGivenIdAndStyleAndExpandsVertically(): void
    {
        $style = new Style(flex: 3);

        $container = LayoutWidgets::container('editor-body', $style);

        $this->assertSame('editor-body', $container->getId());
        $this->assertSame($style, $container->getStyle());
        $this->assertTrue($container->isVerticallyExpanded());
    }

    public function testSeparatorAppliesTheGivenIdAndAFixedFlexOfZero(): void
    {
        $separator = LayoutWidgets::separator(Styles::dark(), 'separator');

        $this->assertSame('separator', $separator->getId());
        $this->assertSame(0, $separator->getStyle()?->getFlex(), 'a separator is a fixed-width pane, not a flex share');
    }
}
