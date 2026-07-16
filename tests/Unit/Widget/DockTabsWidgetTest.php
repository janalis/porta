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
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => false, 'status' => ''],
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'status' => ''],
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
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => true, 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'status' => ''],
        ]);
        $bar->onTabSelected(static function (DockTab $tab) use (&$selected): void {
            $selected[] = $tab;
        });
        $bar->onTabClosed(static function (DockTab $tab) use (&$closed): void {
            $closed[] = $tab;
        });
        $bar->render(new RenderContext(80, 1));

        // ' Terminal × │ Debug × ': tab 1 body starts at col 0, its × at col 10.
        $this->assertTrue($bar->handleTabClick(2), 'a label click hits');
        $this->assertSame([DockTab::Terminal], $selected);
        $this->assertTrue($bar->handleTabClick(10), 'the × zone closes');
        $this->assertSame([DockTab::Terminal], $closed);
        $this->assertTrue($bar->handleTabClick(14), 'the second tab body selects it');
        $this->assertSame([DockTab::Terminal, DockTab::Debug], $selected);
        $this->assertFalse($bar->handleTabClick(70), 'the empty tail misses (resize grab)');
    }

    public function testBeforeRenderRepaintsOnlyWhenTheTabsChange(): void
    {
        $tabs = [['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'status' => '']];
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
        $this->assertGreaterThan($revision, $bar->getRenderRevision(), 'a relabel repaints the strip');
    }

    public function testTheActiveTabsStatusRendersRightAligned(): void
    {
        $bar = self::strip([
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'status' => '3 results'],
            ['tab' => DockTab::Debug, 'label' => 'Debug', 'active' => false, 'status' => 'never shown (inactive)'],
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
            ['tab' => DockTab::Terminal, 'label' => 'a-very-long-terminal-label', 'active' => true, 'status' => ''],
            ['tab' => DockTab::Debug, 'label' => 'debug-tool', 'active' => false, 'status' => ''],
        ]);

        $plain = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(34, 1))[0]);
        $this->assertStringContainsString('a-very-long-terminal-label', $plain);
        $this->assertStringNotContainsString('debug-tool', $plain, 'a tab that no longer fits is dropped');
    }
}
