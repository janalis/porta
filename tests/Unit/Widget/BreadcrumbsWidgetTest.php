<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\BreadcrumbsWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class BreadcrumbsWidgetTest extends TestCase
{
    public function testEmptyTrailRendersNoRow(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => []);
        $bar->beforeRender();

        $this->assertSame([], $bar->render(new RenderContext(80, 1)), 'no scope → no bar');
        $this->assertNull($bar->lineAtColumn(5), 'nothing to hit-test');
    }

    public function testRendersSegmentsJoinedAndExactWidth(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [
            ['label' => 'App', 'line' => 1],
            ['label' => 'UserController', 'line' => 4],
            ['label' => 'index', 'line' => 6],
        ]);

        $rows = $bar->render(new RenderContext(80, 1));
        $this->assertCount(1, $rows);
        $this->assertSame(80, AnsiUtils::visibleWidth($rows[0]), 'padded to the full width');

        $plain = AnsiUtils::stripAnsiCodes($rows[0]);
        $this->assertStringContainsString('App › UserController › index', $plain);
    }

    public function testLineAtColumnMapsToJumpLines(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [
            ['label' => 'App', 'line' => 1], // ' App' → cols 1..3
            ['label' => 'Ctrl', 'line' => 4], // after ' › ' → cols 7..10
        ]);
        $bar->render(new RenderContext(80, 1));

        $this->assertSame(1, $bar->lineAtColumn(2), 'inside the first crumb');
        $this->assertSame(4, $bar->lineAtColumn(8), 'inside the second crumb');
        $this->assertNull($bar->lineAtColumn(0), 'the leading space is not a crumb');
        $this->assertNull($bar->lineAtColumn(5), 'the separator gap is not a crumb');
        $this->assertNull($bar->lineAtColumn(70), 'past the last crumb');
    }

    public function testBeforeRenderInvalidatesOnChange(): void
    {
        $bar = new BreadcrumbsWidget();
        $crumbs = [['label' => 'A', 'line' => 0]];
        $bar->setProvider(static function () use (&$crumbs): array {
            return $crumbs;
        });

        $bar->beforeRender();
        $rev = $bar->getRenderRevision();
        $bar->beforeRender(); // unchanged → no new revision
        $this->assertSame($rev, $bar->getRenderRevision());

        $crumbs = [['label' => 'A', 'line' => 0], ['label' => 'B', 'line' => 2]];
        $bar->beforeRender(); // changed → invalidates
        $this->assertGreaterThan($rev, $bar->getRenderRevision());
    }

    public function testMenuOverlayCompositesOverTheBar(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 1]]);
        $bar->setMenuOverlay([['row' => 0, 'col' => 2, 'width' => 6, 'segment' => "\x1b[7m Paste \x1b[0m"]]);

        $row = $bar->render(new RenderContext(40, 1))[0];
        $this->assertStringContainsString('Paste', AnsiUtils::stripAnsiCodes($row), 'a dropdown span covers the breadcrumb row');
    }

    public function testMenuOverlayIgnoredOnEmptyTrail(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => []);
        $bar->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 5, 'segment' => 'XXXXX']]);

        $this->assertSame([], $bar->render(new RenderContext(40, 1)), 'no bar → nothing to composite onto');
    }

    public function testProviderlessRenderIsEmpty(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->beforeRender(); // no provider set → no-op
        $this->assertSame([], $bar->render(new RenderContext(40, 1)));
    }
}
