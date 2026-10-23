<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;
use Porta\Layout\LayoutController;
use Porta\Layout\PreviewSplit;
use Porta\Tests\Support\BuildsLayoutController;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * A flanked preview's two dividers through the layout controller — flank |
 * editor | pane in 92 columns: each armed on its own column, dragged and
 * persisted under its own key, nudged from the side that has the keys, and
 * restored in the next session apart from the plain split's share.
 */
final class LayoutControllerPreviewFlankTest extends TestCase
{
    use ScreenReads;

    use BuildsLayoutController;

    private CodeEditorWidget $editor;
    private TestPane $flank;
    private TestPane $pane;

    private function onScreen(): LayoutController
    {
        $layout = $this->controller(columns: 92);
        $this->editor = new CodeEditorWidget();
        $this->flank = new TestPane('ours');
        $this->pane = new TestPane('theirs', $this->flank);
        $this->previewSplit->mount($this->editor, $this->pane);
        $this->tui->add($this->previewSplit->area);
        $this->frame($this->tui);

        return $layout;
    }

    private static function mouse(string $sequence): MouseEvent
    {
        $event = MouseEvent::parse($sequence);
        self::assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    private function columnsOf(object $widget): int
    {
        $rect = $this->renderer->getWidgetRect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect->columns;
    }

    /** A press at 0-based column $col, row 2. */
    private static function press(int $col): MouseEvent
    {
        return self::mouse("\x1b[<0;".($col + 1).';3M');
    }

    /** A width a share sets: the layout floors it, so within a column of the pointer's. */
    private function assertAbout(int $expected, int $actual, string $message = ''): void
    {
        $this->assertLessThanOrEqual(1, \abs($expected - $actual), $message.' — '.$actual.' columns');
    }

    private function dividerCol(string $key): int
    {
        $rect = $this->renderer->getWidgetRect($this->previewSplit->splits()[$key]->separator);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect->col;
    }

    public function testTheFlanksDividerDragsTheFlankAndPersistsUnderItsOwnKey(): void
    {
        $layout = $this->onScreen();
        $this->assertFalse($layout->armPreviewResize(self::press($this->dividerCol(PreviewSplit::FLANK) - 1)), 'the flank\'s last column');
        $this->assertTrue($layout->armPreviewResize(self::press($this->dividerCol(PreviewSplit::FLANK))));

        $layout->continueResizeDrag(self::mouse("\x1b[<32;21;3M"), null);
        $layout->continueResizeDrag(self::mouse("\x1b[<0;21;3m"), null);
        $this->frame($this->tui);

        $this->assertAbout(20, $this->columnsOf($this->flank));
        $this->assertSame(['flankSplitPercent'], \array_keys($this->layoutFile()), 'its own key alone');
    }

    public function testThePanesDividerDragsTheOuterSplit(): void
    {
        $layout = $this->onScreen();
        $this->assertTrue($layout->armPreviewResize(self::press($this->dividerCol(PreviewSplit::FLANKED))));

        $layout->continueResizeDrag(self::mouse("\x1b[<32;71;3M"), null);
        $layout->continueResizeDrag(self::mouse("\x1b[<0;71;3m"), null);
        $this->frame($this->tui);

        $this->assertAbout(21, $this->columnsOf($this->pane));
        $this->assertSame(['flankedSplitPercent'], \array_keys($this->layoutFile()), 'its own key alone');
    }

    public function testANudgeMovesTheDividerBesideWhatHasTheKeys(): void
    {
        $layout = $this->onScreen();
        $flank = $this->columnsOf($this->flank);
        $pane = $this->columnsOf($this->pane);

        $this->tui->setFocus($this->flank);
        $layout->nudgePreviewSplit(4);
        $this->frame($this->tui);
        $this->assertAbout($flank + 4, $this->columnsOf($this->flank), 'from the flank, the flank\'s divider');
        $this->assertSame($pane, $this->columnsOf($this->pane));

        $this->tui->setFocus($this->pane);
        $layout->nudgePreviewSplit(4);
        $this->frame($this->tui);
        $this->assertAbout($pane - 4, $this->columnsOf($this->pane), 'from the pane, the pane\'s');
        $this->assertSame(['flankedSplitPercent', 'flankSplitPercent'], \array_keys($this->layoutFile()), 'each under its own key');
    }

    public function testTheNextSessionRestoresBothSharesAndLeavesThePlainOne(): void
    {
        $layout = $this->onScreen();
        $this->tui->setFocus($this->flank);
        $layout->nudgePreviewSplit(-6);
        $this->tui->setFocus($this->editor);
        $layout->nudgePreviewSplit(-8);
        $this->frame($this->tui);
        $written = (string) \file_get_contents($this->project->dir.'/.porta/layout.json');
        $flank = $this->columnsOf($this->flank);
        $pane = $this->columnsOf($this->pane);

        $next = $this->onScreen();
        \mkdir($this->project->dir.'/.porta');
        \file_put_contents($this->project->dir.'/.porta/layout.json', $written);
        $next->applyLayout();
        $this->frame($this->tui);

        $this->assertSame([$flank, $pane], [$this->columnsOf($this->flank), $this->columnsOf($this->pane)]);
        $this->assertSame(50, $this->previewSplit->percent(), 'the plain split keeps its own');
    }
}
