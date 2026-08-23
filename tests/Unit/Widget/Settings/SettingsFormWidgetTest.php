<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsFormWidgetTest extends TestCase
{
    use RendersWidget;

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

    private function widget(?array &$changes = null, ?EventDispatcher $events = null): SettingsFormWidget
    {
        $changes = [];
        $widget = new SettingsFormWidget(new Translator('en'), events: $events);
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows($this->rows());
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): void {
            $changes[] = [$key, $value];
        });

        return $widget;
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
        // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
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
        $this->assertSame(1, Probe::get($widget, 'selected'));
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringNotContainsString("\x1b[48;5;31m", $rendered[0]);
        $this->assertStringContainsString("\x1b[48;5;31m", $rendered[1], 'the highlight followed');

        $widget->handleInput("\x1b[A"); // up
        $widget->handleInput("\x1b[A"); // clamps at the top
        $this->assertSame(0, Probe::get($widget, 'selected'));
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
        $events = new EventDispatcher();
        $widget = $this->widget(events: $events);
        $events->addListener(SettingsSectionRequested::class, static function (SettingsSectionRequested $event) use (&$fired): void {
            $fired[] = $event->section;
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
        $this->assertSame(['keybindings', 'next', 'exit'], $fired);
    }

    public function testClickSelectsAndActuatesButIgnoresDeadRows(): void
    {
        $widget = $this->widget($changes);
        $widget->handleClick(1); // Relative line numbers row
        $this->assertSame(1, Probe::get($widget, 'selected'));
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
        $widget->handleInput('2'); // no dispatcher attached
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
        $this->assertSame(0, Probe::get($widget, 'selected'));
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
        $this->assertTrue(null !== Probe::get($widget, 'editing'));

        $widget->handleInput('-');
        $widget->handleInput('x');
        $this->assertStringContainsString('phpactor-x', AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[0]));

        $widget->handleInput("\x7f"); // backspace
        $widget->handleInput("\r"); // Enter commits
        $this->assertFalse(null !== Probe::get($widget, 'editing'));
        $this->assertSame([['lspServer', 'phpactor-']], $changes);
    }

    public function testEscapeCancelsTextEditingWithoutFiring(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleInput("\r"); // edit
        $widget->handleInput('z');
        $widget->handleInput("\x1b"); // Esc cancels
        $this->assertFalse(null !== Probe::get($widget, 'editing'));
        $this->assertSame([], $changes);
    }

    public function testClickStartsTextEditingAndNavIsSuppressedWhileEditing(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleClick(1); // the empty text row
        $this->assertTrue(null !== Probe::get($widget, 'editing'));
        $this->assertSame(1, Probe::get($widget, 'selected'));

        $widget->handleInput("\x1b[B"); // arrow ignored while editing
        $this->assertSame(1, Probe::get($widget, 'selected'));
        $widget->handleInput('a');
        $widget->handleInput("\r");
        $this->assertSame([['other', 'a']], $changes);
    }

    public function testSetRowsCancelsEditing(): void
    {
        $widget = $this->textWidget();
        $widget->handleInput("\r");
        $this->assertTrue(null !== Probe::get($widget, 'editing'));
        $widget->setRows([new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'x', restart: false)]);
        $this->assertFalse(null !== Probe::get($widget, 'editing'));
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
        $this->assertTrue(null !== Probe::get($widget, 'editing'));
        $this->assertSame([], $changes);
    }

    public function testSetRowsWithZeroRowsClampsSelectionToZeroNotNegative(): void
    {
        $widget = $this->widget();
        $widget->setRows([]);

        $this->assertSame(0, Probe::get($widget, 'selected'));
    }

    public function testSetRowsWithTheSameRowCountKeepsAnUpperBoundSelection(): void
    {
        $widget = $this->widget(); // 5 rows
        for ($i = 0; $i < 4; ++$i) {
            $widget->handleInput("\x1b[B"); // selected = 4 (the last row)
        }

        $widget->setRows($this->rows()); // same 5 rows, re-supplied

        $this->assertSame(4, Probe::get($widget, 'selected'), 'the last row is still in range, selection must not shift');
    }

    public function testSetRowsInvalidatesTheRenderRevision(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows($this->rows());
        $revision = $widget->getRenderRevision();

        $widget->setRows($this->rows());

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testCancellingRowsMidEditDoesNotLeaveATextInputRouteOpen(): void
    {
        // setRows() resets `editing` to null but not `editField` — an
        // editing/editField AND-vs-OR mutant would keep routing keys into
        // the stale text editor instead of normal row navigation.
        $widget = $this->textWidget($changes);
        $widget->handleInput("\r"); // start editing row 0
        $widget->setRows([
            new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'phpactor', restart: false),
            new SettingsRow(key: 'other', label: 'Other', kind: SettingsRowKind::Text, value: '', restart: false),
        ]);

        $widget->handleInput("\x1b[B"); // must navigate, not type into the stale editor

        $this->assertSame(1, Probe::get($widget, 'selected'));
        $this->assertSame([], $changes);
    }

    public function testStaleTextFieldAfterSetRowsDoesNotLeakIntoAnUneditedRow(): void
    {
        // Same divergent-state class as above, but exercised through
        // textValue()'s own (separate) editing/editField guard.
        $widget = $this->textWidget();
        $widget->handleInput("\r"); // start editing row 0
        $widget->handleInput('Z'); // mutate the (soon-to-be-stale) TextField
        $widget->setRows([
            new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'phpactor', restart: false),
        ]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[0]);

        $this->assertStringContainsString('phpactor', $screen);
        $this->assertStringNotContainsString('Z', $screen, 'the stale editor from before setRows() must not render');
    }

    public function testMoveUpStepsExactlyOneRowFromAnInteriorSelection(): void
    {
        $widget = $this->widget(); // 5 rows
        for ($i = 0; $i < 3; ++$i) {
            $widget->handleInput("\x1b[B"); // selected = 3
        }

        $widget->handleInput("\x1b[A"); // up once

        $this->assertSame(2, Probe::get($widget, 'selected'));
    }

    public function testMoveDownPastTheLastRowClampsAtTheLastIndexNotBeyond(): void
    {
        $widget = $this->widget(); // 5 rows: last index 4
        for ($i = 0; $i < 10; ++$i) {
            $widget->handleInput("\x1b[B");
        }

        $this->assertSame(4, Probe::get($widget, 'selected'));
    }

    public function testMoveToInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget();
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\x1b[B");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testClickActuatesAChoiceRowByExactlyOneStep(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'mid', 'light'])]);

        $widget->handleClick(0);

        $this->assertSame([['theme', 'mid']], $changes, 'a click steps by exactly one option, not two');
    }

    public function testStartingTextEditInvalidatesTheRenderRevision(): void
    {
        $widget = $this->textWidget();
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\r");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testExitViewWhileEditingInvalidatesTheRenderRevision(): void
    {
        $widget = $this->textWidget();
        $widget->handleInput("\r");
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\x1b");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testConfirmingTextEditInvalidatesTheRenderRevision(): void
    {
        $widget = $this->textWidget();
        $widget->handleInput("\r");
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\r");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testBackspaceOnlyInvalidatesWhenItActuallyDeletesSomething(): void
    {
        $widget = $this->textWidget();
        $widget->setRows([new SettingsRow(key: 'k', label: 'K', kind: SettingsRowKind::Text, value: '', restart: false)]);
        $widget->handleInput("\r"); // edit the empty field
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\x7f"); // backspace on an already-empty field: nothing to delete
        $this->assertSame($revision, $widget->getRenderRevision(), 'a no-op backspace does not invalidate');

        $widget->handleInput('a');
        $revision = $widget->getRenderRevision();
        $widget->handleInput("\x7f"); // now there is something to delete
        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testTypingAlwaysInvalidates(): void
    {
        $widget = $this->textWidget();
        $widget->handleInput("\r");
        $revision = $widget->getRenderRevision();

        $widget->handleInput('a');

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testRenderWithZeroRowsAndAZeroRowContextProducesNoLines(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([]);

        $lines = $widget->render(new RenderContext(60, 0));

        $this->assertSame([], $lines);
    }

    public function testRenderNeverProducesMoreLinesThanTheAllottedRows(): void
    {
        $lines = $this->widget()->render(new RenderContext(60, 8)); // 5 rows, 8 allotted

        $this->assertCount(8, $lines, 'the context height wins, padding with blank rows');
    }

    public function testBlankPaddingRowsContinueRatherThanStoppingTheLoop(): void
    {
        $lines = $this->widget()->render(new RenderContext(60, 8)); // 5 real rows + 3 blank

        $this->assertStringContainsString('Inline diagnostics', AnsiUtils::stripAnsiCodes($lines[0]), 'the first real row still renders after the loop keeps going past index bounds');
        $this->assertSame(8, \count($lines));
        $this->assertStringContainsString('Tab width', AnsiUtils::stripAnsiCodes($lines[4]), 'the last real row (index 4) is reached — a break at row 0 would prevent this');
    }

    public function testTextRowConcatenatesALeadingSpaceBeforeTheLabel(): void
    {
        $screen = AnsiUtils::stripAnsiCodes($this->textWidget()->render(new RenderContext(60, 4))[0]);

        $this->assertStringStartsWith(' LSP server', $screen);
    }

    public function testActionRowConcatenatesALeadingSpaceBeforeTheLabel(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'k', label: 'Remove', kind: SettingsRowKind::Action, value: 'Go', restart: false)]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[0]);

        $this->assertStringStartsWith(' Remove  [Go]', $screen);
    }

    public function testDefaultChoiceRowConcatenatesALeadingSpaceBeforeTheLabel(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'k', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light'])]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[0]);

        $this->assertStringStartsWith(' Theme  < dark >', $screen);
    }

    public function testAnOverflowingEditedRowScrollsToTheCaretInsteadOfTruncating(): void
    {
        $widget = $this->textWidget($changes);
        $widget->setRows([new SettingsRow(key: 'k', label: 'A very long label that will not fit', kind: SettingsRowKind::Text, value: str_repeat('x', times: 100), restart: false)]);
        $widget->handleInput("\r"); // edit: the caret sits at the end of 100 x's

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(20, 4))[0]);

        // Truncation would keep the LEFT edge (the label); scroll-to-caret
        // keeps the caret (the tail of the value) in view instead.
        $this->assertStringNotContainsString('A very long label', $screen);
        $this->assertStringContainsString('x', $screen);
    }

    public function testANonEditedOverflowingRowTruncatesRatherThanScrolling(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'k', label: 'A very long label that will not fit at all', kind: SettingsRowKind::Bool, value: true, restart: false)]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(20, 4))[0]);

        $this->assertStringStartsWith(' [x] A very long', $screen, 'not being edited: the left edge (label start) is kept, not scrolled');
    }

    public function testShortRowsArePaddedToTheFullColumnWidth(): void
    {
        $screen = AnsiUtils::stripAnsiCodes($this->widget()->render(new RenderContext(60, 8))[0]);

        $this->assertSame(60, mb_strlen($screen));
    }

    public function testEditingCursorVisibilityFollowsFocus(): void
    {
        $widget = $this->textWidget();
        $widget->setFocused(true);
        $widget->handleInput("\r");
        $focused = $widget->render(new RenderContext(60, 4))[0];

        $widget->setFocused(false);
        $unfocused = $widget->render(new RenderContext(60, 4))[0];

        $this->assertNotSame($focused, $unfocused, 'the caret visibility marker must differ between focused and unfocused edit');
    }

    public function testATextRowWithBothAValueAndAHintShowsTheValue(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'k', label: 'K', kind: SettingsRowKind::Text, value: 'set', restart: false, hint: 'auto: none')]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[0]);

        $this->assertStringContainsString('set', $screen);
        $this->assertStringNotContainsString('auto: none', $screen, 'a non-empty value wins over the hint, even with a hint set');
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
