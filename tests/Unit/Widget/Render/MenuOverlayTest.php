<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\MenuOverlay;

final class MenuOverlayTest extends TestCase
{
    public function testASpanClippedToExactlyZeroWidthIsSkipped(): void
    {
        $lines = [\str_repeat(' ', times: 10)];
        // col=10 on a 10-column line leaves 0 columns of room: min(width, 10-10) === 0.
        $span = ['row' => 0, 'col' => 10, 'width' => 5, 'segment' => 'XXXXX'];

        $out = MenuOverlay::apply($lines, [$span], columns: 10);

        $this->assertSame($lines, $out, 'a zero-width span must not be composited at all');
    }

    public function testASpanExactlyAsWideAsItsColumnBudgetIsCompositedUnclipped(): void
    {
        $lines = [\str_repeat(' ', times: 10)];
        // width === span width exactly: no clipping needed, the raw segment is used
        // untouched — slicing it (even at its own full length) would strip its
        // trailing reset, since AnsiUtils::sliceByColumn does not preserve one.
        $span = ['row' => 0, 'col' => 0, 'width' => 5, 'segment' => "\x1b[7mXXXXX\x1b[0m"];

        $out = MenuOverlay::apply($lines, [$span], columns: 10);

        // Sliced (even at the segment's own full length) loses its trailing reset;
        // OverlayCompositor::into() then only adds its own single reset at the tail.
        // Untouched, the segment's reset survives, doubling up with that same tail one.
        $this->assertSame("\x1b[0m\x1b[7mXXXXX\x1b[0m\x1b[0m     \x1b[0m", $out[0]);
    }
}
