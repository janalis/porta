<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsFormWidgetTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['key' => 'inlineDiagnostics', 'label' => 'Inline diagnostics', 'kind' => 'bool', 'value' => true, 'restart' => false],
            ['key' => 'relativeLineNumbers', 'label' => 'Relative line numbers', 'kind' => 'bool', 'value' => false, 'restart' => false],
            ['key' => 'editorConfig', 'label' => 'Respect .editorconfig', 'kind' => 'bool', 'value' => true, 'restart' => true],
            ['key' => 'theme', 'label' => 'Theme', 'kind' => 'choice', 'value' => 'dark', 'restart' => true, 'options' => ['dark', 'light']],
            ['key' => 'tabWidth', 'label' => 'Tab width', 'kind' => 'int', 'value' => 4, 'restart' => true, 'min' => 1, 'max' => 8],
        ];
    }

    private function widget(?array &$changes = null): SettingsFormWidget
    {
        $changes = [];
        $widget = new SettingsFormWidget();
        $widget->setFocused(true);
        $widget->setRows($this->rows());
        $widget->onChange(function (string $key, bool|int|string $value) use (&$changes): void {
            $changes[] = [$key, $value];
        });

        return $widget;
    }

    private function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testRendersCheckboxesSteppersAndRestartNote(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(60, 8)));

        $this->assertStringContainsString('[x] Inline diagnostics', $screen);
        $this->assertStringContainsString('[ ] Relative line numbers', $screen);
        $this->assertStringContainsString('Theme  < dark >', $screen);
        $this->assertStringContainsString('Tab width  < 4 >', $screen);
        $this->assertStringContainsString('· applies on restart', $screen, 'restart rows are annotated');
    }

    public function testFocusedSelectionIsHighlightedAndMovesWithArrows(): void
    {
        $widget = $this->widget();
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringContainsString("\x1b[48;5;31m", $rendered[0], 'row 0 selected + focused');

        $widget->handleInput("\x1b[B"); // down
        $this->assertSame(1, $widget->selectedIndex());
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringNotContainsString("\x1b[48;5;31m", $rendered[0]);
        $this->assertStringContainsString("\x1b[48;5;31m", $rendered[1], 'the highlight followed');

        $widget->handleInput("\x1b[A"); // up
        $widget->handleInput("\x1b[A"); // clamps at the top
        $this->assertSame(0, $widget->selectedIndex());
    }

    public function testUnfocusedSelectionUsesADimmerBar(): void
    {
        $widget = $this->widget();
        $widget->setFocused(false);
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringContainsString("\x1b[48;5;237m", $rendered[0], 'a resting selection bar, not the focused blue');
    }

    public function testSpaceTogglesABoolean(): void
    {
        $widget = $this->widget($changes);
        $widget->handleInput(' ');
        $this->assertSame([['inlineDiagnostics', false]], $changes);
    }

    public function testChoiceCyclesForwardWithWrapAndBackward(): void
    {
        $widget = $this->widget($changes);
        // Select the theme row.
        for ($i = 0; $i < 3; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $widget->handleInput("\x1b[C"); // right → next
        $this->assertSame(['theme', 'light'], $changes[0]);

        // From the last option, forward wraps to the first.
        $widget->setRows([['key' => 'theme', 'label' => 'Theme', 'kind' => 'choice', 'value' => 'light', 'restart' => true, 'options' => ['dark', 'light']]]);
        $widget->handleInput(']');
        $this->assertSame(['theme', 'dark'], $changes[1], ']= forward wraps');

        // Backward from the first wraps to the last.
        $widget->setRows([['key' => 'theme', 'label' => 'Theme', 'kind' => 'choice', 'value' => 'dark', 'restart' => true, 'options' => ['dark', 'light']]]);
        $widget->handleInput("\x1b[D"); // left → prev
        $this->assertSame(['theme', 'light'], $changes[2]);
    }

    public function testIntStepsAndClampsAtBounds(): void
    {
        $widget = $this->widget($changes);
        for ($i = 0; $i < 4; ++$i) {
            $widget->handleInput("\x1b[B"); // select tabWidth
        }
        $widget->handleInput(' '); // +1 → 5
        $this->assertSame(['tabWidth', 5], $changes[0]);

        $widget->setRows([['key' => 'tabWidth', 'label' => 'Tab width', 'kind' => 'int', 'value' => 8, 'restart' => true, 'min' => 1, 'max' => 8]]);
        $widget->handleInput(']');
        $this->assertSame(['tabWidth', 8], $changes[1], 'clamped at max');

        $widget->setRows([['key' => 'tabWidth', 'label' => 'Tab width', 'kind' => 'int', 'value' => 1, 'restart' => true, 'min' => 1, 'max' => 8]]);
        $widget->handleInput('['); // -1
        $this->assertSame(['tabWidth', 1], $changes[2], 'clamped at min');
    }

    public function testIntWithoutExplicitBoundsUsesSaneDefaults(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([['key' => 'tabWidth', 'label' => 'Tab width', 'kind' => 'int', 'value' => 1, 'restart' => false]]);
        $widget->handleInput('['); // default min is 1 → stays
        $this->assertSame(['tabWidth', 1], $changes[0]);
    }

    public function testChoiceWithNoOptionsOrUnknownValueDegradesGracefully(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([['key' => 'theme', 'label' => 'Theme', 'kind' => 'choice', 'value' => 'dark', 'restart' => false, 'options' => []]]);
        $widget->handleInput(' ');
        $this->assertSame(['theme', 'dark'], $changes[0], 'empty options keep the value');

        $widget->setRows([['key' => 'theme', 'label' => 'Theme', 'kind' => 'choice', 'value' => 'sepia', 'restart' => false, 'options' => ['dark', 'light']]]);
        $widget->handleInput(' '); // unknown value → index 0 + 1
        $this->assertSame(['theme', 'light'], $changes[1]);
    }

    public function testTabSwitchExitAndKeybindingsCallbacks(): void
    {
        $fired = [];
        $widget = $this->widget();
        $widget->onShowKeybindings(function () use (&$fired): void { $fired[] = 'kb'; });
        $widget->onFocusNext(function () use (&$fired): void { $fired[] = 'next'; });
        $widget->onExit(function () use (&$fired): void { $fired[] = 'exit'; });

        $widget->handleInput('2');
        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $this->assertSame(['kb', 'next', 'exit'], $fired);
    }

    public function testClickSelectsAndActuatesButIgnoresDeadRows(): void
    {
        $widget = $this->widget($changes);
        $widget->handleClick(1); // Relative line numbers row
        $this->assertSame(1, $widget->selectedIndex());
        $this->assertSame([['relativeLineNumbers', true]], $changes);

        $widget->handleClick(99); // past the last row
        $this->assertCount(1, $changes, 'a click in dead space does nothing');
    }

    public function testActuateWithoutAChangeHandlerIsSafe(): void
    {
        $widget = new SettingsFormWidget();
        $widget->setRows($this->rows());
        $widget->handleInput(' '); // no onChange wired
        $this->expectNotToPerformAssertions();
    }

    public function testCallbacksAreOptional(): void
    {
        $widget = new SettingsFormWidget();
        $widget->setRows($this->rows());
        $widget->handleInput('2');    // no onShowKeybindings
        $widget->handleInput("\t");   // no onFocusNext
        $widget->handleInput("\x1b"); // no onExit
        $this->expectNotToPerformAssertions();
    }

    public function testSetRowsClampsAnOutOfRangeSelection(): void
    {
        $widget = $this->widget();
        for ($i = 0; $i < 4; ++$i) {
            $widget->handleInput("\x1b[B"); // selected = 4
        }
        $widget->setRows([['key' => 'inlineDiagnostics', 'label' => 'Inline diagnostics', 'kind' => 'bool', 'value' => true, 'restart' => false]]);
        $this->assertSame(0, $widget->selectedIndex());
    }

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget($changes);
        $widget->onInput(static fn (string $data): bool => true);
        $widget->handleInput(' '); // swallowed by the hook, no toggle
        $this->assertSame([], $changes);
    }

    public function testAlwaysFillsItsPane(): void
    {
        $widget = new SettingsFormWidget();
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
    }

    public function testOverlayComposites(): void
    {
        $widget = $this->widget();
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $line = $widget->render(new RenderContext(60, 8))[0];
        $this->assertStringContainsString('DROPDOWN', $line);
    }
}
