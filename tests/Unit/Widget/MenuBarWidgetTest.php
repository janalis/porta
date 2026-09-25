<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class MenuBarWidgetTest extends TestCase
{
    use RendersExactWidth;

    use RendersWidget;

    /** @var list<string> */
    private array $ran = [];

    private function bar(): MenuBarWidget
    {
        $this->ran = [];
        $bar = new MenuBarWidget();
        $bar->setMenus([
            new Menu('File', [
                new MenuItem('Save', 'Ctrl+S', function (): void {
                    $this->ran[] = 'save';
                }),
                MenuItem::separator(),
                new MenuItem('Quit', 'Ctrl+Q', function (): void {
                    $this->ran[] = 'quit';
                }),
            ]),
            new Menu('Edit', [
                new MenuItem('Undo', 'Ctrl+Z', function (): void {
                    $this->ran[] = 'undo';
                }),
                new MenuItem('Redo', '', function (): void {
                    $this->ran[] = 'redo';
                }),
            ]),
        ]);

        return $bar;
    }

    public function testClosedRendersTitleStripOnly(): void
    {
        $rows = $this->bar()->render(new RenderContext(60, 24));

        $this->assertCount(1, $rows, 'only the title row when closed');
        $this->assertExactWidth($rows, 60);
        $plain = $this->plain($rows);
        $this->assertStringContainsString('File', $plain);
        $this->assertStringContainsString('Edit', $plain);
    }

    /**
     * A title that does not fit is dropped — the row is one line — but the row
     * has to say so. At 60 columns the German bar ends at Ansicht and used to
     * read as though Hilfe did not exist; the keyboard still reached it
     * (menu_left/menu_right cycle the whole list), so what was missing was any
     * sign that the row had been cut.
     */
    public function testANarrowRowSaysThatItDroppedTitles(): void
    {
        $bar = $this->bar();

        $wide = $this->plain($bar->render(new RenderContext(60, 24)));
        $this->assertStringNotContainsString(Glyphs::ELLIPSIS, $wide, 'nothing was cut, so nothing is marked');

        $rows = $bar->render(new RenderContext(9, 24));
        $narrow = $this->plain($rows);

        $this->assertExactWidth($rows, 9, 'the mark is inside the row, not beyond it');
        $this->assertStringContainsString('File', $narrow);
        $this->assertStringNotContainsString('Edit', $narrow);
        $this->assertStringEndsWith(Glyphs::ELLIPSIS, \rtrim($narrow));
    }

    /**
     * The mark costs a column, and that column may cost a title: a row filled
     * to its own edge has nowhere left to say it was cut.
     */
    /**
     * The mark costs a column, and that column comes out of the TITLES.
     *
     * Three six-column titles: at 12 exactly two fit, and because a third is
     * waiting the row also needs somewhere to say so — which only one of them
     * can now afford. Take the column from the row instead and the strip
     * renders 13 wide into a 12-column pane, which is the over-wide line that
     * garbles whatever is drawn beneath it.
     */
    public function testTheMarkTakesItsColumnFromTheTitlesNotFromTheRow(): void
    {
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('File', []), new Menu('Edit', []), new Menu('View', [])]);

        $rows = $bar->render(new RenderContext(12, 24));
        $plain = $this->plain($rows);

        $this->assertExactWidth($rows, 12, 'the mark is inside the row, never beyond it');
        $this->assertStringContainsString('File', $plain);
        $this->assertStringNotContainsString('Edit', $plain, 'the second title paid for the mark');
        $this->assertStringEndsWith(Glyphs::ELLIPSIS, \rtrim($plain));
        $this->assertSame(1, $bar->titleAtColumn(11), 'and the mark is a title: the first menu it hides');
        $this->assertSame(11, $bar->titleStart(1), 'whose dropdown hangs under the mark');
        $this->assertSame(11, $bar->titleStart(2), 'as does every later hidden menu\'s, not column 0');
    }

    /** The hidden menus are reached through the mark, which is lit while one of them is open. */
    public function testTheMarkIsLitWhileAHiddenMenuIsOpen(): void
    {
        $bar = new MenuBarWidget(Styles::dark());
        $bar->setMenus([new Menu('File', []), new Menu('Edit', []), new Menu('View', [])]);
        $closed = $bar->render(new RenderContext(12, 1))[0];

        $bar->open(2);

        $this->assertNotSame($closed, $bar->render(new RenderContext(12, 1))[0]);
        $this->assertStringEndsWith(Glyphs::ELLIPSIS, AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(12, 1))[0]));
    }

    /**
     * F10 arms the bar, as on a desktop: it has the keys and a title is lit,
     * but nothing drops until ↓, Enter, Space or a title's access letter.
     */
    public function testAnArmedBarDropsNothingUntilAsked(): void
    {
        $bar = $this->bar();
        $bar->arm();

        $this->assertTrue($bar->isOpen(), 'it has the keys');
        $this->assertFalse($bar->isDropdownShown(), 'and shows no menu');
        $this->assertSame([], $bar->dropdownSegments(40));
        $this->assertSame(0, $bar->panelWidth());
        $this->assertStringStartsWith(' File ', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(40, 1))[0]), 'lit, but without the dropdown\'s rails');

        $bar->handleInput("\x1b[C"); // →
        $this->assertSame(1, $bar->openIndex());
        $this->assertFalse($bar->isDropdownShown(), 'the arrows move the lit title only');

        $bar->handleInput("\x1b[B"); // ↓
        $this->assertTrue($bar->isDropdownShown());
        $this->assertSame(1, $bar->openIndex(), 'Edit dropped');
    }

    /** A bar with no menus has nothing to arm, and does not take the keys for nothing. */
    public function testAnEmptyBarDoesNotArm(): void
    {
        $bar = new MenuBarWidget();

        $bar->arm();

        $this->assertFalse($bar->isOpen());
    }

    /** Armed, a title's access letter drops that menu; Space drops the lit one. */
    public function testAnArmedBarOpensAMenuByItsLetter(): void
    {
        $bar = $this->bar();
        $bar->arm();
        $bar->handleInput('e');
        $this->assertTrue($bar->isDropdownShown());
        $this->assertSame(1, $bar->openIndex());

        $bar->close();
        $bar->arm(1);
        $bar->handleInput('z'); // no title's letter
        $this->assertFalse($bar->isDropdownShown(), 'an unknown key drops nothing');
        $bar->handleInput(' ');
        $this->assertTrue($bar->isDropdownShown());
        $this->assertSame(1, $bar->openIndex(), 'the lit one');
    }

    /** In an open menu, an item's access letter runs it. */
    public function testALetterRunsItsItem(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File: Save, ─, Quit

        $bar->handleInput('q');

        $this->assertSame(['quit'], $this->ran);
        $this->assertFalse($bar->isOpen(), 'and the menu closes, as a click would');
    }

    /** The letters are drawn: underlined in the titles, and in the items. */
    public function testTheAccessLettersAreUnderlined(): void
    {
        $bar = new MenuBarWidget(Styles::dark());
        $bar->setMenus([new Menu('File', [new MenuItem('Save', '', static fn (): null => null)])]);

        $this->assertStringContainsString("\x1b[4mF\x1b[24mile", $bar->render(new RenderContext(20, 1))[0]);
        $bar->open(0);
        $this->assertStringContainsString("\x1b[4mS\x1b[24mave", $bar->dropdownSegments($bar->panelWidth())[0]['segment']);
    }

    /** Nothing dropped, nothing marked — and every title still hit-tests. */
    public function testARowThatFitsExactlyIsNotMarked(): void
    {
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('File', []), new Menu('Edit', [])]);

        // ' File ' + ' Edit ' is exactly 12.
        $rows = $bar->render(new RenderContext(12, 24));

        $this->assertStringNotContainsString(Glyphs::ELLIPSIS, $this->plain($rows));
        $this->assertSame(1, $bar->titleAtColumn(6), 'the last title is whole and clickable');
    }

    public function testOpenStillRendersOnlyTheTitleRow(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $rows = $bar->render(new RenderContext(60, 24));

        // The dropdown floats over the widgets below (composited by the
        // coordinator), so opening a menu must NOT append rows here — otherwise
        // the layout reflows and content shifts down.
        $this->assertTrue($bar->isOpen());
        $this->assertCount(1, $rows, 'title row only, even when open');
        $this->assertExactWidth($rows, 60);
    }

    public function testDropdownSegmentsCarryLabelsShortcutsAndSeparators(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->render(new RenderContext(60, 24));

        $width = $bar->panelWidth();
        $segments = $bar->dropdownSegments($width);

        $this->assertCount(3, $segments, 'Save / separator / Quit');
        $this->assertFalse($segments[0]['separator']);
        $this->assertTrue($segments[1]['separator']);
        $this->assertFalse($segments[2]['separator']);
        foreach ($segments as $segment) {
            $this->assertSame($width, AnsiUtils::visibleWidth($segment['segment']), 'each segment is exactly panelWidth wide');
        }
        $plain = $this->plain(\array_column($segments, 'segment'));
        $this->assertStringContainsString('Save', $plain);
        $this->assertStringContainsString('Ctrl+S', $plain);
        $this->assertStringContainsString('Quit', $plain);
    }

    public function testDropdownSegmentsEmptyWhenClosed(): void
    {
        $this->assertSame([], $this->bar()->dropdownSegments(20), 'no segments while closed');
    }

    public function testGeometryAccessorsReflectOpenMenu(): void
    {
        $bar = $this->bar();
        $bar->render(new RenderContext(60, 24)); // populate titleLayout

        $this->assertSame(0, $bar->titleStart(0), 'File starts at column 0');
        $this->assertGreaterThan(0, $bar->titleStart(1), 'Edit starts after File');
        $this->assertSame(0, $bar->panelWidth(), 'no panel while closed');

        $bar->open(1);
        $this->assertSame(1, $bar->openIndex());
        $this->assertGreaterThanOrEqual(8, $bar->panelWidth(), 'open panel has a minimum width');
        $this->assertCount(2, $bar->dropdownSegments($bar->panelWidth()), 'Edit has Undo + Redo');
    }

    /** The open title's span, rails included, for the frame to open under — only while its dropdown shows. */
    public function testOpenTitleIsTheDroppedTitlesSpan(): void
    {
        $bar = $this->bar();
        $bar->render(new RenderContext(60, 24));

        $this->assertNull($bar->openTitle(), 'closed');
        $bar->arm(1);
        $this->assertNull($bar->openTitle(), 'armed: lit, but nothing hangs from it');

        $bar->open(0);
        $this->assertSame(['start' => 0, 'width' => 6], $bar->openTitle(), '┃File┃');
        $bar->handleInput("\x1b[C");
        $this->assertSame(['start' => 6, 'width' => 6], $bar->openTitle(), 'the arrow moved it before any frame drew it');
    }

    /** A menu dropped from the `…` has no rails for the frame to join. */
    public function testAMenuBehindTheMarkHasNoOpenTitle(): void
    {
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('File', [new MenuItem('Save', '', null)]), new Menu('Edit', [new MenuItem('Undo', '', null)])]);
        $bar->render(new RenderContext(9, 1));

        $bar->open(1);
        $this->assertNull($bar->openTitle());
        $bar->open(0);
        $this->assertSame(['start' => 0, 'width' => 6], $bar->openTitle(), 'the control: the title still drawn joins');
    }

    /** The open title's rails are the dropdown frame's, run up into the bar: they wear its tint. */
    public function testTheOpenTitlesRailsWearTheFramesTint(): void
    {
        $styles = Styles::dark();
        $bar = new MenuBarWidget($styles);
        $bar->setMenus([new Menu('File', [new MenuItem('Save', '', null)])]);
        $bar->open(0);

        $this->assertStringStartsWith(
            $styles->merge(Role::TabActiveBg, Role::TabAccent)->apply(Glyphs::FIELD_FOCUS_VERTICAL),
            $bar->render(new RenderContext(20, 1))[0],
        );
    }

    public function testOnNavigateFiresOnOpenAndMovement(): void
    {
        $bar = $this->bar();
        $hits = 0;
        $bar->onNavigate(static function () use (&$hits): void {
            ++$hits;
        });

        $bar->open(0); // fires
        $bar->handleInput("\x1b[B"); // down → fires
        $bar->handleInput("\x1b[C"); // right (re-open) → fires
        $bar->handleInput("\x1b[A"); // up → fires

        $this->assertSame(4, $hits, 'navigation notifies the coordinator to re-place the dropdown');
    }

    public function testDownSkipsSeparatorAndSelectRuns(): void
    {
        $bar = $this->bar();
        $bar->open(0); // selected = Save (0)
        $bar->handleInput("\x1b[B"); // down → skips separator → Quit (2)
        $bar->handleInput("\r"); // enter

        $this->assertSame(['quit'], $this->ran);
        $this->assertFalse($bar->isOpen(), 'closes after select');
    }

    public function testRightSwitchesMenuAndResetsSelection(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->handleInput("\x1b[C"); // right → Edit
        $bar->handleInput("\r"); // enter → Edit's first item (Undo)

        $this->assertSame(['undo'], $this->ran);
    }

    public function testSelectFiresOnCloseCallback(): void
    {
        $bar = $this->bar();
        $closed = false;
        $bar->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $bar->open(0);
        $bar->handleInput("\r"); // enter on Save

        $this->assertSame(['save'], $this->ran);
        $this->assertTrue($closed, 'onClose fired to return focus');
    }

    public function testEscapeClosesWithoutRunning(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->handleInput("\x1b"); // escape

        $this->assertFalse($bar->isOpen());
        $this->assertSame([], $this->ran);
    }

    public function testTitleAtColumnMapsClicks(): void
    {
        $bar = $this->bar();
        $bar->render(new RenderContext(60, 24)); // populate titleLayout

        $this->assertSame(0, $bar->titleAtColumn(3), 'inside File');
        $this->assertSame(1, $bar->titleAtColumn(8), 'inside Edit');
        $this->assertNull($bar->titleAtColumn(58), 'past the last title');
    }

    public function testRunItemIgnoresSeparatorRows(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->render(new RenderContext(60, 24));

        $bar->runItem(1); // the separator row → no-op run, still closes
        $this->assertSame([], $this->ran, 'clicking a separator runs nothing');
        $this->assertFalse($bar->isOpen());
    }

    public function testRunItemInvokesAndCloses(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->render(new RenderContext(60, 24));

        $bar->runItem(2); // Quit
        $this->assertSame(['quit'], $this->ran);
        $this->assertFalse($bar->isOpen());
    }

    public function testLeftSwitchesMenu(): void
    {
        $bar = $this->bar();
        $bar->open(1); // Edit
        $bar->handleInput("\x1b[D"); // left → File
        $bar->handleInput("\r"); // enter → File's first item (Save)

        $this->assertSame(['save'], $this->ran);
    }

    public function testUpWrapsToLastSelectable(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File, selected = Save (0)
        $bar->handleInput("\x1b[A"); // up → wraps past the separator to Quit (2)
        $bar->handleInput("\r");

        $this->assertSame(['quit'], $this->ran);
    }

    public function testAllSeparatorMenuNavigatesSafely(): void
    {
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('Empty', [MenuItem::separator(), MenuItem::separator()])]);
        $bar->open(0);
        $bar->handleInput("\x1b[B"); // down — no selectable item
        $bar->handleInput("\x1b[A"); // up
        $bar->handleInput("\r"); // enter — nothing to run
        $this->assertFalse($bar->isOpen(), 'select on a separator still closes');
    }

    public function testHoverTitleHighlightsAndClears(): void
    {
        $bar = $this->bar();
        $this->assertTrue($bar->hoverTitle(1), 'first hover changes state');
        $this->assertFalse($bar->hoverTitle(1), 'same title → no change');

        $row = $bar->render(new RenderContext(60, 24))[0];
        $this->assertStringContainsString("\x1b[48;5;240m", $row, 'hovered closed title uses the hover background');

        $this->assertTrue($bar->hoverTitle(null), 'clearing changes state');
        $row = $bar->render(new RenderContext(60, 24))[0];
        $this->assertStringNotContainsString("\x1b[48;5;240m", $row, 'no hover background once cleared');
    }

    public function testHoverTitleSuppressedWhileOpen(): void
    {
        $bar = $this->bar();
        $bar->hoverTitle(1);
        $bar->open(0); // the active style wins; the hover style is hidden while open
        $row = $bar->render(new RenderContext(60, 24))[0];
        $this->assertStringContainsString("\x1b[48;5;238m", $row, 'open title uses the tab-active background, like an active tab');
        $this->assertStringContainsString("\x1b[1m", $row, 'and is bold, matching TabStyle::Active');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $row, 'no longer the strong SelectedRow blue');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $row, 'hover style suppressed while a menu is open');
    }

    public function testHoverItemMovesSelectionSkippingSeparators(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File, selected = Save (0)
        $bar->render(new RenderContext(60, 24));

        $this->assertFalse($bar->hoverItem(0), 'already on Save → no change');
        $this->assertFalse($bar->hoverItem(1), 'separator row → no selection');
        $this->assertFalse($bar->hoverItem(99), 'out-of-range index → no change');
        $this->assertTrue($bar->hoverItem(2), 'hovering Quit selects it');

        // The dropdown now highlights Quit; Enter runs the hovered item.
        $bar->handleInput("\r");
        $this->assertSame(['quit'], $this->ran);
    }

    public function testHoverItemNoMenusIsNoOp(): void
    {
        $this->assertFalse(new MenuBarWidget()->hoverItem(0), 'no menus → nothing to hover');
    }

    public function testDisabledItemsAreNotSelectedRunOrHovered(): void
    {
        $ran = false;
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('File', [
            new MenuItem(
                'Save',
                'Ctrl+S',
                static function () use (&$ran): void {
                    $ran = true;
                },
                false,
                static fn (): bool => false,
            ),
            new MenuItem('Quit', 'Ctrl+Q', static fn (): null => null),
        ])]);

        $bar->open(0); // firstSelectable skips disabled Save → selects Quit
        $bar->render(new RenderContext(60, 24));

        $bar->runItem(0); // click the disabled Save → runs nothing
        $this->assertFalse($ran);
        $this->assertFalse($bar->isOpen(), 'clicking still closes the menu');

        $bar->open(0);
        $this->assertFalse($bar->hoverItem(0), 'cannot hover a disabled item');
    }

    public function testNarrowPaneDropsOverflowingTitles(): void
    {
        $bar = $this->bar(); // ' File ' (6) + ' Edit ' (6)
        // 8 columns fit File but not Edit, so Edit is dropped from the strip.
        $rows = $bar->render(new RenderContext(8, 1));

        $this->assertExactWidth($rows, 8);
        $plain = $this->plain($rows);
        $this->assertStringContainsString('File', $plain);
        $this->assertStringNotContainsString('Edit', $plain, 'the overflowing title is dropped');
        $this->assertNull($bar->titleAtColumn(6), 'a dropped title is not clickable');
    }

    public function testInputIgnoredWhenClosed(): void
    {
        $bar = $this->bar();
        $bar->handleInput("\r"); // no menu open
        $this->assertSame([], $this->ran);
        $this->assertFalse($bar->isOpen());
    }

    public function testOpenWithNoMenusIsNoOp(): void
    {
        $bar = new MenuBarWidget();
        $bar->open(0);
        $this->assertFalse($bar->isOpen());
        $this->assertExactWidth($bar->render(new RenderContext(30, 5)), 30);
    }

    public function testWheelMovesTheDropdownSelectionSkippingSeparators(): void
    {
        $bar = $this->bar();
        $bar->moveSelection(1); // closed: a no-op

        $bar->open(0);
        $bar->moveSelection(1); // Save → (separator skipped) → Quit
        $bar->handleInput("\r");
        $this->assertSame(['quit'], $this->ran, 'the wheel stepped past the separator to Quit');
    }

    public function testWheelStepHonoursTheDeltaMagnitudeNotJustItsSign(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File: Save selected
        // Two selectable steps in the {Save, Quit} cycle (separator skipped,
        // wrapping): Save → Quit → Save. A sign-only move would stop at Quit
        // after one step; the magnitude must carry both, landing back on Save.
        $bar->moveSelection(2);
        $bar->handleInput("\r");
        $this->assertSame(['save'], $this->ran, 'two steps wrap back to Save — the magnitude moved, not just the sign');
    }

    /**
     * Being told the writing direction marks the panel for redraw.
     *
     * The direction decides where the shortcut column goes, and it arrives from
     * a locale switch — a switch that changed the layout without asking for a
     * repaint would leave the old one on screen until something unrelated
     * happened to invalidate.
     */
    public function testBeingToldTheDirectionAsksForARedraw(): void
    {
        $widget = new MenuBarWidget();
        AttachedStyles::attach($widget);
        $before = $widget->getRenderRevision();

        $widget->setTextDirection(BaseDirection::Rtl);

        $this->assertNotSame($before, $widget->getRenderRevision());
    }

    /**
     * The open title reads as open with no colour at all: its padding becomes
     * the heavy rails of the dropdown under it, in the same width, and a
     * hovered title underlines its text, never its padding.
     */
    public function testTheOpenTitleIsAShapeAndTheHoveredOneAnUnderline(): void
    {
        $bar = $this->bar();
        $closed = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 1))[0]);
        $bar->open(0);
        $open = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 1))[0]);

        $this->assertStringStartsWith(' File ', $closed);
        $this->assertStringStartsWith('┃File┃', $open, 'the open title wears the dropdown\'s rails');
        $this->assertSame(\mb_strlen($closed), \mb_strlen($open), 'in the same width');

        $styled = new MenuBarWidget(Styles::dark());
        $styled->setMenus([new Menu('File', [])]);
        $styled->hoverTitle(0);
        $hovered = $styled->render(new RenderContext(80, 1))[0];
        $this->assertMatchesRegularExpression('/ (?:\x1b\[[0-9;]*m)*\x1b\[4mFile\x1b\[24m/', $hovered, 'the pointer underlines the title, not its padding');
    }
}
