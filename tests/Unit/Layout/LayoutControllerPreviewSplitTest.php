<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;
use Porta\Layout\LayoutController;
use Porta\Layout\PaneResizer;
use Porta\Tests\Support\BuildsLayoutController;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The preview split's divider through the layout controller: armed only on
 * its own column, dragged, persisted as ONE share for every previewed file,
 * nudged from the keyboard, and restored in the next session.
 */
final class LayoutControllerPreviewSplitTest extends TestCase
{
    use BuildsLayoutController;

    private CodeEditorWidget $editor;

    /** The controller, with the split on screen across the whole 101-column terminal. */
    private function onScreen(): LayoutController
    {
        $layout = $this->controller(columns: 101);
        $this->editor = new CodeEditorWidget();
        $this->previewSplit->mount($this->editor, new TestPane('md'));
        $this->tui->add($this->previewSplit->area);
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

    private function editorColumns(): int
    {
        $rect = $this->renderer->getWidgetRect($this->editor);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect->columns;
    }

    public function testOnlyThePressOnTheDividerArmsIt(): void
    {
        $layout = $this->onScreen();

        $this->assertFalse($layout->armPreviewResize(self::mouse("\x1b[<0;50;3M")), 'column 49 is the editor');
        $this->assertFalse($layout->continueResizeDrag(self::mouse("\x1b[<32;30;3M"), null), 'nothing armed, nothing dragged');
        $this->assertTrue($layout->armPreviewResize(self::mouse("\x1b[<0;51;3M")), 'column 50 is the divider');
    }

    public function testADragMovesTheDividerAndTheReleasePersistsTheShare(): void
    {
        $layout = $this->onScreen();
        $layout->armPreviewResize(self::mouse("\x1b[<0;51;3M"));

        $this->assertTrue($layout->continueResizeDrag(self::mouse("\x1b[<32;31;3M"), null));
        $this->frame();
        $this->assertSame(30, $this->editorColumns());
        $this->assertSame([], $this->layoutFile(), 'nothing written mid-drag');

        $this->assertTrue($layout->continueResizeDrag(self::mouse("\x1b[<0;31;3m"), null));
        $this->assertSame(['previewSplitPercent' => 30], $this->layoutFile());
        $this->assertFalse($layout->continueResizeDrag(self::mouse("\x1b[<32;60;3M"), null), 'the release disarmed it');
    }

    public function testAnArmedDragTakesOnlyItsOwnMotionAndRelease(): void
    {
        $layout = $this->onScreen();
        $layout->armPreviewResize(self::mouse("\x1b[<0;51;3M"));

        $this->assertFalse($layout->continueResizeDrag(self::mouse("\x1b[<0;20;3M"), null), 'a fresh press is not the drag\'s');
        $this->assertSame(50, $this->previewSplit->percent());
    }

    public function testAPressThatNeverMovedWritesNothing(): void
    {
        $layout = $this->onScreen();
        $layout->armPreviewResize(self::mouse("\x1b[<0;51;3M"));
        $layout->continueResizeDrag(self::mouse("\x1b[<0;51;3m"), null);

        $this->assertSame([], $this->layoutFile());
    }

    public function testAFreshPressCancelsAnArmedDrag(): void
    {
        $layout = $this->onScreen();
        $layout->armPreviewResize(self::mouse("\x1b[<0;51;3M"));

        $layout->cancelDrags();

        $this->assertFalse($layout->continueResizeDrag(self::mouse("\x1b[<32;31;3M"), null));
    }

    public function testTheKeyboardNudgeMovesAndPersistsTooButNotWithTheSplitOffScreen(): void
    {
        $layout = $this->onScreen();

        $layout->nudgePreviewSplit(10);
        $this->frame();
        $this->assertSame(60, $this->editorColumns());
        $this->assertSame(['previewSplitPercent' => 60], $this->layoutFile());

        $this->tui->remove($this->previewSplit->area);
        $layout->nudgePreviewSplit(10);
        $this->assertSame(60, $this->previewSplit->percent(), 'no divider on screen to move');
    }

    public function testTheNextSessionRestoresTheShareAndKeepsItBesideTheOtherSizes(): void
    {
        $layout = $this->onScreen();
        $layout->nudgePreviewSplit(-20);
        $layout->nudgeSidebar(4);

        $written = (string) \file_get_contents($this->project->dir.'/.porta/layout.json');

        $next = $this->onScreen(); // a new controller and split, handed what the last one wrote
        \mkdir($this->project->dir.'/.porta');
        \file_put_contents($this->project->dir.'/.porta/layout.json', $written);
        $next->applyLayout();
        $this->frame();

        $this->assertSame(30, $this->previewSplit->percent());
        $this->assertSame(30, $this->editorColumns());
        $this->assertSame(['sidebarWidthPercent', 'previewSplitPercent'], \array_keys($this->layoutFile()), 'one file, every proportion in it');
    }

    public function testShiftF11AndF12NudgeTheSplitFromEitherHalfAndOnlyWhileItIsOnScreen(): void
    {
        $layout = $this->onScreen();
        $pane = $this->previewSplit->preview();
        $this->assertInstanceOf(AbstractWidget::class, $pane);
        $resizer = new PaneResizer(
            $this->tui,
            $layout,
            $this->tree,
            TestBottomDock::create($this->tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            $this->regions,
            $this->previewSplit,
        );

        $this->tui->setFocus($this->editor);
        $resizer->resize(1);
        $this->assertSame(52, $this->previewSplit->percent(), 'two columns a press, from the editor');
        $this->tui->setFocus($pane);
        $resizer->resize(-1);
        $this->assertSame(50, $this->previewSplit->percent(), 'and from the preview');

        $elsewhere = new TestPane('elsewhere');
        $this->tui->add($elsewhere);
        $this->tui->setFocus($elsewhere);
        $this->assertFalse($resizer->canResize(), 'the split on screen, the keys elsewhere: not the split\'s chord');

        $this->tui->remove($this->previewSplit->area);
        $this->tui->add($this->editor); // the text alone, where the chord is the editor's own
        $this->tui->setFocus($this->editor);
        $this->assertFalse($resizer->canResize(), 'no split on screen: the chord falls through');
    }
}
