<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\PreviewSplit;
use Porta\Tests\Support\TestPane;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The editor beside its preview: the share the divider sets, the clamps that
 * keep both sides usable, the mirrored seat, the narrow fallback — and the
 * focus, which re-seating the sides must not lose.
 */
final class PreviewSplitTest extends TestCase
{
    private Tui $tui;
    private VirtualTerminal $terminal;
    private Renderer $renderer;
    private PreviewSplit $split;
    private CodeEditorWidget $editor;
    private TestPane $pane;

    private function mounted(int $columns = 101): void
    {
        $this->terminal = new VirtualTerminal(columns: $columns, rows: 10);
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: $this->terminal, keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->split = new PreviewSplit(Styles::dark(), renderer: $this->renderer, tui: $this->tui);
        $this->editor = new CodeEditorWidget();
        $this->pane = new TestPane('md');
        $this->split->mount($this->editor, $this->pane);
        $this->tui->add($this->split->area);
        $this->frame();
    }

    private function frame(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function columnsOf(object $widget): int
    {
        $rect = $this->renderer->getWidgetRect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect->columns;
    }

    public function testTheSidesShareTheColumnsBesideTheDividerHalfAndHalf(): void
    {
        $this->mounted();

        $this->assertSame(50, $this->split->percent());
        $this->assertSame(50, $this->columnsOf($this->editor));
        $this->assertSame(50, $this->columnsOf($this->pane));
        $this->assertSame(1, $this->columnsOf($this->split->separator));
        $this->assertSame($this->pane, $this->split->preview());
    }

    public function testADragGivesTheEditorEverythingUpToThePointerWithinTheClamps(): void
    {
        $this->mounted();
        $area = $this->split->rect();
        $this->assertInstanceOf(WidgetRect::class, $area);

        $this->split->dragTo(30, $area);
        $this->frame();
        $this->assertSame(30, $this->columnsOf($this->editor));
        $this->assertSame(30, $this->split->percent());

        $this->split->dragTo(2, $area);
        $this->frame();
        $this->assertSame(PreviewSplit::MIN_SIDE, $this->columnsOf($this->editor), 'the editor keeps its floor');

        $this->split->dragTo(99, $area);
        $this->frame();
        $this->assertSame(PreviewSplit::MIN_SIDE, $this->columnsOf($this->pane), 'and so does the preview');

        $this->split->nudge(-10, $area);
        $this->frame();
        $this->assertSame(70, $this->columnsOf($this->editor), 'a nudge moves by columns from where it stands');
    }

    public function testAMirroredSplitSeatsTheEditorOnTheRightAndDragsFromThatEdge(): void
    {
        $this->mounted();
        $this->split->setMirrored(true);
        $this->split->setMirrored(true);
        $this->frame();
        $this->assertSame([$this->pane, $this->editor], [...$this->split->area->all()[0]->all(), ...$this->split->area->all()[2]->all()]);

        $area = $this->split->rect();
        $this->assertInstanceOf(WidgetRect::class, $area);
        $this->split->dragTo(70, $area);
        $this->frame();
        $this->assertSame(30, $this->columnsOf($this->editor), 'the editor runs from the right edge to the pointer');
    }

    public function testAPersistedShareIsClampedIntoRange(): void
    {
        $this->mounted();

        $this->split->setPercent(0);
        $this->assertSame(1, $this->split->percent());
        $this->split->setPercent(150);
        $this->assertSame(99, $this->split->percent());
        $this->split->setPercent(40);
        $this->frame();
        $this->assertSame(40, $this->columnsOf($this->editor));
    }

    public function testMountingWhatIsAlreadyMountedKeepsTheFocus(): void
    {
        $this->mounted();
        $this->tui->setFocus($this->editor);

        $this->split->mount($this->editor, $this->pane);

        $this->assertSame($this->editor, $this->tui->getFocus(), 'a re-mount would detach the editor and drop its keys');
    }

    public function testReleaseHandsBothWidgetsBackDetached(): void
    {
        $this->mounted();

        $this->split->release();

        $this->assertNull($this->split->preview());
        $this->assertNull($this->editor->getContext());
        $this->assertNull($this->pane->getContext());
    }

    public function testATooNarrowSplitShowsTheEditorAloneAndGivesItThePreviewsKeys(): void
    {
        $this->mounted(columns: 40);
        $this->tui->setFocus($this->pane);

        $this->split->fit();
        $this->frame();

        $this->assertCount(1, $this->split->area->all(), 'the divider and the preview stepped aside');
        $this->assertNull($this->pane->getContext());
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the keys went where the reader still is');
        $area = $this->split->rect();
        $this->assertInstanceOf(WidgetRect::class, $area);
        $this->split->dragTo(5, $area);
        $this->assertSame(50, $this->split->percent(), 'no divider on screen, nothing to drag');

        $this->split->fit();
        $this->assertCount(1, $this->split->area->all(), 'still narrow: nothing re-seated');
    }

    public function testRoomAgainBringsThePreviewBackAndKeepsTheFocusWhereItWas(): void
    {
        $this->mounted(columns: 40);
        $this->split->fit();
        $this->frame();
        $this->tui->setFocus($this->editor);

        $this->terminal->simulateResize(100, 10);
        $this->frame();
        $this->split->fit();

        $this->assertCount(3, $this->split->area->all());
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the editor kept the keys through the re-seat');
    }

    public function testAnAreaOffScreenHasNoRectAndFitsNothing(): void
    {
        $split = new PreviewSplit(Styles::dark(), renderer: new Renderer(new StyleSheet()));

        $this->assertNull($split->rect());
        $split->fit();
        $this->assertCount(3, $split->area->all());
    }
}
