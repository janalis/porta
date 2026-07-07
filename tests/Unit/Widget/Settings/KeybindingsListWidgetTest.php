<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Settings\KeybindingsListWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class KeybindingsListWidgetTest extends TestCase
{
    /** @return list<array<string, string>> */
    private function rows(): array
    {
        return [
            ['action' => 'save', 'label' => 'Save', 'binding' => 'ctrl+s'],
            ['action' => 'duplicate_line', 'label' => 'Duplicate Line', 'binding' => 'ctrl+d'],
            ['action' => 'quit', 'label' => 'Quit', 'binding' => ''],
        ];
    }

    private function widget(?array &$rebinds = null): KeybindingsListWidget
    {
        $rebinds = [];
        $widget = new KeybindingsListWidget();
        $widget->setFocused(true);
        $widget->setRows($this->rows());
        $widget->onRebind(function (string $action, string $key) use (&$rebinds): void {
            $rebinds[] = [$action, $key];
        });

        return $widget;
    }

    private function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testRendersLabelsFormattedShortcutsAndUnbound(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(60, 8)));

        $this->assertStringContainsString('3 actions', $screen);
        $this->assertStringContainsString('Save', $screen);
        $this->assertStringContainsString('Ctrl+S', $screen, 'binding formatted for display');
        $this->assertStringContainsString('Ctrl+D', $screen);
        $this->assertStringContainsString('—', $screen, 'an unbound action shows a dash');
    }

    public function testNavigationMovesSelection(): void
    {
        $widget = $this->widget();
        $this->assertSame('save', $widget->selectedAction());
        $widget->handleInput("\x1b[B"); // down
        $this->assertSame('duplicate_line', $widget->selectedAction());
        $widget->handleInput("\x1b[A"); // up
        $this->assertSame('save', $widget->selectedAction());
    }

    public function testEnterArmsCaptureAndTheNextKeyRebinds(): void
    {
        $widget = $this->widget($rebinds);
        $widget->handleInput("\x1b[B"); // select duplicate_line
        $widget->handleInput("\r");     // Enter arms capture
        $this->assertTrue($widget->isCapturing());
        $this->assertStringContainsString('Press a key', $this->plain($widget->render(new RenderContext(60, 8))));

        $widget->handleInput("\x04"); // ctrl+d pressed → captured raw
        $this->assertFalse($widget->isCapturing());
        $this->assertSame([['duplicate_line', "\x04"]], $rebinds);
    }

    public function testEscapeWhileCapturingCancelsWithoutLeaving(): void
    {
        $exited = false;
        $widget = $this->widget($rebinds);
        $widget->onExit(function () use (&$exited): void { $exited = true; });

        $widget->handleInput("\r");    // arm
        $widget->handleInput("\x1b");  // Esc cancels capture
        $this->assertFalse($widget->isCapturing());
        $this->assertSame([], $rebinds, 'nothing rebound');
        $this->assertFalse($exited, 'Esc during capture does not leave the view');
    }

    public function testEscapeWhenIdleLeavesTheView(): void
    {
        $exited = false;
        $widget = $this->widget();
        $widget->onExit(function () use (&$exited): void { $exited = true; });
        $widget->handleInput("\x1b");
        $this->assertTrue($exited);
    }

    public function testTabAndTabSwitchCallbacks(): void
    {
        $fired = [];
        $widget = $this->widget();
        $widget->onShowSettings(function () use (&$fired): void { $fired[] = 'settings'; });
        $widget->onFocusNext(function () use (&$fired): void { $fired[] = 'next'; });
        $widget->handleInput('1');
        $widget->handleInput("\t");
        $this->assertSame(['settings', 'next'], $fired);
    }

    public function testClickSelectsThenArmsCaptureAndIgnoresDeadRows(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(60, 8)); // establish scroll

        $widget->handleClick(2); // row 0 is header; render row 2 → index 1
        $this->assertSame('duplicate_line', $widget->selectedAction());
        $this->assertFalse($widget->isCapturing(), 'first click only selects');

        $widget->handleClick(2); // click the selected row again arms capture
        $this->assertTrue($widget->isCapturing());

        // A click while capturing selects (does not re-arm on a different row).
        $widget->handleClick(1);
        $this->assertSame('save', $widget->selectedAction());

        $widget->handleClick(99); // dead space
        $this->assertSame('save', $widget->selectedAction());
    }

    public function testHoverTintAndClear(): void
    {
        $widget = $this->widget();
        $widget->setFocused(false);
        $this->assertTrue($widget->hoverRow(2));
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2], 'hovered row tinted');
        $this->assertFalse($widget->hoverRow(2), 'same row: no change');
        $this->assertTrue($widget->clearHover());
    }

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget();
        $widget->onInput(static fn (string $data): bool => true);
        $widget->handleInput("\r"); // swallowed: no capture armed
        $this->assertFalse($widget->isCapturing());
    }

    public function testArmingIsANoOpWithNoRows(): void
    {
        $widget = new KeybindingsListWidget();
        $widget->setRows([]);
        $widget->handleInput("\r");
        $this->assertFalse($widget->isCapturing());
        $this->assertNull($widget->selectedAction());
    }

    public function testCallbacksAreOptional(): void
    {
        $widget = new KeybindingsListWidget();
        $widget->setRows($this->rows());
        $widget->handleInput('1');    // no onShowSettings
        $widget->handleInput("\t");   // no onFocusNext
        $widget->handleInput("\x1b"); // no onExit
        $widget->handleInput("\r");   // arm (no onRebind)
        $widget->handleInput('a');    // capture with no onRebind is safe
        $this->assertFalse($widget->isCapturing());
    }

    public function testSetRowsDropsCaptureAndHover(): void
    {
        $widget = $this->widget();
        $widget->hoverRow(2);
        $widget->handleInput("\r");
        $this->assertTrue($widget->isCapturing());
        $widget->setRows($this->rows());
        $this->assertFalse($widget->isCapturing());
    }

    public function testScrollFollowsSelectionInAShortViewport(): void
    {
        $widget = new KeybindingsListWidget();
        $rows = [];
        for ($i = 0; $i < 20; ++$i) {
            $rows[] = ['action' => "a$i", 'label' => "Action $i", 'binding' => ''];
        }
        $widget->setFocused(true);
        $widget->setRows($rows);
        for ($i = 0; $i < 12; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $this->assertSame('a12', $widget->selectedAction());
        $this->assertStringContainsString('Action 12', $this->plain($widget->render(new RenderContext(60, 6))));
    }

    public function testAlwaysFillsItsPaneAndOverlayComposites(): void
    {
        $widget = $this->widget();
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $this->assertStringContainsString('DROPDOWN', $widget->render(new RenderContext(60, 8))[0]);
    }
}
