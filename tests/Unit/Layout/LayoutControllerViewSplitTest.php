<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneResizer;
use Porta\Layout\PaneSplit;
use Porta\Tests\Support\BuildsLayoutController;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * A view's own split through the layout controller: armed on its divider
 * only while that view is the one asked, dragged, persisted per view and
 * split, nudged from either column, and restored when its window next fits.
 */
final class LayoutControllerViewSplitTest extends TestCase
{
    use BuildsLayoutController;

    private TestPane $lead;

    private TestPane $rest;

    private PaneSplit $split;

    /** The git region's 'history' split on screen across the whole 101-column terminal. */
    private function onScreen(): LayoutController
    {
        $layout = $this->controller(columns: 101);
        $this->split = $this->regions->mint('git')->split('history', minSide: 20);
        $this->split->mount($this->lead = new TestPane('files'), $this->rest = new TestPane('diff'));
        $this->tui->add($this->split->area);
        $this->frame();

        return $layout;
    }

    private function frame(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private static function mouse(string $sequence): MouseEvent
    {
        $event = MouseEvent::parse($sequence);
        self::assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    private function leadColumns(): int
    {
        $rect = $this->renderer->getWidgetRect($this->lead);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect->columns;
    }

    public function testADragOnTheDividerMovesItAndTheReleasePersistsItUnderTheViewAndSplit(): void
    {
        $layout = $this->onScreen();

        $this->assertFalse($layout->armViewSplitResize('git', self::mouse("\x1b[<0;50;3M")), 'column 49 is the lead');
        $this->assertFalse($layout->armViewSplitResize('settings', self::mouse("\x1b[<0;51;3M")), 'another view has no such split');
        $this->assertTrue($layout->armViewSplitResize('git', self::mouse("\x1b[<0;51;3M")));
        $this->assertTrue($layout->continueResizeDrag(self::mouse("\x1b[<32;31;3M"), null));
        $this->frame();
        $this->assertSame(30, $this->leadColumns());
        $this->assertSame([], $this->layoutFile(), 'nothing written mid-drag');

        $this->assertTrue($layout->continueResizeDrag(self::mouse("\x1b[<0;31;3m"), null));
        $this->assertSame(['viewSplitPercent' => ['git:history' => 30]], $this->layoutFile());
        $this->assertFalse($layout->continueResizeDrag(self::mouse("\x1b[<32;60;3M"), null), 'the release disarmed it');
    }

    public function testAPressThatNeverMovedWritesNothingAndAFreshPressCancels(): void
    {
        $layout = $this->onScreen();
        $layout->armViewSplitResize('git', self::mouse("\x1b[<0;51;3M"));
        $layout->continueResizeDrag(self::mouse("\x1b[<0;51;3m"), null);
        $this->assertSame([], $this->layoutFile());

        $layout->armViewSplitResize('git', self::mouse("\x1b[<0;51;3M"));
        $layout->cancelDrags();
        $this->assertFalse($layout->continueResizeDrag(self::mouse("\x1b[<32;31;3M"), null));
        $this->assertSame(50, $this->split->percent());
    }

    public function testADragWhoseSplitLeftTheScreenMovesNothingButEnds(): void
    {
        $layout = $this->onScreen();
        $layout->armViewSplitResize('git', self::mouse("\x1b[<0;51;3M"));
        $this->tui->remove($this->split->area);

        $this->assertTrue($layout->continueResizeDrag(self::mouse("\x1b[<32;31;3M"), null), 'still the drag\'s motion');
        $this->assertTrue($layout->continueResizeDrag(self::mouse("\x1b[<0;31;3m"), null));
        $this->assertSame(50, $this->split->percent());
        $this->assertSame([], $this->layoutFile());
    }

    public function testTheNudgeMovesAndPersistsFromEitherColumnOnlyWhileOnScreen(): void
    {
        $layout = $this->onScreen();
        $resizer = new PaneResizer(
            $this->tui,
            $layout,
            $this->tree,
            new CodeEditorWidget(),
            TestBottomDock::create($this->tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $this->regions,
            $this->previewSplit,
        );

        $this->tui->setFocus($this->lead);
        $resizer->resize(5);
        $this->frame();
        $this->assertSame(60, $this->leadColumns(), 'two columns a press, times five');
        $this->tui->setFocus($this->rest);
        $resizer->resize(-1);
        $this->assertSame(['viewSplitPercent' => ['git:history' => 58]], $this->layoutFile());

        $this->tui->remove($this->split->area);
        $this->assertFalse($resizer->canResize(), 'off screen, the chord is not the split\'s');
        $layout->nudgeViewSplit('git', 'history', 10);
        $layout->nudgeViewSplit('git', 'nowhere', 10);
        $this->assertSame(58, $this->split->percent());
    }

    public function testTheNextSessionRestoresTheShareOnLoadAndForASplitMintedAfterIt(): void
    {
        $layout = $this->onScreen();
        $layout->nudgeViewSplit('git', 'history', -20);
        $written = (string) \file_get_contents($this->project->dir.'/.porta/layout.json');

        $next = $this->onScreen();
        \mkdir($this->project->dir.'/.porta');
        \file_put_contents($this->project->dir.'/.porta/layout.json', $written);
        $next->applyLayout();
        $this->assertSame(30, $this->split->percent(), 'a split already minted takes it on load');

        $later = $this->regions->mint('settings')->split('history');
        $next->fitViewSidebar('settings', 100);
        $this->assertSame(50, $later->percent(), 'another view\'s split keeps its own default');
        $this->regions->mint('git')->split('history')->setPercent(70);
        $next->fitViewSidebar('git', 100);
        $this->assertSame(30, $this->split->percent(), 'and its window\'s next fit puts it back');
    }
}
