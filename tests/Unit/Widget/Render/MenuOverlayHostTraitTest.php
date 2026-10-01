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

        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

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

        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

        $this->assertSame('MMTTTTDDDD', $lines[0], 'toast over modal, menu over toast');
    }

    /** The tooltip card sits above the toasts and below an open menu. */
    public function testTheTooltipLayerCompositesBetweenToastAndMenu(): void
    {
        $host = $this->host();
        $host->setToastOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => 'TTTTTTTT']]);
        $host->setTooltipOverlay([['row' => 0, 'col' => 2, 'width' => 6, 'segment' => 'PPPPPP']]);
        $host->setMenuOverlay([['row' => 0, 'col' => 6, 'width' => 4, 'segment' => 'DDDD']]);

        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

        $this->assertSame('TTPPPPDDDD', $lines[0], 'tooltip over toast, menu over tooltip');
    }

    public function testSetTooltipOverlayRepaintsOnlyOnAChange(): void
    {
        $host = $this->host();
        $spans = [['row' => 0, 'col' => 0, 'width' => 2, 'segment' => 'PP']];
        $host->setTooltipOverlay($spans);
        $rev = $host->getRenderRevision();

        $host->setTooltipOverlay($spans);
        $this->assertSame($rev, $host->getRenderRevision(), 'the same spans: no repaint, or a card up repaints every frame');

        $host->setTooltipOverlay([]);
        $this->assertGreaterThan($rev, $host->getRenderRevision());
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

    public function testSetMenuOverlayInvalidatesTheWidget(): void
    {
        $host = $this->host();
        $rev = $host->getRenderRevision();

        $host->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 2, 'segment' => 'DD']]);

        $this->assertGreaterThan($rev, $host->getRenderRevision(), 'a new menu overlay must trigger a repaint');
    }

    public function testClearingOneLayerLeavesTheOther(): void
    {
        $host = $this->host();
        $host->setModalOverlay([['row' => 1, 'col' => 2, 'width' => 3, 'segment' => 'MMM']]);
        $host->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 2, 'segment' => 'DD']]);
        $host->setMenuOverlay([]);

        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), $host->render(new RenderContext(10, 2)));

        $this->assertSame('..........', $lines[0], 'the menu layer cleared');
        $this->assertSame('..MMM.....', $lines[1], 'the modal layer stayed');
    }

    /** A view window is the lowest layer, over its own dim; a picker's dim then dims it too. */
    public function testTheWindowLayerCompositesUnderEveryOtherOverItsOwnDim(): void
    {
        $host = $this->host();
        $host->setWindowBackdrop(true);
        $host->setWindowOverlay([['row' => 0, 'col' => 0, 'width' => 6, 'segment' => 'WWWWWW']]);
        $host->setModalOverlay([['row' => 0, 'col' => 4, 'width' => 4, 'segment' => 'MMMM']]);

        $lines = $host->render(new RenderContext(10, 2));

        $this->assertSame('WWWWMMMM..', AnsiUtils::stripAnsiCodes($lines[0]), 'the picker covers the window where they overlap');
        $this->assertStringStartsWith("\x1b[2m", $lines[1], 'the pane beneath the window renders faint');
        $this->assertMatchesRegularExpression('/\x1b\[0mWWWW/', $lines[0], 'the window composites bright over its dim');

        $host->setModalBackdrop(true);
        $this->assertMatchesRegularExpression('/\x1b\[2m[^\n]*WWWW/', $host->render(new RenderContext(10, 2))[0], 'a picker\'s dim dims the window under it too');

        $host->setWindowBackdrop(false);
        $host->setModalBackdrop(false);
        $this->assertStringNotContainsString("\x1b[2m", $host->render(new RenderContext(10, 2))[1], 'clearing both restores the pane');
    }

    public function testTheWindowSettersRepaintOnlyOnAChange(): void
    {
        $host = $this->host();
        $spans = [['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'W']];
        $host->render(new RenderContext(10, 2));
        $host->setWindowOverlay($spans);
        $revision = $host->getRenderRevision();
        $host->setWindowOverlay($spans);
        $host->setWindowBackdrop(false);
        $this->assertSame($revision, $host->getRenderRevision(), 'the same spans, the same dim: no repaint');
        $host->setWindowBackdrop(true);
        $this->assertGreaterThan($revision, $host->getRenderRevision(), 'a changed dim repaints');
        $revision = $host->getRenderRevision();
        $host->setWindowOverlay([]);
        $this->assertGreaterThan($revision, $host->getRenderRevision(), 'changed spans repaint');
    }
}
