<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Event\DockTabActivationRequested;
use Porta\Event\DockTabCloseRequested;
use Porta\Event\DockTabMoveRequested;
use Porta\Tests\Support\Probe;
use Porta\Ui\Glyphs;
use Porta\Widget\DockTabsWidget;
use Porta\Workbench\Dock\DockTab;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DockTabsWidgetTest extends TestCase
{
    /** @param list<array{tab: DockTab, label: string, active: bool, status: string}> $tabs */
    private static function strip(array $tabs, ?EventDispatcher $events = null): DockTabsWidget
    {
        $bar = new DockTabsWidget(events: $events);
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
        $this->assertSame(2, \substr_count($plain, needle: '×'), 'one close glyph per tab');
        $this->assertStringContainsString("\x1b[1m", $lines[0], 'the active tab renders bold');
    }

    public function testClickZonesSelectAndCloseTheRightTab(): void
    {
        $selected = [];
        $closed = [];
        $events = new EventDispatcher();
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'key' => '', 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'key' => '', 'status' => ''],
        ], $events);
        $events->addListener(DockTabActivationRequested::class, static function (DockTabActivationRequested $event) use (&$selected): void {
            $selected[] = [$event->tab, $event->key];
        });
        $events->addListener(DockTabCloseRequested::class, static function (DockTabCloseRequested $event) use (&$closed): void {
            $closed[] = [$event->tab, $event->key];
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

    /**
     * Every hit-test is half-open, [start, start + width): the first cell of
     * a zone is in it and the cell just past its end is not. The label-click
     * test above hits the middle of each zone, which is why flipping any of
     * those comparisons to their inclusive/exclusive twin stayed green.
     */
    public function testEveryZoneIncludesItsFirstCellAndExcludesTheCellPastItsEnd(): void
    {
        $activated = [];
        $closed = [];
        $events = new EventDispatcher();
        $events->addListener(DockTabActivationRequested::class, static function (DockTabActivationRequested $event) use (&$activated): void {
            $activated[] = $event->tab;
        });
        $events->addListener(DockTabCloseRequested::class, static function (DockTabCloseRequested $event) use (&$closed): void {
            $closed[] = $event->tab;
        });
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'key' => '', 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'key' => '', 'status' => ''],
        ], $events);
        $bar->render(new RenderContext(80, 1));
        /** @var list<array{start: int, width: int, closeStart: int, closeWidth: int}> $layout */
        $layout = Probe::get($bar, 'lastTabLayout');
        [$first, $second] = $layout;
        $firstEnd = $first['start'] + $first['width'];
        $this->assertLessThanOrEqual($second['start'], $firstEnd, 'sanity: the zones do not overlap');
        $lastEnd = $second['start'] + $second['width'];

        // The body, through every lookup that asks "which tab is here".
        $this->assertSame(DockTab::Terminal, $bar->tabAt($first['start'])['tab'] ?? null, "a tab's first cell is the tab");
        $this->assertSame(DockTab::Terminal, $bar->tabAt($firstEnd - 1)['tab'] ?? null, 'and so is its last');
        $this->assertNotSame(DockTab::Terminal, $bar->tabAt($firstEnd)['tab'] ?? null, 'the cell past its end is not');
        $this->assertSame(DockTab::Debug, $bar->familyIndexAt($second['start'])['tab'] ?? null);
        $this->assertNull($bar->familyIndexAt($lastEnd), 'past the last tab is the empty tail');
        $this->assertTrue($bar->isOnTab($first['start']), 'the very first cell of the strip is on a tab');
        $this->assertTrue($bar->isOnTab($lastEnd - 1));
        $this->assertFalse($bar->isOnTab($lastEnd));

        // The close zone: both its edges close, the cell after it does not.
        $closeEnd = $first['closeStart'] + $first['closeWidth'];
        $this->assertNull($bar->dragSourceAt($closeEnd - 1), 'the last close cell cannot arm a drag');
        $this->assertLessThan($firstEnd, $closeEnd, 'sanity: the × is followed by more of the tab body');
        $this->assertSame(DockTab::Terminal, $bar->dragSourceAt($closeEnd)['tab'] ?? null, 'the body cell right after the × arms one again');
        $bar->handleTabClick($first['closeStart']);
        $bar->handleTabClick($closeEnd - 1);
        $this->assertSame([DockTab::Terminal, DockTab::Terminal], $closed, 'both edges of the × close');
        $bar->handleTabClick($closeEnd);
        $this->assertSame([DockTab::Terminal, DockTab::Terminal], $closed, 'the cell after the × does not');
        $bar->handleTabClick($second['closeStart']);
        $this->assertSame([DockTab::Terminal, DockTab::Terminal, DockTab::Debug], $closed, "a LATER tab's × is reached too — the scan does not stop at the first zone it misses");

        // Activation, on the second tab's first cell and the cell past its end.
        $activated = [];
        $this->assertTrue($bar->handleTabClick($second['start']));
        $this->assertSame([DockTab::Debug], $activated);
        $this->assertFalse($bar->handleTabClick($lastEnd), 'past the last tab misses');
    }

    /** A strip built without a dispatcher still answers clicks and reorders; it just tells no one. */
    public function testAStripWithoutADispatcherStaysInert(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'key' => '', 'status' => ''],
        ]);
        $bar->render(new RenderContext(80, 1));
        /** @var list<array{start: int, closeStart: int}> $layout */
        $layout = Probe::get($bar, 'lastTabLayout');

        $this->assertTrue($bar->handleTabClick($layout[0]['closeStart']), 'a close click still lands');
        $this->assertTrue($bar->handleTabClick($layout[0]['start']), 'an activation click still lands');
        $bar->moveTab(DockTab::Terminal, 0, 0);
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
        $this->assertSame('3 results', \trim(\substr($plain, offset: 40)), 'right-aligned near the strip edge');
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
        $events = new EventDispatcher();
        $bar = self::strip($tabs);

        $bar->render(new RenderContext(40, 1));
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(40, 1))[0]);
        $this->assertStringContainsString('search-06', $plain, 'the active tab is always on the strip');
        $this->assertStringNotContainsString('search-00', $plain, 'leading tabs drop first');
        // The hit map follows the window: clicking column 2 hits the first VISIBLE tab.
        $selected = [];
        $events->addListener(DockTabActivationRequested::class, static function (DockTabActivationRequested $event) use (&$selected): void {
            $selected[] = $event->key;
        });
        $this->assertTrue($bar->handleTabClick(2));
        $this->assertNotContains('1', $selected, 'the dropped leading tab is not clickable');
    }

    public function testFamilyIndexAtIsFamilyRelativeAndDragZonesExcludeTheClose(): void
    {
        $events = new EventDispatcher();
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'sh 1', 'active' => false, 'key' => 'shell:1', 'status' => ''],
            ['tab' => DockTab::Terminal, 'label' => 'sh 2', 'active' => false, 'key' => 'shell:2', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'key' => '1', 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'TODO', 'active' => false, 'key' => '2', 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'key' => '', 'status' => ''],
        ], $events);
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(100, 1))[0]);
        $problems = (int) \mb_strpos($plain, needle: 'Problems');

        $this->assertSame(['tab' => DockTab::Terminal, 'index' => 0], $bar->familyIndexAt((int) \mb_strpos($plain, needle: 'sh 1')));
        $this->assertSame(['tab' => DockTab::Terminal, 'index' => 1], $bar->familyIndexAt((int) \mb_strpos($plain, needle: 'sh 2')), 'session indexes count only Terminal entries');
        $this->assertSame(['tab' => DockTab::Results, 'index' => 0], $bar->familyIndexAt($problems), 'tool indexes count only Results entries');
        $this->assertSame(['tab' => DockTab::Results, 'index' => 1], $bar->familyIndexAt((int) \mb_strpos($plain, needle: 'TODO')));
        $this->assertSame(['tab' => DockTab::Debug, 'index' => 0], $bar->familyIndexAt((int) \mb_strpos($plain, needle: 'Debug')));
        $this->assertNull($bar->familyIndexAt(99), 'the empty tail is no tab');

        $this->assertSame(['tab' => DockTab::Results, 'index' => 0], $bar->dragSourceAt($problems), 'a body press arms a drag');
        $this->assertNull($bar->dragSourceAt((int) \mb_strpos($plain, needle: '×', offset: $problems)), 'a close press must not arm one');

        $moves = [];
        $events->addListener(DockTabMoveRequested::class, static function (DockTabMoveRequested $event) use (&$moves): void {
            $moves[] = [$event->family, $event->from, $event->to];
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
        $problems = (int) \mb_strpos($plain, needle: 'Problems');

        $this->assertSame(['tab' => DockTab::Terminal, 'key' => 'shell:1'], $bar->tabAt((int) \mb_strpos($plain, needle: 'sh 1')));
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

        $this->assertTrue($bar->clearHover());
        $this->assertFalse($bar->clearHover());
        $this->assertFalse($bar->hoverTabAt(70), 'the empty tail hovers nothing');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $bar->render(new RenderContext(80, 1))[0]);
    }

    public function testOverflowScrollsWithClickableIndicators(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['tab' => DockTab::Results, 'label' => "Tool {$i}", 'active' => 0 === $i, 'key' => (string) $i, 'status' => ''];
        }
        $bar = self::strip($tabs);

        // Active is the head tab → the strip shows the start: a › (more right), no ‹.
        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(30, 1))[0]);
        $this->assertStringContainsString(Glyphs::SCROLL_NEXT, $plain, 'overflow shows the page-right ›');
        $this->assertSame(1, $bar->scrollIndicatorAt(0, 29), 'the › cell (last column) pages right');
        $this->assertNull($bar->scrollIndicatorAt(0, 3), 'a tab-body cell is not an indicator');

        // Scroll right: leading tabs hide, so a ‹ appears at column 0.
        $bar->scrollBy(2);
        $plain2 = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(30, 1))[0]);
        $this->assertStringContainsString(Glyphs::SCROLL_PREV, $plain2, 'after scrolling a ‹ marks hidden leading tabs');
        $this->assertSame(-1, $bar->scrollIndicatorAt(0, 0), 'the ‹ cell pages left');
    }

    public function testTheEmptyTailHeavyRulesUnderTheGrabHover(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'key' => '', 'status' => ''],
        ]);
        $bar->render(new RenderContext(80, 1));

        $this->assertTrue($bar->isOnTab(3), 'a column on the tab body is a tab');
        $this->assertFalse($bar->isOnTab(70), 'a column past the last tab is the resize tail');
        $this->assertStringNotContainsString(Glyphs::PANEL_RESIZE_GRAB, AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 1))[0]), 'at rest the tail is plain');

        $this->assertTrue($bar->hoverGrab(), 'grabbing the tail changes it');
        $this->assertFalse($bar->hoverGrab(), 'same state: no change');
        $ruled = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 1))[0]);
        $this->assertStringContainsString(Glyphs::PANEL_RESIZE_GRAB, $ruled, 'the empty tail heavy-rules under the grab');

        $this->assertTrue($bar->clearGrab());
        $this->assertFalse($bar->clearGrab(), 'already clear: no change');
        $this->assertStringNotContainsString(Glyphs::PANEL_RESIZE_GRAB, AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 1))[0]));
    }

    public function testTheGrabRuleFillsTheGapBesideARightAlignedStatus(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Terminal, 'label' => 'sh', 'active' => true, 'key' => '', 'status' => '[+3]'],
        ]);
        $bar->render(new RenderContext(40, 1));
        $bar->hoverGrab();

        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(40, 1))[0]);
        $this->assertStringContainsString(Glyphs::PANEL_RESIZE_GRAB, $plain, 'the gap before the status heavy-rules');
        $this->assertStringContainsString('[+3]', $plain, 'the status still shows');
    }
}
