<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Help\Layout\PageWords;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Highlight\CodeHighlighter;
use Porta\Tests\Support\ClosureHighlightProvider;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The F1 browser's and the preview's page, as GitHub would lay the markdown
 * out: block shapes, inline styles as self-contained SGR regions, link spans
 * over exactly the cells they draw, and the source behind every line and cell.
 * Tables have their own file, {@see MarkdownTableTest}.
 */
final class MarkdownRendererTest extends TestCase
{
    private Styles $styles;

    protected function setUp(): void
    {
        $this->styles = new Styles(DarkTheme::definition());
    }

    private function render(string $markdown, int $width = 40, ?CodeHighlighter $highlighter = null): RenderedDoc
    {
        return new MarkdownRenderer($this->styles, $highlighter)->render($markdown, $width);
    }

    /** @return list<string> */
    private static function plain(RenderedDoc $doc): array
    {
        return \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $doc->lines);
    }

    public function testHeadingsLoseTheirMarkersAndTheFirstTwoLevelsAreRuledTheFullWidth(): void
    {
        $doc = $this->render("# One\n## Two\n### Three\n", 20);
        $this->assertSame(['One', \str_repeat(Glyphs::MD_HEADING_RULE, times: 20), '', '', 'Two', \str_repeat(Glyphs::MD_RULE, times: 20), '', '', 'Three'], self::plain($doc));
        $bright = $this->styles->of(Role::Bright)->getAnsiRestore();
        $this->assertSame($bright.\str_repeat(Glyphs::MD_HEADING_RULE, times: 20).Sgr::RESET, $doc->lines[1]);
        $this->assertStringStartsWith($this->styles->of(Role::Emphasis)->getAnsiRestore().Sgr::BOLD, $doc->lines[0]);
        $this->assertStringStartsWith($bright.Sgr::BOLD, $doc->lines[4]);
        $this->assertSame(Sgr::BOLD.'Three'.Sgr::RESET, $doc->lines[8]);
    }

    public function testAHeadingIsAnAnchorAsGithubSpellsIt(): void
    {
        $doc = $this->render("# Hello, World!\n\ntext\n\n## Hello World\n\n## Hello World\n");
        $this->assertSame(0, $doc->anchor('hello-world'));
        $this->assertSame(0, $doc->anchor('Hello-World'), 'matched case-insensitively');
        $this->assertSame(['hello-world', 'hello-world-1', 'hello-world-2'], \array_keys($doc->anchors));
        $this->assertNull($doc->anchor('nowhere'));
    }

    public function testInlineStylesAreSelfContainedRegions(): void
    {
        $doc = $this->render('Some **bold**, _it_, ~~gone~~ and `code`.', 60);
        $code = $this->styles->of(Role::Emphasis)->getAnsiRestore().$this->styles->of(Role::Toolbar)->getAnsiRestore();
        $this->assertSame(
            'Some '.Sgr::BOLD.'bold'.Sgr::RESET.', '.Sgr::ITALIC.'it'.Sgr::RESET.', '.Sgr::STRIKETHROUGH.'gone'.Sgr::RESET.' and '.$code."\u{00A0}code\u{00A0}".Sgr::RESET.'.',
            $doc->lines[0],
        );
    }

    public function testWithColoursOffThePageIsPlainText(): void
    {
        $doc = new MarkdownRenderer(new Styles(MonochromeTheme::definition()))->render("# T\n\n**b** [l](x) `c` <kbd>k</kbd>\n\n```php\ncode\n```\n> q\n\n| a |\n|---|\n| b |\n", 30);
        foreach ($doc->lines as $line) {
            $this->assertStringNotContainsString("\x1b", $line);
        }
        $this->assertContains('  code'.\str_repeat(' ', times: 24), $doc->lines, 'the code line padded to the width');
        $this->assertContains(\str_repeat(' ', times: 26).'php ', $doc->lines, 'the code box keeps its shape, its label row as wide as the page');
    }

    public function testAParagraphJoinsItsLinesAndWrapsToTheWidth(): void
    {
        $doc = $this->render("one two\nthree four five six seven", 20);
        $this->assertSame(['one two three four', 'five six seven'], self::plain($doc));
    }

    public function testAHardBreakStartsANewLine(): void
    {
        $this->assertSame(['one', 'two', 'three', 'four'], self::plain($this->render("one  \ntwo\\\nthree<br>four")));
    }

    public function testAWordWiderThanTheLineIsCutAndAWrappedCjkRunLosesNothing(): void
    {
        $this->assertSame(['abcdefghijklmnopqrst', 'uvwxyz'], self::plain($this->render(\implode('', \range('a', end: 'z')), 20)), 'cut at the width');
        $cjk = \str_repeat('漢字', times: 15);
        $this->assertSame($cjk, \implode('', self::plain($this->render($cjk, 20))));
        $this->assertSame(\array_fill(0, count: 3, value: \str_repeat('漢字', times: 5)), self::plain($this->render($cjk, 20)), 'twenty columns a line, no glyph split');
        $this->assertSame(["\u{00E9}".\str_repeat('a', times: 19), 'aaaaa'], self::plain($this->render("\u{00E9}".\str_repeat('a', times: 24), 20)), 'measured by columns, not bytes');
    }

    public function testPunctuationTouchingALinkOrEmphasisStaysTouchingIt(): void
    {
        $this->assertSame(['See the guide. Or this!'], self::plain($this->render('See [the guide](g.md). Or *this*!', 60)));
        // …and across a wrap: the comma does not start a line, its word moves down with it.
        $this->assertSame(['xxxxxxxxxxxxxxxxx', 'both, yy'], self::plain($this->render('xxxxxxxxxxxxxxxxx **both**, yy', 22)));
        $this->assertSame(['xxxxxxxxxxxxxxxxx', 'ab.'], self::plain($this->render('xxxxxxxxxxxxxxxxx [a](x.md)[b](y.md).', 20)), 'a run of several glued words moves whole');
        $this->assertSame(['xxxxxxxxxxxxxxxxx', 'aabb.', \str_repeat('c', times: 17)], self::plain($this->render('xxxxxxxxxxxxxxxxx [aa](x.md)[bb](y.md). '.\str_repeat('c', times: 17), 22)));
        $this->assertSame(['x', 'aaaaabbbbbcccccccccc'], self::plain($this->render('x [aaaaa](a.md)bbbbb**cccccccccc**', 20)), 'a run exactly filling the next line moves');
        $this->assertSame(['x aaaaaabbbbb', 'cccccccccc'], self::plain($this->render('x [aaaaaa](a.md)bbbbb**cccccccccc**', 20)), 'one column more and it stays');
        // A glued run longer than a line breaks where it must, losing nothing.
        $this->assertSame(['aaaa link', 'bbbbbbbbbbbbbbbbbbbb', 'bbbbbbbb'], self::plain($this->render('aaaa [link](x.md)'.\str_repeat('b', times: 28), 20)));
    }

    public function testALinkSpanCoversExactlyTheCellsItDrawsAcrossAWrap(): void
    {
        $doc = $this->render('Read [the long guide to everything](docs/guide.md) now', 20);
        $spans = $doc->spansOfGroup(0);
        $this->assertCount(2, $spans);
        $drawn = '';
        foreach ($spans as $span) {
            $drawn .= AnsiUtils::sliceByColumn(AnsiUtils::stripAnsiCodes($doc->lines[$span->line]), $span->col, $span->endCol - $span->col).'|';
        }
        $this->assertSame('the long guide|to everything|', $drawn);
        $this->assertSame('docs/guide.md', $doc->target(0));
    }

    public function testLinksAreGroupedInReadingOrderAndAnchorsAndMailFollow(): void
    {
        $doc = $this->render("[a](#install) <me@example.com> www.example.com\n\n## Install", 60);
        $this->assertSame(3, $doc->groupCount());
        $this->assertSame(['#install', 'mailto:me@example.com', 'http://www.example.com'], [$doc->target(0), $doc->target(1), $doc->target(2)]);
    }

    public function testAnImageThePageCannotDrawIsItsAltTextLeadingToTheFile(): void
    {
        $doc = $this->render("![The logo](img/logo.png) and ![](shot.png?raw=1)\n", 60);
        $this->assertSame(['[The logo] and [shot.png]'], self::plain($doc));
        $this->assertSame('img/logo.png', $doc->target(0));
    }

    public function testBulletsChangeWithDepthAndItemsHangFromTheirMarker(): void
    {
        $doc = $this->render("- one two three four five\n  - nested\n    - deeper\n- after\n", 20);
        $this->assertSame(
            [
                Glyphs::MD_BULLET.' one two three four',
                '  five',
                '  '.Glyphs::MD_BULLET_2.' nested',
                '    '.Glyphs::MD_BULLET_3.' deeper',
                Glyphs::MD_BULLET.' after',
            ],
            self::plain($doc),
        );
    }

    public function testOrderedNumbersKeepTheirStartAndAlignTheirText(): void
    {
        $markdown = "8. eight\n9. nine\n10. ten\n";
        $this->assertSame([' 8. eight', ' 9. nine', '10. ten'], self::plain($this->render($markdown)));
    }

    public function testALooseListKeepsABlankBetweenItems(): void
    {
        $this->assertSame([Glyphs::MD_BULLET.' a', '', Glyphs::MD_BULLET.' b'], self::plain($this->render("- a\n\n- b\n")));
    }

    public function testATaskBoxIsALinkThatTogglesItsSource(): void
    {
        $markdown = "- [x] done\n- [ ] open\n";
        $doc = $this->render($markdown);
        $this->assertSame([Glyphs::MD_BULLET.' ['.Glyphs::MD_CHECK.'] done', Glyphs::MD_BULLET.' [ ] open'], self::plain($doc));
        $box = $doc->spansOfGroup(0)[0];
        $this->assertSame([0, 2, 5], [$box->line, $box->col, $box->endCol]);
        $this->assertSame('task:2-5', $box->target);
        $this->assertStringContainsString($this->styles->of(Role::TabAccent)->getAnsiRestore().'['.Glyphs::MD_CHECK.']'.Sgr::RESET, $doc->lines[0], 'a done box in the accent');
        $this->assertStringContainsString($this->styles->of(Role::Muted)->getAnsiRestore().'[ ]'.Sgr::RESET, $doc->lines[1], 'an open one muted');
        $this->assertSame('[x]', \substr($markdown, offset: 2, length: 3));
        $this->assertSame('task:13-16', $doc->target(1));
    }

    public function testAQuoteKeepsItsBarOnEveryLineAndQuotesNest(): void
    {
        $doc = $this->render("> one\n>\n> > two\n");
        $bar = Glyphs::MD_QUOTE_BAR;
        $this->assertSame([$bar.' one', $bar, $bar.' '.$bar.' two'], self::plain($doc));
        $this->assertStringStartsWith($this->styles->of(Role::Muted)->getAnsiRestore().$bar.Sgr::RESET.' ', $doc->lines[0]);
    }

    public function testAnAlertHasItsTitleAndColour(): void
    {
        $doc = new MarkdownRenderer($this->styles, null, new PageWords(static fn (string $key): string => \strtoupper($key)))->render("> [!WARNING]\n> Mind it.\n", 40);
        $bar = Glyphs::MD_ALERT_BAR;
        $this->assertSame([$bar.' WARNING', $bar.' Mind it.'], self::plain($doc));
        $warning = $this->styles->of(Role::StatusWarning)->getAnsiRestore();
        $this->assertStringStartsWith($warning.$bar.Sgr::RESET.' '.$warning.Sgr::BOLD, $doc->lines[0]);
        $this->assertSame(
            ['Note', 'Tip', 'Important', 'Warning', 'Caution'],
            \array_map(static fn (string $kind): string => \trim(self::plain(new MarkdownRenderer(new Styles(DarkTheme::definition()))->render('> [!'.$kind."]\n> x", 40))[0], $bar.' '), [
                'NOTE',
                'TIP',
                'IMPORTANT',
                'WARNING',
                'CAUTION',
            ]),
        );
    }

    public function testACodeBlockIsAShadedBoxThatCutsRatherThanWraps(): void
    {
        $doc = $this->render("```php\n\$a = 1; // a comment far too long to fit\n```\n", 24);
        $this->assertSame([\str_repeat(' ', times: 20).'php', '  $a = 1; // a commen…', ''], self::plain($doc), 'inset two columns each side');
        foreach ($doc->lines as $line) {
            $this->assertSame(24, AnsiUtils::visibleWidth($line));
            $this->assertStringStartsWith($this->styles->of(Role::Toolbar)->getAnsiRestore(), $line);
            $this->assertStringEndsWith(Sgr::RESET, $line);
        }
        $this->assertStringContainsString($this->styles->of(Role::Muted)->getAnsiRestore().'php'.Sgr::DEFAULT_FG.' '.Sgr::RESET, $doc->lines[0], 'the language muted, its row closed');
    }

    public function testACodeBlocksLanguageColoursItAndAResizeDoesNotColourItAgain(): void
    {
        $languages = [];
        $provider = new ClosureHighlightProvider(static function (string $code, string $language) use (&$languages): string {
            $languages[] = $language;

            return \str_replace('x', replace: "\x1b[31mx\x1b[39m", subject: $code);
        });
        $renderer = new MarkdownRenderer($this->styles, new CodeHighlighter($provider));
        $markdown = "```sh\nx y\n```\n\n```nope\nx\n```\n";
        $doc = $renderer->render($markdown, 30);
        $this->assertStringContainsString("\x1b[31mx\x1b[39m y", $doc->lines[1]);
        $this->assertSame(['bash'], $languages, 'sh is GitHub\'s name for bash, and an unknown language is not asked for');
        $this->assertStringNotContainsString("\x1b[31m", $doc->lines[5], 'an unknown language is drawn plain');
        $renderer->render($markdown, 50);
        $this->assertSame(1, $provider->calls, 'the colouring is remembered across widths');
    }

    public function testFootnotesAreNumberedGatheredAtTheEndAndLinkBothWays(): void
    {
        $doc = $this->render("Body[^b] and[^a].\n\n[^a]: Note A.\n[^b]: Note B.\n", 40);
        $this->assertSame(['Body[1] and[2].', '', \str_repeat(Glyphs::MD_RULE, times: 40), '1. Note B. '.Glyphs::MD_BACKREF, '2. Note A. '.Glyphs::MD_BACKREF], self::plain($doc));
        $this->assertSame('#fn-b', $doc->target(0));
        $this->assertSame(3, $doc->anchor('fn-b'));
        $this->assertSame(0, $doc->anchor('fnref-b'));
        $this->assertSame('#fnref-b', $doc->target(2));

        // A reference finds its definition case aside, as a link label does — both ways.
        $doc = $this->render("See[^Note].\n\n[^NOTE]: N.\n", 40);
        $this->assertSame('#fn-note', $doc->target(0));
        $this->assertSame(3, $doc->anchor('fn-note'));
        $this->assertSame('#fnref-note', $doc->target(1));
        $this->assertSame(0, $doc->anchor('fnref-note'));
    }

    public function testInlineHtmlIsDrawnNotShown(): void
    {
        $doc = $this->render('Press <kbd>Ctrl</kbd>+<b>F</b><!-- hidden --> <a href="x.md">here</a> <blink>odd</blink>', 60);
        $this->assertSame(['Press Ctrl+F here <blink>odd</blink>'], self::plain($doc));
        $this->assertStringContainsString(Sgr::REVERSE.'Ctrl'.Sgr::RESET, $doc->lines[0]);
        $this->assertSame('x.md', $doc->target(0));
    }

    public function testAnHtmlCommentBlockDrawsNothingAndStructureThePageCannotDrawIsShownMuted(): void
    {
        $doc = $this->render("<!-- a note -->\n\n<ul>\n<li>raw</li>\n</ul>\n");
        $this->assertSame(['<ul>', '<li>raw</li>', '</ul>'], self::plain($doc));
        $this->assertStringStartsWith($this->styles->of(Role::Muted)->getAnsiRestore(), $doc->lines[0]);
        $this->assertSame([2, 3, 4], $doc->origins, 'each row its own source line');
        $this->assertSame([3, 1], $doc->sourcePointAt(1, 1));
    }

    public function testAReadmeHeaderInHtmlIsDrawnCentredAsItsAuthorMeantIt(): void
    {
        $markdown = "<p align=\"center\">\n  <a href=\"https://x.org\"><img src=\"logo.svg\" alt=\"Logo\"></a>\n</p>\n<h1 align=\"center\">Porta</h1>\n<div>left<br>text &amp; <b>bold</b></div>\n";
        $doc = $this->render($markdown, 30);
        $this->assertSame([\str_repeat(' ', times: 12).'[Logo]', '', \str_repeat(' ', times: 12).'Porta', '', 'left', 'text & bold'], self::plain($doc));
        $this->assertSame('https://x.org', $doc->target(0), 'the image leads where its link does');
        $this->assertStringContainsString($this->styles->of(Role::Emphasis)->getAnsiRestore().Sgr::BOLD.'Porta', $doc->lines[2]);
        $this->assertStringStartsWith('left', $doc->lines[4], 'an uncentred block is not centred');
    }

    public function testADetailsSectionIsCollapsedUntilTheReaderOpensIt(): void
    {
        $markdown = "<details>\n<summary>More <b>about</b> it</summary>\n\nHidden *text*.\n\n- item\n</details>\n\nAfter.\n";
        $renderer = new MarkdownRenderer($this->styles);
        $closed = $renderer->render($markdown, 30);
        $this->assertSame([Glyphs::MD_DETAILS_CLOSED.' More about it', '', 'After.'], self::plain($closed));
        $this->assertSame('details:0', $closed->target(0));
        $span = $closed->spansOfGroup(0)[0];
        $this->assertSame([0, 15], [$span->col, $span->endCol], 'the marker and the summary');
        $open = $renderer->render($markdown, 30, [0 => true]);
        $this->assertSame([Glyphs::MD_DETAILS_OPEN.' More about it', '  Hidden text.', '', '  '.Glyphs::MD_BULLET.' item', '', 'After.'], self::plain($open));
    }

    public function testADetailsSectionWrittenAsOneBlockOpensToItsText(): void
    {
        $markdown = "<details><summary>Why</summary>Because <i>so</i>.</details>\n";
        $this->assertSame([Glyphs::MD_DETAILS_OPEN.' Why', '  Because so.'], self::plain(new MarkdownRenderer($this->styles)->render($markdown, 30, [0 => true])));
        $this->assertSame([Glyphs::MD_DETAILS_CLOSED.' Details'], self::plain($this->render("<details>\n\nno summary\n")));
    }

    public function testEmptyInputRendersNothingAndBlankLinesNeverTrail(): void
    {
        $this->assertSame([], $this->render('')->lines);
        $this->assertSame(['x'], self::plain($this->render("\n\nx\n\n\n")));
    }

    public function testThePageIsNeverNarrowerThanItsFloor(): void
    {
        $this->assertSame(\str_repeat(Glyphs::MD_RULE, times: 20), self::plain($this->render('---', 5))[0]);
    }

    public function testEveryLineKnowsTheSourceLineItsBlockStartsOnInOrder(): void
    {
        $doc = $this->render("# T\n\npara\ngraph\n\n- a\n- b\n\n```\nx\ny\n```\n", 40);
        $this->assertSame([0, 0, 0, 2, 2, 5, 6, 6, 8, 9, 10, 10], $doc->origins, 'a blank belongs to the block above it');
    }

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        yield 'prose' => ["Some *mixed* **text** with `code` and a [link](x.md).\nA second line, wrapped: ".\str_repeat('word ', times: 20)];
        yield 'containers' => ["> quoted *text*\n> - item **one**\n\n1. first\n   - [ ] task text\n"];
        yield 'escapes and entities' => ["A \\*star\\* &amp; caf&eacute; :rocket: done\n"];
        yield 'table' => ["| a | b |\n|---|:-:|\n| one | *two* |\n"];
        yield 'not ascii' => ["naïve café au lait\n"];
        yield 'ascii from byte 0' => ["plain words *here*\n"];
        yield 'cut three times' => [\str_repeat('abcdefghijklmnopqrstuvwxyz', times: 3)."\n"];
        yield 'markup shown as written' => ["<ul>\n<li>raw</li>\n</ul>\n"];
    }

    /**
     * The WYSIWYG seam: wherever a cell maps to the source exactly, the
     * character drawn there is the character written there.
     */
    #[DataProvider('documents')]
    public function testACellMapsToTheByteItDraws(string $markdown): void
    {
        $doc = $this->render($markdown, 30);
        $checked = 0;
        foreach ($doc->lines as $index => $line) {
            $plain = AnsiUtils::stripAnsiCodes($line);
            foreach ($doc->cells[$index] as [$start, $end, , , $text]) {
                if ('' === $text) {
                    continue;
                }
                for ($col = $start; $col < $end; ++$col) {
                    $byte = $doc->sourceAt($index, $col);
                    $this->assertNotNull($byte);
                    $drawn = AnsiUtils::sliceByColumn($plain, $col, 1);
                    $this->assertSame($drawn, \substr($markdown, $byte, \strlen($drawn)), \sprintf('line %d col %d', $index, $col));
                    ++$checked;
                }
            }
        }
        $this->assertGreaterThan(3, $checked);
        $this->assertNull($doc->sourceAt(0, 999));
    }

    public function testACellDrawnFromAnEscapeMapsToWhereTheEscapeIsWritten(): void
    {
        $markdown = 'x \\*y';
        $doc = $this->render($markdown);
        $this->assertSame('x *y', self::plain($doc)[0]);
        $this->assertSame(2, $doc->sourceAt(0, 2), 'the escape\'s start');
    }

    public function testACodeLineMapsToItsTextNotToAMarkerThatSpellsTheSame(): void
    {
        $doc = $this->render("> ```\n> >\n> ```\n");
        $row = \array_search('│   >', self::plain($doc), strict: true);
        $this->assertIsInt($row, 'the code row: the quote bar, the inset, the code');
        $this->assertSame(8, $doc->cells[$row][0][2], 'the code, not the quote marker at 6');
    }

    public function testListDepthCountsOnlyBulletsAndUnwindsAfterEachList(): void
    {
        $lines = \array_values(\array_filter(self::plain($this->render("- a\n\n1. b\n   - c\n\n- d\n")), static fn (string $line): bool => '' !== $line));
        $this->assertSame([Glyphs::MD_BULLET.' a', '1. b', '   '.Glyphs::MD_BULLET.' c', Glyphs::MD_BULLET.' d'], $lines);
        $this->assertSame('      '.Glyphs::MD_BULLET_3.' four', self::plain($this->render("- one\n  - two\n    - three\n      - four\n"))[3], 'the fourth level keeps the third mark');
        $this->assertSame(['8. eight', '9. nine'], self::plain($this->render("8. eight\n9. nine\n")));
        $this->assertSame([Glyphs::MD_BULLET.' aaaa bbbb cccc', '  dddd'], self::plain($this->render("- aaaa bbbb cccc dddd\n", 20)));
        $this->assertSame([Glyphs::MD_BULLET.' a', '', '  a2'], self::plain($this->render("- a\n\n  a2\n")));
        $this->assertSame([Glyphs::MD_BULLET.' a', Glyphs::MD_BULLET, Glyphs::MD_BULLET.' b'], self::plain($this->render("- a\n-\n- b\n")));
    }

    public function testQuotesAndAlertsKeepTheirWidthBodyAndEmptyBar(): void
    {
        $bar = Glyphs::MD_QUOTE_BAR;
        $this->assertSame([$bar.' aaaa bbbb cccc ddd', '', $bar.' aaaa bbbb cccc', $bar.' dddd'], self::plain($this->render("> aaaa bbbb cccc ddd\n\n> aaaa bbbb cccc dddd\n", 20)));
        $this->assertSame([$bar], self::plain($this->render(">\n")));
        $alert = Glyphs::MD_ALERT_BAR;
        $doc = $this->render("> [!NOTE]\n> one\n>\n> [x](y.md)\n");
        $this->assertSame([$alert.' Note', $alert.' one', $alert, $alert.' x'], self::plain($doc));
        $span = $doc->spansOfGroup(0)[0];
        $this->assertSame([3, 2, 3], [$span->line, $span->col, $span->endCol]);
    }

    public function testAFootnoteWrapsUnderItsNumberAndAnEmptyOneStillLinksBack(): void
    {
        $doc = $this->render("x[^a]\n\n[^a]: aaaa bbbb cccc dddd\n", 20);
        $this->assertSame(['x[1]', '', \str_repeat(Glyphs::MD_RULE, times: 20), '1. aaaa bbbb cccc', '   dddd '.Glyphs::MD_BACKREF], self::plain($doc));
        $back = $doc->spansOfGroup(1)[0];
        $this->assertSame([4, 8, 9], [$back->line, $back->col, $back->endCol]);
        $empty = $this->render("x[^a]\n\n[^a]:\n", 20);
        $this->assertSame('1.  '.Glyphs::MD_BACKREF, self::plain($empty)[3]);
        $back = $empty->spansOfGroup(1)[0];
        $this->assertSame([3, 4, 5], [$back->line, $back->col, $back->endCol]);
    }

    public function testInlineRunsKeepEveryChildEmojiAndNoTab(): void
    {
        $this->assertSame(['Ship it '."\u{1F680}"], self::plain($this->render('Ship it :rocket:')));
        $this->assertSame(['see x y now'], self::plain($this->render('*see [x **y**](y.md) now*', 60)));
        foreach ($this->render("a\tb `c\td`")->lines as $line) {
            $this->assertStringNotContainsString("\t", $line);
        }
        $this->assertSame(['[a.png] [Logo]'], self::plain($this->render('![ ](a.png) ![ Logo ](b.png)', 60)));
    }

    public function testAHeadingsAnchorReadsCodeEmphasisAndAccents(): void
    {
        $this->assertSame(0, $this->render("## The `verify` *gate*\n")->anchor('the-verify-gate'));
        $this->assertSame(0, $this->render("# Été\n")->anchor('été'));
        $this->assertSame('a[1] b[1]', self::plain($this->render("a[^x] b[^x]\n\n[^x]: X.\n"))[0]);
        $this->assertSame(['[a b '."\u{1F680}".']'], self::plain($this->render("![a\\\nb :rocket:](x.png)\n")));
    }

    public function testABlockAfterAFootnoteDefinitionIsStillDrawn(): void
    {
        $this->assertSame(['A[1].', '', 'After.', '', \str_repeat(Glyphs::MD_RULE, times: 40), '1. Note. '.Glyphs::MD_BACKREF], self::plain($this->render("A[^a].\n\n[^a]: Note.\n\nAfter.\n")));
    }

    public function testAParagraphOfTagsAloneDrawsNothingAndTakesNoGap(): void
    {
        $this->assertSame(['a', '', 'b'], self::plain($this->render("a\n\n<b></b>\n\nb")));
    }

    public function testFootnotesFirstDefinitionWinsNestedOnesCountAndTheBackrefIsTheFirst(): void
    {
        $this->assertStringStartsWith('1. First.', self::plain($this->render("R[^a].\n\n[^a]: First.\n\n[^a]: Second.\n"))[3]);
        $this->assertNotNull($this->render("R[^q].\n\n> [^q]: Quoted.\n")->anchor('fn-q'), 'a definition inside a quote');
        $this->assertSame(
            ['R[1].', '', \str_repeat(Glyphs::MD_RULE, times: 40), '1. Quoted. '.Glyphs::MD_BACKREF],
            self::plain($this->render("R[^q].\n\n> [^q]: Quoted.\n")),
            'and the quote it leaves empty draws no bar',
        );
        $this->assertSame(0, $this->render("A[^a]\n\nB[^a]\n\n[^a]: N.\n")->anchor('fnref-a'), 'back to the first reference');
        $this->assertSame(0, $this->render("# Title[^n]\n\n[^n]: N.\n")->anchor('fnref-n'), 'a heading\'s slug does not replace it');
    }

    public function testAnAnchorMatchesAccentedCapitals(): void
    {
        $this->assertSame(0, $this->render("# Été\n")->anchor('Été'));
    }

    public function testKbdKeepsItsBoldAndALinkItsColour(): void
    {
        $this->assertSame(Sgr::BOLD.Sgr::REVERSE.'K'.Sgr::RESET, $this->render('**<kbd>K</kbd>**')->lines[0]);
        $this->assertSame($this->styles->of(Role::TabAccent)->getAnsiRestore().Sgr::UNDERLINE.'x'.Sgr::RESET, $this->render('[x](y)')->lines[0]);
    }

    public function testACellNotDrawnAsWrittenMapsToTheStartOfItsRun(): void
    {
        $this->assertSame(0, $this->render("a\tb c")->sourceAt(0, 2));
        $this->assertSame(0, $this->render("a\tb \u{00E9}")->sourceAt(0, 2), 'the grapheme-measured path too');
    }

    public function testACodeLineMapsByteForByteAndItsBlockEndsOnItsLastLine(): void
    {
        $doc = $this->render("```\nab\n```\n");
        $this->assertSame(5, $doc->sourceAt(1, 3));
        $this->assertSame([0, 1, 1], $doc->origins);
    }

    public function testACodeLabelTooLongIsCutInsideTheRow(): void
    {
        $top = AnsiUtils::stripAnsiCodes($this->render("```abcdefghijklmnopqrstuvwxyz\nx\n```\n", 20)->lines[0]);
        $this->assertSame(20, AnsiUtils::visibleWidth($top));
        $this->assertStringStartsWith(' a', $top);
    }

    public function testACodeLineStaysShadedAfterItsColouringResets(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $code, string $language): string => "\x1b[31m".$code);
        $line = $this->render("```php\nx\n```\n", 20, new CodeHighlighter($provider))->lines[1];
        $this->assertStringContainsString(Sgr::RESET.$this->styles->of(Role::Toolbar)->getAnsiRestore().' ', $line);
    }

    public function testEveryBlockKeepsItsOwnColouringAndColoursOffMeansNone(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $code, string $language): string => "\x1b[31m{$code}\x1b[39m");
        $renderer = new MarkdownRenderer($this->styles, new CodeHighlighter($provider));
        $markdown = "```php\nx\n```\n\n```sh\nx\n```\n";
        $renderer->render($markdown, 30);
        $this->assertSame(2, $provider->calls, 'one text, two languages');
        $renderer->render($markdown, 50);
        $this->assertSame(2, $provider->calls);
        $mono = new MarkdownRenderer(new Styles(MonochromeTheme::definition()), new CodeHighlighter($provider))->render("```php\ny\n```\n", 30);
        $this->assertStringNotContainsString("\x1b", $mono->lines[1]);
    }
}
