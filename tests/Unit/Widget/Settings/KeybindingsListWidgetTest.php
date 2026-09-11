<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\KeybindingRow;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class KeybindingsListWidgetTest extends TestCase
{
    use RendersWidget;

    /** @return list<KeybindingRow> */
    private function rows(): array
    {
        return [
            new KeybindingRow('save', 'Save', 'ctrl+s'),
            new KeybindingRow('duplicate_line', 'Duplicate Line', 'ctrl+d'),
            new KeybindingRow('quit', 'Quit', ''),
        ];
    }

    private function widget(?array &$rebinds = null, ?EventDispatcher $events = null): KeybindingsListWidget
    {
        $rebinds = [];
        $widget = new KeybindingsListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $widget->setRows($this->rows());
        $widget->onRebind(static function (string $action, string $key) use (&$rebinds): void {
            $rebinds[] = [$action, $key];
        });

        return $widget;
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
        $this->assertSame('save', $this->selectedAction($widget));
        $widget->handleInput("\x1b[B"); // down
        $this->assertSame('duplicate_line', $this->selectedAction($widget));
        $widget->handleInput("\x1b[A"); // up
        $this->assertSame('save', $this->selectedAction($widget));
    }

    public function testEnterArmsCaptureAndTheNextKeyRebinds(): void
    {
        $widget = $this->widget($rebinds);
        $widget->handleInput("\x1b[B"); // select duplicate_line
        $widget->handleInput("\r"); // Enter arms capture
        $this->assertTrue(null !== Probe::get($widget, 'capturingIndex'));
        $this->assertStringContainsString('Press a key', $this->plain($widget->render(new RenderContext(60, 8))));

        $widget->handleInput("\x04"); // ctrl+d pressed → captured raw
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'));
        $this->assertSame([['duplicate_line', "\x04"]], $rebinds);
    }

    public function testEscapeWhileCapturingCancelsWithoutLeaving(): void
    {
        $exited = false;
        $widget = $this->widget($rebinds);
        $widget->onExit(static function () use (&$exited): void {
            $exited = true;
        });

        $widget->handleInput("\r"); // arm
        $widget->handleInput("\x1b"); // Esc cancels capture
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'));
        $this->assertSame([], $rebinds, 'nothing rebound');
        $this->assertFalse($exited, 'Esc during capture does not leave the view');
    }

    public function testEscapeWhenIdleLeavesTheView(): void
    {
        $exited = false;
        $widget = $this->widget();
        $widget->onExit(static function () use (&$exited): void {
            $exited = true;
        });
        $widget->handleInput("\x1b");
        $this->assertTrue($exited);
    }

    public function testTabAndTabSwitchCallbacks(): void
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
        $widget->handleInput('1');
        $widget->handleInput("\t");
        $this->assertSame(['general', 'next'], $fired);
    }

    public function testClickSelectsThenArmsCaptureAndIgnoresDeadRows(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(60, 8)); // establish scroll

        $widget->handleClick(2); // row 0 is header; render row 2 → index 1
        $this->assertSame('duplicate_line', $this->selectedAction($widget));
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'), 'first click only selects');

        $widget->handleClick(2); // click the selected row again arms capture
        $this->assertTrue(null !== Probe::get($widget, 'capturingIndex'));

        // A click while capturing selects (does not re-arm on a different row).
        $widget->handleClick(1);
        $this->assertSame('save', $this->selectedAction($widget));

        $widget->handleClick(99); // dead space
        $this->assertSame('save', $this->selectedAction($widget));
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
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'));
    }

    public function testArmingIsANoOpWithNoRows(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $widget->setRows([]);
        $widget->handleInput("\r");
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'));
        $this->assertNull($this->selectedAction($widget));
    }

    public function testCallbacksAreOptional(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $widget->setRows($this->rows());
        $widget->handleInput('1'); // no onShowSettings
        $widget->handleInput("\t"); // no onFocusNext
        $widget->handleInput("\x1b"); // no onExit
        $widget->handleInput("\r"); // arm (no onRebind)
        $widget->handleInput('a'); // capture with no onRebind is safe
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'));
    }

    public function testSetRowsDropsCaptureAndHover(): void
    {
        $widget = $this->widget();
        $widget->hoverRow(2);
        $widget->handleInput("\r");
        $this->assertTrue(null !== Probe::get($widget, 'capturingIndex'));
        $widget->setRows($this->rows());
        $this->assertFalse(null !== Probe::get($widget, 'capturingIndex'));
    }

    public function testScrollFollowsSelectionInAShortViewport(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $rows = [];
        for ($i = 0; $i < 20; ++$i) {
            $rows[] = new KeybindingRow("a{$i}", "Action {$i}", '');
        }
        $widget->setFocused(true);
        $widget->setRows($rows);
        for ($i = 0; $i < 12; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $this->assertSame('a12', $this->selectedAction($widget));
        $this->assertStringContainsString('Action 12', $this->plain($widget->render(new RenderContext(60, 6))));
    }

    public function testAlwaysFillsItsPaneAndOverlayComposites(): void
    {
        $widget = $this->widget();
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $this->assertStringContainsString('DROPDOWN', $widget->render(new RenderContext(60, 8))[0]);
    }

    public function testSetRowsInvalidatesTheRenderRevision(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $widget->setRows($this->rows());
        $revision = $widget->getRenderRevision();

        $widget->setRows($this->rows());

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testCapturingAKeyInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\r"); // arm
        $revision = $widget->getRenderRevision();

        $widget->handleInput('x'); // captured

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testExitViewInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(60, 8));
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\x1b[B");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testClickInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(60, 8));
        $revision = $widget->getRenderRevision();

        $widget->handleClick(2);

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testArmCaptureInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget();
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\r");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testAZeroRowContextFallsBackToTwelveDefaultRows(): void
    {
        $lines = $this->widget()->render(new RenderContext(60, 0));

        $this->assertCount(12, $lines);
    }

    public function testTitleConcatenatesALeadingSpaceBeforeTheTranslatedText(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(60, 8)));

        $this->assertStringStartsWith(' Keybindings', $screen);
    }

    public function testAnUnboundActionsRightColumnWidthIsExactlyOneNotZeroOrTwo(): void
    {
        // The dash '—' occupies width 1. A width-default mutant (0 or 2)
        // shifts the label's truncation boundary at a width tight enough to
        // matter — pin the boundary exactly instead of just presence.
        $widget = new KeybindingsListWidget(new Translator('en'));
        $widget->setRows([new KeybindingRow('a', str_repeat('X', times: 50), '')]);

        // width=10: labelWidth = max(0, 10 - 1 - 2) = 7 → truncated label ' XXXXX…'.
        $line = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(10, 4))[1]);

        $this->assertSame(' XXXXX… — ', $line);
    }

    public function testRowAssemblyConcatenatesLabelSpaceRightSpaceInOrder(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $widget->setRows([new KeybindingRow('a', 'Save', 'ctrl+s')]);

        $line = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(30, 4))[1]);

        $this->assertStringStartsWith(' Save', $line);
        $this->assertStringEndsWith('Ctrl+S ', $line);
    }

    public function testPageUpAndPageDownKeybindingsPageTheSelection(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $rows = [];
        for ($i = 0; $i < 20; ++$i) {
            $rows[] = new KeybindingRow("a{$i}", "Action {$i}", '');
        }
        $widget->setRows($rows);
        $widget->render(new RenderContext(60, 6)); // establishes lastRows for paging

        $widget->handleInput("\x1b[6~"); // Page Down
        $this->assertNotSame('a0', $this->selectedAction($widget), 'page_down must move the selection');

        $widget->handleInput("\x1b[5~"); // Page Up
        $this->assertSame('a0', $this->selectedAction($widget), 'page_up brought it back');
    }

    /** The action of the row under the selection (the widget's private rows + list, via Probe). */
    private function selectedAction(KeybindingsListWidget $widget): ?string
    {
        return (Probe::get($widget, 'rows')[Probe::get($widget, 'list')->selected()] ?? null)?->action;
    }

    public function testTheFilterNarrowsTheListByLabelAndByKey(): void
    {
        // 187 actions in the real page: without this, finding one means
        // scrolling past every caret movement in the editor.
        $widget = $this->widget();

        $widget->handleInput('/');
        $widget->handleInput('dup');
        $screen = $this->plain($widget->render(new RenderContext(60, 8)));

        $this->assertStringContainsString('Duplicate Line', $screen);
        $this->assertStringNotContainsString('Save', $screen, 'the rest is filtered out');
        $this->assertStringContainsString('1 of 3', $screen, 'and the header says how much is hidden');

        // The KEY is searchable too: "what is Ctrl+S" is the other half of what
        // this page is opened for.
        foreach (str_split('xxx') as $ignored) {
            $widget->handleInput("\x7f"); // backspace, one key at a time
        }
        $widget->handleInput('ctrl+s');
        $screen = $this->plain($widget->render(new RenderContext(60, 8)));

        $this->assertStringContainsString('Save', $screen);
        $this->assertStringNotContainsString('Duplicate Line', $screen);
    }

    /**
     * Delete asks the controller to drop the selected row's override, and does
     * it on the row the FILTER shows — the same rule Enter follows, because a
     * key that acts on the row underneath the one you can see is worse than no
     * key at all.
     */
    public function testDeleteAsksToResetTheSelectedRow(): void
    {
        $resets = [];
        $widget = $this->widget();
        $widget->onReset(static function (string $action) use (&$resets): void {
            $resets[] = $action;
        });

        $widget->handleInput("\x1b[B"); // down to Duplicate Line
        $widget->handleInput("\x1b[3~"); // Delete

        $this->assertSame(['duplicate_line'], $resets);
    }

    /**
     * Delete with no reset callback wired must do nothing rather than reach
     * for a row it never checked. The guard is `&&`, and `||` there
     * dereferences a null row on a page whose controller has not hooked it up
     * yet — a shape every callback on this widget can be in, since they are
     * installed after construction. Infection found this one.
     */
    public function testDeleteWithoutAResetCallbackDoesNotCrash(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        $widget->setRows([new KeybindingRow('save', 'Save', 'ctrl+s', overridden: true)]);

        $widget->handleInput("\x1b[3~"); // Delete, with nothing listening

        $this->addToAssertionCount(1);
    }

    /** Delete is not Backspace: Backspace belongs to the filter field, and overloading it would make both untrustworthy. */
    public function testBackspaceDoesNotResetABinding(): void
    {
        $resets = [];
        $widget = $this->widget();
        $widget->onReset(static function (string $action) use (&$resets): void {
            $resets[] = $action;
        });

        $widget->handleInput("\x7f");

        $this->assertSame([], $resets);
    }

    /**
     * An overridden row is showing a CHOICE and looked exactly like the two
     * hundred rows showing a default, so the one row with something to reset
     * was the one row you could not pick out. Marked with a colour rather than
     * a glyph: this row budgets its width down to the column, and a marker
     * column would come out of the label.
     */
    public function testAnOverriddenRowIsMarkedAndADefaultOneIsNot(): void
    {
        $widget = new KeybindingsListWidget(new Translator('en'));
        AttachedStyles::attach($widget);
        $widget->setRows([
            new KeybindingRow('save', 'Save', 'ctrl+s', overridden: true),
            new KeybindingRow('duplicate_line', 'Duplicate Line', 'ctrl+d'),
        ]);

        $lines = $widget->render(new RenderContext(60, 6));
        $overridden = $this->rowWith($lines, 'Save');
        $plain = $this->rowWith($lines, 'Duplicate Line');

        $this->assertNotSame(
            $this->markup($overridden, 'Ctrl+S'),
            $this->markup($plain, 'Ctrl+D'),
            'the two shortcuts are drawn differently',
        );
        $this->assertStringContainsString('Ctrl+S', AnsiUtils::stripAnsiCodes($overridden), 'and the key itself still reads');
    }

    /** @param list<string> $lines */
    private function rowWith(array $lines, string $label): string
    {
        foreach ($lines as $line) {
            if (str_contains(AnsiUtils::stripAnsiCodes($line), $label)) {
                return $line;
            }
        }

        return '';
    }

    /** The escape sequence immediately before $needle — what makes the two rows differ, or not. */
    private function markup(string $line, string $needle): string
    {
        $at = mb_strpos($line, $needle);

        return false === $at ? '' : mb_substr($line, start: 0, length: $at);
    }

    public function testEscapeLeavesTheFilterAndBringsEveryActionBack(): void
    {
        $widget = $this->widget();

        $widget->handleInput('/');
        $widget->handleInput('quit');
        $this->assertStringNotContainsString('Save', $this->plain($widget->render(new RenderContext(60, 8))));

        $widget->handleInput("\x1b");
        $screen = $this->plain($widget->render(new RenderContext(60, 8)));

        $this->assertStringContainsString('Save', $screen);
        $this->assertStringContainsString('Duplicate Line', $screen);
        $this->assertStringContainsString('Ctrl+F to filter', $screen, 'and the hint is back');
    }

    public function testRebindingActsOnTheFilteredRowNotTheOneUnderneath(): void
    {
        // The selection indexes the SHOWN rows: filter to one action, press
        // Enter, and the capture must arm on that action — not on whatever sat
        // at that index before the filter narrowed the list.
        $widget = $this->widget($rebinds);

        $widget->handleInput('/');
        $widget->handleInput('quit');
        $widget->handleInput("\r"); // arm capture on the only row left
        $widget->handleInput("\x0c"); // ctrl+l, captured raw

        $this->assertSame([['quit', "\x0c"]], $rebinds);
    }

    /**
     * The chord column is a COLUMN, whatever a label ends with.
     *
     * Four shipped action labels end in a Latin run — `הצג TODO`,
     * `Git: הסחנה (stash)` and two more. That tail is strong left-to-right and
     * so is the chord beside it, so the two merge and the chord is drawn inside
     * the label: `TODO ... Ctrl+S גצה`, with the label torn around it.
     *
     * The same defect the settings form had one file over. Asserted as the
     * column rather than as the label surviving, because a ragged column passes
     * the latter.
     */
    public function testTheChordColumnHoldsWhateverTheLabelEndsWith(): void
    {
        $widget = new KeybindingsListWidget(new Translator('he'));
        AttachedStyles::attach($widget);
        $widget->setRows([
            new KeybindingRow('a', 'יציאה', 'Ctrl+S', false),
            new KeybindingRow('b', 'הצג TODO', 'Ctrl+S', false),
            new KeybindingRow('c', 'Git: הסחנה (stash)', 'Ctrl+S', false),
            new KeybindingRow('d', 'קווי הזחה', 'Ctrl+S', false),
        ]);

        $columns = [];
        foreach (\array_slice($widget->render(new RenderContext(52, 6)), offset: 1, length: 4) as $line) {
            $mirrored = AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl));
            $columns[] = mb_strpos($mirrored, needle: 'Ctrl+S', encoding: 'UTF-8');
        }

        $this->assertCount(1, array_unique($columns), 'every chord is in the same column: '.json_encode($columns));
    }

    /** And a Latin tail stays with its label rather than crossing the chord. */
    public function testALabelEndingInLatinIsNotTornAroundTheChord(): void
    {
        $widget = new KeybindingsListWidget(new Translator('he'));
        AttachedStyles::attach($widget);
        $widget->setRows([new KeybindingRow('a', 'הצג TODO', 'Ctrl+S', false)]);

        $mirrored = AnsiUtils::stripAnsiCodes(Bidi::reorder($widget->render(new RenderContext(52, 4))[1], BaseDirection::Rtl));

        $chord = mb_strpos($mirrored, needle: 'Ctrl+S', encoding: 'UTF-8');
        $tail = mb_strpos($mirrored, needle: 'TODO', encoding: 'UTF-8');
        $this->assertNotFalse($chord);
        $this->assertNotFalse($tail);
        $this->assertLessThan($tail, $chord, 'the whole label sits to the right of the chord');
    }
}
