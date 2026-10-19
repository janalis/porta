<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Graphics;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PngFixture;
use Porta\Ui\Graphics\HalfBlockRow;
use Porta\Ui\Graphics\PngDimensions;
use Porta\Ui\Graphics\PngRaster;

final class PngRasterTest extends TestCase
{
    public function testHalfBlockRunsSpacesAndMissingHalves(): void
    {
        $red = [200, 40, 40];
        $blue = [0, 0, 200];
        $this->assertSame(' ', HalfBlockRow::encode([[null, null]]));
        $this->assertSame('', HalfBlockRow::encode([]));
        $this->assertSame(
            " \x1b[38;2;200;40;40;48;2;0;0;200m▀▀\x1b[0m \x1b[38;2;30;30;30;48;2;0;0;200m▀\x1b[38;2;200;40;40;48;2;30;30;30m▀\x1b[0m",
            HalfBlockRow::encode([[null, null], [$red, $blue], [$red, $blue], [null, null], [null, $blue], [$red, null]]),
        );
        $this->assertSame("\x1b[38;2;200;40;40;48;2;0;0;200m▀\x1b[0m ", HalfBlockRow::encode([[$red, $blue], [null, null]]));
    }

    public function testFittedRowsAndHeaderValidation(): void
    {
        $bytes = PngFixture::bytes(4, 4);
        $this->assertSame([4, 4], PngDimensions::read($bytes));
        $this->assertNull(PngDimensions::read('not PNG'));
        $this->assertNull(PngDimensions::read(\str_replace('IHDR', replace: 'nope', subject: $bytes)));
        $this->assertNull(PngDimensions::read(\substr_replace($bytes, \pack('N', 0), offset: 16, length: 4)));
        $this->assertNull(PngDimensions::read(\substr_replace($bytes, \pack('N', 0), offset: 20, length: 4)));
        $row = "\x1b[38;2;200;40;40;48;2;200;40;40m▀▀\x1b[0m";
        $this->assertSame([$row, $row], new PngRaster()->rows($bytes, columns: 2, rows: 2));
        $this->assertSame(["\x1b[38;2;200;40;40;48;2;200;40;40m▀\x1b[0m"], new PngRaster()->rows($bytes, columns: 0, rows: 0));
        $this->assertNull(new PngRaster(available: false)->rows($bytes, columns: 2, rows: 1));
        $this->assertNull(new PngRaster()->rows('not PNG', columns: 2, rows: 1));
        $this->assertNull(new PngRaster()->rows(\substr($bytes, offset: 0, length: 24), columns: 2, rows: 1));
        $this->assertNull(new PngRaster()->rows($bytes.\str_repeat(' ', 20 * 1024 * 1024), columns: 2, rows: 1));
        $this->assertNull(new PngRaster()->rows(PngFixture::bytes(4001, 4000), columns: 2, rows: 1));
    }

    public function testTransparentPixelsBlendOnTheCheckerboard(): void
    {
        $gd = \imagecreatetruecolor(16, height: 2);
        \imagealphablending($gd, enable: false);
        \imagesavealpha($gd, enable: true);
        \imagefilledrectangle($gd, x1: 0, y1: 0, x2: 15, y2: 1, color: (int) \imagecolorallocatealpha($gd, red: 0, green: 0, blue: 0, alpha: 127));
        \ob_start();
        \imagepng($gd);
        $bytes = (string) \ob_get_clean();
        $this->assertSame(
            ["\x1b[38;2;90;90;90;48;2;90;90;90m".\str_repeat('▀', times: 8)."\x1b[38;2;140;140;140;48;2;140;140;140m".\str_repeat('▀', times: 8)."\x1b[0m"],
            new PngRaster()->rows($bytes, columns: 16, rows: 1),
        );
    }
}
