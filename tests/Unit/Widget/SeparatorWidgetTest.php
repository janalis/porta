<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SeparatorWidgetTest extends TestCase
{
    use RendersWidget;

    public function testRendersAOneColumnRuleFillingTheHeight(): void
    {
        $sep = new SeparatorWidget();
        $this->assertTrue($sep->isVerticallyExpanded());
        $this->assertSame($sep, $sep->expandVertically(false), 'expandVertically is a no-op fluent setter');

        $lines = $sep->render(new RenderContext(1, 5));
        $this->assertCount(5, $lines);
        foreach ($lines as $line) {
            $this->assertSame(Glyphs::PANE_DIVIDER, AnsiUtils::stripAnsiCodes($line));
            $this->assertSame(1, AnsiUtils::visibleWidth($line), 'the divider is exactly its one column');
        }
    }

    public function testTheDividerThickensUnderThePointer(): void
    {
        $sep = new SeparatorWidget();
        $sep->render(new RenderContext(1, 3));

        $this->assertTrue($sep->hover(), 'the pointer arrived');
        $this->assertFalse($sep->hover(), 'already hovered → no change');
        $hovered = $sep->render(new RenderContext(1, 3));
        $this->assertSame(Glyphs::PANE_DIVIDER_ACTIVE, AnsiUtils::stripAnsiCodes($hovered[0]));
        $this->assertSame(1, AnsiUtils::visibleWidth($hovered[0]), 'the heavy rule is still one column');

        $this->assertTrue($sep->clearHover(), 'the pointer left');
        $this->assertFalse($sep->clearHover(), 'nothing left to clear');
        $this->assertSame(Glyphs::PANE_DIVIDER, AnsiUtils::stripAnsiCodes($sep->render(new RenderContext(1, 3))[0]));
    }

    /**
     * The reason the affordance is a glyph and not just a shade: the
     * monochrome theme resolves the hover roles to nothing, so a tinted
     * divider would be invisible with --no-ansi — which is the bug this fixes.
     */
    public function testTheStatesStillReadWithoutColour(): void
    {
        $sep = new SeparatorWidget(new Styles(MonochromeTheme::definition()));

        $rest = AnsiUtils::stripAnsiCodes($sep->render(new RenderContext(1, 1))[0]);
        $sep->hover();
        $hovered = AnsiUtils::stripAnsiCodes($sep->render(new RenderContext(1, 1))[0]);

        $this->assertNotSame($rest, $hovered, 'thin vs heavy still tells the two states apart');
        $this->assertSame(Glyphs::PANE_DIVIDER, $rest);
        $this->assertSame(Glyphs::PANE_DIVIDER_ACTIVE, $hovered);
    }

    public function testMenuOverlayCompositesOntoTheColumn(): void
    {
        $sep = new SeparatorWidget();
        $sep->setMenuOverlay([['row' => 2, 'col' => 0, 'width' => 1, 'segment' => "\x1b[7mX\x1b[0m"]]);

        $lines = $this->painted($sep, new RenderContext(1, 5));
        $this->assertSame('X', AnsiUtils::stripAnsiCodes($lines[2]), 'menu segment painted over the separator column');
        $this->assertSame(Glyphs::PANE_DIVIDER, AnsiUtils::stripAnsiCodes($lines[0]), 'other rows keep the rule');
    }

    /**
     * The divider is one column however wide the layout is, and a dropdown
     * crossing it composites into that one column — at the layout's width, the
     * row padded out past the divider into the pane beside it.
     */
    public function testAnOverlayCompositesIntoTheOneColumn(): void
    {
        $widget = new SeparatorWidget();
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 200, 'segment' => \str_repeat('M', times: 200)]]);

        $row = $this->painted($widget, new RenderContext(300, 5))[0];
        $this->assertSame(1, AnsiUtils::visibleWidth($row));
        $this->assertStringContainsString('M', AnsiUtils::stripAnsiCodes($row));
    }
}
