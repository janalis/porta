<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class StatusBarWidgetTest extends TestCase
{
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

    public function testLongLeftContentTruncates(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => str_repeat('x', 200), 'right' => 'RIGHT']);
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
}
