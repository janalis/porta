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
