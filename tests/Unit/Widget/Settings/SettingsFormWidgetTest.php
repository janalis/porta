<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsFormWidgetTest extends TestCase
{
    use ReadsSettingsForm;

    /**
     * The setting rows only: the form renders a title above them and a key
     * legend below, and neither is addressable by a row index. Tests that
     * genuinely assert ORDER slice through here; tests that assert one named
     * row's content use {@see ReadsSettingsForm::settingRow()} instead.
     *
     * @param list<string> $rendered
     *
     * @return list<string>
     */
    private function body(array $rendered): array
    {
        return array_values(array_slice($rendered, offset: 1, length: -1));
    }

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
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): bool {
            $changes[] = [$key, $value];

            return true;
        });

        return $widget;
    }

    public function testSelectKeyPutsTheSelectionOnTheNamedRow(): void
    {
        $widget = $this->widget();
        $this->assertStringContainsString('Inline diagnostics', $this->selectedSettingRow($widget), 'starts on the first row');

        $widget->selectKey('theme');

        $this->assertStringContainsString('Theme', $this->selectedSettingRow($widget));
    }

    /**
     * A search result can name a row that a plugin toggle or a locale switch
     * has since removed. Opening its page and staying put beats throwing.
     */
    public function testSelectKeyLeavesTheSelectionAloneWhenThePageHasNoSuchRow(): void
    {
        $widget = $this->widget();
        $widget->selectKey('theme');

        $widget->selectKey('aSettingThatMovedPages');

        $this->assertStringContainsString('Theme', $this->selectedSettingRow($widget));
    }

    public function testRendersCheckboxesSteppersAndRestartNote(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(60, 8)));

        // Label first, control in the shared column — a Bool's checkbox used to
        // sit at column 1, ahead of its label and out of line with every other
        // kind's control.
        $this->assertMatchesRegularExpression('/Inline diagnostics\s+\[x\]/', $screen);
        $this->assertMatchesRegularExpression('/Relative line numbers\s+\[ \]/', $screen);
        $this->assertMatchesRegularExpression('/Theme\s+< dark >/', $screen);
        $this->assertMatchesRegularExpression('/Tab width\s+< 4 >/', $screen);
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
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): bool {
            $changes[] = [$key, $value];

            return true;
        });
        $widget->handleInput("\x1b[D");
        $this->assertSame([], $changes, 'a button has no value to step back');

        $widget->handleInput("\r"); // Enter actuates
        $widget->handleClick(1); // so does a click on the selected row
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
        $calm = $widget->render(new RenderContext(60, 2))[1];
        $widget->setRows($row(true));
        $armed = $widget->render(new RenderContext(60, 2))[1];

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
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): bool {
            $changes[] = [$key, $value];

            return true;
        });

        $widget->handleInput("\x1b[C"); // right → next

        $this->assertSame([['language', 'fr']], $changes, 'persists the code, not the display name');
    }

    public function testFocusedSelectionIsHighlightedAndMovesWithArrows(): void
    {
        $widget = $this->widget();
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringContainsString("\x1b[48;5;31m", $this->body($rendered)[0], 'row 0 selected + focused');

        $widget->handleInput("\x1b[B"); // down
        $this->assertSame(1, Probe::object($widget, 'list')->selected());
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringNotContainsString("\x1b[48;5;31m", $this->body($rendered)[0]);
        $this->assertStringContainsString("\x1b[48;5;31m", $this->body($rendered)[1], 'the highlight followed');

        $widget->handleInput("\x1b[A"); // up
        $widget->handleInput("\x1b[A"); // clamps at the top
        $this->assertSame(0, Probe::object($widget, 'list')->selected());
    }

    public function testUnfocusedSelectionUsesADimmerBar(): void
    {
        $widget = $this->widget();
        $widget->setFocused(false);
        $rendered = $widget->render(new RenderContext(60, 8));
        $this->assertStringContainsString("\x1b[48;5;237m", $this->body($rendered)[0], 'a resting selection bar, not the focused blue');
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

    /**
     * A click SELECTS; only a click on the already-selected row actuates.
     *
     * The first click used to mutate outright, so a stray click on this screen
     * toggled a setting or armed a plugin removal. Pane row 0 is the title, so
     * the first setting is at pane row 1.
     */
    public function testAFirstClickSelectsWithoutChangingAnything(): void
    {
        $widget = $this->widget($changes);

        $widget->handleClick(2); // pane row 2 = the second setting

        $this->assertSame(1, Probe::object($widget, 'list')->selected(), 'the click moved the selection');
        $this->assertSame([], $changes, 'and changed nothing');

        $widget->handleClick(2); // the same row, now selected

        $this->assertSame([['relativeLineNumbers', true]], $changes, 'the second click actuates');
    }

    public function testAClickOnTheTitleOrPastTheLastRowDoesNothing(): void
    {
        $widget = $this->widget($changes);

        $widget->handleClick(0); // the title row
        $widget->handleClick(99); // past the last row

        $this->assertSame(0, Probe::object($widget, 'list')->selected());
        $this->assertSame([], $changes);
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
        $this->assertSame(0, Probe::object($widget, 'list')->selected());
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
        // Pane coordinates, not row indices: a dropdown falls where it falls,
        // including over the title row.
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
        $widget->onChange(static function (string $key, bool|int|string $value) use (&$changes): bool {
            $changes[] = [$key, $value];

            return true;
        });

        return $widget;
    }

    public function testTextRowRendersItsValueAndItsHintAsADimmedDetail(): void
    {
        $rendered = $this->textWidget()->render(new RenderContext(60, 4));
        $this->assertMatchesRegularExpression('/LSP server\s+phpactor/', AnsiUtils::stripAnsiCodes($this->body($rendered)[0]));
        $this->assertStringContainsString('auto: none', AnsiUtils::stripAnsiCodes($this->body($rendered)[1]), 'the hint describes the row');
        $this->assertStringContainsString("\x1b[38;5;245m", $this->body($rendered)[1], 'and is dimmed');
    }

    public function testEditingATextRowTypesAndCommits(): void
    {
        $widget = $this->textWidget($changes);
        $widget->handleInput("\r"); // Enter starts editing row 0
        $this->assertTrue(null !== Probe::get($widget, 'editing'));

        $widget->handleInput('-');
        $widget->handleInput('x');
        $this->assertStringContainsString('phpactor-x', AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[1]));

        $widget->handleInput("\x7f"); // backspace
        $widget->handleInput("\r"); // Enter commits
        $this->assertFalse(null !== Probe::get($widget, 'editing'));
        $this->assertSame([['lspServer', 'phpactor-']], $changes);
    }

    /**
     * A refused value keeps the editor open with the text as typed.
     *
     * The commit used to close the editor regardless, and the controller's
     * setRows() then snapped the row back to the persisted value — so invalid
     * JSON in a debug launch config was reported and destroyed in the same
     * breath, leaving nothing to correct.
     */
    public function testARefusedValueKeepsTheEditorOpenWithWhatWasTyped(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        $widget->setRows([new SettingsRow(key: 'debugLaunch', label: 'Launch', kind: SettingsRowKind::Text, value: '', restart: false)]);
        $widget->onChange(static fn (string $key, bool|int|string $value): bool => false); // always refuses

        $widget->handleInput("\r"); // open
        foreach (str_split('{bad') as $char) {
            $widget->handleInput($char);
        }
        $widget->handleInput("\r"); // commit → refused

        $this->assertNotNull(Probe::get($widget, 'editing'), 'still editing');
        $this->assertStringContainsString('{bad', AnsiUtils::stripAnsiCodes(implode('', $widget->render(new RenderContext(60, 5)))), 'and the typing survived');
    }

    public function testAnAcceptedValueClosesTheEditor(): void
    {
        $widget = $this->textWidget($changes);

        $widget->handleInput("\r");
        $widget->handleInput('x');
        $widget->handleInput("\r");

        $this->assertNull(Probe::get($widget, 'editing'), 'accepted, so the editor closed');
        $this->assertSame([['lspServer', 'phpactorx']], $changes);
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
        $widget->handleClick(2); // pane row 2 = the empty text row: selects it
        $this->assertNull(Probe::get($widget, 'editing'), 'the first click only selects');
        $widget->handleClick(2); // the second opens the editor
        $this->assertTrue(null !== Probe::get($widget, 'editing'));
        $this->assertSame(1, Probe::object($widget, 'list')->selected());

        $widget->handleInput("\x1b[B"); // arrow ignored while editing
        $this->assertSame(1, Probe::object($widget, 'list')->selected());
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

        $this->assertSame(0, Probe::object($widget, 'list')->selected());
    }

    public function testSetRowsWithTheSameRowCountKeepsAnUpperBoundSelection(): void
    {
        $widget = $this->widget(); // 5 rows
        for ($i = 0; $i < 4; ++$i) {
            $widget->handleInput("\x1b[B"); // selected = 4 (the last row)
        }

        $widget->setRows($this->rows()); // same 5 rows, re-supplied

        $this->assertSame(4, Probe::object($widget, 'list')->selected(), 'the last row is still in range, selection must not shift');
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

        $this->assertSame(1, Probe::object($widget, 'list')->selected());
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

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[1]);

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

        $this->assertSame(2, Probe::object($widget, 'list')->selected());
    }

    public function testMoveDownPastTheLastRowClampsAtTheLastIndexNotBeyond(): void
    {
        $widget = $this->widget(); // 5 rows: last index 4
        for ($i = 0; $i < 10; ++$i) {
            $widget->handleInput("\x1b[B");
        }

        $this->assertSame(4, Probe::object($widget, 'list')->selected());
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

        $widget->handleClick(1); // the selected row: a click actuates it

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

    /**
     * The defect this pins: render() looped from index 0 with no scroll offset,
     * so a page taller than the pane had rows that could not be reached and a
     * selection that walked off-screen invisibly. The keybindings list beside
     * it has always scrolled; this one never did.
     */
    public function testAPageTallerThanThePaneScrollsToKeepTheSelectionOnScreen(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; ++$i) {
            $rows[] = new SettingsRow(key: 'k'.$i, label: 'Setting '.$i, kind: SettingsRowKind::Bool, value: false, restart: false);
        }
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->setRows($rows);

        // A ten-row pane: a title, eight settings and a footer.
        $this->assertTrue($this->hasSettingRow($widget->render(new RenderContext(60, 10)), 'Setting 0'));
        $this->assertFalse($this->hasSettingRow($widget->render(new RenderContext(60, 10)), 'Setting 20'), 'row 20 is below the fold to begin with');

        for ($i = 0; $i < 20; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $lines = $widget->render(new RenderContext(60, 10));

        $this->assertTrue($this->hasSettingRow($lines, 'Setting 20'), 'the window followed the selection down');
        $this->assertStringContainsString('Setting 20', $this->selectedSettingRow($widget, 60, 10), 'and it is the selected row, not merely visible');
        $this->assertFalse($this->hasSettingRow($lines, 'Setting 0'), 'the top scrolled away');
    }

    /** Every row must be reachable — the last one included. */
    public function testTheLastRowOfALongPageCanBeReached(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; ++$i) {
            $rows[] = new SettingsRow(key: 'k'.$i, label: 'Setting '.$i, kind: SettingsRowKind::Bool, value: false, restart: false);
        }
        $widget = new SettingsFormWidget(new Translator('en'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        $widget->setRows($rows);

        for ($i = 0; $i < 40; ++$i) { // past the end: the selection clamps
            $widget->handleInput("\x1b[B");
        }

        $this->assertStringContainsString('Setting 29', $this->selectedSettingRow($widget, 60, 10));
        $this->assertTrue($this->hasSettingRow($widget->render(new RenderContext(60, 10)), 'Setting 29'));
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

        $this->assertStringContainsString('Inline diagnostics', AnsiUtils::stripAnsiCodes($this->body($lines)[0]), 'the first real row still renders after the loop keeps going past index bounds');
        $this->assertSame(8, \count($lines));
        $this->assertStringContainsString('Tab width', AnsiUtils::stripAnsiCodes($this->body($lines)[4]), 'the last real row (index 4) is reached — a break at row 0 would prevent this');
    }

    /**
     * Every kind reserves the same leading marker column, so the label column
     * lines up whether or not a row is modified.
     */
    public function testEveryKindReservesTheMarkerColumn(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([
            new SettingsRow(key: 'a', label: 'Remove', kind: SettingsRowKind::Action, value: 'Go', restart: false),
            new SettingsRow(key: 'b', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light']),
            new SettingsRow(key: 'c', label: 'Sticky', kind: SettingsRowKind::Bool, value: true, restart: false),
        ]);

        $rendered = $widget->render(new RenderContext(60, 6));

        foreach ([0, 1, 2] as $i) {
            $this->assertStringStartsWith('  ', AnsiUtils::stripAnsiCodes($this->body($rendered)[$i]), 'an unmarked row leaves the marker column blank');
        }
        $this->assertMatchesRegularExpression('/^  Remove\s+\[Go\]/', AnsiUtils::stripAnsiCodes($this->body($rendered)[0]));
    }

    public function testRResetsTheSelectedRowButIsInertOnARowWithNoDefault(): void
    {
        $reset = [];
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->onReset(static function (string $key) use (&$reset): void {
            $reset[] = $key;
        });
        $widget->setRows([
            new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'light', restart: false, options: ['dark', 'light'], default: 'dark'),
            new SettingsRow(key: 'go', label: 'Go', kind: SettingsRowKind::Action, value: 'Run', restart: false),
        ]);

        $widget->handleInput('r');
        $this->assertSame(['theme'], $reset);

        $widget->handleInput("\x1b[B"); // the Action row
        $widget->handleInput('r');
        $this->assertSame(['theme'], $reset, 'a row with no default has nothing to reset to');
    }

    public function testFixingATypoAtTheStartOfAValueWithoutRetypingIt(): void
    {
        // The case that made append-only editing untenable: a JSON launch
        // config with a wrong first character.
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'debugLaunch', label: 'Launch', kind: SettingsRowKind::Text, value: '{"stopOnEntry": true}', restart: false)]);

        $widget->handleInput("\r"); // open the editor
        $widget->handleInput("\x1b[H"); // Home
        $widget->handleInput("\x1b[3~"); // Delete the '{'
        $widget->handleInput('[');
        $widget->handleInput("\r"); // commit

        $this->assertSame([['debugLaunch', '["stopOnEntry": true}']], $changes);
    }

    public function testTheCaretKeysMoveWithinTheValueWhileEditing(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'k', label: 'K', kind: SettingsRowKind::Text, value: 'ad', restart: false)]);

        $widget->handleInput("\r");
        $widget->handleInput("\x1b[D"); // left, between a and d
        $widget->handleInput('bc');
        $widget->handleInput("\x1b[F"); // End
        $widget->handleInput('!');
        $widget->handleInput("\r");

        $this->assertSame([['k', 'abcd!']], $changes);
    }

    public function testCtrlWDeletesTheWordBeforeTheCaretAndRightMovesBackOverIt(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'runCommand', label: 'Run', kind: SettingsRowKind::Text, value: 'php -d memory_limit', restart: false)]);

        $widget->handleInput("\r");
        $widget->handleInput("\x17"); // Ctrl+W: drop the last word
        $widget->handleInput("\x1b[D"); // left, into the value
        $widget->handleInput("\x1b[C"); // and back right again
        $widget->handleInput('x');
        $widget->handleInput("\r");

        $this->assertSame([['runCommand', 'php -d x']], $changes);
    }

    /** ← on a Text row is the no-op it already was on a button. */
    public function testSteppingBackDoesNotOpenTheTextEditor(): void
    {
        $widget = $this->textWidget();

        $widget->handleInput("\x1b[D");

        $this->assertNull(Probe::get($widget, 'editing'), 'a step-back key does not start an edit');
    }

    public function testRIsInertWhileTheInlineEditorHasTheKeys(): void
    {
        // Otherwise typing an `r` into an LSP command would wipe the row.
        $reset = [];
        $widget = $this->textWidget($changes);
        $widget->onReset(static function (string $key) use (&$reset): void {
            $reset[] = $key;
        });
        $widget->handleInput("\r"); // open the editor
        $widget->handleInput('r');

        $this->assertSame([], $reset, 'the letter went into the field');
        $widget->handleInput("\r"); // commit
        $this->assertSame([['lspServer', 'phpactorr']], $changes, 'and it really was typed');
    }

    /**
     * The marker answers "what have I changed on this page?" — a question the
     * screen could not answer at all before.
     */
    public function testAModifiedRowIsMarkedAndAStockRowIsNot(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([
            new SettingsRow(key: 'a', label: 'Stock', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light'], default: 'dark'),
            new SettingsRow(key: 'b', label: 'Changed', kind: SettingsRowKind::Choice, value: 'light', restart: false, options: ['dark', 'light'], default: 'dark'),
            new SettingsRow(key: 'c', label: 'Defaultless', kind: SettingsRowKind::Action, value: 'Go', restart: false),
        ]);

        $rendered = array_map(AnsiUtils::stripAnsiCodes(...), $this->body($widget->render(new RenderContext(60, 6))));

        $this->assertStringStartsWith('  Stock', $rendered[0], 'a row at its default is not marked');
        $this->assertStringStartsWith('● Changed', $rendered[1], 'a row that differs is');
        $this->assertStringStartsWith('  Defaultless', $rendered[2], 'a row with no default never marks');
    }

    public function testAnOverflowingEditedRowScrollsToTheCaretInsteadOfTruncating(): void
    {
        $widget = $this->textWidget($changes);
        $widget->setRows([new SettingsRow(key: 'k', label: 'A very long label that will not fit', kind: SettingsRowKind::Text, value: str_repeat('x', times: 100), restart: false)]);
        $widget->handleInput("\r"); // edit: the caret sits at the end of 100 x's

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(20, 4))[1]);

        // Truncation would keep the LEFT edge (the label); scroll-to-caret
        // keeps the caret (the tail of the value) in view instead.
        $this->assertStringNotContainsString('A very long label', $screen);
        $this->assertStringContainsString('x', $screen);
    }

    public function testANonEditedOverflowingRowTruncatesRatherThanScrolling(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'k', label: 'A very long label that will not fit at all', kind: SettingsRowKind::Bool, value: true, restart: false)]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(20, 4))[1]);

        $this->assertStringStartsWith('  A very long', $screen, 'not being edited: the left edge (marker column, then label) is kept, not scrolled');
    }

    public function testShortRowsArePaddedToTheFullColumnWidth(): void
    {
        $screen = AnsiUtils::stripAnsiCodes($this->widget()->render(new RenderContext(60, 8))[1]);

        $this->assertSame(60, mb_strlen($screen));
    }

    public function testEditingCursorVisibilityFollowsFocus(): void
    {
        $widget = $this->textWidget();
        $widget->setFocused(true);
        $widget->handleInput("\r");
        $focused = $widget->render(new RenderContext(60, 4))[1];

        $widget->setFocused(false);
        $unfocused = $widget->render(new RenderContext(60, 4))[1];

        $this->assertNotSame($focused, $unfocused, 'the caret visibility marker must differ between focused and unfocused edit');
    }

    /**
     * A hint is a description, not a placeholder. It used to stand in for an
     * empty text value — which is why it reached no other kind, and why a row
     * that had a value lost its explanation.
     */
    public function testARowShowsItsValueAndItsHintTogether(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([new SettingsRow(key: 'k', label: 'K', kind: SettingsRowKind::Text, value: 'set', restart: false, hint: 'auto: none')]);

        $screen = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(60, 4))[1]);

        $this->assertStringContainsString('set', $screen);
        $this->assertStringContainsString('auto: none', $screen, 'the hint survives a row that has a value');
    }

    /** The hint reaches every kind, not only Text rows with nothing in them. */
    public function testAHintRendersOnEveryKind(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([
            new SettingsRow(key: 'a', label: 'Bool', kind: SettingsRowKind::Bool, value: true, restart: false, hint: 'bool hint'),
            new SettingsRow(key: 'b', label: 'Choice', kind: SettingsRowKind::Choice, value: 'x', restart: false, options: ['x', 'y'], hint: 'choice hint'),
            new SettingsRow(key: 'c', label: 'Int', kind: SettingsRowKind::Int, value: 2, restart: false, hint: 'int hint'),
            new SettingsRow(key: 'd', label: 'Text', kind: SettingsRowKind::Text, value: 'v', restart: false, hint: 'text hint'),
            new SettingsRow(key: 'e', label: 'Action', kind: SettingsRowKind::Action, value: 'Go', restart: false, hint: 'action hint'),
        ]);

        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $widget->render(new RenderContext(80, 8))));

        foreach (['bool hint', 'choice hint', 'int hint', 'text hint', 'action hint'] as $hint) {
            $this->assertStringContainsString($hint, $screen, $hint.' must reach the screen');
        }
    }

    public function testABoundedIntShowsItsRangeAndStepsByItsDeclaredStep(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'maxBytes', label: 'Max bytes', kind: SettingsRowKind::Int, value: 4_194_304, restart: false, min: 0, max: 8_388_608, step: 1_048_576)]);

        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $widget->render(new RenderContext(80, 5))));
        $this->assertStringContainsString('0-8388608', $screen, 'the range is visible without hammering the key');

        $widget->handleInput("\x1b[C"); // one step right

        $this->assertSame([['maxBytes', 5_242_880]], $changes, 'one keypress moves it one declared step, not one byte');
    }

    public function testAnUnboundedIntStillStepsByOne(): void
    {
        $widget = $this->widget($changes);
        $widget->setRows([new SettingsRow(key: 'n', label: 'N', kind: SettingsRowKind::Int, value: 5, restart: false)]);

        $widget->handleInput("\x1b[C");

        $this->assertSame([['n', 6]], $changes);
    }

    public function testHoverTintsARowVisualOnlyAndClears(): void
    {
        $widget = $this->widget(); // the selection starts on row 0

        $this->assertTrue($widget->hoverRow(3), 'hovering another row tints it');
        $this->assertFalse($widget->hoverRow(3), 'same row: no change');
        $lines = $widget->render(new RenderContext(60, 10));
        $this->assertStringContainsString("\x1b[48;5;236m", $this->body($lines)[2], 'the hovered row sits on RowHighlightBg');
        $this->assertStringNotContainsString("\x1b[48;5;236m", $this->body($lines)[0], 'the selection keeps its own shade');

        $this->assertTrue($widget->hoverRow(99), 'past the rows the tint clears (a change)');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $widget->hoverRow(1);
        $this->assertTrue($widget->clearHover());
        $this->assertStringNotContainsString("\x1b[48;5;236m", implode('', $widget->render(new RenderContext(60, 10))));
    }

    /**
     * A Choice row's value keeps its brackets on a mirrored screen.
     *
     * The stepper is `< value >` with a Latin value, sitting after a Hebrew
     * label. The opening `<` has strong right-to-left on one side and strong
     * left-to-right on the other, so with nothing to bind it it takes the
     * paragraph direction, leaves for the far side of the group and mirrors on
     * the way — the row then shows two `>`, and the current value is no longer
     * bracketed at all. Theme is the first row on the Interface page.
     *
     * Rendered through the real reorder, because the widget's own output is in
     * logical order and looks perfect there — this defect only exists on screen.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mirroredValueProvider')]
    public function testAValueKeepsItsBracketsWhenMirrored(SettingsRow $row, string $expected): void
    {
        $translator = new Translator('he');
        $widget = new SettingsFormWidget($translator);
        AttachedStyles::attach($widget);
        $widget->setRows([$row], $translator->trans('Interface'));

        $line = $this->body($widget->render(new RenderContext(58, 4)))[0];
        $mirrored = AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl));

        $this->assertStringContainsString($expected, $mirrored, 'the value reads as one intact block');
    }

    /**
     * Every shape a value takes, because fixing one broke another.
     *
     * A mark in FRONT of the control alone binds the stepper and breaks the
     * checkbox: `[x]`'s opening bracket goes left-to-right while its closing one
     * still has the row's padding on its right, takes the paragraph direction
     * and leaves, so a row that rendered correctly starts showing `[ [x`. Only
     * bracketing the whole value region holds all four.
     *
     * @return iterable<string, array{SettingsRow, string}>
     */
    public static function mirroredValueProvider(): iterable
    {
        // The CONTROL is what the mark protects; the detail beyond it is prose
        // and mirrors, separated by the space the spent mark leaves behind.
        yield 'choice' => [new SettingsRow('theme', 'ערכת נושא', SettingsRowKind::Choice, 'dark', false, ['dark', 'light']), '< dark >'];
        yield 'bool' => [new SettingsRow('sticky', 'גלילה דביקה', SettingsRowKind::Bool, true, false), '[x]'];
        yield 'action' => [new SettingsRow('rm', 'demo (project)', SettingsRowKind::Action, 'Remove', false), '[Remove]'];
        yield 'int' => [new SettingsRow('indent', 'גודל הזחה', SettingsRowKind::Int, 4, false, null, 1, 8), '< 4 >'];
        yield 'int range' => [new SettingsRow('indent', 'גודל הזחה', SettingsRowKind::Int, 4, false, null, 1, 8), '1-8'];
        // A detail clause in HEBREW, which the theme row's Latin option name
        // does not exercise: wrapping the detail along with the control reads
        // as the same fix and breaks this row, because prose belongs mirrored.
        yield 'choice with a hebrew detail' => [
            new SettingsRow('scope', 'שמירת שינויים אל', SettingsRowKind::Choice, '.porta.json', false, ['.porta.json'], hint: 'להפעלה זו'),
            '< .porta.json >',
        ];
    }

    /** The mark is spent on the way out, so the row is exactly as wide as it measures. */
    public function testAMirroredRowShipsNoDirectionalMark(): void
    {
        $translator = new Translator('he');
        $widget = new SettingsFormWidget($translator);
        AttachedStyles::attach($widget);
        $widget->setRows([
            new SettingsRow('theme', $translator->trans('Theme'), SettingsRowKind::Choice, 'dark', false, ['dark', 'light']),
        ], $translator->trans('Interface'));

        $row = $this->body($widget->render(new RenderContext(58, 4)))[0];
        $mirrored = Bidi::reorder($row, BaseDirection::Rtl);

        $this->assertStringNotContainsString("\u{200E}", $mirrored, 'no mark reaches the terminal');
        $this->assertSame(58, AnsiUtils::visibleWidth($mirrored), 'and the row still fills its pane');
    }
}
