<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * Images the page draws in pixels: a local PNG alone in its paragraph (or an
 * `<img>` in an HTML block) sent to the terminal as it is, sized in cells from
 * its header or its `width`, and drawn behind placeholder text — and, when it
 * cannot be, the alt text it falls back to.
 */
final class MarkdownImagesTest extends TestCase
{
    private TempProject $project;
    private TerminalGraphics $graphics;
    private VirtualTerminal $terminal;

    protected function setUp(): void
    {
        $this->project = new TempProject([
            'docs/wide.png' => self::png(160, 80),
            'docs/tall.png' => self::png(40, 2000),
            'docs/fake.png' => 'GIF89a not a png',
            'docs/cut.png' => "\x89PNG\r\n\x1a\n",
            'docs/empty.png' => self::png(0, 10),
            'docs/copy.png' => self::png(160, 80),
            'docs/needle.png' => self::png(8, 2000),
            'docs/column.png' => self::png(100, 2000),
            'docs/dot.png' => self::png(1, 1),
            'docs/odd.png' => "\x89PNG\r\n\x1a\n".\pack('N', 13).'tEXt'.\pack('NN', 16, 16),
        ]);
        [$this->graphics, $this->terminal] = PixelGraphics::answered(cellWidth: 8, cellHeight: 16);
    }

    /** The smallest PNG a size can be read from: the signature and an IHDR chunk. */
    private static function png(int $width, int $height): string
    {
        $ihdr = 'IHDR'.\pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".\pack('N', 13).$ihdr.\pack('N', \crc32($ihdr));
    }

    private function render(string $markdown, ?TerminalGraphics $graphics = null, int $width = 40): RenderedDoc
    {
        return new MarkdownRenderer(new Styles(DarkTheme::definition()), null, null, $graphics ?? $this->graphics)->render($markdown, $width, [], $this->project->path('docs'));
    }

    public function testAnImageAloneIsDrawnInCellsThatKeepItsShape(): void
    {
        $doc = $this->render('![Wide](wide.png)');
        $this->assertCount(5, $doc->lines, '160×80 px over 8×16 cells: 20 columns, 5 rows');
        $this->assertSame(20, AnsiUtils::visibleWidth($doc->lines[0]));
        $this->assertStringContainsString("\u{100000}", $doc->lines[0], 'placement row 0, column 0');
        $this->assertSame('wide.png', $doc->target(0), 'the picture leads to its file');
        $this->assertCount(5, $doc->spansOfGroup(0), 'every row of it');
        $this->assertSame([0, 20], [$doc->spansOfGroup(0)[0]->col, $doc->spansOfGroup(0)[0]->endCol]);
        $this->assertStringStartsWith("\u{100000}", AnsiUtils::stripAnsiCodes($doc->lines[0]));
        $sent = $this->terminal->getOutput();
        $this->assertStringContainsString('a=t,f=100', $sent, 'the PNG sent as it is');
        $this->assertStringContainsString(\base64_encode(self::png(160, 80)), $sent, 'the whole file, not only the header read to size it');
        $this->assertStringContainsString(',c=20,r=5,', $sent);
    }

    public function testAWidthTheImgAsksForIsHonouredAndAPercentageIsOfThePage(): void
    {
        $this->assertSame(10, AnsiUtils::visibleWidth($this->render('<img src="wide.png" width="80">')->lines[0]));
        $this->assertSame(20, AnsiUtils::visibleWidth($this->render('<p align="center"><img src="wide.png" width="50%"></p>', null, 40)->lines[0]) - 10, 'centred: half the page, ten columns in');
    }

    public function testATallImageIsCappedSoThePageStaysAPage(): void
    {
        $doc = $this->render('![](tall.png)');
        $this->assertCount(24, $doc->lines);
        $this->assertSame(1, AnsiUtils::visibleWidth($doc->lines[0]), '5 columns × 24/125 rows, rounded');
        $this->assertSame(1, AnsiUtils::visibleWidth($this->render('![](needle.png)')->lines[0]));
        $this->assertSame(2, AnsiUtils::visibleWidth($this->render('![](column.png)')->lines[0]), '13 × 24/130 = 2.4');
    }

    public function testAnImageThePageCannotDrawKeepsItsAltText(): void
    {
        foreach ([
            '![Remote](https://x.org/a.png)',
            '![Fake](fake.png)',
            '![Gone](gone.png)',
            '![Cut](cut.png)',
            '![Empty](empty.png)',
            '![Odd](odd.png)',
            'Text ![Inline](wide.png) beside',
        ] as $markdown) {
            $this->assertStringContainsString('[', AnsiUtils::stripAnsiCodes($this->render($markdown)->lines[0]), $markdown);
        }
        $this->assertStringNotContainsString('[', AnsiUtils::stripAnsiCodes($this->render('![Dot](dot.png)')->lines[0]), 'a 1×1 PNG still draws');
        [$silent] = PixelGraphics::unanswered();
        $this->assertSame('[Wide]', \trim(AnsiUtils::stripAnsiCodes($this->render('![Wide](wide.png)', $silent)->lines[0])), 'a terminal without the protocol');
        $this->assertSame(
            '[Wide]',
            \trim(AnsiUtils::stripAnsiCodes(new MarkdownRenderer(new Styles(DarkTheme::definition()), null, null, $this->graphics)->render('![Wide](wide.png)', 40)->lines[0])),
            'a page with no file of its own',
        );
    }

    public function testTheSameImageIsSentOnceAndFreedWhenThePageStopsShowingIt(): void
    {
        $renderer = new MarkdownRenderer(new Styles(DarkTheme::definition()), null, null, $this->graphics);
        $renderer->render("![a](wide.png)\n\n![b](wide.png)", 40, [], $this->project->path('docs'));
        $this->assertSame(1, \substr_count($this->terminal->getOutput(), needle: 'a=t,'));
        $this->terminal->clearOutput();
        $renderer->render('no image', 40, [], $this->project->path('docs'));
        $this->assertStringContainsString('a=d,d=I,', $this->terminal->getOutput());
    }

    public function testAnInlineImgInsideALinkKeepsItsWidth(): void
    {
        $this->assertSame(10, AnsiUtils::visibleWidth($this->render('[<img src="wide.png" width="80">](x.md)')->lines[0]));
    }

    public function testAskedWidthsRoundToCellsAndKeepTheShape(): void
    {
        foreach (['2' => [1, 1], '85' => [11, 3], '73' => [9, 2]] as $asked => $shape) {
            $doc = $this->render('<img src="wide.png" width="'.$asked.'">');
            $this->assertSame($shape, [AnsiUtils::visibleWidth($doc->lines[0]), \count($doc->lines)], "width {$asked}");
        }
    }

    public function testOnARetinaScreenAskedWidthsAreLogicalPixels(): void
    {
        $this->graphics->setPixelRatio(2.0);
        $this->assertSame(20, AnsiUtils::visibleWidth($this->render('![W](wide.png)')->lines[0]), 'its own pixels: as at 1x');
        $this->assertSame(20, AnsiUtils::visibleWidth($this->render('<img src="wide.png" width="80">')->lines[0]));
        $this->assertSame(10, AnsiUtils::visibleWidth($this->render('<img src="wide.png" width="25%">')->lines[0]));
    }

    public function testWhitespaceBesideAnImageLeavesItAlone(): void
    {
        $this->assertCount(5, $this->render('<span></span> ![Wide](wide.png) <!-- caption -->')->lines);
    }

    public function testEachFileIsItsOwnImageAnEditIsSentAgainAndEveryUnusedOneFreed(): void
    {
        $renderer = new MarkdownRenderer(new Styles(DarkTheme::definition()), null, null, $this->graphics);
        $docs = $this->project->path('docs');
        $renderer->render("![a](wide.png)\n\n![b](copy.png)", 40, [], $docs);
        $this->assertSame(2, \substr_count($this->terminal->getOutput(), needle: 'a=t,'));
        $this->terminal->clearOutput();
        \touch($this->project->path('docs/wide.png'), \time() + 60);
        \clearstatcache();
        $renderer->render("![a](wide.png)\n\n![b](copy.png)", 40, [], $docs);
        $this->assertSame(1, \substr_count($this->terminal->getOutput(), needle: 'a=t,'), 'only the file that changed');
        $this->terminal->clearOutput();
        $renderer->render('![a](wide.png)', 40, [], $docs);
        $this->assertStringContainsString('a=d,d=I,', $this->terminal->getOutput(), 'copy.png freed though wide.png, before it, is kept');
    }
}
