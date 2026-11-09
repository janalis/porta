<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Graphics;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;
use Porta\Ui\Graphics\RasterImage;
use Symfony\Component\Process\ExecutableFinder;

/** Any picture off the network, as the PNG the terminal is sent. */
final class RasterImageTest extends TestCase
{
    use PumpsLoop;

    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10"><rect width="20" height="10" fill="red"/></svg>';

    private string $path = '';

    #[Before]
    public function keepPath(): void
    {
        $this->path = (string) \getenv('PATH');
    }

    protected function tearDown(): void
    {
        \putenv('PATH='.$this->path);
    }

    private function png(RasterImage $raster, string $bytes): ?string
    {
        $answers = [];
        $raster->png($bytes, static function (?string $png) use (&$answers): void {
            $answers[] = $png;
        });
        // A converter runs off the loop: the answer comes once it is done.
        $this->await(static function () use (&$answers): bool {
            return [] !== $answers;
        }, what: 'the PNG');
        $this->assertCount(1, $answers, 'answered exactly once');

        return $answers[0];
    }

    /** @return array{string, string} */
    private static function gd(): array
    {
        $image = \imagecreatetruecolor(2000, height: 10);
        \ob_start();
        \imagejpeg($image);
        $jpeg = (string) \ob_get_clean();
        \ob_start();
        \imagegif(\imagecreatetruecolor(30, height: 20));

        return [$jpeg, (string) \ob_get_clean()];
    }

    public function testItTellsWhatAPictureIs(): void
    {
        [$jpeg, $gif] = self::gd();
        $this->assertSame('png', RasterImage::kind(PngFixture::bytes(2, 2)));
        $this->assertSame('jpeg', RasterImage::kind($jpeg));
        $this->assertSame('gif', RasterImage::kind($gif));
        $this->assertSame('gif', RasterImage::kind('GIF87a...'));
        $this->assertSame('webp', RasterImage::kind('RIFF1234WEBPVP8 '));
        $this->assertNull(RasterImage::kind('RIFF1234WAVEfmt '));
        $this->assertSame('svg', RasterImage::kind(self::SVG));
        $this->assertSame('svg', RasterImage::kind("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- made by hand -->\n<!DOCTYPE svg>\n<svg/>"));
        $this->assertNull(RasterImage::kind('<html><svg/></html>'));
        $this->assertNull(RasterImage::kind('hello'));
    }

    public function testAPngPassesAsItIsAndOtherPicturesAreRedrawn(): void
    {
        [$jpeg, $gif] = self::gd();
        $raster = new RasterImage(function (): never {
            $this->fail('no SVG here');
        });
        $png = PngFixture::bytes(3, 3);
        $this->assertSame($png, $this->png($raster, $png));
        $this->assertSame([RasterImage::MAX_WIDTH, 5], PngDimensions::read((string) $this->png($raster, $jpeg)), 'narrowed to the widest kept');
        $this->assertSame([30, 20], PngDimensions::read((string) $this->png($raster, $gif)));
        $this->assertNull($this->png($raster, 'GIF89a broken'));
        $this->assertNull($this->png($raster, 'neither'));
        $this->assertNull($this->png(new RasterImage(gd: false), $jpeg), 'no GD, no JPEG');
    }

    public function testAPictureOfTooManyPixelsIsNotDecoded(): void
    {
        foreach ([[8000, 2001], [2001, 8000]] as [$width, $height]) {
            \ob_start();
            \imagegif(\imagecreate($width, $height));
            $this->assertNull($this->png(new RasterImage(), (string) \ob_get_clean()), $width.'×'.$height.' is past the bound, whichever way it stands');
        }
    }

    public function testAHugeCanvasIsNotDecoded(): void
    {
        // A header claiming 5000×5000 is refused before GD allocates it.
        $gif = 'GIF89a'.\pack('v', 5000).\pack('v', 5000)."\x00\x00\x00;";
        $this->assertNull($this->png(new RasterImage(), $gif));
    }

    public function testAnSvgIsDrawnByLibrsvgWhenItIsInstalled(): void
    {
        $handed = [];
        $raster = new RasterImage(static function (string $svg, \Closure $onPng) use (&$handed): void {
            $handed[] = $svg;
            $onPng(1 === \count($handed) ? PngFixture::bytes(512, 256) : 'not a png');
        });
        $this->assertSame([512, 256], PngDimensions::read((string) $this->png($raster, self::SVG)));
        $this->assertNull($this->png($raster, self::SVG), 'what the converter answers must be a PNG');
        $this->assertSame([self::SVG, self::SVG], $handed);
    }

    public function testRsvgConvertIsDrivenOverStdinAtAFixedWidth(): void
    {
        $bin = new TempProject();
        $bin->write('rsvg-convert', "#!/bin/sh\n[ \"\$*\" = \"-f png -w ".RasterImage::SVG_WIDTH."\" ] || exit 3\ngrep -q '<svg' || exit 4\nprintf 'PNG-OUT'\n");
        \chmod($bin->path('rsvg-convert'), permissions: 0o755);
        \putenv('PATH='.$bin->dir);
        $this->assertNull($this->png(new RasterImage(), self::SVG), 'what it drew is no PNG');

        $bin->write('rsvg-convert', "#!/bin/sh\ncat >/dev/null\nexit 1\n");
        $this->assertNull($this->png(new RasterImage(), self::SVG), 'a failed conversion is nothing');

        \putenv('PATH='.$bin->path('missing'));
        $this->assertNull($this->png(new RasterImage(), self::SVG), 'none installed: the SVG is skipped');
    }

    public function testARealRsvgConvertDrawsTheDocument(): void
    {
        if (null === new ExecutableFinder()->find('rsvg-convert')) {
            $this->markTestSkipped('librsvg is not installed');
        }
        $this->assertSame([RasterImage::SVG_WIDTH, 256], PngDimensions::read((string) $this->png(new RasterImage(), self::SVG)));
    }
}
