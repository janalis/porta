<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Help\Layout\SvgPictures;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;
use Porta\Ui\Graphics\RasterImage;

/** The SVG files a page shows, drawn once per version of each. */
final class SvgPicturesTest extends TestCase
{
    public function testEachVersionOfAFileIsDrawnOnceAndAHugeOneNever(): void
    {
        $project = new TempProject(['a.svg' => '<svg/>', 'huge.svg' => '<svg>'.\str_repeat(' ', SvgPictures::MAX_BYTES).'</svg>']);
        $handed = [];
        $svgs = new SvgPictures(static function (): void {}, new RasterImage(static function (string $svg, int $width, \Closure $onPng) use (&$handed): void {
            $handed[] = \strlen($svg);
            $onPng(PngFixture::bytes(2, 2));
        }));

        $this->assertSame([2, 2], PngDimensions::read((string) $svgs->png($project->path('a.svg'))));
        $svgs->png($project->path('a.svg'));
        $this->assertSame([6], $handed, 'once');

        \touch($project->path('a.svg'), \time() + 10);
        \clearstatcache();
        $svgs->png($project->path('a.svg'));
        $this->assertSame([6, 6], $handed, 'an edited file is drawn again');

        $this->assertNull($svgs->png($project->path('huge.svg')));
        $this->assertSame([6, 6], $handed, 'one too large is never read, nor handed on');
    }

    /** One drawn at once, as it is asked, is in the layout that asked: there is nothing to lay out again. */
    public function testAnAnswerAsItIsAskedIsNoNews(): void
    {
        $project = new TempProject(['a.svg' => '<svg/>']);
        $relaid = 0;
        $svgs = new SvgPictures(
            static function () use (&$relaid): void {
                ++$relaid;
            },
            new RasterImage(static function (string $svg, int $width, \Closure $onPng): void {
                $onPng(PngFixture::bytes(2, 2));
            }),
        );

        $this->assertNotNull($svgs->png($project->path('a.svg')));
        $this->assertSame(0, $relaid);
    }
}
