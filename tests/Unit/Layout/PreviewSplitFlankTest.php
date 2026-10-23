<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\PaneSplit;
use Porta\Layout\PreviewSplit;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\TestPane;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\WidgetContext;

/**
 * A preview with a flank — flank | editor | pane, the merge view's ours,
 * result and theirs: three columns, two dividers that each keep their own
 * share, and a narrowing area that gives up the flank, then the pane, and
 * never the editor.
 */
final class PreviewSplitFlankTest extends TestCase
{
    use ScreenReads;

    private Tui $tui;
    private VirtualTerminal $terminal;
    private Renderer $renderer;
    private PreviewSplit $split;
    private CodeEditorWidget $editor;
    private TestPane $flank;
    private TestPane $pane;

    private function mounted(int $columns = 92): void
    {
        $this->terminal = new VirtualTerminal(columns: $columns, rows: 10);
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: $this->terminal, keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->split = new PreviewSplit(Styles::dark(), renderer: $this->renderer, tui: $this->tui);
        $this->editor = new CodeEditorWidget();
        $this->flank = new TestPane('ours');
        $this->pane = new TestPane('theirs', $this->flank);
        $this->split->mount($this->editor, $this->pane);
        $this->tui->add($this->split->area);
        $this->frame($this->tui);
    }

    /** Each split's narrow check, outer first, as the frame hooks run them. */
    private function fit(): void
    {
        foreach ($this->split->splits() as $split) {
            $split->fit();
        }
        $this->frame($this->tui);
    }

    private function rect(object $widget): ?WidgetRect
    {
        return null === $widget->getContext() ? null : $this->renderer->getWidgetRect($widget);
    }

    private function columnsOf(object $widget): int
    {
        $rect = $this->rect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect->columns;
    }

    private function split(string $key): PaneSplit
    {
        return $this->split->splits()[$key];
    }

    public function testThreeColumnsInTheFlanksOrder(): void
    {
        $this->mounted();

        $columns = [$this->columnsOf($this->flank), $this->columnsOf($this->editor), $this->columnsOf($this->pane)];
        $this->assertSame(90, \array_sum($columns), 'all of it but the two dividers');
        $this->assertLessThanOrEqual(1, \max($columns) - \min($columns), 'thirds, to the column');
        $flank = $this->rect($this->flank);
        $editor = $this->rect($this->editor);
        $pane = $this->rect($this->pane);
        $this->assertInstanceOf(WidgetRect::class, $flank);
        $this->assertInstanceOf(WidgetRect::class, $editor);
        $this->assertInstanceOf(WidgetRect::class, $pane);
        $this->assertLessThan($editor->col, $flank->col, 'the flank on the editor\'s other side');
        $this->assertLessThan($pane->col, $editor->col);
        $this->assertSame($this->pane, $this->split->preview());
        $this->assertSame($this->flank, $this->split->flankPane());
        $this->assertNull($this->split->separator->getContext(), 'the plain split\'s divider is not on screen');
    }

    public function testEachDividerDragsItsOwnSplit(): void
    {
        $this->mounted();
        $area = $this->split('flank')->rect();
        $this->assertInstanceOf(WidgetRect::class, $area);

        $pane = $this->columnsOf($this->pane);
        $pair = $this->columnsOf($this->flank) + $this->columnsOf($this->editor);
        $this->split('flank')->dragTo($area->col + 25, $area);
        $this->frame($this->tui);

        $this->assertSame(25, $this->columnsOf($this->flank));
        $this->assertSame($pair - 25, $this->columnsOf($this->editor), 'the editor takes what the flank gave up');
        $this->assertSame($pane, $this->columnsOf($this->pane), 'the pane does not move');
    }

    public function testRemountingTheSamePaneKeepsTheSeatAndTheKeys(): void
    {
        $this->mounted();
        $this->tui->setFocus($this->flank);

        $this->split->mount($this->editor, $this->pane);

        $this->assertSame($this->flank, $this->tui->getFocus(), 'a re-seat would detach the focused flank');
        $this->assertSame($this->flank, $this->split->flankPane());
    }

    public function testAPlainPaneAfterAFlankedOneSeatsThePlainSplit(): void
    {
        $this->mounted();
        $plain = new TestPane('md');

        $this->split->mount($this->editor, $plain);
        $this->frame($this->tui);

        $this->assertNull($this->split->flankPane());
        $this->assertNull($this->flank->getContext(), 'the flank handed back');
        $this->assertSame([$this->split('preview')->area], $this->split->area->all());
        $this->assertSame([45, 46], [$this->columnsOf($this->editor), $this->columnsOf($plain)], 'half and half again');
    }

    public function testReleaseHandsEveryWidgetBack(): void
    {
        $this->mounted();

        $this->split->release();

        $this->assertSame([], $this->split->area->all());
        $this->assertNull($this->split->preview());
        $this->assertNull($this->split->flankPane());
        foreach ([$this->editor, $this->flank, $this->pane] as $widget) {
            $this->assertNull($widget->getContext());
        }
    }

    public function testNarrowingGivesUpTheFlankThenThePaneAndKeepsTheEditor(): void
    {
        $this->mounted(columns: 50);
        $this->tui->setFocus($this->flank);
        $this->fit();
        $this->assertNull($this->flank->getContext(), 'too narrow for three: the flank steps aside first');
        $this->assertInstanceOf(WidgetContext::class, $this->pane->getContext(), 'the pane stays beside the editor');
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the editor takes the keys the flank had');

        $this->terminal->simulateResize(30, 10);
        $this->frame($this->tui);
        $this->fit();

        $this->assertNull($this->pane->getContext(), 'then the pane');
        $this->assertInstanceOf(WidgetContext::class, $this->editor->getContext(), 'the editor stays');

        $this->terminal->simulateResize(92, 10);
        $this->frame($this->tui);
        $this->fit();
        $this->fit();
        $this->assertInstanceOf(WidgetContext::class, $this->flank->getContext(), 'room again brings them back');
        $this->assertInstanceOf(WidgetContext::class, $this->pane->getContext());
    }

    public function testThePaneSteppingAsideWithTheKeysHandsThemToTheEditor(): void
    {
        $this->mounted();
        $this->tui->setFocus($this->pane);

        $this->terminal->simulateResize(50, 10);
        $this->frame($this->tui);
        $this->fit();
        $this->terminal->simulateResize(30, 10);
        $this->frame($this->tui);
        $this->fit();

        $this->assertNull($this->pane->getContext());
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the editor, not the container it sits in');
    }

    public function testAMirroredWorkspaceReversesTheColumns(): void
    {
        $this->mounted();
        $this->split->setMirrored(true);
        $this->frame($this->tui);

        $flank = $this->rect($this->flank);
        $pane = $this->rect($this->pane);
        $this->assertInstanceOf(WidgetRect::class, $flank);
        $this->assertInstanceOf(WidgetRect::class, $pane);
        $this->assertGreaterThan($pane->col, $flank->col, 'the flank on the right, the pane on the left');
    }

    public function testANudgeMovesTheDividerBesideWhatHasTheKeys(): void
    {
        $this->mounted();

        $this->assertSame(PreviewSplit::FLANK, $this->split->splitFor($this->flank));
        $this->assertSame(PreviewSplit::FLANKED, $this->split->splitFor($this->editor));
        $this->assertSame(PreviewSplit::FLANKED, $this->split->splitFor($this->pane));
        $this->assertNull($this->split->splitFor(new TestPane('elsewhere')));
        $this->assertNull($this->split->splitFor(null));

        $this->tui->remove($this->split->area);
        $this->assertNull($this->split->splitFor($this->editor), 'off screen, nothing to nudge');
    }

    public function testEveryDividerIsListedForThePointer(): void
    {
        $this->mounted();

        $this->assertSame([$this->split('preview')->separator, $this->split('flanked')->separator, $this->split('flank')->separator], $this->split->separators());
    }
}
