<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DebugState;
use Porta\Event\DebugFrameSelected;
use Porta\Event\DebugVariableEditRequested;
use Porta\Event\DebugWatchRemovalRequested;
use Porta\Tests\Support\Probe;
use Porta\Widget\DebugPanelCallbacks;
use Porta\Widget\DebugPanelWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DebugPanelWidgetTest extends TestCase
{
    private function panel(?EventDispatcher $events = null): DebugPanelWidget
    {
        $panel = new DebugPanelWidget(events: $events);
        $panel->open('a.php');
        $panel->setSessionState(DebugState::Paused, 'src/a.php:5');
        $panel->setFrames([
            ['id' => 1, 'name' => '{main}', 'path' => 'src/a.php', 'line' => 4],
            ['id' => 2, 'name' => 'require', 'path' => 'src/b.php', 'line' => 0],
        ]);
        $panel->setScopes([['name' => 'Locals', 'variablesReference' => 1000]]);
        $panel->setVariables(1000, [
            ['name' => '$x', 'value' => '42', 'variablesReference' => 0],
            ['name' => '$arr', 'value' => 'array(2)', 'variablesReference' => 1001],
        ]);

        return $panel;
    }

    /** @return list<string> */
    private function screen(DebugPanelWidget $panel): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(80, 14)));
    }

    public function testClosedRendersNothingAndStatesLabelTheStatusText(): void
    {
        $panel = new DebugPanelWidget();
        $this->assertSame([], $panel->render(new RenderContext(80, 14)));

        // The panel has no header row of its own — the session state and the
        // step legend ride the dock strip through statusText().
        $panel->open('a.php');
        $panel->handleInput("\r"); // Enter with no rows yet is inert
        $this->assertSame('a.php', $panel->getTitle(), 'the launched file names the dock tab');
        $this->assertSame([], $this->screen($panel), 'no frames yet: only the dock strip shows');
        $this->assertStringContainsString('starting…', $panel->statusText());
        $panel->setSessionState(DebugState::Running, null);
        $this->assertStringContainsString('running…', $panel->statusText());
        $panel->setSessionState(DebugState::Paused, 'src/a.php:5');
        $this->assertStringContainsString('paused at src/a.php:5', $panel->statusText());
        $panel->setSessionState(DebugState::Terminated, null);
        $this->assertStringContainsString('terminated', $panel->statusText());
        $this->assertStringContainsString('F8 over', $panel->statusText(), 'the step legend rides in the status');
    }

    public function testScopeWithoutAVariablesReferenceIsNotAutoExpanded(): void
    {
        $panel = new DebugPanelWidget();
        $panel->open('a.php');
        $panel->setSessionState(DebugState::Paused, 'src/a.php:5');
        // The second scope has no variablesReference (<= 0): it is not marked
        // expanded, but still renders as a (leaf) scope header.
        $panel->setScopes([
            ['name' => 'Locals', 'variablesReference' => 1000],
            ['name' => 'Registers', 'variablesReference' => 0],
        ]);

        $screen = implode("\n", $this->screen($panel));
        $this->assertStringContainsString('Locals', $screen);
        $this->assertStringContainsString('Registers', $screen);
    }

    public function testFramesScopesAndVariablesFlattenIntoRows(): void
    {
        $screen = implode("\n", $this->screen($this->panel()));

        $this->assertStringContainsString('Frames', $screen);
        $this->assertStringContainsString('#0 {main}  src/a.php:5', $screen, 'frame lines are 1-based for display');
        $this->assertStringContainsString('#1 require  src/b.php:1', $screen);
        $this->assertStringContainsString('Variables', $screen);
        $this->assertStringContainsString('▾ Locals', $screen, 'scopes render expanded');
        $this->assertStringContainsString('$x = 42', $screen);
        $this->assertStringContainsString('▸ $arr = array(2)', $screen, 'expandable child shows a collapsed arrow');
    }

    public function testEditKeyOnAVariableFiresWithItsContainerNameAndValue(): void
    {
        $events = new EventDispatcher();
        $panel = $this->panel($events);
        $edited = null;
        $events->addListener(DebugVariableEditRequested::class, static function (DebugVariableEditRequested $event) use (&$edited): void {
            $edited = [$event->parentReference, $event->name, $event->value];
        });

        // Rows: 0 Frames, 1 #0, 2 #1, 3 Variables, 4 Locals, 5 $x — step to $x.
        for ($i = 0; $i < 5; ++$i) {
            $panel->handleInput("\x1b[B");
        }
        $panel->handleInput('e');
        $this->assertSame([1000, '$x', '42'], $edited, 'the edit key hands back parent reference, name and current value');

        // The edit key is inert on a non-variable row (a scope header).
        $edited = null;
        $panel->handleInput("\x1b[A"); // back up → Locals
        $panel->handleInput('e');
        $this->assertNull($edited, 'a scope row cannot be edited');
    }

    public function testEnterOnAFrameJumpsAndOnAVariableExpandsLazily(): void
    {
        $events = new EventDispatcher();
        $panel = $this->panel($events);
        $jumped = null;
        $expanded = [];
        $events->addListener(DebugFrameSelected::class, static function (DebugFrameSelected $event) use (&$jumped): void {
            $jumped = [$event->frameId, $event->path, $event->line];
        });
        $panel->setCallbacks(new DebugPanelCallbacks(
            onExpandVariable: static function (int $ref, callable $deliver) use (&$expanded): void {
                $expanded[] = $ref;
                $deliver([['name' => '0', 'value' => "'a'", 'variablesReference' => 0]]);
            },
        ));

        // Rows: 0 Frames, 1 #0, 2 #1, 3 Variables, 4 Locals, 5 $x, 6 $arr.
        $panel->handleInput("\x1b[B"); // → #0
        $panel->handleInput("\x1b[B"); // → #1
        $panel->handleInput("\x1b[A"); // back up → #0
        $panel->handleInput("\r");
        $this->assertSame([1, 'src/a.php', 4], $jumped, 'Enter on a frame fires the 0-based jump');

        for ($i = 0; $i < 5; ++$i) {
            $panel->handleInput("\x1b[B"); // → $arr
        }
        $panel->handleInput("\r");
        $this->assertSame([1001], $expanded, 'children fetched lazily on first expand');
        $this->assertStringContainsString("0 = 'a'", implode("\n", $this->screen($panel)), 'delivered children render nested');

        $panel->handleInput("\r"); // collapse again — cached, no second fetch
        $this->assertSame([1001], $expanded);
        $this->assertStringNotContainsString("0 = 'a'", implode("\n", $this->screen($panel)));

        $panel->handleInput("\r"); // re-expand uses the cache
        $this->assertSame([1001], $expanded);
        $this->assertStringContainsString("0 = 'a'", implode("\n", $this->screen($panel)));
    }

    public function testHoverTintsTheRowWithoutMovingTheSelection(): void
    {
        $events = new EventDispatcher();
        $panel = $this->panel($events);
        $jumped = null;
        $events->addListener(DebugFrameSelected::class, static function (DebugFrameSelected $event) use (&$jumped): void {
            $jumped = $event->frameId;
        });
        $panel->render(new RenderContext(80, 14));
        $panel->handleInput("\x1b[B"); // ↓ off the 'Frames' section onto the first frame

        // The pointer rests on the second frame; Enter must still act on the first.
        $panel->hoverRow(2);
        $lines = $panel->render(new RenderContext(80, 14));
        $this->assertStringContainsString("\x1b[48;5;236m", $lines[2], 'the hovered row carries the hover tint');

        $panel->handleInput("\r");
        $this->assertSame(1, $jumped, 'Enter jumped to the selected frame, not the hovered one');
    }

    public function testRebuildingTheRowsDropsAStaleHoverTint(): void
    {
        $panel = $this->panel();
        $panel->render(new RenderContext(80, 14));
        $panel->hoverRow(2);

        // A fresh stop moves the rows under a pointer that has not moved.
        $panel->setFrames([['id' => 9, 'name' => 'other', 'path' => 'src/c.php', 'line' => 1]]);
        $lines = $panel->render(new RenderContext(80, 14));

        $this->assertStringNotContainsString("\x1b[48;5;236m", implode("\n", $lines), 'no row stays tinted after the rows are rebuilt');
    }

    public function testMouseClickHoverScrollAndEscape(): void
    {
        $events = new EventDispatcher();
        $panel = $this->panel($events);
        $jumped = null;
        $events->addListener(DebugFrameSelected::class, static function (DebugFrameSelected $event) use (&$jumped): void {
            $jumped = $event->frameId;
        });

        $panel->render(new RenderContext(80, 14));
        $this->assertTrue($panel->hoverRow(2), 'hover tints the row under the pointer (rel 2 → row 2, no header row)');
        $this->assertFalse($panel->hoverRow(2), 'unchanged hover reports false');
        $this->assertTrue($panel->hoverRow(-1), 'above the panel is no row: the tint clears, so the caller repaints');
        $this->assertFalse($panel->hoverRow(-1), 'still no row → no change');
        $this->assertTrue($panel->hoverRow(2), 'back onto a row');
        $this->assertTrue($panel->clearHover(), 'the pointer left the panel');
        $this->assertFalse($panel->clearHover(), 'nothing left to clear');

        $panel->handleClick(1); // rel 1 → row index 1 → frame #0
        $this->assertSame(1, $jumped);
        $panel->handleClick(99); // out of range → no-op
        $this->assertSame(1, $jumped);

        $panel->scrollBy(3);
        $panel->scrollBy(-30); // clamps at the top
        $this->assertNotSame([], $panel->render(new RenderContext(80, 14)));

        $closed = false;
        $panel->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $panel->handleInput("\x1b"); // Escape
        $this->assertTrue($closed);
        $this->assertFalse($panel->isOpen());
        $this->assertSame([], $panel->render(new RenderContext(80, 14)));
    }

    public function testLeavingPauseDropsTheStackAndPanelRowsResize(): void
    {
        $panel = $this->panel();
        $panel->setSessionState(DebugState::Running, null);
        $screen = implode("\n", $this->screen($panel));
        $this->assertStringNotContainsString('Frames', $screen, 'resuming clears the stale stack');

        $panel->setVisibleRows(4);
        $this->assertSame(4, Probe::get($panel, 'visibleRows'));
    }

    public function testWatchesSectionPendingResultErrorAndStaleIndex(): void
    {
        $panel = $this->panel();
        $panel->setWatches(['$x', '$boom']);

        $screen = implode("\n", $this->screen($panel));
        $this->assertStringContainsString('Watches', $screen);
        $this->assertStringContainsString('$x = …', $screen, 'unevaluated watches render pending');

        $panel->setWatchResult(0, '42', 0);
        $panel->setWatchResult(1, '', 0, 'cannot evaluate');
        $panel->setWatchResult(5, 'ghost', 0); // stale index after a removal: ignored
        $screen = implode("\n", $this->screen($panel));
        $this->assertStringContainsString('$x = 42', $screen);
        $this->assertStringContainsString('$boom = cannot evaluate', $screen);
        $this->assertStringNotContainsString('ghost', $screen);

        // Leaving pause resets values to pending but keeps the expressions.
        $panel->setSessionState(DebugState::Running, null);
        $screen = implode("\n", $this->screen($panel));
        $this->assertStringContainsString('$x = …', $screen);
        $this->assertStringNotContainsString('42', $screen);
    }

    public function testWatchRowsExpandThroughRefsAndDeleteFiresRemoval(): void
    {
        $events = new EventDispatcher();
        $panel = $this->panel($events);
        $panel->setWatches(['$arr']);
        $panel->setWatchResult(0, 'array(2)', 2000);
        $expanded = [];
        $removed = [];
        $events->addListener(DebugWatchRemovalRequested::class, static function (DebugWatchRemovalRequested $event) use (&$removed): void {
            $removed[] = $event->index;
        });
        $panel->setCallbacks(new DebugPanelCallbacks(
            onExpandVariable: static function (int $ref, callable $deliver) use (&$expanded): void {
                $expanded[] = $ref;
                $deliver([['name' => '0', 'value' => "'a'", 'variablesReference' => 0]]);
            },
        ));

        // Rows: 0 Frames, 1 #0, 2 #1, 3 Watches, 4 $arr, then Variables…
        for ($i = 0; $i < 4; ++$i) {
            $panel->handleInput("\x1b[B");
        }
        $panel->handleInput("\r"); // expand the watch through its ref
        $this->assertSame([2000], $expanded);
        $this->assertStringContainsString("0 = 'a'", implode("\n", $this->screen($panel)));

        $panel->handleInput("\x1b[3~"); // Delete on the watch row
        $this->assertSame([0], $removed);

        // Delete anywhere else is inert.
        $panel->handleInput("\x1b[A");
        $panel->handleInput("\x1b[3~");
        $this->assertSame([0], $removed);
    }
}
