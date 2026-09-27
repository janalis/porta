<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Menu\MenuPanel;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MenuPanelTest extends TestCase
{
    /** @return list<MenuItem> */
    private function items(): array
    {
        return [
            new MenuItem('Save', 'Ctrl+S', static fn (): null => null),
            MenuItem::separator(),
            new MenuItem('Quit', 'Ctrl+Q', static fn (): null => null),
        ];
    }

    public function testDisabledItemsAreSkippedForSelection(): void
    {
        $items = [
            new MenuItem('a', '', static fn (): null => null, false, static fn (): bool => false), // disabled
            new MenuItem('b', '', static fn (): null => null),
            MenuItem::separator(),
            new MenuItem('c', '', static fn (): null => null),
        ];

        $this->assertSame(1, MenuPanel::firstSelectable($items), 'skips the disabled first item');
        $this->assertSame(3, MenuPanel::step($items, 1, 1), 'down from b skips the separator to c');
        $this->assertSame(1, MenuPanel::step($items, 3, 1), 'wraps back to b, skipping disabled a and the separator');
    }

    public function testDisabledItemRendersDimAndIsNeverHighlighted(): void
    {
        $items = [new MenuItem('Save', 'Ctrl+S', static fn (): null => null, false, static fn (): bool => false)];
        $segment = MenuPanel::segments($items, 0, 20, new Styles(DarkTheme::definition()))[0]['segment']; // selected index 0, yet disabled

        $this->assertStringContainsString("\x1b[38;5;242m", $segment, 'disabled item uses the faint foreground');
        $this->assertStringContainsString("\x1b[2m", $segment, 'SGR dim attribute keeps the state visible without color');
        $this->assertStringContainsString("\x1b[9mSave\x1b[29m", $segment, 'and its label is struck through: "not available", not "dimmer"');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $segment, 'disabled item is not highlighted even when selected');
    }

    public function testWidthFitsWidestItemAndHasAFloor(): void
    {
        $width = MenuPanel::width($this->items());
        // 'Quit' + '  ' + 'Ctrl+Q' + padding is wider than the 8-column floor.
        $this->assertGreaterThan(8, $width);
        $this->assertSame(8, MenuPanel::width([new MenuItem('x', '', static fn (): null => null)]), 'never narrower than 8');
        $this->assertSame(8, MenuPanel::width([MenuItem::separator()]), 'separators do not drive the width');
    }

    public function testSegmentsRenderExactWidthWithSelectionHighlight(): void
    {
        $width = MenuPanel::width($this->items());
        $segments = MenuPanel::segments($this->items(), 2, $width, new Styles(DarkTheme::definition()));

        $this->assertCount(3, $segments);
        $this->assertTrue($segments[1]['separator']);
        foreach ($segments as $segment) {
            $this->assertSame(
                $segment['separator'] ? 0 : $width,
                AnsiUtils::visibleWidth($segment['segment']),
                'each item row is exactly panelWidth wide; a separator draws nothing, the frame tees it',
            );
        }
        // The selected row (index 2, Quit) carries the highlight background.
        $this->assertStringContainsString("\x1b[48;5;31m", $segments[2]['segment']);
        $this->assertStringNotContainsString("\x1b[48;5;31m", $segments[0]['segment']);

        $plain = AnsiUtils::stripAnsiCodes(\implode("\n", \array_column($segments, 'segment')));
        $this->assertStringContainsString('Save', $plain);
        $this->assertStringContainsString('Ctrl+S', $plain);
    }

    public function testLongLabelIsTruncatedToFit(): void
    {
        $items = [new MenuItem('A very long menu item label indeed', 'F2', static fn (): null => null)];
        $segments = MenuPanel::segments($items, 0, 20, new Styles(DarkTheme::definition()));
        $this->assertSame(20, AnsiUtils::visibleWidth($segments[0]['segment']));
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($segments[0]['segment']));
    }

    public function testFirstSelectableSkipsLeadingSeparators(): void
    {
        $this->assertSame(0, MenuPanel::firstSelectable($this->items()));
        $this->assertSame(1, MenuPanel::firstSelectable([MenuItem::separator(), new MenuItem('X', '', static fn (): null => null)]));
        $this->assertSame(0, MenuPanel::firstSelectable([MenuItem::separator(), MenuItem::separator()]), 'none selectable → 0');
    }

    public function testStepSkipsSeparatorsAndWraps(): void
    {
        $items = $this->items(); // Save(0), sep(1), Quit(2)
        $this->assertSame(2, MenuPanel::step($items, 0, 1), 'down from Save skips the separator to Quit');
        $this->assertSame(0, MenuPanel::step($items, 2, 1), 'down from Quit wraps to Save');
        $this->assertSame(2, MenuPanel::step($items, 0, -1), 'up from Save wraps past the separator to Quit');
        $this->assertSame(0, MenuPanel::step([MenuItem::separator(), MenuItem::separator()], 0, 1), 'no selectable item → stays put');
    }

    public function testStepMovesInTheRequestedDirectionNotReversed(): void
    {
        // Four fully-selectable, evenly-spaced items: unlike this file's other
        // fixtures (a single separator splitting two items), this has no
        // symmetry that would let a reversed step direction land on the same
        // index as the correct one.
        $items = [
            new MenuItem('A', '', static fn (): null => null),
            new MenuItem('B', '', static fn (): null => null),
            new MenuItem('C', '', static fn (): null => null),
            new MenuItem('D', '', static fn (): null => null),
        ];
        $this->assertSame(1, MenuPanel::step($items, 0, 1), 'forward from A lands on B, not D');
        $this->assertSame(3, MenuPanel::step($items, 0, -1), 'backward from A lands on D, not B');
    }

    public function testWidthContinuesPastASeparatorInsteadOfStoppingThere(): void
    {
        // A separator sits first: measuring must keep scanning past it, not
        // stop the walk there and leave every later item's width unseen.
        $items = [MenuItem::separator(), new MenuItem('Longlabel', '', static fn (): null => null)];
        $this->assertSame(13, MenuPanel::width($items), '9-wide label + 4-column chrome, past the leading separator');
    }

    public function testWidthWithNoShortcutAddsNoExtraColumns(): void
    {
        $items = [new MenuItem('Abcdef', '', static fn (): null => null)]; // 6-wide label, no shortcut
        $this->assertSame(10, MenuPanel::width($items), '6-wide label + 0 (no shortcut) + 4-column chrome');
    }

    public function testSeparatorRowNeverThrowsOrOverdrawsAtAZeroOrNegativePanelWidth(): void
    {
        $items = [MenuItem::separator()];
        $styles = new Styles(DarkTheme::definition());

        $this->assertSame(0, AnsiUtils::visibleWidth(MenuPanel::segments($items, 0, 0, $styles)[0]['segment']), 'a zero-width panel draws no separator glyphs');
        $this->assertSame(0, AnsiUtils::visibleWidth(MenuPanel::segments($items, 0, -1, $styles)[0]['segment']), 'a negative panel width clamps to zero rather than crashing str_repeat');
    }

    public function testInnerWidthFloorLeavesNoRoomForALabelAtATooNarrowPanel(): void
    {
        $items = [new MenuItem('Hi', '', static fn (): null => null)];
        $segment = MenuPanel::segments($items, 0, 2, new Styles(DarkTheme::definition()))[0]['segment'];
        // inner = max(0, 2 - EDGE_COLS(2)) = 0: no budget for even a
        // truncation ellipsis, just the two edge spaces — and no gap, which
        // used to make the row one column wider than its panel.
        $this->assertSame('  ', AnsiUtils::stripAnsiCodes($segment));
    }

    /**
     * A panel too narrow for a row's label and shortcut drops the shortcut,
     * not the label: the label says what the item does. It used to go the
     * other way — `Save` gave up every column to a shortcut that still did
     * not fit, and the row ran 17 columns past its panel.
     */
    public function testAShortcutYieldsToTheLabelOnAPanelTooNarrowForBoth(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $items = [new MenuItem('Save', 'Ctrl+Alt+Shift+Insert', static fn (): null => null)];

        $this->assertSame(' Save  ', AnsiUtils::stripAnsiCodes(MenuPanel::segments($items, 0, 7, $styles)[0]['segment']));
        $this->assertSame(' Save  Ctrl+Alt+Shift+Insert ', AnsiUtils::stripAnsiCodes(MenuPanel::segments($items, 0, 29, $styles)[0]['segment']), 'the control: where both fit, both show');
        $this->assertSame(\str_pad(' Save', length: 28), AnsiUtils::stripAnsiCodes(MenuPanel::segments($items, 0, 28, $styles)[0]['segment']), 'one column short, the key goes whole — never cut');
    }

    /** Each row decides alone: a short item keeps its key beside a long one that had to drop its own. */
    public function testOnlyTheRowsThatCannotFitDropTheirShortcut(): void
    {
        $items = [new MenuItem('Undo', 'Ctrl+Z', static fn (): null => null), new MenuItem('Undo Last Refactoring', 'Ctrl+Alt+Z', static fn (): null => null)];
        $rows = \array_map(static fn (array $row): string => AnsiUtils::stripAnsiCodes($row['segment']), MenuPanel::segments($items, 0, 26, new Styles(DarkTheme::definition())));

        $this->assertSame([' Undo              Ctrl+Z ', ' Undo Last Refactoring    '], $rows);
    }

    /** A mirrored row with no shortcut spends no column on the mark that keeps a label and its key apart. */
    public function testAMirroredRowWithNoShortcutCarriesNoMark(): void
    {
        $items = [new MenuItem('Welcome', '', static fn (): null => null)];
        $segment = MenuPanel::segments($items, 0, 12, new Styles(DarkTheme::definition()), BaseDirection::Rtl)[0]['segment'];

        $this->assertStringNotContainsString(Bidi::RTL_MARK, $segment);
        $this->assertSame(12, AnsiUtils::visibleWidth($segment));
    }

    public function testLongLabelWithNoShortcutTruncatesToExactlyTheInnerWidth(): void
    {
        $items = [new MenuItem(\str_repeat('x', times: 20), '', static fn (): null => null)];
        $segment = MenuPanel::segments($items, 0, 14, new Styles(DarkTheme::definition()))[0]['segment'];
        // inner = 12: 11 x's plus the truncation ellipsis exactly fill it, and
        // the row is the panel's 14 — a gap floored at 1 once made it 15, and
        // the frame's clip then cut it again, a second ellipsis.
        $this->assertSame(' xxxxxxxxxxx… ', AnsiUtils::stripAnsiCodes($segment));
    }

    public function testEmptyShortcutTailConsumesNoColumns(): void
    {
        // Every other fixture in this file gives its item a real shortcut;
        // none of the existing "exact width" assertions can catch a tail
        // that quietly widens from '' to a single space when there is none.
        $items = [new MenuItem('X', '', static fn (): null => null)];
        $width = MenuPanel::width($items);
        $segment = MenuPanel::segments($items, 0, $width, new Styles(DarkTheme::definition()))[0]['segment'];
        $this->assertSame($width, AnsiUtils::visibleWidth($segment), 'the empty-shortcut tail must not widen the row past panelWidth');
    }

    public function testSegmentLayoutIsExactlyEdgeLabelGapShortcutEdge(): void
    {
        $items = [new MenuItem('Ab', 'F2', static fn (): null => null)];
        $segment = MenuPanel::segments($items, 0, 10, new Styles(DarkTheme::definition()))[0]['segment'];
        // inner=8, labelMax=8-(2+2)=4 (label fits untouched), gap=8-2-2=4.
        $this->assertSame(' Ab    F2 ', AnsiUtils::stripAnsiCodes($segment));
    }

    /**
     * The shortcut column is a COLUMN, whatever a label ends with.
     *
     * The third site of the defect the settings form and the keybindings page
     * had: a label ending in a Latin run is strong left-to-right, the shortcut
     * beside it is too, and the two merge — so the shortcut is drawn inside the
     * label, which is torn around it. Four shipped action labels end that way.
     */
    public function testTheShortcutColumnHoldsWhateverTheLabelEndsWith(): void
    {
        $items = [
            new MenuItem('יציאה', 'Ctrl+Q', static fn (): null => null),
            new MenuItem('הצג TODO', 'Ctrl+Q', static fn (): null => null),
            new MenuItem('Git: הסחנה (stash)', 'Ctrl+Q', static fn (): null => null),
        ];

        $columns = [];
        foreach (MenuPanel::segments($items, 0, 30, new Styles(DarkTheme::definition()), BaseDirection::Rtl) as $row) {
            $mirrored = AnsiUtils::stripAnsiCodes(Bidi::reorder($row['segment'], BaseDirection::Rtl));
            $columns[] = \mb_strpos($mirrored, needle: 'Ctrl+Q', encoding: 'UTF-8');
        }

        $this->assertCount(1, \array_unique($columns), 'every shortcut is in the same column: '.\json_encode($columns));
    }

    /**
     * A segment is exactly the panel's width, in either direction.
     *
     * The mirrored row spends one of its gap columns on a mark rather than
     * adding a column beside it. Add instead of spend and the segment is one
     * column wider than the panel it is drawn into — an over-wide row, which in
     * this renderer garbles whatever is beneath it.
     *
     * @param int<1, max> $width
     */
    #[DataProvider('menuWidthProvider')]
    public function testASegmentIsExactlyThePanelWidth(int $width, BaseDirection $direction): void
    {
        $items = [
            new MenuItem('הצג TODO', 'Ctrl+Q', static fn (): null => null),
            new MenuItem('יציאה', 'Ctrl+Shift+Q', static fn (): null => null),
            new MenuItem('Show TODO', 'Ctrl+Q', static fn (): null => null),
        ];

        foreach (MenuPanel::segments($items, 0, $width, new Styles(DarkTheme::definition()), $direction) as $row) {
            $this->assertSame($width, AnsiUtils::visibleWidth($row['segment']), 'a row wider than its panel garbles the rows beneath it');
        }
    }

    /** @return iterable<string, array{int, BaseDirection}> */
    public static function menuWidthProvider(): iterable
    {
        foreach ([20, 24, 30, 44] as $width) {
            yield $width.' ltr' => [$width, BaseDirection::Ltr];
            yield $width.' rtl' => [$width, BaseDirection::Rtl];
        }
    }

    /** A left-to-right menu is untouched — the mark is spent only when mirrored. */
    public function testALeftToRightMenuCarriesNoMark(): void
    {
        $items = [new MenuItem('Show TODO', 'Ctrl+Q', static fn (): null => null)];

        $segment = MenuPanel::segments($items, 0, 30, new Styles(DarkTheme::definition()))[0]['segment'];

        $this->assertStringNotContainsString(Bidi::RTL_MARK, $segment, 'nothing to pin when nothing mirrors');
    }

    /**
     * Without colour, a disabled item and a separator must not look hovered.
     * Both borrowed the row-hover shade, which monochrome draws as an
     * underline — the mark it gives the row under the pointer.
     */
    public function testInMonochromeNeitherADisabledItemNorASeparatorLooksHovered(): void
    {
        $items = [new MenuItem('Save All', '', static fn (): null => null, false, static fn (): bool => false), MenuItem::separator()];
        $segments = MenuPanel::segments($items, 1, 20, new Styles(MonochromeTheme::definition()));

        foreach ($segments as $i => $segment) {
            $this->assertStringNotContainsString("\x1b[4m", $segment['segment'], "row {$i} is not underlined");
        }
        $this->assertStringContainsString("\x1b[9m", $segments[0]['segment'], 'the disabled label is struck through');
    }

    /**
     * A Hebrew item's access letter keeps its underline through the
     * right-to-left reorder, on that letter alone, and the row's shade is
     * re-armed around it rather than dropped.
     */
    public function testAnAccessLetterSurvivesTheMirroredReorder(): void
    {
        $row = "\x1b[48;5;236m ".MenuPanel::underlineLetter('קובץ חדש', 'קובץ חדש', 0).' Ctrl+N '."\x1b[49m";

        $mirrored = Bidi::reorder($row, BaseDirection::Rtl);

        $this->assertMatchesRegularExpression('/\x1b\[4mק/u', $mirrored, 'the underline is still on its letter');
        $this->assertSame(1, \preg_match_all('/\x1b\[4m[^\x1b\s]/u', $mirrored), 'and on no other');
        $this->assertStringEndsWith("\x1b[48;5;236m \x1b[0m", $mirrored, 'the shade runs to the row\'s end');
    }

    /**
     * A menu with a toggle is a menu of checkbox items: every row keeps a
     * two-column gutter, a toggle that is on shows ✓ in it (tinted off the
     * selection), and the panel grows by the gutter so no row is cut.
     */
    public function testAToggleShowsItsCheckInAGutterEveryRowKeeps(): void
    {
        $on = true;
        $items = [
            new MenuItem('Soft-Wrap', 'Alt+Z', null, isChecked: static function () use (&$on): bool {
                return $on;
            }),
            new MenuItem('Structure', '', null),
        ];
        $width = MenuPanel::width($items);
        $this->assertSame(MenuPanel::width([$items[1], new MenuItem('Soft-Wrap', 'Alt+Z', null)]) + 2, $width, 'the gutter widens the panel');

        $rows = MenuPanel::segments($items, 1, $width, Styles::dark());
        $plain = \array_map(static fn (array $row): string => AnsiUtils::stripAnsiCodes($row['segment']), $rows);

        $this->assertStringStartsWith(' ✓ Soft-Wrap', $plain[0]);
        $this->assertStringStartsWith('   Structure', $plain[1], 'the item that is no toggle keeps the gutter too');
        $this->assertStringContainsString(Styles::dark()->of(Role::TabAccent)->apply('✓'), $rows[0]['segment'], 'tinted off the selection');
        foreach ($rows as $row) {
            $this->assertSame($width, AnsiUtils::visibleWidth($row['segment']));
        }

        $on = false;
        $this->assertStringStartsWith('   Soft-Wrap', AnsiUtils::stripAnsiCodes(MenuPanel::segments($items, 1, $width, Styles::dark())[0]['segment']), 'off: the blank, nothing moves');
        $this->assertStringStartsWith(' Structure', AnsiUtils::stripAnsiCodes(MenuPanel::segments([$items[1]], 0, 20, Styles::dark())[0]['segment']), 'a menu with no toggle has no gutter');

        $on = true;
        $selected = MenuPanel::segments($items, 0, $width, Styles::dark())[0]['segment'];
        $this->assertStringNotContainsString(Styles::dark()->of(Role::TabAccent)->apply('✓'), $selected, 'the selected row keeps its own shade');
    }

    /** The window moves only as far as keeps the selection in it, and never past the last item. */
    public function testTheWindowFollowsTheSelectionNoFurther(): void
    {
        $this->assertSame(0, MenuPanel::window(0, 3, 10, 4), 'already in view: stays');
        $this->assertSame(2, MenuPanel::window(0, 5, 10, 4), 'below: the selection becomes the last row');
        $this->assertSame(1, MenuPanel::window(4, 1, 10, 4), 'above: the selection becomes the first row');
        $this->assertSame(6, MenuPanel::window(8, 9, 10, 4), 'never past the last item');
        $this->assertSame(0, MenuPanel::window(3, 5, 10, 20), 'room for all: no scroll');
    }
}
