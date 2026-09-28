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

    public function testRendersSectionsHeaderAndLanguages(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(24, 20)));

        $this->assertStringContainsString('General', $screen);
        $this->assertStringContainsString('Keybindings', $screen);
        $this->assertStringContainsString('Plugins', $screen);
        $this->assertStringContainsString('Languages', $screen);
        $this->assertStringContainsString('PHP', $screen);
        $this->assertStringContainsString('TypeScript', $screen);
        $this->assertStringContainsString('Twig', $screen);
        $this->assertSame('general', $this->widget()->selectedId(), 'opens on General');
    }

    public function testArrowNavigationSkipsTheHeaderAndFires(): void
    {
        $widget = $this->widget($selected);

        $widget->handleInput("\x1b[B"); // → Interface
        $this->assertSame('interface', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Highlighting
        $this->assertSame('highlighting', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Keybindings
        $this->assertSame('keybindings', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Plugins
        $widget->handleInput("\x1b[B"); // skips the Languages header → PHP
        $this->assertSame('lang:php', $widget->selectedId());
        $this->assertSame(['interface', 'highlighting', 'keybindings', 'plugins', 'lang:php'], $selected);

        $widget->handleInput("\x1b[A"); // back up, skipping the Languages header → Plugins
        $this->assertSame('plugins', $widget->selectedId());
    }

    public function testNavigationClampsAtBothEnds(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\x1b[A"); // already at the top
        $this->assertSame('general', $widget->selectedId());

        for ($i = 0; $i < 20; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $this->assertSame('lang:dotenv', $widget->selectedId(), 'stops at the last language');
    }

    public function testClickSelectsAndTheHeaderIsInert(): void
    {
        $widget = $this->widget($selected);
        $widget->render(new RenderContext(24, 20)); // establish scroll

        $widget->handleClick(3); // Keybindings (row 3, after the new Highlighting row)
        $this->assertSame('keybindings', $widget->selectedId());

        $widget->handleClick(5); // the Languages header → no-op
        $this->assertSame('keybindings', $widget->selectedId());
        $this->assertSame(['keybindings'], $selected, 'the header fires nothing');

        $widget->handleClick(6); // PHP
        $this->assertSame('lang:php', $widget->selectedId());
        $widget->handleClick(99); // dead space
        $this->assertSame('lang:php', $widget->selectedId());
    }

    public function testSetPluginEntriesInsertsIndentedSubItemsUnderPlugins(): void
    {
        $widget = $this->widget($selected);
        $widget->setPluginEntries(['phpstan', 'mago']);

        $screen = $this->plain($widget->render(new RenderContext(24, 24)));
        $this->assertStringContainsString('   phpstan', $screen, 'plugin sub-items indent like languages');
        $this->assertMatchesRegularExpression('/Plugins\s*\n\s{3}phpstan\s*\n\s{3}mago/', $screen, 'they sit right under the Plugins section');

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
        $this->assertTrue($widget->hoverRow(6)); // hover PHP
        $rendered = $widget->render(new RenderContext(24, 20));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[6], 'hovered language tinted');
        $this->assertTrue($widget->hoverRow(5), 'hovering the header clears the language tint');
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
        $widget->setWidth(5); // clamped up
        $this->assertSame(14, $widget->width());
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
        $this->assertSame(14, $widget->minWidth(), 'a bare install drags exactly as far as it always did');

        $widget->setPluginEntries(['git', 'symfony-lsp', 'local-history']);

        $this->assertSame(16, $widget->minWidth(), '   local-history, indented');
        $rows = $this->navRows($widget, $widget->minWidth());
        $this->assertContains('   local-history', $rows, 'and at that width it is spelled out, not elided');
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

        $this->assertSame(19, $widget->minWidth());
        $this->assertContains(' Raccourcis clavier', $this->navRows($widget, 19));
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

        $this->assertSame(24, $widget->minWidth());
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

        $this->assertSame('', \trim($rows[0]), 'nothing is clipped above yet, so the top edge stays blank');
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, \end($rows), 'and the bottom says there is more');
        $this->assertContains(' General', $rows, 'the list itself still starts where it did');
    }

    /** Scrolled into the middle, both edges are clipped and both say so. */
    public function testBothEdgesMarkWhenBothAreClipped(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['phpantom', 'mago', 'tempest', 'chroma', 'image-viewer', 'graph-viewer', 'local-history', 'git', 'symfony-lsp']);
        for ($i = 0; $i < 27; ++$i) {
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
        $this->assertSame(' General', $rows[0]);
        $this->assertSame(' '.$this->lastEntryLabel($widget), \rtrim((string) \end($rows)), 'and the last entry is drawn, not traded for a marker');
    }

    private function lastEntryLabel(SettingsNavWidget $widget): string
    {
        /** @var list<array{id: string|null, label: string, kind: mixed}> $entries */
        $entries = Probe::get($widget, 'entries');

        return '  '.$entries[\count($entries) - 1]['label'];
    }

    /** A list that fits keeps every row for entries — the edges cost nothing when there is nothing to say. */
    public function testAListThatFitsSpendsNoRowsOnMarkers(): void
    {
        $widget = $this->widget();

        $rows = $this->navRows($widget, 22, rows: 40);

        $this->assertSame(' General', $rows[0], 'the first entry is on the first row');
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

        $widget->render(new RenderContext(24, 20)); // establish scroll for the click's row mapping
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
        $this->assertSame('', \trim(AnsiUtils::stripAnsiCodes($rows[0])), 'the first line is the reserved indicator slot');

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
     * Clicking the ▼ indicator selects NOTHING.
     *
     * `indicatorRows` counts only the leading ▲, while an overflowing render
     * reserves the last row for ▼ as well — and `ListSelection::rowIndexAt()`
     * bounds by the total entry count rather than by what is visible. So the ▼
     * row mapped to `scroll + budget`: the first entry BELOW the window, which
     * is precisely the entry whose existence puts the ▼ there. Clicking "there
     * is more below" navigated to a page you could not see.
     *
     * The sibling case above covers the LEADING indicator, which was fixed; the
     * trailing one was never clicked by a test.
     */
    public function testClickingTheTrailingScrollIndicatorSelectsNothing(): void
    {
        $widget = $this->widget();
        $widget->setPluginEntries(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta']);
        $rows = $widget->render(new RenderContext(24, 6));
        $this->assertStringContainsString(Glyphs::SCROLL_DOWN, \end($rows), 'the list overflows, so the ▼ is drawn');
        $before = $widget->selectedId();

        $widget->handleClick(5); // the last row of a six-row pane: the ▼ itself

        $this->assertSame($before, $widget->selectedId(), 'the indicator is not an entry');
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
}
