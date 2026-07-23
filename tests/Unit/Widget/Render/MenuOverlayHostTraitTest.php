<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The two floating span layers every pane composites: the modal layer renders
 * first and the menu layer over it, so an open dropdown covers the modal
 * where they overlap.
 */
final class MenuOverlayHostTraitTest extends TestCase
{
    /** @return AbstractWidget&MenuOverlayHost a 10-column host exposing its composite */
    private function host(): AbstractWidget&MenuOverlayHost
    {
        return new class extends AbstractWidget implements MenuOverlayHost {
            use MenuOverlayHostTrait;

            public function render(RenderContext $context): array
            {
                return $this->withMenuOverlay(['..........', '..........'], 10);
            }

            public function handleInput(string $data): void {}
        };
    }

    public function testTheModalLayerCompositesUnderTheMenuLayer(): void
    {
        $host = $this->host();
        $host->setModalOverlay([['row' => 0, 'col' => 0, 'width' => 6, 'segment' => 'MMMMMM']]);
        $host->setMenuOverlay([['row' => 0, 'col' => 4, 'width' => 4, 'segment' => 'DDDD']]);

        $lines = array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

        $this->assertSame('MMMMDDDD..', $lines[0], 'the dropdown covers the modal where they overlap');
        $this->assertSame('..........', $lines[1]);
    }

    /** The backdrop dims the pane's own rows; the composited spans stay bright. */
    public function testTheBackdropDimsBaseLinesBeneathTheSpans(): void
    {
        $host = $this->host();
        $host->setModalBackdrop(true);
        $host->setModalOverlay([['row' => 0, 'col' => 2, 'width' => 3, 'segment' => 'MMM']]);

        $lines = $host->render(new RenderContext(10, 2));

        $this->assertStringStartsWith("\x1b[2m", $lines[1], 'a base row renders faint');
        $this->assertMatchesRegularExpression('/\x1b\[0mMMM\x1b\[0m/', $lines[0], 'the modal span composites bright, reset-guarded');

        $host->setModalBackdrop(false);
        $this->assertStringNotContainsString("\x1b[2m", $host->render(new RenderContext(10, 2))[1], 'clearing the flag restores the pane');
    }

    /** Toasts sit between the layers: above the modal, below the menu. */
    public function testTheToastLayerCompositesBetweenModalAndMenu(): void
    {
        $host = $this->host();
        $host->setModalOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => 'MMMMMMMM']]);
        $host->setToastOverlay([['row' => 0, 'col' => 2, 'width' => 6, 'segment' => 'TTTTTT']]);
        $host->setMenuOverlay([['row' => 0, 'col' => 6, 'width' => 4, 'segment' => 'DDDD']]);

        $lines = array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

        $this->assertSame('MMTTTTDDDD', $lines[0], 'toast over modal, menu over toast');
    }

    /** A span sliced against a stale (wider) rect clips to the live width instead of blowing the row. */
    public function testAnOversizedSpanClipsToTheLiveWidth(): void
    {
        $host = $this->host();
        $host->setToastOverlay([['row' => 0, 'col' => 6, 'width' => 9, 'segment' => 'TTTTTTTTT']]);
        $host->setMenuOverlay([['row' => 1, 'col' => 12, 'width' => 3, 'segment' => 'DDD']]);

        $lines = $host->render(new RenderContext(10, 2));

        $this->assertSame(10, AnsiUtils::visibleWidth($lines[0]), 'the clipped span keeps the row exactly the widget width');
        $this->assertSame('......TTTT', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertSame('..........', AnsiUtils::stripAnsiCodes($lines[1]), 'a span wholly past the width is dropped');
    }

    public function testClearingOneLayerLeavesTheOther(): void
    {
        $host = $this->host();
        $host->setModalOverlay([['row' => 1, 'col' => 2, 'width' => 3, 'segment' => 'MMM']]);
        $host->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 2, 'segment' => 'DD']]);
        $host->setMenuOverlay([]);

        $lines = array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

        $this->assertSame('..........', $lines[0], 'the menu layer cleared');
        $this->assertSame('..MMM.....', $lines[1], 'the modal layer stayed');
    }
}
