<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Help\Layout\ImageLayout;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Porta\Ui\Graphics\PngRaster;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** A picture drawn into a fixed box: an icon, a banner, a screenshot of a strip. */
final class ImageLayoutBoxTest extends TestCase
{
    public function testASquareIconFillsItsBoxWithItsShapeKept(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $layout = new ImageLayout($graphics);
        $icon = PngFixture::bytes(64, 64);

        $lines = $layout->boxed($icon, 6, 3);
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertSame(6, AnsiUtils::visibleWidth($line), 'every line as wide as the box');
        }
        $this->assertCount(1, PixelGraphics::sentImages($terminal));
        $this->assertSame(64, \imagesx(PixelGraphics::sentImages($terminal)[0]), 'the picture itself, sent as it is: the terminal scales it');
        $this->assertStringContainsString('c=6,r=3', $terminal->getOutput(), 'six cells by three: square at 8×16 cells');

        $this->assertSame($lines, $layout->boxed($icon, 6, 3));
        $this->assertCount(1, PixelGraphics::sentImages($terminal), 'drawn again, it is not sent again');

        $other = $layout->boxed(PngFixture::bytes(64, 64, [0, 0, 200]), 6, 3);
        $this->assertNotSame($lines, $other, 'another picture of the same size is another image');
        $layout->boxed($icon, 4, 2);
        $layout->boxed($icon, 2, 2);
        $this->assertCount(4, PixelGraphics::sentImages($terminal), 'and the same picture at another size, too');
        $this->assertNotNull($layout->boxed($icon, 1, 1), 'one cell is room enough');
    }

    public function testAPictureOfAnotherShapeIsCentredInItsBox(): void
    {
        $layout = new ImageLayout();
        $wide = PngFixture::bytes(160, 16);

        $lines = $layout->boxed($wide, 20, 5);
        $this->assertCount(5, $lines);
        $this->assertSame([\str_repeat(' ', times: 20), \str_repeat(' ', times: 20)], [$lines[0], $lines[1]], 'one row tall, the rows over it blank');
        $this->assertSame([\str_repeat(' ', times: 20), \str_repeat(' ', times: 20)], [$lines[3], $lines[4]], 'and under it');
        $this->assertStringContainsString('▀', $lines[2]);
        $this->assertSame(20, AnsiUtils::visibleWidth($lines[2]));

        $tall = $layout->boxed(PngFixture::bytes(16, 64), columns: 20, rows: 2);
        $this->assertSame([2, 2], [\count($tall), \count(\array_filter($tall, static fn (string $line): bool => \str_contains($line, '▀')))]);
        $this->assertSame(9, \strspn(AnsiUtils::stripAnsiCodes($tall[0]), characters: ' '), 'one column wide, nine to its left');
        $this->assertSame(20, AnsiUtils::visibleWidth($tall[0]));

        $this->assertSame([20, 1], $layout->fits($wide, columns: 20, rows: 5));
        $this->assertSame([1, 2], $layout->fits(PngFixture::bytes(16, 64), columns: 20, rows: 2));
        $this->assertNull($layout->fits('not a png', 20, 5));
    }

    public function testWhatCannotBeDrawnIsNull(): void
    {
        $layout = new ImageLayout();
        $this->assertNull($layout->boxed('not a png', 6, 3));
        $this->assertNull($layout->boxed(PngFixture::bytes(8, 8), 0, 3));
        $this->assertNull($layout->boxed(PngFixture::bytes(8, 8), 6, 0));
        $this->assertNull(new ImageLayout(raster: new PngRaster(available: false))->boxed(PngFixture::bytes(8, 8), 6, 3));
    }
}
