<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SeparatorWidgetTest extends TestCase
{
    public function testRendersOneColumnBlankLinesFillingHeight(): void
    {
        $sep = new SeparatorWidget();
        $this->assertTrue($sep->isVerticallyExpanded());
        $this->assertSame($sep, $sep->expandVertically(false), 'expandVertically is a no-op fluent setter');

        $lines = $sep->render(new RenderContext(1, 5));
        $this->assertCount(5, $lines);
        foreach ($lines as $line) {
            $this->assertSame(' ', $line);
        }
    }

    public function testMenuOverlayCompositesOntoTheColumn(): void
    {
        $sep = new SeparatorWidget();
        $sep->setMenuOverlay([['row' => 2, 'col' => 0, 'width' => 1, 'segment' => "\x1b[7mX\x1b[0m"]]);

        $lines = $sep->render(new RenderContext(1, 5));
        $this->assertSame('X', AnsiUtils::stripAnsiCodes($lines[2]), 'menu segment painted over the separator column');
        $this->assertSame(' ', $lines[0], 'other rows stay blank');
    }
}
