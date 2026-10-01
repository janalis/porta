<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\FloatingLayer;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A mirrored frame's floating surfaces, on a canvas of their own: blank where
 * nothing floats, stacked in the panes' order, and each setter saying whether
 * its slot changed so the caller can ask for a frame.
 */
final class FloatingLayerTest extends TestCase
{
    public function testAnEmptyLayerIsABlankCanvasOfTheFramesSize(): void
    {
        $this->assertSame(['    ', '    ', '    '], new FloatingLayer()->rows(4, 3));
        $this->assertSame([], new FloatingLayer()->rows(4, 0));
    }

    public function testTheSlotsStackModalToastTooltipMenu(): void
    {
        $layer = new FloatingLayer();
        $span = static fn (string $text): array => [['row' => 0, 'col' => 0, 'width' => 4, 'segment' => $text]];

        $top = static fn (): string => AnsiUtils::stripAnsiCodes($layer->rows(4, 1)[0]);

        $layer->setMenu($span('menu'));
        $layer->setTooltip($span('tip!'));
        $layer->setToast($span('tost'));
        $layer->setModal($span('modl'));
        $this->assertSame('menu', $top(), 'the menus above everything');

        $layer->setMenu([]);
        $this->assertSame('tip!', $top(), 'then the tooltip');
        $layer->setTooltip([]);
        $this->assertSame('tost', $top(), 'then the toasts');
        $layer->setToast([]);
        $this->assertSame('modl', $top(), 'the modal over a view window');
        $layer->setWindow($span('wind'));
        $this->assertSame('modl', $top(), 'a picker floats above the window it opened over');
        $layer->setModal([]);
        $this->assertSame('wind', $top(), 'the window lowest');
    }

    public function testASpanLandsAtItsOwnScreenCell(): void
    {
        $layer = new FloatingLayer();
        $layer->setToast([['row' => 1, 'col' => 2, 'width' => 2, 'segment' => 'ab']]);

        $this->assertSame(['      ', '  ab  '], \array_map(AnsiUtils::stripAnsiCodes(...), $layer->rows(6, 2)));
    }

    /** A pane invalidates itself when its spans change; the layer's caller asks for the frame instead. */
    public function testEachSetterSaysWhetherItsSlotChanged(): void
    {
        $layer = new FloatingLayer();
        $spans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'x']];

        foreach (['setWindow', 'setModal', 'setToast', 'setTooltip', 'setMenu'] as $setter) {
            $this->assertTrue($layer->{$setter}($spans), "{$setter}: new spans");
            $this->assertFalse($layer->{$setter}($spans), "{$setter}: the same again");
            $this->assertTrue($layer->{$setter}([]), "{$setter}: cleared");
        }
    }

    public function testTheCanvasRectIsTheScreenAtAnySize(): void
    {
        $rect = FloatingLayer::rect();

        $this->assertSame([0, 0], [$rect->row, $rect->col]);
        $this->assertSame([\PHP_INT_MAX, \PHP_INT_MAX], [$rect->rows, $rect->columns]);
    }
}
