<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Layout\NarrowSplit;
use Porta\Layout\PaneSplit;
use Porta\Tests\Support\TestPane;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * What a view's split adds to the preview's ({@see PreviewSplitTest} grades
 * the shared drag and clamps): the lead's columns known before the frame
 * lays them out, and the columns stacked into rows when too narrow — said to
 * whoever listens, and undone when the room comes back.
 */
final class PaneSplitTest extends TestCase
{
    private Tui $tui;

    private VirtualTerminal $terminal;

    private Renderer $renderer;

    private TestPane $lead;

    private TestPane $rest;

    private function split(int $columns, int $share = 400): PaneSplit
    {
        $this->terminal = new VirtualTerminal(columns: $columns, rows: 10);
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: $this->terminal, keybindings: new Keybindings([]), renderer: $this->renderer);
        $split = new PaneSplit('s', Styles::dark(), NarrowSplit::Stacked, $this->renderer, $this->tui, $share, minSide: 28);
        $split->mount($this->lead = new TestPane('files'), $this->rest = new TestPane('diff'));
        $this->tui->add($split->area);
        $this->frame();

        return $split;
    }

    private function frame(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function rectOf(object $widget): WidgetRect
    {
        $rect = $this->renderer->getWidgetRect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect;
    }

    /** @return iterable<string, array{int, int}> */
    public static function widths(): iterable
    {
        yield 'even' => [101, 400];
        yield 'odd' => [120, 400];
        yield 'a third' => [89, 333];
        yield 'most' => [97, 677];
    }

    #[DataProvider('widths')]
    public function testTheLeadsColumnsAreTheOnesTheLayoutGivesIt(int $columns, int $share): void
    {
        $split = $this->split($columns, $share);

        $this->assertSame($this->rectOf($this->lead)->columns, $split->leadColumns($columns));
        $this->assertSame($split->leadColumns($columns), $this->rectOf($split->separator)->col);
    }

    public function testTooNarrowTheColumnsStackAndComeBackSayingSoEachTime(): void
    {
        $split = $this->split(columns: 120);
        $heard = [];
        $split->onNarrow(static function (bool $narrow) use (&$heard): void {
            $heard[] = $narrow;
        });
        $this->tui->setFocus($this->rest);

        $this->terminal->simulateResize(56, 10);
        $this->frame();
        $split->fit();
        $this->frame();

        $this->assertTrue($split->isNarrow());
        $this->assertSame([0, 0, 56], [$this->rectOf($this->lead)->row, $this->rectOf($this->lead)->col, $this->rectOf($this->rest)->columns], 'the lead on top, the rest under it, across the area');
        $this->assertGreaterThan($this->rectOf($this->lead)->row, $this->rectOf($this->rest)->row);
        $this->assertNull($split->separator->getContext(), 'no divider to drag');
        $this->assertSame($this->rest, $this->tui->getFocus(), 'the keys stay where they were');

        $split->fit();
        $this->terminal->simulateResize(57, 10);
        $this->frame();
        $split->fit();
        $this->frame();

        $this->assertFalse($split->isNarrow());
        $this->assertSame([true, false], $heard, 'once per flip, not per frame');
        $this->assertSame($this->rectOf($this->lead)->row, $this->rectOf($this->rest)->row, 'side by side again');
        $this->assertSame(1, $this->rectOf($split->separator)->columns);
    }

    public function testADragWhileStackedMovesNothing(): void
    {
        $split = $this->split(columns: 50);
        $split->fit();
        $this->frame();

        $split->dragTo(10, $this->rectOf($split->area));

        $this->assertSame(40, $split->percent());
    }

    public function testTheShareIsKeptWithinWhatAWeightCanSay(): void
    {
        $this->assertSame(1, $this->split(columns: 120, share: 0)->percent());
        $this->assertSame(99, $this->split(columns: 120, share: 2000)->percent());
    }
}
