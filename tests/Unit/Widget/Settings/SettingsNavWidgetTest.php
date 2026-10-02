<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSearchRequested;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Ui\ScreenDensity;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsNavWidgetTest extends TestCase
{
    use RendersWidget;

    /**
     * Tab lands on the nav, so Ctrl+F has to work from there too. A key that
     * works on one of a view's two panes and not the other is the kind people
     * stop trying.
     */
    public function testCtrlFAsksForTheSettingsPickerFromTheNavToo(): void
    {
        $events = new EventDispatcher();
        $fired = 0;
        $events->addListener(SettingsSearchRequested::class, static function () use (&$fired): void {
            ++$fired;
        });

        new SettingsNavWidget(new Translator('en'), events: $events)->handleInput("\x06");

        $this->assertSame(1, $fired);
    }

    private function widget(?array &$selected = null): SettingsNavWidget
    {
        $selected = [];
        $widget = new SettingsNavWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->onSelect(static function (string $id) use (&$selected): void {
            $selected[] = $id;
        });

        return $widget;
    }

    public function testRenderIsMemoizedUntilSomethingInvalidates(): void
    {
        // The flex-0 side pane renders twice per frame (intrinsic measure +
        // real pass); the memo serves both and misses after a selection move.
        $widget = $this->widget();

        $first = $widget->render(new RenderContext(24, 20));
        $this->assertSame($first, $widget->render(new RenderContext(24, 20)), 'a same-revision render serves the memo');

        $widget->handleInput("\x1b[B"); // move the selection: invalidates
        $second = $widget->render(new RenderContext(24, 20));
        $this->assertNotSame($first, $second, 'a selection move must miss the memo');
        $this->assertNotSame(\implode('', $first), \implode('', $second), 'the moved shade recolors the rows');
    }

    public function testRendersPagesAndFoldersAsATree(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(24, 20)));

        $this->assertMatchesRegularExpression('/^▌  General\s*$/mu', $screen, 'a page sits where a folder\'s name does, past the blank its triangle would take');
        $this->assertStringContainsString('Keybindings', $screen);
        $this->assertMatchesRegularExpression('/^ ▾ Plugins\s*$/mu', $screen, 'Plugins is a folder, the file tree\'s shape');
        $this->assertMatchesRegularExpression('/^ ▾ Languages\s*$/mu', $screen);
        $this->assertMatchesRegularExpression('/^ {4}PHP\s*$/mu', $screen, 'a language indents inside its folder');
        $this->assertStringContainsString('PHP', $screen);
        $this->assertStringContainsString('TypeScript', $screen);
        $this->assertStringContainsString('Twig', $screen);
        $this->assertSame('general', $this->widget()->selectedId(), 'opens on General');
    }

    public function testArrowNavigationWalksTheTreeAndFiresForPages(): void
    {
        $widget = $this->widget($selected);

        $widget->handleInput("\x1b[B"); // → Interface
        $this->assertSame('interface', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Highlighting
        $this->assertSame('highlighting', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Keybindings
        $this->assertSame('keybindings', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Command line
        $this->assertSame('commandLine', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Plugins
        $widget->handleInput("\x1b[B"); // → the Languages folder, which is no page
        $this->assertNull($widget->selectedId());
        $widget->handleInput("\x1b[B"); // → PHP
        $this->assertSame('lang:php', $widget->selectedId());
        $this->assertSame(['interface', 'highlighting', 'keybindings', 'commandLine', 'plugins', 'lang:php'], $selected, 'the folder fired nothing');

        $widget->handleInput("\x1b[A");
        $widget->handleInput("\x1b[A"); // back up through the folder → Plugins
        $this->assertSame('plugins', $widget->selectedId());
    }

    public function testNavigationClampsAtBothEnds(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\x1b[A"); // already at the top
        $this->assertSame('general', $widget->selectedId());

        for ($i = 0; $i < 30; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $this->assertSame('lang:dotenv', $widget->selectedId(), 'stops at the last language');
    }

    public function testClickSelectsAndAFolderClickFlipsIt(): void
    {
        $widget = $this->widget($selected);
        $widget->render(new RenderContext(24, 20)); // establish scroll

        $widget->handleClick(3); // Keybindings
        $this->assertSame('keybindings', $widget->selectedId());

        $widget->handleClick(6); // the Languages folder: selected, and closed
        $this->assertNull($widget->selectedId());
        $this->assertSame(['keybindings'], $selected, 'the folder is no page: it fires nothing');
        $this->assertMatchesRegularExpression('/^▌▸ Languages\s*$/mu', $this->plain($widget->render(new RenderContext(24, 20))));

        $widget->handleClick(7); // where PHP was: nothing is drawn there now
        $this->assertNull($widget->selectedId());

        $widget->handleClick(6); // open it again
        $widget->render(new RenderContext(24, 20));
        $widget->handleClick(7); // PHP
        $this->assertSame('lang:php', $widget->selectedId());
        $widget->handleClick(99); // dead space
        $this->assertSame('lang:php', $widget->selectedId());
    }

    public function testSetPluginEntriesInsertsIndentedSubItemsUnderPlugins(): void
    {
        $widget = $this->widget($selected);
        $widget->setPluginEntries(['phpstan', 'mago']);

        $screen = $this->plain($widget->render(new RenderContext(24, 24)));
        $this->assertStringContainsString('    phpstan', $screen, 'plugin pages indent like languages');
        $this->assertMatchesRegularExpression('/▾ Plugins\s*\n\s{4}phpstan\s*\n\s{4}mago/', $screen, 'they sit right inside the Plugins folder');

        $widget->setActive('plugin:mago');
        $this->assertSame('plugin:mago', $widget->selectedId());

        // A rebuild without mago falls the selection back to the Plugins section.
        $widget->setPluginEntries(['phpstan']);
        $this->assertSame('plugins', $widget->selectedId());

        // A rebuild that keeps the selected id preserves it.
        $widget->setActive('plugin:phpstan');
        $widget->setPluginEntries(['phpstan', 'late']);
        $this->assertSame('plugin:phpstan', $widget->selectedId());
    }

    public function testSetActiveHighlightsAnEntry(): void
    {
        $widget = $this->widget();
        $widget->setActive('lang:css');
        $this->assertSame('lang:css', $widget->selectedId());
        $widget->setActive('bogus'); // no-op
        $this->assertSame('lang:css', $widget->selectedId());
    }

    public function testFocusedSelectionHighlightAndHover(): void
    {
        $widget = $this->widget();
        $rendered = $widget->render(new RenderContext(24, 20));
        $this->assertStringContainsString("\x1b[48;5;31m", $rendered[0], 'General selected + focused');

        $widget->setFocused(false);
        $this->assertTrue($widget->hoverRow(7)); // hover PHP
        $rendered = $widget->render(new RenderContext(24, 20));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[7], 'hovered language tinted');
        $this->assertTrue($widget->hoverRow(6), 'the Languages folder takes the tint from PHP');
        $this->assertTrue($widget->clearHover());
        $this->assertFalse($widget->clearHover(), 'already cleared');
    }

    public function testTabAndEscapeAndInputHook(): void
    {
        $fired = [];
        $widget = $this->widget();
        $widget->onFocusNext(static function () use (&$fired): void {
            $fired[] = 'next';
        });
        $widget->onExit(static function () use (&$fired): void {
            $fired[] = 'exit';
        });
        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $this->assertSame(['next', 'exit'], $fired);

        $widget->onInput(static fn (string $d): bool => true);
        $widget->handleInput("\x1b[B"); // swallowed
        $this->assertSame('general', $widget->selectedId());
    }

    public function testWidthResizeExpandAndOverlay(): void
    {
        $widget = $this->widget();
        $widget->setWidth(30);
        $this->assertSame(30, $widget->width());
        $widget->setWidth(5); // clamped up to the widest row, `   Command line`
        $this->assertSame(15, $widget->width());
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROP\x1b[0m"]]);
        $this->assertStringContainsString('DROP', $this->painted($widget, new RenderContext(24, 20))[0]);
    }

    public function testDefaultWidthIsTwentyTwo(): void
    {
        $this->assertSame(22, $this->widget()->width());
    }

    /**
     * The floor has to fit the rows, because the floor is where the sidebar
     * lands: the layout controller applies minWidth OUTSIDE its ratio caps, so
     * a persisted `settings: 8` resolves to exactly this number on any terminal
     * narrower than 200 columns.
     *
     * 14 was right in July, against three sections and the language catalogue.
     * Plugins became sub-rows afterwards and the number did not move, so the
     * one place the nav is guaranteed to be drawn was the one place it could
     * not spell `local-history`.
     */
    public function testTheNarrowestWidthFitsEveryRowItDraws(): void
    {
        $widget = $this->widget();
        $this->assertSame(15, $widget->minWidth(), '`   Command line`, past the blank a folder\'s triangle takes');

        $widget->setPluginEntries(['git', 'symfony-lsp', 'local-history']);

        $this->assertSame(17, $widget->minWidth(), '    local-history, indented inside its folder');
        $rows = $this->navRows($widget, $widget->minWidth());
        $this->assertContains('    local-history', $rows, 'and at that width it is spelled out, not elided');
    }

    /**
     * Measured through the translator, not off the English source strings:
     * French spells Keybindings `Raccourcis clavier`, and a floor that fits
     * only the English screen cuts a row in six of the seven shipped locales.
     */
    public function testTheNarrowestWidthFollowsTheTranslatedRows(): void
    {
        $widget = new SettingsNavWidget(new Translator('fr'));
        AttachedStyles::attach($widget);

        $this->assertSame(21, $widget->minWidth());
        $this->assertContains('   Raccourcis clavier', $this->navRows($widget, 21));
    }

    /**
     * One long plugin name must not win against the terminal.
     *
     * LayoutController::clampSidebarWidth() applies minWidth outside its
     * max-ratio and neighbour caps, so an unbounded content-derived minimum
     * would be honoured past the point where the pane beside it still fits.
     * Past the ceiling, rows truncate exactly as they did before.
     */
    public function testAnAbsurdlyLongPluginNameCannotForceTheSidebarWider(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['a-plugin-with-a-truly-preposterous-name']);

        $this->assertSame(26, $widget->minWidth());
    }

    /** The nav's rows at $width x $rows, ANSI stripped and right-trimmed. @return list<string> */
    private function navRows(SettingsNavWidget $widget, int $width, int $rows = 40): array
    {
        $lines = [];
        foreach ($widget->render(new RenderContext($width, $rows)) as $line) {
            $lines[] = \rtrim(AnsiUtils::stripAnsiCodes($line));
        }

        return $lines;
    }

    /**
     * A finite, complete menu of destinations that scrolls has to say so.
     *
     * Five sections, one row per installed plugin, then the language
     * catalogue — 28 rows with nine plugins, and on a 24-row terminal the last
     * six were simply not there. Nothing distinguished that from a list which
     * ends where it appears to. The vertical tab strip has marked its overflow
     * this way since it was written; this was the only other finite list in
     * the IDE and it marked nothing.
     */
    public function testAListThatScrollsSaysThereIsMore(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['phpantom', 'mago', 'tempest', 'chroma', 'image-viewer', 'graph-viewer', 'local-history', 'git', 'symfony-lsp']);

        $rows = $this->navRows($widget, 22, rows: 22);

        $this->assertSame('▌  General', $rows[0], 'nothing is clipped above yet: no ▲ row, and no blank one standing in for it');
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, \end($rows), 'and the bottom says there is more');
    }

    /**
     * A drawn edge is a control: ▼ selects the first entry hidden below it,
     * ▲ the first hidden above, each exactly as the keys would — while an
     * edge left blank is no target at all.
     */
    public function testAClickOnADrawnEdgeRevealsTheEntryPastIt(): void
    {
        $widget = $this->widget($selected);
        $widget->setPluginEntries(['phpantom', 'mago', 'tempest', 'chroma', 'image-viewer', 'graph-viewer', 'local-history', 'git', 'symfony-lsp']);
        $before = $this->navRows($widget, 22, rows: 22);

        $widget->handleClick(21); // ▼
        $after = $this->navRows($widget, 22, rows: 22);
        $this->assertCount(1, $selected, 'the entry it revealed was selected, and fired');
        $this->assertStringContainsString(Glyphs::SCROLL_UP, $after[0], 'the window moved: the top is clipped now');
        $this->assertStringStartsWith('▌', $after[20], 'and the revealed entry is the last one drawn');
        $this->assertNotContains($after[20], $before, 'which was hidden before');

        $widget->handleClick(0); // ▲
        $this->navRows($widget, 22, rows: 22);
        $this->assertSame('interface', $widget->selectedId(), 'the nearest entry above the window');
    }

    /** Scrolled into the middle, both edges are clipped and both say so. */
    public function testBothEdgesMarkWhenBothAreClipped(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['phpantom', 'mago', 'tempest', 'chroma', 'image-viewer', 'graph-viewer', 'local-history', 'git', 'symfony-lsp']);
        for ($i = 0; $i < 40; ++$i) {
            $widget->handleInput("\x1b[B"); // to the last entry, dragging the window with it
        }

        $rows = $this->navRows($widget, 22, rows: 22);

        $this->assertStringContainsString(Glyphs::SCROLL_UP, $rows[0]);
        $this->assertSame('', \trim((string) \end($rows)), 'nothing below the last entry, so the bottom edge is blank');
    }

    /**
     * Exactly as many entries as rows is the boundary, and `>=` there would
     * reserve two edge rows to announce an overflow that does not exist —
     * dropping two entries that fit in order to say the list is longer than it
     * is. Infection found this one; no test came near equality.
     */
    public function testAListThatFitsToTheRowExactlyIsNotAnOverflow(): void
    {
        $widget = $this->widget();
        $count = \count(Probe::get($widget, 'entries'));

        $rows = $this->navRows($widget, 22, rows: $count);

        $this->assertStringNotContainsString(Glyphs::SCROLL_DOWN, \implode('', $rows));
        $this->assertSame('▌  General', $rows[0]);
        $this->assertSame(' '.$this->lastEntryLabel($widget), \rtrim((string) \end($rows)), 'and the last entry is drawn, not traded for a marker');
    }

    private function lastEntryLabel(SettingsNavWidget $widget): string
    {
        /** @var list<array{id: string|null, label: string, kind: mixed}> $entries */
        $entries = Probe::get($widget, 'entries');

        return '   '.$entries[\count($entries) - 1]['label'];
    }

    /** A list that fits keeps every row for entries — the edges cost nothing when there is nothing to say. */
    public function testAListThatFitsSpendsNoRowsOnMarkers(): void
    {
        $widget = $this->widget();

        $rows = $this->navRows($widget, 22, rows: 40);

        $this->assertSame('▌  General', $rows[0], 'the first entry is on the first row');
        $this->assertStringNotContainsString(Glyphs::SCROLL_DOWN, \implode('', $rows));
        $this->assertStringNotContainsString(Glyphs::SCROLL_UP, \implode('', $rows));
    }

    public function testMaxWidthIsSixty(): void
    {
        $widget = $this->widget();
        $widget->setWidth(60);
        $this->assertSame(60, $widget->width());
        $widget->setWidth(61); // clamped down
        $this->assertSame(60, $widget->width());
    }

    public function testARealSelectionMoveBumpsTheRenderRevision(): void
    {
        $widget = $this->widget();
        $before = $widget->getRenderRevision();
        $widget->setPluginEntries(['phpstan']);
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'setPluginEntries always invalidates');

        // Tall enough that nothing overflows: an overflowing list reserves a
        // row for its ▲, and this is about the click, not the scrolling.
        $widget->render(new RenderContext(24, 24)); // establish scroll for the click's row mapping
        $before = $widget->getRenderRevision();
        $widget->handleClick(3); // 'general' -> 'keybindings'
        $this->assertSame('keybindings', $widget->selectedId());
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'a real click-driven move invalidates');

        $before = $widget->getRenderRevision();
        $widget->setActive('general'); // a different, real move
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'setActive on a real move invalidates');
    }

    public function testHoveringARowInvalidatesButAReRequestOfTheSameRowDoesNot(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(24, 20));

        $before = $widget->getRenderRevision();
        $this->assertTrue($widget->hoverRow(1));
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'a real hover change invalidates');

        $before = $widget->getRenderRevision();
        $this->assertFalse($widget->hoverRow(1), 'still the same row → no change');
        $this->assertSame($before, $widget->getRenderRevision());
    }

    public function testMoveWithoutAnOnSelectCallbackDoesNotCrash(): void
    {
        $widget = new SettingsNavWidget(new Translator('en'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        // deliberately no onSelect(...) registered

        $widget->handleInput("\x1b[B"); // move_down: moveTo() must not call a null callback
        $this->assertSame('interface', $widget->selectedId());
    }

    public function testRenderRowCountRespectsTheContextRowsIncludingTheSingleRowFloor(): void
    {
        $widget = $this->widget();
        $this->assertCount(1, $widget->render(new RenderContext(24, 1)), 'exactly 1 context row renders exactly 1 line');
        $this->assertCount(12, $widget->render(new RenderContext(24, 0)), 'a non-positive context row count falls back to 12');
    }

    /**
     * A click lands on the row it is pointing at, even when the list scrolls.
     *
     * `render()` reserves its first line for the ▲ indicator as soon as the
     * entries outnumber the pane — and reserves it even when nothing is
     * scrolled yet, so the line is blank rather than absent. The hit test did
     * not know, and counted from the top of the PANE instead of the top of the
     * list: every click came out one row low, so choosing a category selected
     * the one below it. Nothing to do with the locale.
     *
     * Asserted through the selection shade, which is the widget's own answer to
     * "which row did that pick", rather than by mapping a label back to an id.
     */
    public function testAClickSelectsTheRowItPointsAtWhenTheListScrolls(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta']);
        $rows = $widget->render(new RenderContext(24, 6));
        $this->assertSame('▌  General', \trim(AnsiUtils::stripAnsiCodes($rows[0])), 'at the top, the first line is the first entry: no ▲ slot yet');

        $widget->handleClick(1);

        $shaded = $widget->render(new RenderContext(24, 6));
        $this->assertStringContainsString("\x1b[48;5;31m", $shaded[1], 'the clicked line is the selected one');
    }

    /** Hovering takes the same path, so it was wrong in the same way. */
    public function testHoverTintsTheRowItPointsAtWhenTheListScrolls(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta']);
        $widget->render(new RenderContext(24, 6));

        $widget->hoverRow(2);

        $rows = $widget->render(new RenderContext(24, 6));
        $this->assertStringContainsString("\x1b[48;5;236m", $rows[2], 'the tinted line is the one under the pointer');
    }

    /**
     * Clicking the ▼ indicator is not a click on an entry.
     *
     * `indicatorRows` counts only the leading ▲, while an overflowing render
     * reserves the last row for ▼ as well — and `ListSelection::rowIndexAt()`
     * bounds by the total entry count rather than by what is visible. So the ▼
     * row mapped to `scroll + budget` and selected it as though it were drawn
     * there, leaving the window where it was. The ▼ is a control now: it
     * selects that same entry on purpose, and the window follows it, so the
     * selection is never on a row you cannot see.
     */
    public function testClickingTheTrailingScrollIndicatorScrollsToWhatItSelects(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta']);
        $rows = $widget->render(new RenderContext(24, 6));
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, \end($rows), 'the list overflows, so the ▼ is drawn');

        $widget->handleClick(5); // the last row of a six-row pane: the ▼ itself

        $rows = $this->navRows($widget, 24, rows: 6);
        $this->assertSame('plugins', $widget->selectedId(), 'the entry just past the window');
        $this->assertStringStartsWith('▌', $rows[4], 'drawn: the window followed it');
        $this->assertStringContainsString('Plugins', $rows[4]);
    }

    /** And hover takes the same path, so it was wrong in the same way. */
    public function testHoveringTheTrailingScrollIndicatorTintsNothing(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta']);
        $widget->render(new RenderContext(24, 6));

        $widget->hoverRow(5);

        $rows = $widget->render(new RenderContext(24, 6));
        $this->assertStringNotContainsString("\x1b[48;5;236m", \implode('', $rows), 'nothing was tinted by hovering the indicator');
    }

    /**
     * A side panel draws at its own width however wide the layout is, and a
     * dropdown over it composites at that width too — at the layout's, the row
     * padded out past the panel and starved the pane beside it.
     */
    public function testAnOverlayCompositesAtThePanelWidthNotTheLayouts(): void
    {
        $widget = new SettingsNavWidget(new Translator('en'));
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 200, 'segment' => \str_repeat('M', times: 200)]]);

        $row = $this->painted($widget, new RenderContext(300, 5))[0];
        $this->assertSame($widget->width(), AnsiUtils::visibleWidth($row));
        $this->assertStringContainsString('MMM', $row);
    }

    /** A nav on a screen of $terminalRows, the density every chrome row asks. */
    private function navOnScreen(int $terminalRows, ?array &$selected = null): SettingsNavWidget
    {
        $selected = [];
        $density = new ScreenDensity();
        $density->observe($terminalRows);
        $widget = new SettingsNavWidget(new Translator('en'), density: $density);
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        $widget->onSelect(static function (string $id) use (&$selected): void {
            $selected[] = $id;
        });

        return $widget;
    }

    /**
     * A roomy screen runs a rule between every two rows; the rules are
     * nobody's, so a click or the pointer on one picks nothing.
     */
    public function testARoomyScreenRulesBetweenEveryRow(): void
    {
        $widget = $this->navOnScreen(40, $selected);

        $rows = $this->navRows($widget, 20, rows: 60);

        $this->assertSame(['▌  General', \str_repeat('─', times: 20), '   Interface', \str_repeat('─', times: 20)], \array_slice($rows, offset: 0, length: 4));
        $this->assertSame('    Dotenv', \rtrim($rows[2 * (\count(Probe::get($widget, 'entries')) - 1)]), 'the last row');
        $this->assertSame('', $rows[(2 * \count(Probe::get($widget, 'entries'))) - 1], 'and no rule after it');

        $widget->handleClick(1);
        $this->assertSame([], $selected, 'a rule is no row');
        $this->assertFalse($widget->hoverRow(1), 'and tints nothing');
        $widget->handleClick(2);
        $this->assertSame(['interface'], $selected);
    }

    public function testACompactScreenGivesTheRulesTheirRowsBack(): void
    {
        $widget = $this->navOnScreen(24);

        $this->assertSame(['▌  General', '   Interface'], \array_slice($this->navRows($widget, 20, rows: 30), offset: 0, length: 2));
    }

    /** Each row costs two lines with its rule, so the window counts rows, not lines. */
    public function testARuledListThatOverflowsKeepsTheSelectionInView(): void
    {
        $widget = $this->navOnScreen(40);
        $widget->setActive('lang:twig');

        $rows = $this->navRows($widget, 20, rows: 12);

        $this->assertStringContainsString(Glyphs::SCROLL_UP, $rows[0]);
        $this->assertContains('▌   Twig', $rows, 'the selection is drawn');
        $this->assertCount(12, $rows);
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, \end($rows), 'Dotenv is still below');
    }

    public function testADensityFlipRepaints(): void
    {
        $density = new ScreenDensity();
        $widget = new SettingsNavWidget(new Translator('en'), density: $density);
        $widget->render(new RenderContext(20, 30));
        $widget->beforeRender();
        $before = $widget->getRenderRevision();

        $density->observe(24);
        $widget->beforeRender();

        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'the cached pane would keep its rules otherwise');
    }

    /** The file tree's keys: ← closes or steps out, → opens, Enter flips. */
    public function testAFolderOpensAndClosesByTheTreesKeys(): void
    {
        $widget = $this->widget($selected);
        $widget->setPluginEntries(['git']);
        $widget->setActive('plugin:git');

        $widget->handleInput("\x1b[D"); // ← from inside: out onto the folder
        $this->assertSame('plugins', $widget->selectedId());
        $this->assertSame(['plugins'], $selected, 'Plugins is a page: stepping onto it shows it');

        $widget->handleInput("\x1b[D"); // ← on the open folder: it closes
        $this->assertDoesNotMatchRegularExpression('/git/', $this->plain($widget->render(new RenderContext(24, 30))));
        $this->assertMatchesRegularExpression('/^▌▸ Plugins\s*$/mu', $this->plain($widget->render(new RenderContext(24, 30))));

        $widget->handleInput("\x1b[C"); // → opens it
        $this->assertStringContainsString('git', $this->plain($widget->render(new RenderContext(24, 30))));
        $widget->handleInput("\r"); // Enter flips it shut
        $this->assertStringNotContainsString('git', $this->plain($widget->render(new RenderContext(24, 30))));
        $widget->handleInput(' '); // and Space open again
        $this->assertStringContainsString('git', $this->plain($widget->render(new RenderContext(24, 30))));

        $widget->handleInput("\x1b[B"); // → git
        $widget->handleInput("\x1b[C"); // → on a page does nothing
        $widget->handleInput("\r");
        $this->assertSame('plugin:git', $widget->selectedId());
        $widget->setActive('general');
        $widget->handleInput("\x1b[D"); // ← on a top-level page does nothing
        $this->assertSame('general', $widget->selectedId());
    }

    /** Closing the folder the cursor is in leaves it on the folder, never on a hidden row. */
    public function testClosingAFolderOverTheCursorLeavesItOnTheFolder(): void
    {
        $widget = $this->widget();
        $widget->setActive('lang:php');
        $widget->render(new RenderContext(24, 30));

        $widget->handleClick(6); // the Languages folder: selected and closed

        $this->assertNull($widget->selectedId());
        $widget->handleInput("\x1b[B"); // nothing below a closed last folder
        $this->assertNull($widget->selectedId());
    }

    /** A page reached from the search or the header is never left hidden in a closed folder. */
    public function testSetActiveOpensTheFolderItsPageIsIn(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(24, 30));
        $widget->handleClick(6); // close Languages

        $widget->setActive('lang:css');

        $this->assertSame('lang:css', $widget->selectedId());
        $this->assertContains('▌   CSS', $this->navRows($widget, 24, rows: 30));
    }

    public function testATrailNamesThePageAndWhereItLeads(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['git']);

        $this->assertSame([['label' => 'Settings', 'id' => 'general'], ['label' => 'Interface', 'id' => 'interface']], $widget->trailFor('interface'));
        $this->assertSame([['label' => 'Settings', 'id' => 'general'], ['label' => 'Plugins', 'id' => 'plugins'], ['label' => 'git', 'id' => 'plugin:git']], $widget->trailFor('plugin:git'));
        $this->assertSame(
            [['label' => 'Settings', 'id' => 'general'], ['label' => 'Languages', 'id' => null], ['label' => 'PHP', 'id' => 'lang:php']],
            $widget->trailFor('lang:php'),
            'Languages is no page to go to',
        );
        $this->assertSame([['label' => 'Settings', 'id' => 'general']], $widget->trailFor('bogus'));
        $this->assertSame('Paramètres', new SettingsNavWidget(new Translator('fr'))->trailFor('general')[0]['label'], 'translated');
    }

    /** A folder wears the tree's accent, except where the selection shades it. */
    public function testAFolderWearsTheTreesAccentUntilSelected(): void
    {
        $widget = $this->widget();
        $widget->setFocused(false);
        $rows = $widget->render(new RenderContext(24, 30));

        $this->assertStringContainsString("\x1b[38;5;110m", $rows[5], 'Plugins, at rest');
        $this->assertStringNotContainsString("\x1b[38;5;110m", $rows[1], 'a page does not');
        $this->assertStringStartsWith('▎', AnsiUtils::stripAnsiCodes($rows[0]), 'the cursor of a nav without the keys');

        $widget->setActive('plugins');
        $this->assertStringNotContainsString("\x1b[38;5;110m", $widget->render(new RenderContext(24, 30))[5], 'selected, the selection shades it');
    }

    /** A key that changes nothing repaints nothing. */
    public function testAFolderKeyThatChangesNothingDoesNotRepaint(): void
    {
        $widget = $this->widget();
        $widget->setActive('plugins');
        $before = $widget->getRenderRevision();

        $widget->handleInput("\x1b[C"); // → on an open folder
        $this->assertSame($before, $widget->getRenderRevision());

        $widget->setActive('general');
        $before = $widget->getRenderRevision();
        $widget->handleInput("\r"); // Enter on a page
        $widget->handleInput("\x1b[C");
        $this->assertSame($before, $widget->getRenderRevision());
    }

    /** n rows ruled take 2n − 1 lines: exactly that many is no overflow, one fewer is. */
    public function testARuledListOverflowsExactlyWhenItsLinesDoNotFit(): void
    {
        $widget = $this->navOnScreen(40);
        $lines = (2 * \count(Probe::get($widget, 'entries'))) - 1;

        $fits = $this->navRows($widget, 20, rows: $lines);
        $this->assertStringNotContainsString(Glyphs::SCROLL_DOWN, \implode('', $fits));
        $this->assertSame('    Dotenv', \end($fits));

        $over = $this->navRows($widget, 20, rows: $lines - 1);
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, (string) \end($over));
    }

    /** The window counts rows: on twelve lines, five rows and their rules between ▲ and ▼. */
    public function testARuledWindowHoldsAsManyRowsAsItsLinesAllow(): void
    {
        $widget = $this->navOnScreen(40);
        $widget->setActive('lang:twig');

        $rows = $this->navRows($widget, 20, rows: 12);

        $this->assertSame('▌   Twig', $rows[9], 'the selection, the fifth row in the window');
        $this->assertSame('    SCSS', $rows[1], 'the first');
        $this->assertSame(\str_repeat('─', times: 20), $rows[10], 'a rule under it');
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, $rows[11]);
    }
}
