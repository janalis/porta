<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusMenuSpan;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class StatusBarWidgetTest extends TestCase
{
    /** A status row with one clickable cell spanning cols [0,6) of `left`. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    private static function provider(bool $menus, string $right = 'R'): callable
    {
        $spans = $menus ? [new StatusMenuSpan(0, 6, static fn (): array => [new MenuItem('Switch', '', static fn () => null)])] : [];

        return static fn (): array => ['left' => "\u{e0a0} main  file.php", 'right' => $right, 'menus' => $spans];
    }

    public function testRendersProviderContentAtExactWidth(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => 'file.php ●', 'right' => '12:3 LSP:phpactor']);
        $status->beforeRender();

        $line = $status->render(new RenderContext(60, 1))[0];

        $this->assertSame(60, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('file.php', $line);
        $this->assertStringContainsString('phpactor', $line);
    }

    public function testFlashOverridesLeftAndExpires(): void
    {
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'normal', 'right' => 'R']);
        $status->beforeRender();

        $status->flash('Saved!', 0.05);
        $line = $status->render(new RenderContext(40, 1))[0];
        $this->assertStringContainsString('Saved!', $line);

        $clock->sleep(0.08); // advance past the 0.05s flash window, deterministically
        $status->beforeRender();
        $line = $status->render(new RenderContext(40, 1))[0];
        $this->assertStringNotContainsString('Saved!', $line);
        $this->assertStringContainsString('normal', $line);
    }

    public function testAFlashOutranksTheRightSideLegend(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn () => ['left' => 'file.php', 'right' => '1:1  LSP:none  Ctrl+S save  Ctrl+T panel  Ctrl+Q quit']);
        $status->beforeRender();

        $status->flash('Saved a/really/long/path/src/Demo.php', 1.0);
        $line = $status->render(new RenderContext(50, 1))[0];
        $this->assertSame(50, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('Saved a/really/long/path/src/Demo.php', $line, 'the flash renders whole — the legend yields');
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($line), 'the right side truncates with a mark');
    }

    public function testLongLeftContentTruncates(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => str_repeat('x', times: 200), 'right' => 'RIGHT']);
        $status->beforeRender();

        $line = $status->render(new RenderContext(50, 1))[0];
        $this->assertSame(50, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('RIGHT', $line, 'right side survives truncation');
    }

    public function testWithoutProviderRendersEmptyBar(): void
    {
        $status = new StatusBarWidget();
        $status->beforeRender();

        $line = $status->render(new RenderContext(20, 1))[0];
        $this->assertSame(20, AnsiUtils::visibleWidth($line));
    }

    public function testStatusCellMenuTracksTheBranchCellWithTheLeadingSpace(): void
    {
        $status = new StatusBarWidget();
        // The cell occupies cols [0,6) in `left`; the row's one leading space shifts it to [1,7).
        $status->setProvider(self::provider(menus: true));
        $status->beforeRender();
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(0), 'the leading space is not part of the cell');
        $this->assertNotNull($status->menuAt(1), 'the on-screen span accounts for it');
        $this->assertNotNull($status->menuAt(6), 'the last column of the cell');
        $this->assertNull($status->menuAt(7), 'and the column past it');
        $this->assertSame(['Switch'], array_map(static fn (MenuItem $i): string => $i->label, $status->menuAt(1)()), 'the cell carries its OWN menu');
    }

    public function testStatusCellMenuIsNullWithoutABranch(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(self::provider(menus: false));
        $status->beforeRender();
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(1));
    }

    public function testStatusCellMenuIsNullWhileFlashing(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(self::provider(menus: true));
        $status->beforeRender();
        // A flash LONGER than the cell, deliberately: a short one is excluded
        // by the truncation filter anyway, so this would pass with the flash
        // guard deleted and prove nothing about it.
        $status->flash(str_repeat('Saved ', times: 4), 1.0);
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(1), 'a flash replaces the prefix, so there is no hit region');
    }

    public function testStatusCellMenuIsNullWhenTruncatedOff(): void
    {
        $status = new StatusBarWidget();
        // A wide right side + narrow terminal truncates the branch off the left.
        $status->setProvider(self::provider(menus: true, right: str_repeat('R', times: 40)));
        $status->beforeRender();
        $status->render(new RenderContext(20, 1));

        $this->assertNull($status->menuAt(1), 'a clipped cell offers no hit region');
    }
}
