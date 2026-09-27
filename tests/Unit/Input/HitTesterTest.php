<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * {@see HitTester::rect} is public on purpose: {@see HitTester::hit} calls it
 * internally, but callers also read a widget's rect directly (mouse routers
 * checking a pane's bounds before delegating). A stale rect from a widget
 * that has since been detached must never be returned.
 */
final class HitTesterTest extends TestCase
{
    private function widget(): AbstractWidget
    {
        return new class extends AbstractWidget {
            #[\Override]
            public function render(RenderContext $context): array
            {
                return ['xx'];
            }
        };
    }

    public function testRectIsPubliclyCallable(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 10, rows: 2), renderer: $renderer);
        $widget = $this->widget();
        $tui->add($widget);
        $tui->requestRender();
        $tui->processRender();

        $tester = new HitTester($renderer);

        $this->assertInstanceOf(WidgetRect::class, $tester->rect($widget), 'rect() is callable from outside the class');
    }

    public function testRectOfAnAttachedRenderedWidgetIsNotNull(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 10, rows: 2), renderer: $renderer);
        $widget = $this->widget();
        $tui->add($widget);
        $tui->requestRender();
        $tui->processRender();

        $tester = new HitTester($renderer);

        $this->assertSame(0, $tester->rect($widget)?->row);
        $this->assertSame(10, $tester->rect($widget)?->columns);
    }

    public function testRectOfADetachedWidgetIsNullEvenWithAStaleRenderedRect(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 10, rows: 2), renderer: $renderer);
        $widget = $this->widget();
        $tui->add($widget);
        $tui->requestRender();
        $tui->processRender();
        $tui->remove($widget);

        $tester = new HitTester($renderer);

        $this->assertNull($tester->rect($widget), 'a detached widget must never route to a stale rect');
    }

    public function testHitInsideTheWidgetsRectReturnsRelativeCoordinates(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 10, rows: 2), renderer: $renderer);
        $widget = $this->widget();
        $tui->add($widget);
        $tui->requestRender();
        $tui->processRender();

        $tester = new HitTester($renderer);

        $this->assertSame(['row' => 0, 'col' => 3], $tester->hit($widget, 0, 3));
    }

    public function testHitOutsideTheWidgetsRectIsNull(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 10, rows: 2), renderer: $renderer);
        $widget = $this->widget();
        $tui->add($widget);
        $tui->requestRender();
        $tui->processRender();

        $tester = new HitTester($renderer);

        $this->assertNull($tester->hit($widget, 5, 5));
    }

    public function testContainsIsFalseWithoutARect(): void
    {
        $tester = new HitTester(new Renderer(new StyleSheet()));

        $this->assertFalse($tester->contains(null, MouseEvent::parse("\x1b[<0;1;1M")));
    }

    /** A widget answers in its own cells; the hit-tester asks in them and carries the answer back to the screen. */
    public function testTooltipAsksInWidgetCellsAndAnswersOnTheScreen(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 10, rows: 3), renderer: $renderer);
        $tui->add(new class extends AbstractWidget {
            #[\Override]
            public function render(RenderContext $context): array
            {
                return ['--'];
            }
        });
        $widget = new class extends AbstractWidget implements TooltipProvider {
            /** @var list<array{int, int}> */
            public array $asked = [];

            #[\Override]
            public function render(RenderContext $context): array
            {
                return ['ab', 'cd'];
            }

            #[\Override]
            public function tooltipAt(int $row, int $col): ?Tooltip
            {
                $this->asked[] = [$row, $col];

                return 0 === $col ? new Tooltip('a', 'the a', $row, 0, 1) : null;
            }
        };
        $tui->add($widget);
        $tui->requestRender();
        $tui->processRender();
        $tester = new HitTester($renderer);

        $tip = $tester->tooltip($widget, 2, 0);
        $this->assertSame([[1, 0]], $widget->asked, 'asked in its own cells');
        $this->assertSame([2, 0, 1], [$tip?->row, $tip?->colStart, $tip?->colEnd], 'answered on the screen');
        $this->assertNull($tester->tooltip($widget, 2, 1), 'a cell that means nothing');
        $this->assertNull($tester->tooltip($widget, 0, 0), 'outside the widget: not even asked');
        $this->assertCount(2, $widget->asked);
    }
}
