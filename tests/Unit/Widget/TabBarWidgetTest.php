<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
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
        $this->assertSame(str_repeat(' ', 40), $line);
    }

    public function testWithoutProviderRendersBlankLine(): void
    {
        $bar = new TabBarWidget();

        $this->assertSame(str_repeat(' ', 20), $bar->render(new RenderContext(20, 1))[0]);
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
        $bar->setProvider(function () use (&$tabs) {
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

        $this->assertSame(0, $bar->tabIndexAtColumn(1), 'first tab region');
        $this->assertNotSame(0, $bar->tabIndexAtColumn(12), 'second tab region');
        $this->assertNull($bar->tabIndexAtColumn(500), 'click past every tab misses');
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

        $this->assertSame(2, substr_count($plain, '×'), 'a close × per tab');
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

        $this->assertSame(0, $bar->closeIndexAtColumn(7), "first tab's × closes tab 0");
        $this->assertSame(1, $bar->closeIndexAtColumn(18), "second tab's × closes tab 1");
        $this->assertNull($bar->closeIndexAtColumn(2), 'the tab body is not a close zone');
        $this->assertNull($bar->closeIndexAtColumn(17), 'the dirty dot is not the close zone');
        $this->assertNull($bar->closeIndexAtColumn(500), 'past every tab');
        // The body hit-test still resolves those columns to their tab.
        $this->assertSame(1, $bar->tabIndexAtColumn(17), 'the dirty dot is still tab-1 body');
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
}
