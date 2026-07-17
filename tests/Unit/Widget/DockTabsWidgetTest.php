<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\DockTab;
use Porta\Widget\DockTabsWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DockTabsWidgetTest extends TestCase
{
    /** @param list<array{tab: DockTab, label: string, active: bool, status: string}> $tabs */
    private static function strip(array $tabs): DockTabsWidget
    {
        $bar = new DockTabsWidget();
        $bar->setProvider(static fn (): array => $tabs);

        return $bar;
    }

    public function testWithoutProviderOrTabsRendersZeroRows(): void
    {
        $bare = new DockTabsWidget();
        $this->assertSame([], $bare->render(new RenderContext(80, 1)), 'no provider: the strip takes no rows');
        $bare->beforeRender();

        $empty = self::strip([]);
        $empty->beforeRender();
        $this->assertSame([], $empty->render(new RenderContext(80, 1)), 'no alive tabs: the strip disappears');
    }

    public function testRendersTabsWithActiveStylingAndCloseGlyphs(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => false, 'key' => '', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'key' => '', 'status' => ''],
        ]);

        $lines = $bar->render(new RenderContext(80, 1));
        $this->assertCount(1, $lines);
        $this->assertSame(80, AnsiUtils::visibleWidth($lines[0]), 'exactly one full-width row');
        $plain = AnsiUtils::stripAnsiCodes($lines[0]);
        $this->assertStringContainsString('Terminal ×', $plain);
        $this->assertStringContainsString('Problems ×', $plain);
        $this->assertSame(2, substr_count($plain, needle: '×'), 'one close glyph per tab');
        $this->assertStringContainsString("\x1b[1m", $lines[0], 'the active tab renders bold');
    }

    public function testClickZonesSelectAndCloseTheRightTab(): void
    {
        $selected = [];
        $closed = [];
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'key' => '', 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'key' => '', 'status' => ''],
        ]);
        $bar->onTabSelected(static function (DockTab $tab, string $key) use (&$selected): void {
            $selected[] = [$tab, $key];
        });
        $bar->onTabClosed(static function (DockTab $tab, string $key) use (&$closed): void {
            $closed[] = [$tab, $key];
        });
        $bar->render(new RenderContext(80, 1));

        // ' Terminal × │ Debug × ': tab 1 body starts at col 0, its × at col 10.
        $this->assertTrue($bar->handleTabClick(2), 'a label click hits');
        $this->assertSame([[DockTab::Terminal, '']], $selected);
        $this->assertTrue($bar->handleTabClick(10), 'the × zone closes');
        $this->assertSame([[DockTab::Terminal, '']], $closed);
        $this->assertTrue($bar->handleTabClick(14), 'the second tab body selects it');
        $this->assertSame(
            [
                [DockTab::Terminal, ''],
                [DockTab::Debug,    ''],
            ],
            $selected,
        );
        $this->assertFalse($bar->handleTabClick(70), 'the empty tail misses (resize grab)');
    }

    public function testBeforeRenderRepaintsOnlyWhenTheTabsChange(): void
    {
        $tabs = [['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'key' => '', 'status' => '']];
        $bar = new DockTabsWidget();
        $bar->setProvider(static function () use (&$tabs): array {
            return $tabs;
        });

        $bar->beforeRender();
        $revision = $bar->getRenderRevision();
        $bar->beforeRender();
        $this->assertSame($revision, $bar->getRenderRevision(), 'unchanged tabs do not invalidate');

        $tabs[0]['label'] = 'Usages';
        $bar->beforeRender();
        $revision2 = $bar->getRenderRevision();
        $this->assertGreaterThan($revision, $revision2, 'a relabel repaints the strip');

        $tabs[0]['key'] = '9';
        $bar->beforeRender();
        $this->assertGreaterThan($revision2, $bar->getRenderRevision(), 'a membership change repaints even under the same label');
    }

    public function testTheActiveTabsStatusRendersRightAligned(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'key' => '7', 'status' => '3 results'],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'key' => '8', 'status' => 'never shown (inactive)'],
        ]);

        $line = $bar->render(new RenderContext(60, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);
        $this->assertSame(60, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('3 results', $plain, "the active tool's status shows");
        $this->assertStringNotContainsString('never shown', $plain, 'inactive statuses stay hidden');
        $this->assertSame('3 results', trim(substr($plain, offset: 40)), 'right-aligned near the strip edge');
    }

    public function testTabsPastTheWidthAreDropped(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'a-very-long-terminal-label', 'active' => true, 'key' => '', 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'debug-tool', 'active' => false, 'key' => '', 'status' => ''],
        ]);

        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(34, 1))[0]);
        $this->assertStringContainsString('a-very-long-terminal-label', $plain);
        $this->assertStringNotContainsString('debug-tool', $plain, 'a tab that no longer fits is dropped');
    }

    public function testOverflowDropsLeadingTabsToKeepTheActiveOneVisible(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['tab' => DockTab::Results, 'key' => (string) ($i + 1), 'label' => \sprintf('search-%02d', $i), 'active' => 6 === $i, 'status' => ''];
        }
        $bar = self::strip($tabs);

        $bar->render(new RenderContext(40, 1));
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(40, 1))[0]);
        $this->assertStringContainsString('search-06', $plain, 'the active tab is always on the strip');
        $this->assertStringNotContainsString('search-00', $plain, 'leading tabs drop first');
        // The hit map follows the window: clicking column 2 hits the first VISIBLE tab.
        $selected = [];
        $bar->onTabSelected(static function (DockTab $tab, string $key) use (&$selected): void {
            $selected[] = $key;
        });
        $this->assertTrue($bar->handleTabClick(2));
        $this->assertNotContains('1', $selected, 'the dropped leading tab is not clickable');
    }

    public function testFamilyIndexAtIsFamilyRelativeAndDragZonesExcludeTheClose(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'sh 1', 'active' => false, 'key' => 'shell:1', 'status' => ''],
            ['tab' => DockTab::Terminal, 'label' => 'sh 2', 'active' => false, 'key' => 'shell:2', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'key' => '1', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'TODO', 'active' => false, 'key' => '2', 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'key' => '', 'status' => ''],
        ]);
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(100, 1))[0]);
        $problems = (int) mb_strpos($plain, needle: 'Problems');

        $this->assertSame(['tab' => DockTab::Terminal, 'index' => 0], $bar->familyIndexAt((int) mb_strpos($plain, needle: 'sh 1')));
        $this->assertSame(['tab' => DockTab::Terminal, 'index' => 1], $bar->familyIndexAt((int) mb_strpos($plain, needle: 'sh 2')), 'session indexes count only Terminal entries');
        $this->assertSame(['tab' => DockTab::Results, 'index' => 0], $bar->familyIndexAt($problems), 'tool indexes count only Results entries');
        $this->assertSame(['tab' => DockTab::Results, 'index' => 1], $bar->familyIndexAt((int) mb_strpos($plain, needle: 'TODO')));
        $this->assertSame(['tab' => DockTab::Debug, 'index' => 0], $bar->familyIndexAt((int) mb_strpos($plain, needle: 'Debug')));
        $this->assertNull($bar->familyIndexAt(99), 'the empty tail is no tab');

        $this->assertSame(['tab' => DockTab::Results, 'index' => 0], $bar->dragSourceAt($problems), 'a body press arms a drag');
        $this->assertNull($bar->dragSourceAt((int) mb_strpos($plain, needle: '×', offset: $problems)), 'a close press must not arm one');

        $bar->moveTab(DockTab::Results, 0, 1); // unwired: a no-op
        $moves = [];
        $bar->onTabMoved(static function (DockTab $family, int $from, int $to) use (&$moves): void {
            $moves[] = [$family, $from, $to];
        });
        $bar->moveTab(DockTab::Results, 0, 1);
        $this->assertSame([[DockTab::Results, 0, 1]], $moves, 'the reorder request reaches the wired dock');
    }

    public function testTabAtAndAnchorForResolveStripIdentity(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'sh 1', 'active' => true, 'key' => 'shell:1', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => false, 'key' => '1', 'status' => ''],
        ]);
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 1))[0]);
        $problems = (int) mb_strpos($plain, needle: 'Problems');

        $this->assertSame(['tab' => DockTab::Terminal, 'key' => 'shell:1'], $bar->tabAt((int) mb_strpos($plain, needle: 'sh 1')));
        $this->assertSame(['tab' => DockTab::Results, 'key' => '1'], $bar->tabAt($problems));
        $this->assertNull($bar->tabAt(79), 'the empty tail has no identity');

        $this->assertSame($problems - 1, $bar->anchorFor(DockTab::Results, '1'), "a tab's anchor is its body start (one cell before the label)");
        $this->assertNull($bar->anchorFor(DockTab::Results, '99'), 'an unknown tab has no anchor');
    }

    public function testHoverTintsTheInactiveTabAndClears(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'key' => '', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => false, 'key' => '1', 'status' => ''],
        ]);
        $bar->render(new RenderContext(80, 1));

        $this->assertTrue($bar->hoverTabAt(14), 'hovering the inactive tab changes the tint');
        $this->assertFalse($bar->hoverTabAt(15), 'same tab: no change');
        $this->assertStringContainsString("\x1b[48;5;240m", $bar->render(new RenderContext(80, 1))[0]);

        $this->assertTrue($bar->clearTabHover());
        $this->assertFalse($bar->clearTabHover());
        $this->assertFalse($bar->hoverTabAt(70), 'the empty tail hovers nothing');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $bar->render(new RenderContext(80, 1))[0]);
    }
}
