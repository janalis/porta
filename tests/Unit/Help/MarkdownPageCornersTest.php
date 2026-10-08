<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Highlight\CodeHighlighter;
use Porta\Tests\Support\ClosureHighlightProvider;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The page's less travelled paths: the minor heading levels, code the click
 * cannot map, HTML that nests oddly, links and images inside each other, and
 * what a rendered page answers about itself. Split from
 * {@see MarkdownRendererTest} to keep each class under mago's method count.
 */
final class MarkdownPageCornersTest extends TestCase
{
    private Styles $styles;

    protected function setUp(): void
    {
        $this->styles = new Styles(DarkTheme::definition());
    }

    private function render(string $markdown, int $width = 40): RenderedDoc
    {
        return new MarkdownRenderer($this->styles)->render($markdown, $width);
    }

    /** @return list<string> */
    private static function plain(RenderedDoc $doc): array
    {
        return \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $doc->lines);
    }

    public function testTheMinorHeadingsStepDownAndAnEmptyOneStillHoldsItsPlace(): void
    {
        $doc = $this->render("##### Five\n###### Six\n#\n");
        $muted = $this->styles->of(Role::Muted)->getAnsiRestore();
        $this->assertSame($muted.Sgr::BOLD.'Five'.Sgr::RESET, $doc->lines[0]);
        $this->assertSame($muted.'Six'.Sgr::RESET, $doc->lines[3]);
        $this->assertSame(['Five', '', '', 'Six', '', '', '', \str_repeat(Glyphs::MD_HEADING_RULE, times: 40)], self::plain($doc));
        $this->assertSame(6, $doc->anchor(''), 'even an empty heading is somewhere to go');
    }

    public function testACodeLineWithATabIsDrawnExpandedButMapsNowhereFiner(): void
    {
        $doc = $this->render("```\na\tb\n```\n");
        $this->assertSame('  a   b', self::plain($doc)[1]);
        $this->assertSame([], $doc->cells[1]);
        $this->assertSame([1, 0], $doc->sourcePointAt(1, 3), 'the line, not the byte');
    }

    public function testTheCodeColourMemoIsBoundedAndColoursAgainAfterItEmpties(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $code, string $language): string => $code);
        $renderer = new MarkdownRenderer($this->styles, new CodeHighlighter($provider));
        $blocks = \implode("\n", \array_map(static fn (int $n): string => "```php\n\$a = {$n};\n```\n", \range(0, end: 256)));
        $renderer->render($blocks, 40);
        $this->assertSame(257, $provider->calls);
        $renderer->render("```php\n\$a = 0;\n```\n", 40);
        $this->assertSame(258, $provider->calls, 'the 257th block emptied the memo, so the first is coloured again');
        $renderer->refreshTheme();
        $renderer->render("```php\n\$a = 0;\n```\n", 40);
        $this->assertSame(259, $provider->calls, 'a theme switch forgets every colouring');
    }

    public function testADetailsSectionKeepsBothItsOwnTextAndTheBlocksAfterIt(): void
    {
        $markdown = "<details><summary>S</summary>\nown text\n\nMarkdown *after*.\n</details>\n";
        $this->assertSame([Glyphs::MD_DETAILS_OPEN.' S', '  own text', '', '  Markdown after.'], self::plain(new MarkdownRenderer($this->styles)->render($markdown, 30, [0 => true])));
    }

    public function testInlineHtmlThatNestsOddlyStillUnwinds(): void
    {
        $doc = $this->render('<b><i>both</b> plain</i> <?php echo 1; ?> <span>kept</span> <x-tag>shown</x-tag>', 70);
        $this->assertSame(['both plain <?php echo 1; ?> kept <x-tag>shown</x-tag>'], self::plain($doc));
        $this->assertStringContainsString(Sgr::BOLD.Sgr::ITALIC.'both'.Sgr::RESET.' plain', $doc->lines[0], 'closing <b> closes the <i> opened inside it');
    }

    public function testAnHtmlBlockOfPictureSourcesDrawsItsImageAndSkipsLeadingSpace(): void
    {
        $doc = $this->render("<p>   <b>x</b></p>\n<picture>\n<source srcset=\"dark.png\">\n<img src=\"light.png\" alt=\"Shot\">\n</picture>\n");
        $this->assertSame(['x', '', '[Shot]'], self::plain($doc));
        $this->assertSame('light.png', $doc->target(0));
    }

    public function testALessThanThatOpensNoTagIsText(): void
    {
        $this->assertSame(['1 <3 and 4 > 2'], self::plain($this->render("<p>1 <3 and 4 > 2</p>\n")));
    }

    public function testALinkInsideALinkReadsAsTextAndAnImageInsideOneLeadsWhereItDoes(): void
    {
        $doc = $this->render('<a href="out.md">see [inner](in.md) and ![pic](p.png)</a> then <img src="s.png" alt="Small">', 70);
        $this->assertSame(['see inner and [pic] then [Small]'], self::plain($doc));
        $this->assertSame(['out.md', 's.png'], [$doc->target(0), $doc->target(1)]);
        $this->assertSame(2, $doc->groupCount());
    }

    public function testAHeadingsAnchorIsItsPlainTextBreaksAndEmojiIncluded(): void
    {
        $doc = $this->render("Ship it :rocket:\nnow\n==\n\n![a\nb](x.png)\n");
        $this->assertSame(0, $doc->anchor('ship-it--now'), 'GitHub drops the emoji and keeps the space around it');
        $this->assertSame('[a b]', self::plain($doc)[3]);
    }

    public function testARenderedPageAnswersOnlyForWhatItDrew(): void
    {
        $doc = $this->render('[a](x)');
        $this->assertNull($doc->target(7));
        $this->assertNull($doc->sourcePointAt(5, 0));
        $this->assertSame([0, 1], $doc->sourcePointAt(0, 0), 'inside the link, the byte after its [');
        $this->assertSame([3, 0], new RenderedDoc(['x'], [], [3])->sourcePointAt(0, 0), 'a page without its source maps to its lines');
    }

    public function testAnUnclosedTagEndsWithItsParagraph(): void
    {
        $this->assertSame(1, $this->render("<a href=\"a.md\"><b>x\n\ny</b> z\n")->groupCount());
    }

    public function testDetailsWrittenBackToBackAreSectionsOfTheirOwn(): void
    {
        $closed = Glyphs::MD_DETAILS_CLOSED;
        $faq = "<details>\n<summary>A</summary>\n\nBody A.\n\n</details>\n<details>\n<summary>B</summary>\n\nBody B.\n\n</details>\n\nAfter.\n";
        $this->assertSame([$closed.' A', '', $closed.' B', '', 'After.'], self::plain($this->render($faq)));
        $this->assertSame([$closed.' A', '', $closed.' B'], self::plain($this->render("<details><summary>A</summary>a</details>\n<details><summary>B</summary>b</details>\n")));
        $open = new MarkdownRenderer(new Styles(DarkTheme::definition()))->render($faq, 40, [1 => true]);
        $this->assertSame([$closed.' A', '', Glyphs::MD_DETAILS_OPEN.' B', '  Body B.', '', 'After.'], self::plain($open), 'the second opens by its own number');
        $this->assertSame(6, $open->sourcePointAt(2, 0)[0] ?? null, 'B starts on its own line');
    }

    public function testEachRowOfMarkupShownAsWrittenLeadsToItsOwnLine(): void
    {
        $doc = $this->render("<ul>\n<li>x</li>\n</ul>\n");
        $this->assertSame([1, 2], $doc->sourcePointAt(1, 2), 'the row clicked, the byte under the pointer');
        $this->assertSame(\strlen("<ul>\n<l"), $doc->sourceAt(1, 2));
    }

    public function testASummaryWrittenOverSeveralLinesIsOneLine(): void
    {
        $this->assertSame([Glyphs::MD_DETAILS_CLOSED.' Why it is'], self::plain($this->render("<details><summary>\n  Why\n  it is\n</summary>\n\nx\n</details>\n")));
    }

    public function testATabInCodeStopsByColumnsAfterAWideGlyph(): void
    {
        $this->assertSame('  漢  b', self::plain($this->render("```\n漢\tb\n```\n"))[1]);
    }
}
