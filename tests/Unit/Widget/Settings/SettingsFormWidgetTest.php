<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\AttachedStyles;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsFormWidgetTest extends TestCase
{
    /** @return list<SettingsRow> */
    private function rows(): array
    {
        return [
            new SettingsRow(key: 'inlineDiagnostics', label: 'Inline diagnostics', kind: SettingsRowKind::Bool, value: true, restart: false),
            new SettingsRow(key: 'relativeLineNumbers', label: 'Relative line numbers', kind: SettingsRowKind::Bool, value: false, restart: false),
            new SettingsRow(key: 'editorConfig', label: 'Respect .editorconfig', kind: SettingsRowKind::Bool, value: true, restart: true),
            new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: true, options: ['dark', 'light']),
            new SettingsRow(key: 'tabWidth', label: 'Tab width', kind: SettingsRowKind::Int, value: 4, restart: true, min: 1, max: 8),
        ];
    }

    private function widget(?array &$changes = null): SettingsFormWidget
    {
        $changes = [];
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows($this->rows());
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): void {
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

    public function testActionRowRendersABracketedButtonAndActuatesForwardOnly(): void
    {
        $changes = [];
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows([
            new SettingsRow(key: 'pluginRemove:project:demo', label: 'demo (project)', kind: SettingsRowKind::Action, value: 'Remove', restart: false),
        ]);

        $screen = $this->plain($widget->render(new RenderContext(60, 4)));
        $this->assertStringContainsString('demo (project)  [Remove]', $screen);

        $widget->handleInput("\x1b[D"); // ← is a no-op on a button, even without onChange
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): void {
            $changes[] = [$key, $value];
        });
        $widget->handleInput("\x1b[D");
        $this->assertSame([], $changes, 'a button has no value to step back');

        $widget->handleInput("\r"); // Enter actuates
        $widget->handleClick(0); // so does a click
        $this->assertSame(
            [
                ['pluginRemove:project:demo', 'Remove'],
                ['pluginRemove:project:demo', 'Remove'],
            ],
            $changes,
        );
    }

    /** The armed half of a two-press destructive confirm wears the danger shade. */
    public function testAnArmedDangerRowWearsTheDangerShade(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        $row = static fn (bool $danger) => [
            new SettingsRow(key: 'pluginRemove:project:demo', label: 'demo (project)', kind: SettingsRowKind::Action, value: 'Remove', restart: false, danger: $danger),
        ];

        $widget->setRows($row(false));
        $calm = $widget->render(new RenderContext(60, 2))[0];
        $widget->setRows($row(true));
        $armed = $widget->render(new RenderContext(60, 2))[0];

        $this->assertNotSame($calm, $armed, 'arming restyles the row');
        $this->assertSame($this->plain([$calm]), $this->plain([$armed]), 'same text, different shade');
    }

    public function testChoiceRowRendersOptionLabelInsteadOfValue(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows([
            new SettingsRow(key: 'language', label: 'Language', kind: SettingsRowKind::Choice, value: 'fr', restart: false, options: Translator::LOCALES, optionLabels: Translator::LOCALE_NAMES),
        ]);

        $screen = $this->plain($widget->render(new RenderContext(60, 8)));

        $this->assertStringContainsString('Language  < Français >', $screen, 'shows the language name');
        $this->assertStringNotContainsString('< fr >', $screen, 'not the language code');
    }

    public function testCyclingALabelledChoiceStillEmitsTheCodeValue(): void
    {
        $changes = [];
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows([
            new SettingsRow(key: 'language', label: 'Language', kind: SettingsRowKind::Choice, value: 'en', restart: false, options: Translator::LOCALES, optionLabels: Translator::LOCALE_NAMES),
        ]);
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): void {
            $changes[] = [$key, $value];
        });

        $widget->handleInput("\x1b[C"); // right → next

        $this->assertSame([['language', 'fr']], $changes, 'persists the code, not the display name');
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
        $widget->setRows([new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'light', restart: true, options: ['dark', 'light'])]);
        $widget->handleInput(']');
        $this->assertSame(['theme', 'dark'], $changes[1], ']= forward wraps');

        // Backward from the first wraps to the last.
        $widget->setRows([new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: true, options: ['dark', 'light'])]);
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

        $widget->setRows([new SettingsRow(key: 'tabWidth', label: 'Tab width', kind: SettingsRowKind::Int, value: 8, restart: true, min: 1, max: 8)]);
        $widget->handleInput(']');
        $this->assertSame(['tabWidth', 8], $changes[1], 'clamped at max');

        $widget->setRows([new SettingsRow(key: 'tabWidth', label: 'Tab width', kind: SettingsRowKind::Int, value: 1, restart: true, min: 1, max: 8)]);
        $widget->handleInput('['); // -1
        $this->assertSame(['tabWidth', 1], $changes[2], 'clamped at min');
    }

    public function testIntWithoutExplicitBoundsUsesSaneDefaults(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'tabWidth', label: 'Tab width', kind: SettingsRowKind::Int, value: 1, restart: false)]);
        $widget->handleInput('['); // default min is 1 → stays
        $this->assertSame(['tabWidth', 1], $changes[0]);
    }

    public function testChoiceWithNoOptionsOrUnknownValueDegradesGracefully(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: [])]);
        $widget->handleInput(' ');
        $this->assertSame(['theme', 'dark'], $changes[0], 'empty options keep the value');

        $widget->setRows([new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'sepia', restart: false, options: ['dark', 'light'])]);
        $widget->handleInput(' '); // unknown value → index 0 + 1
        $this->assertSame(['theme', 'light'], $changes[1]);
    }

    public function testTabSwitchExitAndKeybindingsCallbacks(): void
    {
        $fired = [];
        $widget = $this->widget();
        $widget->onShowKeybindings(static function () use (&$fired): void {
            $fired[] = 'kb';
        });
        $widget->onFocusNext(static function () use (&$fired): void {
            $fired[] = 'next';
        });
        $widget->onExit(static function () use (&$fired): void {
            $fired[] = 'exit';
        });

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
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows($this->rows());
        $widget->handleInput(' '); // no onChange wired
        $this->expectNotToPerformAssertions();
    }

    public function testCallbacksAreOptional(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows($this->rows());
        $widget->handleInput('2'); // no onShowKeybindings
        $widget->handleInput("\t"); // no onFocusNext
        $widget->handleInput("\x1b"); // no onExit
        $this->expectNotToPerformAssertions();
    }

    public function testSetRowsClampsAnOutOfRangeSelection(): void
    {
        $widget = $this->widget();
        for ($i = 0; $i < 4; ++$i) {
            $widget->handleInput("\x1b[B"); // selected = 4
        }
        $widget->setRows([new SettingsRow(key: 'inlineDiagnostics', label: 'Inline diagnostics', kind: SettingsRowKind::Bool, value: true, restart: false)]);
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
        $widget = new SettingsFormWidget(new Translator('en'));
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
    }

    public function testActuateWithNoRowsIsSafe(): void
    {
        $widget = new SettingsFormWidget(new Translator('en')); // no rows set
        $widget->handleInput(' '); // increment → actuate → no selected row
        $this->expectNotToPerformAssertions();
    }

    public function testOverlayComposites(): void
    {
        $widget = $this->widget();
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $line = $widget->render(new RenderContext(60, 8))[0];
        $this->assertStringContainsString('DROPDOWN', $line);
    }

    private function textWidget(?array &$changes = null): SettingsFormWidget
    {
        $changes = [];
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows([
            new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'phpactor', restart: false),
            new SettingsRow(key: 'other', label: 'Other', kind: SettingsRowKind::Text, value: '', restart: false, hint: 'auto: none'),
        ]);
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): void {
            $changes[] = [$key, $value];
        });

        return $widget;
    }

    public function testTextRowRendersValueAndDimmedHint(): void
    {
        $rendered = $this->textWidget()->render(new RenderContext(60, 4));
        $this->assertStringContainsString('LSP server  phpactor', AnsiUtils::stripAnsiCodes($rendered[0]));
        $this->assertStringContainsString('auto: none', AnsiUtils::stripAnsiCodes($rendered[1]), 'empty text row shows its hint');
        $this->assertStringContainsString("\x1b[38;5;245mauto: none", $rendered[1], 'hint is dimmed');
    }

    public function testEditingATextRowTypesAndCommits(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleInput("\r"); // Enter starts editing row 0
        $this->assertTrue($widget->isEditing());

        $widget->handleInput('-');
        $widget->handleInput('x');
        $this->assertStringContainsString('phpactor-x', AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[0]));

        $widget->handleInput("\x7f"); // backspace
        $widget->handleInput("\r"); // Enter commits
        $this->assertFalse($widget->isEditing());
        $this->assertSame([['lspServer', 'phpactor-']], $changes);
    }

    public function testEscapeCancelsTextEditingWithoutFiring(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleInput("\r"); // edit
        $widget->handleInput('z');
        $widget->handleInput("\x1b"); // Esc cancels
        $this->assertFalse($widget->isEditing());
        $this->assertSame([], $changes);
    }

    public function testClickStartsTextEditingAndNavIsSuppressedWhileEditing(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleClick(1); // the empty text row
        $this->assertTrue($widget->isEditing());
        $this->assertSame(1, $widget->selectedIndex());

        $widget->handleInput("\x1b[B"); // arrow ignored while editing
        $this->assertSame(1, $widget->selectedIndex());
        $widget->handleInput('a');
        $widget->handleInput("\r");
        $this->assertSame([['other', 'a']], $changes);
    }

    public function testSetRowsCancelsEditing(): void
    {
        $widget = $this->textWidget();
        $widget->handleInput("\r");
        $this->assertTrue($widget->isEditing());
        $widget->setRows([new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'x', restart: false)]);
        $this->assertFalse($widget->isEditing());
    }

    public function testEditingWithoutAChangeHandlerIsSafe(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'x', restart: false)]);
        $widget->handleInput("\r"); // edit
        $widget->handleInput('y');
        $widget->handleInput("\r"); // commit with no onChange
        $this->expectNotToPerformAssertions();
    }

    public function testInputHookConsumesFirstWhileEditing(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleInput("\r");
        $widget->onInput(static fn (string $d): bool => true);
        $widget->handleInput('q'); // swallowed
        $widget->handleInput("\r"); // swallowed too, so no commit
        $this->assertTrue($widget->isEditing());
        $this->assertSame([], $changes);
    }

    public function testHoverTintsARowVisualOnlyAndClears(): void
    {
        $widget = $this->widget();
        $widget->handleClick(0); // selection sits on row 0

        $this->assertTrue($widget->hoverRow(2), 'hovering another row tints it');
        $this->assertFalse($widget->hoverRow(2), 'same row: no change');
        $lines = $widget->render(new RenderContext(60, 10));
        $this->assertStringContainsString("\x1b[48;5;236m", $lines[2], 'the hovered row sits on RowHighlightBg');
        $this->assertStringNotContainsString("\x1b[48;5;236m", $lines[0], 'the selection keeps its own shade');

        $this->assertTrue($widget->hoverRow(99), 'past the rows the tint clears (a change)');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $widget->hoverRow(1);
        $this->assertTrue($widget->clearHover());
        $this->assertStringNotContainsString("\x1b[48;5;236m", implode('', $widget->render(new RenderContext(60, 10))));
    }
}
