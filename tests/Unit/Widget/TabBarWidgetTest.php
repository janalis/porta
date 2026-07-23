<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class TabBarWidgetTest extends TestCase
{
    /**
     * The glyph occupying display column $col — which is not mb_substr($col),
     * because a wide glyph advances the column by two and the char index by one.
     */
    private static function glyphAtColumn(string $plain, int $col): string
    {
        $at = 0;
        foreach (mb_str_split($plain) as $glyph) {
            if ($at === $col) {
                return $glyph;
            }
            $at += mb_strwidth($glyph);
        }

        return '';
    }

    public function testEmptyStateRendersAToolbarStyledBlankLine(): void
    {
        $bar = new TabBarWidget();
        $bar->beforeRender();

        // The strip keeps its Toolbar base even with no tabs, so closing the
        // last one never drops the bar to the terminal default.
        $line = $bar->render(new RenderContext(40, 1))[0];
        $this->assertSame(str_repeat(' ', times: 40), AnsiUtils::stripAnsiCodes($line));
        $this->assertNotSame(str_repeat(' ', times: 40), $line, 'the empty strip is styled, not bare');
    }

    public function testWithoutProviderRendersAToolbarStyledBlankLine(): void
    {
        $bar = new TabBarWidget();

        $line = $bar->render(new RenderContext(20, 1))[0];
        $this->assertSame(str_repeat(' ', times: 20), AnsiUtils::stripAnsiCodes($line));
    }

    public function testRendersTabsWithDirtyDotAndActiveStyle(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => false],
            ['name' => 'b.php', 'dirty' => true, 'active' => true],
        ]);
        $bar->beforeRender();

        $line = $bar->render(new RenderContext(60, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);

        $this->assertSame(60, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('a.php', $plain);
        $this->assertStringContainsString('b.php ●', $plain);
        $this->assertStringContainsString("\x1b[48;5;238m", $line, 'active tab background');
    }

    public function testBeforeRenderInvalidatesOnlyOnChange(): void
    {
        $tabs = [['name' => 'a.php', 'dirty' => false, 'active' => true]];
        $bar = new TabBarWidget();
        $bar->setProvider(static function () use (&$tabs) {
            return $tabs;
        });

        $bar->beforeRender();
        $revision = $bar->getRenderRevision();
        $bar->beforeRender();
        $this->assertSame($revision, $bar->getRenderRevision(), 'unchanged tabs do not invalidate');

        $tabs[0]['dirty'] = true;
        $bar->beforeRender();
        $this->assertGreaterThan($revision, $bar->getRenderRevision());
    }

    public function testTrailingTabsDroppedWhenActiveIsFirst(): void
    {
        $tabs = [];
        for ($i = 0; $i < 12; ++$i) {
            $tabs[] = ['name' => \sprintf('very-long-filename-%02d.php', $i), 'dirty' => false, 'active' => 0 === $i];
        }
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => $tabs);
        $bar->beforeRender();

        $line = $bar->render(new RenderContext(60, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);

        $this->assertStringContainsString('very-long-filename-00.php', $plain);
        $this->assertStringNotContainsString('very-long-filename-11.php', $plain, 'tabs past the width are dropped');
    }

    public function testTabIndexAtColumn(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => false, 'active' => false],
        ]);
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));

        $this->assertSame(0, $bar->tabIndexAt(0, 1), 'first tab region');
        $this->assertNotSame(0, $bar->tabIndexAt(0, 12), 'second tab region');
        $this->assertNull($bar->tabIndexAt(0, 500), 'click past every tab misses');
        $this->assertSame(0, $bar->tabIndexAt(3, 1), 'the horizontal strip ignores the row (drags wander off it)');
    }

    public function testTabAnchorMirrorsTheRenderedLayout(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => false, 'active' => false],
        ]);
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));

        $this->assertSame(['row' => 0, 'col' => 0], $bar->tabAnchor(0), 'first tab starts at the strip edge');
        $second = $bar->tabAnchor(1);
        $this->assertNotNull($second);
        $this->assertSame(1, $bar->tabIndexAt($second['row'], $second['col']), 'anchor cell lands inside its own tab');
        $this->assertNull($bar->tabAnchor(99), 'a tab outside the layout has no anchor');
    }

    public function testRendersACloseIconOnEveryTab(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => false],
            ['name' => 'b.php', 'dirty' => true, 'active' => true],
        ]);
        $bar->beforeRender();
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(60, 1))[0]);

        $this->assertSame(2, substr_count($plain, needle: '×'), 'a close × per tab');
        $this->assertStringContainsString('a.php ×', $plain, 'clean tab: name then ×');
        $this->assertStringContainsString('b.php ●×', $plain, 'dirty tab: name, dirty dot, then ×');
    }

    /**
     * The strip's click zones are columns into the line it painted, so the two
     * must be measured the same way. A wide (CJK) name is where a byte count
     * would diverge from a display-column count: the × would then sit somewhere
     * other than where closeIndexAt() says it does, and every zone after it
     * would be off by the difference.
     */
    public function testCloseZonesLandOnTheRenderedCloseGlyphForWideNames(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => '日本語.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => true, 'active' => false],
        ]);
        $bar->beforeRender();
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(60, 1))[0]);

        $this->assertSame(60, mb_strwidth($plain), 'the strip fills its row exactly');
        foreach ([0, 1] as $index) {
            $col = 0;
            while ($col < 60 && $bar->closeIndexAt(0, $col) !== $index) {
                ++$col;
            }
            $this->assertLessThan(60, $col, "tab {$index} has a close zone");
            $this->assertSame('×', self::glyphAtColumn($plain, $col), "tab {$index}'s close zone lands on its ×");
        }
    }

    public function testCloseIndexAtColumn(): void
    {
        // Tab a ' a.php × ' (cols 0-9): × at col 7. Tab b ' b.php ●× ' (starts col
        // 10): dirty ● at col 17, × at col 18.
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => true, 'active' => false],
        ]);
        $bar->beforeRender();
        $bar->render(new RenderContext(40, 1));

        $this->assertSame(0, $bar->closeIndexAt(0, 7), "first tab's × closes tab 0");
        $this->assertSame(1, $bar->closeIndexAt(0, 18), "second tab's × closes tab 1");
        $this->assertNull($bar->closeIndexAt(0, 2), 'the tab body is not a close zone');
        $this->assertNull($bar->closeIndexAt(0, 17), 'the dirty dot is not the close zone');
        $this->assertNull($bar->closeIndexAt(0, 500), 'past every tab');
        // The body hit-test still resolves those columns to their tab.
        $this->assertSame(1, $bar->tabIndexAt(0, 17), 'the dirty dot is still tab-1 body');
    }

    public function testMenuOverlayCompositesOverTabsKeepingWidth(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
        ]);
        $bar->beforeRender();
        // Only local row 0 lands on the single-row tab strip; row 1 is ignored.
        $bar->setMenuOverlay([
            ['row' => 0, 'col' => 2, 'width' => 8, 'segment' => "\x1b[7m Cut    \x1b[0m"],
            ['row' => 1, 'col' => 2, 'width' => 8, 'segment' => 'ignored!'],
        ]);

        $line = $bar->render(new RenderContext(40, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);

        $this->assertSame(40, AnsiUtils::visibleWidth($line), 'overlay keeps the row exactly one screen wide');
        $this->assertStringContainsString('Cut', $plain, 'dropdown segment composited over the tab');
        $this->assertStringNotContainsString('ignored', $plain, 'off-row spans are dropped');
    }

    public function testMenuOverlayOnEmptyStripStillComposites(): void
    {
        $bar = new TabBarWidget();
        $bar->beforeRender();
        $bar->setMenuOverlay([
            ['row' => 0, 'col' => 0, 'width' => 6, 'segment' => "\x1b[7m Open \x1b[0m"],
        ]);

        $line = $bar->render(new RenderContext(30, 1))[0];
        $this->assertSame(30, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('Open', AnsiUtils::stripAnsiCodes($line));
    }

    public function testOverflowKeepsActiveTabVisible(): void
    {
        $tabs = [];
        for ($i = 0; $i < 12; ++$i) {
            $tabs[] = ['name' => \sprintf('very-long-filename-%02d.php', $i), 'dirty' => false, 'active' => 11 === $i];
        }
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => $tabs);
        $bar->beforeRender();

        $line = $bar->render(new RenderContext(60, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);

        $this->assertSame(60, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('very-long-filename-11.php', $plain, 'active (last) tab visible after dropping leading tabs');
        $this->assertStringNotContainsString('very-long-filename-00.php', $plain);
    }

    // --- vertical (left/right) list mode ---------------------------------------

    /** @param list<array{name: string, dirty: bool, active: bool}> $tabs */
    private function verticalBar(array $tabs): TabBarWidget
    {
        $bar = new TabBarWidget();
        $bar->setPosition(TabBarPosition::Left);
        $bar->setProvider(static fn () => $tabs);
        $bar->beforeRender();

        return $bar;
    }

    public function testSetPositionInvalidatesOnlyOnChange(): void
    {
        $bar = new TabBarWidget();
        $this->assertSame(TabBarPosition::Top, $bar->position());

        $revision = $bar->getRenderRevision();
        $bar->setPosition(TabBarPosition::Top);
        $this->assertSame($revision, $bar->getRenderRevision(), 'same position does not invalidate');

        $bar->setPosition(TabBarPosition::Right);
        $this->assertSame(TabBarPosition::Right, $bar->position());
        $this->assertGreaterThan($revision, $bar->getRenderRevision());
    }

    public function testPositionHelpersDescribeEveryCase(): void
    {
        $this->assertFalse(TabBarPosition::Top->isSide());
        $this->assertFalse(TabBarPosition::Bottom->isSide());
        $this->assertTrue(TabBarPosition::Left->isSide());
        $this->assertTrue(TabBarPosition::Right->isSide());
        $this->assertTrue(TabBarPosition::Top->tabsFirst());
        $this->assertFalse(TabBarPosition::Bottom->tabsFirst());
        $this->assertTrue(TabBarPosition::Left->tabsFirst());
        $this->assertFalse(TabBarPosition::Right->tabsFirst());
    }

    public function testVerticalRendersOneTabPerRowWithMarkersAndPadding(): void
    {
        $bar = $this->verticalBar([
            ['name' => 'a.php', 'dirty' => false, 'active' => false],
            ['name' => 'b.php', 'dirty' => true, 'active' => true],
        ]);

        $lines = $bar->render(new RenderContext(80, 6));

        $this->assertCount(6, $lines, 'blank rows pad the list to the pane height');
        foreach ($lines as $line) {
            $this->assertSame(TabBarWidget::VERTICAL_WIDTH, AnsiUtils::visibleWidth($line), 'every row is exactly the fixed list width');
        }
        $this->assertStringContainsString('a.php', AnsiUtils::stripAnsiCodes($lines[0]));
        $row1 = AnsiUtils::stripAnsiCodes($lines[1]);
        $this->assertStringContainsString('b.php', $row1);
        $this->assertStringContainsString('●×', $row1, 'dirty dot right-aligned beside the close ×');
        $this->assertStringContainsString(' × ', AnsiUtils::stripAnsiCodes($lines[0]), 'clean tab keeps a blank marker cell before its ×');
        $this->assertStringContainsString("\x1b[48;5;238m", $lines[1], 'active row background');
        $this->assertSame(str_repeat(' ', TabBarWidget::VERTICAL_WIDTH), $lines[2], 'filler row below the last tab');
    }

    public function testVerticalTruncatesLongNamesWithAnEllipsis(): void
    {
        $bar = $this->verticalBar([
            ['name' => 'a-very-long-file-name-that-cannot-fit.php', 'dirty' => false, 'active' => true],
        ]);

        $line = $bar->render(new RenderContext(80, 3))[0];

        $this->assertSame(TabBarWidget::VERTICAL_WIDTH, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($line));
    }

    public function testVerticalEmptyAndProviderlessStatesRenderABlankColumn(): void
    {
        $empty = $this->verticalBar([]);
        $this->assertSame(array_fill(0, count: 4, value: str_repeat(' ', TabBarWidget::VERTICAL_WIDTH)), $empty->render(new RenderContext(80, 4)));

        $bare = new TabBarWidget();
        $bare->setPosition(TabBarPosition::Right);
        $this->assertSame([str_repeat(' ', TabBarWidget::VERTICAL_WIDTH)], $bare->render(new RenderContext(80, 0)), 'a zero-row context still yields one row');
    }

    public function testVerticalKeepsTheActiveTabVisible(): void
    {
        $tabs = [];
        for ($i = 0; $i < 12; ++$i) {
            $tabs[] = ['name' => \sprintf('file-%02d.php', $i), 'dirty' => false, 'active' => 11 === $i];
        }
        $bar = $this->verticalBar($tabs);

        $lines = $bar->render(new RenderContext(80, 5));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));

        $this->assertStringContainsString('file-11.php', $plain, 'active (last) tab visible after dropping leading rows');
        $this->assertStringNotContainsString('file-06.php', $plain);
        $this->assertSame(11, $bar->tabIndexAt(3, 1), 'the visible window maps rows back to full-list indexes');
    }

    public function testVerticalHitTestingMatchesRowsAndTheCloseZone(): void
    {
        $bar = $this->verticalBar([
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => true, 'active' => false],
        ]);
        $bar->render(new RenderContext(80, 5));

        $closeCol = TabBarWidget::VERTICAL_WIDTH - 2;
        $this->assertSame(0, $bar->tabIndexAt(0, 1), 'row 0 body is tab 0');
        $this->assertSame(1, $bar->tabIndexAt(1, $closeCol - 1), 'the dirty dot is still tab-1 body');
        $this->assertNull($bar->tabIndexAt(3, 1), 'a filler row misses');
        $this->assertSame(1, $bar->closeIndexAt(1, $closeCol), "row 1's × closes tab 1");
        $this->assertNull($bar->closeIndexAt(1, 2), 'the row body is not a close zone');
        $this->assertNull($bar->closeIndexAt(3, $closeCol), 'a filler row has no close zone');
        $this->assertSame(['row' => 1, 'col' => 0], $bar->tabAnchor(1), 'vertical anchors sit at the row start');
    }

    public function testVerticalMenuOverlayCompositesOverTheList(): void
    {
        $bar = $this->verticalBar([
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
        ]);
        $bar->setMenuOverlay([
            ['row' => 1, 'col' => 2, 'width' => 8, 'segment' => "\x1b[7m Close  \x1b[0m"],
        ]);

        $lines = $bar->render(new RenderContext(80, 3));

        $this->assertSame(TabBarWidget::VERTICAL_WIDTH, AnsiUtils::visibleWidth($lines[1]), 'overlay keeps the row exactly the list width');
        $this->assertStringContainsString('Close', AnsiUtils::stripAnsiCodes($lines[1]), 'dropdown segment composited over the filler row');
    }

    // --- scrolling --------------------------------------------------------------

    /** @return list<array{name: string, dirty: bool, active: bool}> */
    private static function manyTabs(int $count, int $active = 0): array
    {
        $tabs = [];
        for ($i = 0; $i < $count; ++$i) {
            $tabs[] = ['name' => \sprintf('very-long-filename-%02d.php', $i), 'dirty' => false, 'active' => $active === $i];
        }

        return $tabs;
    }

    /** Short names so the vertical list's 19-column truncation keeps them distinguishable. @return list<array{name: string, dirty: bool, active: bool}> */
    private static function manyShortTabs(int $count, int $active = 0): array
    {
        $tabs = [];
        for ($i = 0; $i < $count; ++$i) {
            $tabs[] = ['name' => \sprintf('t-%02d.php', $i), 'dirty' => false, 'active' => $active === $i];
        }

        return $tabs;
    }

    private function plainRender(TabBarWidget $bar, int $columns = 60, int $rows = 1): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $bar->render(new RenderContext($columns, $rows))));
    }

    public function testScrollByRevealsClippedTrailingTabsAndSurvivesParkedFrames(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => self::manyTabs(12));
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1)); // active (first) tab followed → window at 0

        $bar->scrollBy(2);
        $plain = $this->plainRender($bar);
        $this->assertStringNotContainsString('filename-00', $plain, 'scrolled past the first tabs');
        $this->assertStringContainsString('filename-02', $plain);

        // A parked frame (active tab unchanged) keeps the manual scroll.
        $plain = $this->plainRender($bar);
        $this->assertStringNotContainsString('filename-00', $plain, 'the manual scroll survives re-renders');

        $bar->scrollBy(-2);
        $this->assertStringContainsString('filename-00', $this->plainRender($bar), 'scrolled back to the first tab');
    }

    public function testScrollClampsAtBothEnds(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => self::manyTabs(12));
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));

        $bar->scrollBy(-5);
        $this->assertStringContainsString('filename-00', $this->plainRender($bar), 'never scrolls before the first tab');

        $bar->scrollBy(99);
        $plain = $this->plainRender($bar);
        $this->assertStringContainsString('filename-11', $plain, 'the last tab stays reachable');
        $this->assertSame(60, AnsiUtils::visibleWidth($bar->render(new RenderContext(60, 1))[0]), 'no blank tail past the last tab');
        $this->assertStringNotContainsString('filename-10', $plain, 'only one 30-column tab fits beside the reserved indicator cells');
    }

    public function testActiveTabChangeSnapsTheWindowBack(): void
    {
        $active = 0;
        $bar = new TabBarWidget();
        $bar->setProvider(static function () use (&$active) {
            return self::manyTabs(12, $active);
        });
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));

        $bar->scrollBy(6); // active tab 0 scrolls out of view
        $this->assertStringNotContainsString('filename-00', $this->plainRender($bar));

        $active = 1; // switching tabs snaps the window to the new active tab
        $bar->beforeRender();
        $this->assertStringContainsString('filename-01', $this->plainRender($bar), 'the window followed the new active tab');
    }

    public function testHitMapReflectsTheScrolledWindow(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => self::manyTabs(12));
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));

        $bar->scrollBy(3);
        $bar->render(new RenderContext(60, 1));

        $this->assertSame(3, $bar->tabIndexAt(0, 1), 'the first rendered tab maps to full-list index 3');
        $this->assertNull($bar->tabAnchor(0), 'a scrolled-out tab has no anchor');
    }

    public function testVerticalScrollByAndClamps(): void
    {
        $bar = $this->verticalBar(self::manyShortTabs(12));
        $bar->render(new RenderContext(80, 5)); // active (first) → tab rows 1-3 between the indicator rows

        $bar->scrollBy(3);
        $plain = $this->plainRender($bar, 80, 5);
        $this->assertStringNotContainsString('t-00', $plain);
        $this->assertStringContainsString('t-03', $plain);
        $this->assertSame(3, $bar->tabIndexAt(1, 1), 'the first tab row maps to full-list index 3');

        $bar->scrollBy(99);
        $plain = $this->plainRender($bar, 80, 5);
        $this->assertStringContainsString('t-09', $plain, 'clamped: the last window starts at count minus the three tab rows');
        $this->assertStringContainsString('t-11', $plain);

        $bar->scrollBy(-99);
        $this->assertStringContainsString('t-00', $this->plainRender($bar, 80, 5));
    }

    public function testHorizontalIndicatorsMarkOnlyTheClippedSides(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => self::manyTabs(12));
        $bar->beforeRender();

        // Window at the first tab: only the trailing side is clipped.
        $plain = $this->plainRender($bar);
        $this->assertStringStartsWith(' ', $plain, 'the leading indicator cell is reserved but blank');
        $this->assertStringEndsWith('›', $plain);
        $this->assertNull($bar->scrollIndicatorAt(0, 0), 'a blank indicator cell is not clickable');
        $this->assertSame(1, $bar->scrollIndicatorAt(0, 59), 'the › cell scrolls forward one tab');
        $this->assertNull($bar->tabIndexAt(0, 0), 'the indicator cell is not a tab body');

        // Window at the last tab: only the leading side is clipped.
        $bar->scrollBy(99);
        $plain = $this->plainRender($bar);
        $this->assertStringStartsWith('‹', $plain);
        $this->assertStringEndsWith(' ', $plain);
        $this->assertSame(-1, $bar->scrollIndicatorAt(0, 0), 'the ‹ cell scrolls back one tab');
        $this->assertNull($bar->scrollIndicatorAt(0, 59), 'a blank indicator cell is not clickable');
    }

    public function testHorizontalFittingTabsRenderNoIndicators(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => self::manyShortTabs(2));
        $bar->beforeRender();

        $plain = $this->plainRender($bar);
        $this->assertStringNotContainsString('‹', $plain);
        $this->assertStringNotContainsString('›', $plain);
        $this->assertNull($bar->scrollIndicatorAt(0, 0));
        $this->assertSame(0, $bar->tabIndexAt(0, 0), 'without overflow the first tab starts at the strip edge');
    }

    public function testVerticalIndicatorRowsMarkOnlyTheClippedSides(): void
    {
        $bar = $this->verticalBar(self::manyShortTabs(12));

        // Window at the first tab: only the bottom is clipped.
        $plain = $this->plainRender($bar, 80, 5);
        $lines = explode("\n", $plain);
        $this->assertStringNotContainsString('▲', $lines[0], 'the top indicator row is reserved but blank');
        $this->assertStringContainsString('▼', $lines[4]);
        $this->assertNull($bar->scrollIndicatorAt(0, 1), 'a blank indicator row is not clickable');
        $this->assertSame(3, $bar->scrollIndicatorAt(4, 1), 'the ▼ row scrolls forward three rows');
        $this->assertSame(3, $bar->scrollIndicatorAt(4, TabBarWidget::VERTICAL_WIDTH - 1), 'the whole row is the click zone');

        // Window at the last tab: only the top is clipped.
        $bar->scrollBy(99);
        $plain = $this->plainRender($bar, 80, 5);
        $lines = explode("\n", $plain);
        $this->assertStringContainsString('▲', $lines[0]);
        $this->assertStringNotContainsString('▼', $lines[4]);
        $this->assertSame(-3, $bar->scrollIndicatorAt(0, 1), 'the ▲ row scrolls back three rows');
        $this->assertNull($bar->scrollIndicatorAt(4, 1), 'a blank indicator row is not clickable');
    }

    public function testVerticalFittingTabsRenderNoIndicatorRows(): void
    {
        $bar = $this->verticalBar(self::manyShortTabs(3));

        $plain = $this->plainRender($bar, 80, 5);
        $this->assertStringNotContainsString('▲', $plain);
        $this->assertStringNotContainsString('▼', $plain);
        $this->assertSame(0, $bar->tabIndexAt(0, 1), 'without overflow the first tab sits on row 0');
        $this->assertNull($bar->scrollIndicatorAt(4, 1));
    }

    public function testSetPositionResetsTheScroll(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => self::manyShortTabs(12));
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));
        $bar->scrollBy(5);

        $bar->setPosition(TabBarPosition::Left);
        $this->assertStringContainsString('t-00', $this->plainRender($bar, 80, 5), 'a position change starts the window at the active tab again');
    }

    public function testHoverTintsTheInactiveTabAndClears(): void
    {
        $bar = new TabBarWidget();
        $bar->setProvider(static fn () => [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => false, 'active' => false],
        ]);
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));

        $this->assertTrue($bar->hoverTab(0, 12), 'hovering the inactive tab changes the tint');
        $this->assertFalse($bar->hoverTab(0, 12), 'same tab: no change');
        $line = $bar->render(new RenderContext(60, 1))[0];
        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'the hovered tab sits on the bar-chrome hover shade');

        $this->assertTrue($bar->clearHover(), 'leaving the strip clears the tint');
        $this->assertFalse($bar->clearHover(), 'already clear: no change');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $bar->render(new RenderContext(60, 1))[0]);
        $this->assertFalse($bar->hoverTab(0, 500), 'a column past every tab hovers nothing');
    }

    public function testAProviderChangeKeepsAStillCorrectHoverTint(): void
    {
        $tabs = [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => false, 'active' => false],
        ];
        $bar = new TabBarWidget();
        $bar->setProvider(static function () use (&$tabs) {
            return $tabs;
        });
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));
        $bar->hoverTab(0, 12); // over the second tab

        // Saving flips a dirty dot (a fingerprint change) but the second tab is
        // still there — the tint must stay put without a fresh motion event.
        $tabs[1]['dirty'] = true;
        $bar->beforeRender();
        $this->assertStringContainsString("\x1b[48;5;240m", $bar->render(new RenderContext(60, 1))[0], 'the hover survives a save under a still pointer');
    }

    public function testARemovedHoveredTabDropsTheTint(): void
    {
        $tabs = [
            ['name' => 'a.php', 'dirty' => false, 'active' => true],
            ['name' => 'b.php', 'dirty' => false, 'active' => false],
        ];
        $bar = new TabBarWidget();
        $bar->setProvider(static function () use (&$tabs) {
            return $tabs;
        });
        $bar->beforeRender();
        $bar->render(new RenderContext(60, 1));
        $bar->hoverTab(0, 12); // over the second tab

        // Closing the second tab leaves the pointer's column past the only tab
        // left — the cell resolves to nothing, so no stale tint lingers.
        array_pop($tabs);
        $bar->beforeRender();
        $this->assertStringNotContainsString("\x1b[48;5;240m", $bar->render(new RenderContext(60, 1))[0], 'nothing is tinted where the removed tab was');
    }
}
