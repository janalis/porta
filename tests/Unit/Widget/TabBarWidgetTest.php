<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class TabBarWidgetTest extends TestCase
{
    public function testEmptyStateRendersBlankLine(): void
    {
        $bar = new TabBarWidget();
        $bar->beforeRender();

        $line = $bar->render(new RenderContext(40, 1))[0];
        $this->assertSame(str_repeat(' ', times: 40), $line);
    }

    public function testWithoutProviderRendersBlankLine(): void
    {
        $bar = new TabBarWidget();

        $this->assertSame(str_repeat(' ', times: 20), $bar->render(new RenderContext(20, 1))[0]);
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
        $this->assertSame(11, $bar->tabIndexAt(4, 1), 'the visible window maps rows back to full-list indexes');
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
}
