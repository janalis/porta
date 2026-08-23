<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Support\AnsiText;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MarkdownRendererTest extends TestCase
{
    use RendersWidget;

    private Styles $styles;

    protected function setUp(): void
    {
        $this->styles = new Styles(DarkTheme::definition());
    }

    private function render(string $markdown, int $width = 80): RenderedDoc
    {
        return new MarkdownRenderer($this->styles)->render($markdown, $width);
    }

    /** @return list<string> */
    public function testHeadingsRenderStyledWithoutTheHashes(): void
    {
        $doc = $this->render("# Title\n\n## Section\n\n### Sub");
        $plain = $this->rows($doc->lines);

        $this->assertSame('Title', $plain[0]);
        $this->assertStringContainsString("\x1b[1m", $doc->lines[0], 'headings are bold');
        $this->assertSame('═════', $plain[1], 'an H1 carries a double rule as wide as its title');
        $this->assertSame('───────', $plain[array_search('Section', $plain, strict: true) + 1], 'an H2 carries a single rule');
        $this->assertContains('Sub', $plain);
        $this->assertSame('Sub', $plain[array_key_last($plain)], 'H3+ has no trailing rule');
        $this->assertNotContains('# Title', $plain);
    }

    public function testHeadingSpacingIsTwoBlanksAboveAndOneBelowRegardlessOfTheSource(): void
    {
        $doc = $this->render("intro\n\n\n\n## Head\n\n\ntext\nmore\n\ntail");
        $plain = $this->rows($doc->lines);

        $this->assertSame(['intro', '', '', 'Head', '────', '', 'text more', '', 'tail'], array_map(rtrim(...), $plain));

        $doc = $this->render("# Top\ntight");
        $this->assertSame(['Top', '═══', '', 'tight'], array_map(rtrim(...), $this->rows($doc->lines)), 'no blanks above at the document top, one below even without a source blank');
    }

    public function testAnH1TitleOpensWithBoldThenTheEmphasisRoleColor(): void
    {
        $doc = $this->render('# T');
        $open = Sgr::BOLD.$this->styles->of(Role::Emphasis)->getAnsiRestore();
        $this->assertStringStartsWith($open.'T', $doc->lines[0]);
    }

    public function testAnH2TitleOpensWithBoldThenTheBrightRoleColorNotEmphasis(): void
    {
        $doc = $this->render('## T');
        $open = Sgr::BOLD.$this->styles->of(Role::Bright)->getAnsiRestore();
        $this->assertStringStartsWith($open.'T', $doc->lines[0]);
        $this->assertStringNotContainsString($this->styles->of(Role::Emphasis)->getAnsiRestore(), $doc->lines[0]);
    }

    public function testAnH3TitleOpensWithBoldOnlyNoRoleColor(): void
    {
        $doc = $this->render('### T');
        $this->assertStringStartsWith(Sgr::BOLD.'T', $doc->lines[0]);
    }

    public function testAnH1RuleLineIsExactlyTheBrightColorThenGlyphsThenReset(): void
    {
        $doc = $this->render('# Tee');
        $expected = $this->styles->of(Role::Bright)->getAnsiRestore().str_repeat(Glyphs::MD_HEADING_RULE, times: 3).Sgr::RESET;
        $this->assertSame($expected, $doc->lines[1]);
    }

    public function testAnH2RuleLineIsExactlyTheMutedColorThenGlyphsThenReset(): void
    {
        $doc = $this->render('## Tee');
        $expected = $this->styles->of(Role::Muted)->getAnsiRestore().str_repeat(Glyphs::MD_RULE, times: 3).Sgr::RESET;
        $this->assertSame($expected, $doc->lines[1]);
    }

    public function testAnH3HasNoRuleLineAtAll(): void
    {
        $doc = $this->render("### T\nafter");
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame(['T', '', 'after'], $plain);
    }

    public function testASingleCharacterHeadingTitleGetsARuleExactlyOneGlyphWide(): void
    {
        $doc = $this->render('# T');
        $this->assertSame(Glyphs::MD_HEADING_RULE, rtrim($this->rows($doc->lines)[1]));
    }

    public function testAHeadingThatRendersToZeroVisibleWidthStillGetsAOneGlyphRuleFloor(): void
    {
        // A pure in-page anchor link (empty label, no followable target)
        // renders to an empty title — the rule width must still floor at 1.
        $doc = $this->render('# [](#x)', 20);
        $this->assertSame(Glyphs::MD_HEADING_RULE, rtrim($this->rows($doc->lines)[1]));
    }

    public function testAHeadingLineEndsWithAResetCode(): void
    {
        $doc = $this->render('# T');
        $this->assertStringEndsWith(Sgr::RESET, $doc->lines[0]);
    }

    public function testAHeadingContainingALinkRecordsItsSpanAtTheCorrectColumn(): void
    {
        $doc = $this->render('# See [guide](guide.md) now');
        $this->assertSame(1, $doc->groupCount(), 'the link inside a heading is still tracked');
        $this->assertSame('guide.md', $doc->target(0));
        $span = $doc->spansOfGroup(0)[0];
        $this->assertSame(0, $span->line);
        $text = $this->rows($doc->lines)[0];
        $this->assertSame('guide', mb_substr($text, $span->col, $span->endCol - $span->col), 'the span brackets exactly the link text at its true column');
    }

    public function testInlineStylesAndParagraphJoining(): void
    {
        $doc = $this->render("Some **bold** and *italic* and `code` here\njoined onto one source line.");

        $this->assertCount(1, $doc->lines, 'hard-wrapped source joins, then re-wraps to the width');
        $plain = $this->rows($doc->lines)[0];
        $this->assertSame('Some bold and italic and code here joined onto one source line.', $plain);
        $this->assertStringContainsString("\x1b[1mbold\x1b[22m", $doc->lines[0]);
        $this->assertStringContainsString("\x1b[3mitalic\x1b[23m", $doc->lines[0]);
    }

    public function testWordWrapWithHangingIndentAndOverWideWords(): void
    {
        $doc = $this->render('- alpha beta gamma delta epsilon zeta', 20);
        $plain = $this->rows($doc->lines);
        $this->assertSame('• alpha beta gamma', rtrim($plain[0]));
        $this->assertSame('  delta epsilon zeta', rtrim($plain[1]), 'continuations hang under the marker');

        $doc = $this->render('averyveryverylongunbrokenword', 20);
        $plain = $this->rows($doc->lines);
        $this->assertSame('averyveryverylongu', rtrim($plain[0]), 'over-wide words hard-break');
        $this->assertSame('nbrokenword', rtrim($plain[1]));

        $doc = $this->render('tiny', 5);
        $this->assertSame('tiny', rtrim($this->rows($doc->lines)[0]), 'width clamps to a sane minimum');
    }

    public function testOrderedListsBlockquotesAndRules(): void
    {
        $doc = $this->render("1. first\n2. second\n\n> quoted words\n\n---");
        $plain = $this->rows($doc->lines);

        $this->assertSame('1. first', rtrim($plain[0]));
        $this->assertSame('2. second', rtrim($plain[1]));
        $this->assertContains('│ quoted words', array_map(rtrim(...), $plain));
        $this->assertContains(str_repeat('─', times: 80), $plain, 'horizontal rule spans the width');
    }

    public function testFencedCodeBlocksBandTruncateAndNeverWrap(): void
    {
        $doc = $this->render("```php\n\$x = 1;\n".str_repeat('x', times: 200)."\n```", 30);
        $plain = $this->rows($doc->lines);

        $this->assertSame('  $x = 1;', rtrim($plain[0]));
        $this->assertSame(30, mb_strwidth($plain[0]), 'code lines pad to the full width (bg band)');
        $this->assertStringEndsWith('…', rtrim($plain[1]), 'over-wide code truncates');
        $this->assertCount(2, array_filter($plain, static fn (string $l): bool => '' !== $l), 'no fence markers rendered');
    }

    public function testAnUnclosedFenceStopsAtTheEndOfTheSourceWithoutReadingPastIt(): void
    {
        // No closing ``` in the source: the loop bound must stop exactly at
        // $count, not attempt one more (undefined) source line.
        $doc = $this->render("```\ncode", 20);
        $plain = $this->rows($doc->lines);
        $this->assertSame('  code', rtrim($plain[0]));
    }

    public function testAFenceLineTrailingWhitespaceIsStrippedBeforeTheTruncatePad(): void
    {
        $doc = $this->render("```\ncode   \n```", 20);
        $this->assertSame('  code', rtrim($this->rows($doc->lines)[0]));
    }

    public function testExcessFenceLineTrailingWhitespaceDoesNotTriggerATruncationEllipsis(): void
    {
        // Enough trailing whitespace to overflow the width if left unstripped
        // before truncate() runs — must stay clean, not end in "…".
        $doc = $this->render("```\ncode".str_repeat(' ', times: 20)."\n```", 20);
        $this->assertSame('  code', rtrim($this->rows($doc->lines)[0]));
    }

    public function testAFenceLineIsExactlyToolbarBackgroundThenPaddedTextThenReset(): void
    {
        $doc = $this->render("```\nx\n```", 20);
        $bg = $this->styles->of(Role::Toolbar)->getAnsiRestore();
        $text = AnsiText::padRight('  x', 20);
        $this->assertSame($bg.$text.Sgr::RESET, $doc->lines[0]);
    }

    public function testTablesAlignBoldTheHeaderAndCarryCellLinks(): void
    {
        $doc = $this->render("| Key | Action |\n| --- | --- |\n| `F1` | Open [docs](guide.md) now |");
        $plain = $this->rows($doc->lines);

        $this->assertSame(' Key  Action', rtrim($plain[0]));
        $this->assertStringContainsString("\x1b[1m", $doc->lines[0], 'header row is bold');
        $this->assertMatchesRegularExpression('/^─+\s+─+/u', trim($plain[1]), 'separator renders as rules');
        $this->assertSame(' F1   Open docs now', rtrim($plain[2]));
        $this->assertSame(strpos($plain[0], needle: 'Action'), strpos($plain[2], needle: 'Open'), 'columns align to natural widths');

        $this->assertSame(1, $doc->groupCount());
        $span = $doc->links[0];
        $this->assertSame('guide.md', $span->target);
        $this->assertSame(2, $span->line);
        $this->assertSame('docs', AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($doc->lines[2], $span->col, $span->endCol - $span->col)), 'the span brackets exactly the link text');
    }

    public function testTableSeparatorRuleWidthsMatchEachColumnIndependently(): void
    {
        // Two columns of DIFFERENT widths: a shared/uniform mutation to the
        // rule-building loop (dropping str_repeat, or reordering the glyphs
        // vs the 2-space gap) is only observable when the columns differ.
        $doc = $this->render("| A | BBBB |\n| --- | --- |\n| x | y |");
        $sep = rtrim($this->rows($doc->lines)[1]);
        $this->assertSame(' '.str_repeat('─', times: 1).'  '.str_repeat('─', times: 4), $sep);
    }

    public function testTableSeparatorLineIsExactlyMutedColorThenRuleThenReset(): void
    {
        $doc = $this->render("| A |\n| --- |\n| x |");
        $expected = $this->styles->of(Role::Muted)->getAnsiRestore().' ─'.Sgr::RESET;
        $this->assertSame($expected, $doc->lines[1]);
    }

    public function testASecondSeparatorRowIsAlsoRecognizedNotJustTheFirst(): void
    {
        // headerSeen must flip permanently once tripped by the FIRST real
        // (non-separator) row — a second separator-shaped row appearing
        // later must still be rendered as a rule, not as a bolded header.
        $doc = $this->render("| A |\n| --- |\n| x |\n| --- |\n| y |");
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame(' ─', $plain[1]);
        $this->assertSame(' ─', $plain[3]);
        $this->assertStringNotContainsString("\x1b[1m", $doc->lines[3], 'a later row is not re-bolded as a header');
    }

    public function testOnlyTheFirstNonSeparatorRowIsBolded(): void
    {
        $doc = $this->render("| Head |\n| --- |\n| x |\n| y |");
        $this->assertStringContainsString("\x1b[1m", $doc->lines[0], 'the header row is bolded');
        $this->assertStringNotContainsString("\x1b[1m", $doc->lines[2], 'the first data row is plain');
        $this->assertStringNotContainsString("\x1b[1m", $doc->lines[3], 'the second data row is plain');
    }

    public function testTheHeaderRowIsExactlyBoldThenTrimmedTextThenReset(): void
    {
        $doc = $this->render('| Head |');
        $this->assertSame(Sgr::BOLD.' Head'.Sgr::RESET, $doc->lines[0], 'trailing whitespace trimmed before the reset, bold visibly wraps the text');
    }

    public function testANonHeaderTableRowIsNotWrappedInBoldOrAResetPair(): void
    {
        $doc = $this->render("| Head |\n| --- |\n| x |");
        $this->assertSame(' x', rtrim($doc->lines[2]), 'no leading bold or trailing reset on a plain data row');
    }

    public function testATableColumnPadsToTheWidestCellPlusATwoColumnGap(): void
    {
        // column 0's widest cell is "AA" (2); the narrower "x" cell (1) must
        // pad out to that width (1 fill space) plus the fixed 2-space gap.
        $doc = $this->render('| AA | B |'."\n".'| --- | --- |'."\n".'| x | yy |');
        $this->assertSame(' x   yy', $this->rows($doc->lines)[2]);
    }

    public function testATableCellLinkSpanColumnAccountsForEveryPrecedingColumnsWidth(): void
    {
        $doc = $this->render("| AAAA | B |\n| --- | --- |\n| x | [l](t.md) |");
        $span = $doc->links[0];
        $text = $this->rows($doc->lines)[2];
        $this->assertSame('l', mb_substr($text, $span->col, $span->endCol - $span->col), 'the span column is offset past column 0s full padded width');
    }

    public function testAnEmptyTableCellStillContributesAZeroWidthColumnNotOne(): void
    {
        // A lone row with an empty cell: the running max's own default must
        // be 0, or a never-otherwise-populated column would pad to width 1
        // instead of collapsing to nothing.
        $doc = $this->render('|  |');
        $this->assertSame('', rtrim($this->rows($doc->lines)[0]));
    }

    public function testASeparatorCellRequiresDashesAtTheVeryStartOfTheCell(): void
    {
        // A dash run buried after other content must not be mistaken for a
        // separator row — the leading anchor is load-bearing.
        $doc = $this->render("| a--- |\n| --- |\n| x |");
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame(' a---', $plain[0], 'the first row rendered as an ordinary header cell, not a swallowed separator');
    }

    public function testASeparatorCellRequiresDashesAtTheVeryEndOfTheCell(): void
    {
        $doc = $this->render("| ---a |\n| --- |\n| x |");
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame(' ---a', $plain[0]);
    }

    public function testLinkSpansFragmentsAnchorsAndWrapSplitting(): void
    {
        $doc = $this->render('See [the full editor guide](editor.md#top) and [Porta](https://porta.dev "site") and [anchor](#local).', 20);

        $this->assertSame(2, $doc->groupCount(), 'the pure anchor is not a followable link');
        $this->assertSame('editor.md', $doc->target(0), 'fragments are stripped');
        $this->assertSame('https://porta.dev', $doc->target(1), 'titles are dropped');
        $this->assertNull($doc->target(9));

        $spans = $doc->spansOfGroup(0);
        $this->assertGreaterThan(1, \count($spans), 'a wrapped link splits into one span per line');
        foreach ($spans as $span) {
            $text = AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($doc->lines[$span->line], $span->col, $span->endCol - $span->col));
            $this->assertStringContainsString(trim($text), 'the full editor guide', 'every fragment covers link text only');
        }

        $this->assertStringContainsString('anchor', implode("\n", $this->rows($doc->lines)), 'the anchor text still renders');
    }

    public function testAngleBracketTargetsAndTruncatedTableSpansAreDropped(): void
    {
        $doc = $this->render('[spaced](<my file.md>)');
        $this->assertSame('my file.md', $doc->target(0));

        $doc = $this->render("| A | B |\n| --- | --- |\n| xxxxxxxxxx | words then a [link](far.md) |", 20);
        $this->assertStringEndsWith('…', rtrim(AnsiUtils::stripAnsiCodes($doc->lines[2])), 'the row truncated');
        $this->assertSame([], $doc->links, 'spans truncated out of view are dropped');
    }

    public function testMonochromeOutputHasNoEscapes(): void
    {
        $markdown = "# T\n\n**b** *i* `c` [l](x.md)\n\n| A |\n| --- |\n| v |\n\n```\ncode\n```";
        $doc = new MarkdownRenderer(new Styles(MonochromeTheme::definition()))->render($markdown, 40);

        $this->assertStringNotContainsString("\x1b", implode("\n", $doc->lines), 'no ANSI without color support');
        $this->assertSame(1, $doc->groupCount(), 'links still tracked for Tab-cycling');
    }

    public function testEmptyInputRendersNothing(): void
    {
        $doc = $this->render('');
        $this->assertSame([], $doc->lines);
        $this->assertSame(0, $doc->groupCount());
    }

    public function testALeadingBlankLineIsPreservedNotSwallowed(): void
    {
        $doc = $this->render("\ntext");
        $this->assertSame(['', 'text'], $this->rows($doc->lines));
    }

    public function testTrailingWhitespaceOnASourceLineDoesNotLeakIntoAHeading(): void
    {
        $doc = $this->render("# Title   \n\nbody");
        $this->assertSame('Title', $this->rows($doc->lines)[0]);
    }

    public function testAWhitespaceOnlyLineCountsAsBlankAndSplitsParagraphs(): void
    {
        $doc = $this->render("first\n   \nsecond");
        $plain = $this->rows($doc->lines);
        $this->assertSame(['first', '', 'second'], $plain);
    }

    public function testAnIndentedFenceMarkerIsStillRecognized(): void
    {
        $doc = $this->render("  ```\ncode\n  ```");
        $plain = $this->rows($doc->lines);
        $this->assertSame('  code', rtrim($plain[0]));
        $this->assertCount(1, array_filter($plain, static fn (string $l): bool => '' !== trim($l)), 'no fence markers rendered, indented or not');
    }

    public function testAFenceFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n```\ncode\n```");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0], 'the paragraph rendered before the fence, not merged into it');
    }

    public function testNothingLeaksAfterAClosedFence(): void
    {
        $doc = $this->render("```\ncode\n```\ntail");
        $joined = implode("\n", $this->rows($doc->lines));
        $this->assertSame(1, substr_count($joined, needle: 'code'), 'the fence-open line never re-enters the block dispatcher as stray paragraph text');
        $this->assertStringContainsString('tail', $joined);
    }

    public function testAnIndentedTableRowIsStillRecognized(): void
    {
        $doc = $this->render("  | A | B |\n  | --- | --- |\n  | x | y |");
        $plain = $this->rows($doc->lines);
        $this->assertSame(' A  B', rtrim($plain[0]));
    }

    public function testATableFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n| A |\n| --- |\n| x |");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0]);
    }

    public function testATrailingWhitespaceTableRowStillParsesItsLastCell(): void
    {
        $doc = $this->render("| A | B |   \n| --- | --- |\n| x | y |");
        $plain = $this->rows($doc->lines);
        $this->assertSame(' A  B', rtrim($plain[0]));
        $this->assertSame(' x  y', rtrim($plain[2]));
    }

    public function testContentImmediatelyAfterATableWithNoBlankLineStillRenders(): void
    {
        $doc = $this->render("| A |\n| --- |\n| x |\nafter");
        $plain = $this->rows($doc->lines);
        $this->assertSame('after', end($plain), 'the boundary line was reprocessed as a paragraph, not swallowed by the table scan');
    }

    public function testAHashInTheMiddleOfALineIsNotTreatedAsAHeading(): void
    {
        $doc = $this->render('see the #1 issue');
        $plain = $this->rows($doc->lines);
        $this->assertSame('see the #1 issue', $plain[0]);
    }

    public function testAHashPatternNotAtTheStartOfTheLineIsNotTreatedAsAHeading(): void
    {
        // A "# " run mid-line, not at column 0: the heading regex's leading
        // anchor must stop preg_match from finding it anywhere in the string.
        $doc = $this->render('text # Heading');
        $this->assertSame('text # Heading', $this->rows($doc->lines)[0]);
    }

    public function testAHeadingFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n# Head");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0]);
    }

    public function testAHorizontalRuleLineHasTheMutedColorBeforeTheGlyphsAndAResetAfter(): void
    {
        $doc = $this->render('---', 20);
        $expected = $this->styles->of(Role::Muted)->getAnsiRestore().str_repeat(Glyphs::MD_RULE, times: 20).Sgr::RESET;
        $this->assertSame($expected, $doc->lines[0]);
    }

    public function testAHorizontalRuleLineIsExactlyAWhitespaceWrappedRunOfMarkers(): void
    {
        $doc = $this->render('not --- a rule');
        $this->assertSame('not --- a rule', $this->rows($doc->lines)[0]);
    }

    public function testAHorizontalRuleMarkerNotAtTheStartOfTheLineIsNotTreatedAsARule(): void
    {
        // Dashes trailing other text: the leading anchor must stop
        // preg_match from finding a bare "---" run anywhere in the string.
        $doc = $this->render('text ---');
        $this->assertSame('text ---', $this->rows($doc->lines)[0]);
    }

    public function testTrailingContentAfterTheDashesMeansItIsNotARule(): void
    {
        // Content after the dash run: the trailing anchor must stop the
        // match from succeeding on just the "---" prefix.
        $doc = $this->render('--- text');
        $this->assertSame('--- text', $this->rows($doc->lines)[0]);
    }

    public function testAHorizontalRuleFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n---");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0]);
    }

    public function testContentAfterAHorizontalRuleDoesNotRepeatTheRuleLine(): void
    {
        $doc = $this->render("---\ntail", 20);
        $this->assertSame(
            ['tail'],
            array_values(array_filter($this->rows($doc->lines), static fn (string $l): bool => !str_contains($l, '─'))),
            'the rule line never re-enters the dispatcher as stray paragraph text',
        );
    }

    public function testABlockquoteFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n> quoted");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0]);
    }

    public function testABlockquoteWrapsWithTheCorrectHangingIndentAndStripsExactlyItsMarker(): void
    {
        $doc = $this->render('> one two three four five', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame('│ one two three four', $plain[0]);
        $this->assertSame('│ five', $plain[1], 'the "> " marker (2 chars) is stripped and the continuation repeats the bar prefix');
    }

    public function testABlockquoteContinuationLinesUseTheHangValueNotTheVisiblePrefixWidth(): void
    {
        // hang=2 must feed the continuation column budget exactly (not just
        // floor the over-wide-word room calc) — an off-by-one here lets one
        // extra word squeeze onto a continuation line and overflow into a
        // truncated line instead of wrapping cleanly to a third line.
        $doc = $this->render('> alpha beta gamma delta echo1234 fg h', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertCount(3, $plain);
        $this->assertSame('│ h', $plain[2]);
    }

    public function testABlockquoteOverWideWordHardBreaksAtExactlyWidthMinusTheHangFloor(): void
    {
        $doc = $this->render('> '.str_repeat('a', times: 19), 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame('│ '.str_repeat('a', times: 18), $plain[0]);
        $this->assertSame('│ a', $plain[1]);
    }

    public function testABlockquotePrefixIsExactlyMutedColorThenBarThenSpaceThenForegroundReset(): void
    {
        $doc = $this->render('> x');
        $prefix = $this->styles->of(Role::Muted)->getAnsiRestore().Glyphs::MD_QUOTE_BAR.' '.Sgr::DEFAULT_FG;
        $this->assertStringStartsWith($prefix, $doc->lines[0]);
    }

    public function testABulletPrefixKeepsTheIndentBeforeTheMutedColorEscape(): void
    {
        // The indent is real leading whitespace the reader sees; it must sit
        // before the escape code, not be swallowed inside/after it.
        $doc = $this->render('  - x');
        $prefix = '  '.$this->styles->of(Role::Muted)->getAnsiRestore().Glyphs::MD_BULLET.' '.Sgr::DEFAULT_FG;
        $this->assertStringStartsWith($prefix, $doc->lines[0]);
    }

    public function testABulletFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n- item");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0]);
    }

    public function testAnIndentedBulletHangsAtIndentPlusTwo(): void
    {
        $doc = $this->render('  - one two three four five', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame('  • one two three', $plain[0]);
        $this->assertSame('    four five', $plain[1], 'continuations hang at indent (2) + 2 = 4, under the bullet text');
    }

    public function testABulletContinuationLinesUseTheHangValueNotTheVisiblePrefixWidth(): void
    {
        $doc = $this->render('  - alpha beta gamma delta echo1234 fg h', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertCount(3, $plain);
        $this->assertSame('    fg h', $plain[2]);
    }

    public function testContentAfterABulletItemStillRenders(): void
    {
        // A second block after a bullet item must still be processed — the
        // dispatcher's continue must not exit the whole source-line loop.
        $doc = $this->render("- item\ntail paragraph");
        $plain = $this->rows($doc->lines);
        $this->assertContains('tail paragraph', $plain);
    }

    public function testAnOrderedListFlushesAPendingParagraphFirst(): void
    {
        $doc = $this->render("intro text\n1. item");
        $plain = $this->rows($doc->lines);
        $this->assertSame('intro text', $plain[0]);
    }

    public function testAnOrderedListItemHangsExactlyUnderItsOwnMarkerWidth(): void
    {
        $doc = $this->render('10. one two three four five', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame('10. one two three', $plain[0]);
        $this->assertSame('    four five', $plain[1], 'the hang is strlen(indent=0) + strlen("10.") + 1 = 4, not a fixed width');
    }

    public function testAnOrderedListMarkerNotAtTheStartOfTheLineIsNotTreatedAsAListItem(): void
    {
        $doc = $this->render('see 10. item');
        $this->assertSame('see 10. item', $this->rows($doc->lines)[0]);
    }

    public function testAnIndentedOrderedListPutsTheIndentBeforeTheMarkerNotAfter(): void
    {
        $doc = $this->render('  10. x');
        $this->assertSame('  10. x', rtrim($this->rows($doc->lines)[0]));
    }

    public function testALeadingWhitespaceOnAPlainParagraphLineIsStripped(): void
    {
        $doc = $this->render('  plain text');
        $this->assertSame('plain text', $this->rows($doc->lines)[0]);
    }

    public function testAPlainParagraphContinuationLinesStartAtColumnZero(): void
    {
        // hang=0 for a bare paragraph: an off-by-one here shifts the packing
        // budget on every wrapped continuation line.
        $doc = $this->render(str_repeat('a', times: 19).' b c', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertCount(2, $plain);
        $this->assertSame('b c', $plain[1]);
    }

    public function testAPlainParagraphSecondLinePacksExactlyToTheFullWidthBudget(): void
    {
        $doc = $this->render(str_repeat('a', times: 18).' '.str_repeat('b', times: 18).' c', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertCount(2, $plain, 'the trailing "c" still fits on line 2 with a 0-width continuation budget');
        $this->assertSame(str_repeat('b', times: 18).' c', $plain[1]);
    }

    public function testAPlainParagraphDoesNotOverpackAContinuationLineBeyondTheWidth(): void
    {
        // A negative hang would let the packing budget drift below zero,
        // squeezing one extra word onto a continuation line and truncating
        // it, instead of correctly wrapping that word to its own line.
        $doc = $this->render(str_repeat('a', times: 18).' '.str_repeat('b', times: 18).' xy', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertCount(3, $plain);
        $this->assertSame(str_repeat('b', times: 18), $plain[1]);
        $this->assertSame('xy', $plain[2]);
    }

    public function testContentAfterAPureAnchorLinkStillRenders(): void
    {
        // The anchor branch's continue must not exit the whole tokenizer
        // loop early — text after the anchor must still be processed.
        $doc = $this->render('See [x](#a) and tail.');
        $this->assertStringContainsString('tail', $this->rows($doc->lines)[0]);
    }

    public function testALinkTargetsSurroundingWhitespaceIsTrimmedBeforeTheSpaceSplit(): void
    {
        // Without the trim, a leading space inside the parens would make
        // the space-split's first segment empty, nulling out the target.
        $doc = $this->render('[l]( t.md )');
        $this->assertSame('t.md', $doc->target(0));
    }

    public function testAnOverWideWordExactlyAtTheRoomBoundaryIsNotHardBroken(): void
    {
        $doc = $this->render(str_repeat('a', times: 18), 20);
        $plain = $this->rows($doc->lines);
        $this->assertCount(1, $plain, 'an 18-char word exactly fills the 18-col room, no hard break needed');
    }

    public function testTheFirstWordPlacedOnALineDoesNotChargeAnInterWordSpaceToTheColumnBudget(): void
    {
        $doc = $this->render(str_repeat('a', times: 18).' bb c', 20);
        $plain = array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertCount(2, $plain);
        $this->assertSame('bb c', $plain[1]);
    }

    public function testABlockquoteThatTokenizesToNoWordsStillEmitsItsBarPrefix(): void
    {
        // A pure in-page anchor renders to an empty label, so word-splitting
        // yields zero words — the line must still emit (just the bar), not
        // vanish entirely, because the prefix itself is non-empty.
        $doc = $this->render('> [](#a)');
        $this->assertSame('│', rtrim($this->rows($doc->lines)[0]));
    }

    public function testAdjacentWordsOfTheSameLinkGroupMergeIntoOneStyledRun(): void
    {
        $doc = $this->render('[go here](t.md) now', 80);
        $this->assertSame(1, $doc->groupCount());
        $span = $doc->spansOfGroup(0)[0];
        $this->assertSame('go here', mb_substr($this->rows($doc->lines)[0], $span->col, $span->endCol - $span->col));
    }

    public function testALinkSpansEndColumnExcludesATrailingJoiningSpace(): void
    {
        $doc = $this->render('[go here](t.md) now', 80);
        $span = $doc->spansOfGroup(0)[0];
        $lineAfterSpan = mb_substr($this->rows($doc->lines)[0], $span->endCol, length: 1);
        $this->assertSame(' ', $lineAfterSpan, 'the space right after the span is not part of it');
        $this->assertSame(7, $span->endCol - $span->col, 'the span itself is exactly "go here" (7), not "go here " (8)');
    }

    public function testATableRowFillingTheLineExactlyToWidthStillHidesASpanEndingRightAtTheEdge(): void
    {
        // Filling exactly to $width (no visible "…") still applies the
        // tighter width-1 margin: a span ending exactly at the last column
        // must be dropped defensively, matching a genuinely-truncated line.
        $doc = $this->render('| '.str_repeat('a', times: 16).' [bb](t.md) |', 20);
        $this->assertSame(' '.str_repeat('a', times: 16).' bb', rtrim($this->rows($doc->lines)[0]));
        $this->assertSame(20, mb_strwidth(rtrim($this->rows($doc->lines)[0])));
        $this->assertSame([], $doc->links, 'the row fills exactly to width, so the trailing span is still hidden');
    }

    public function testATableRowOneColumnUnderWidthKeepsASpanEndingAtItsLastColumn(): void
    {
        $doc = $this->render('| '.str_repeat('a', times: 15).' [bb](t.md) |', 20);
        $this->assertNotEmpty($doc->links, 'one column of slack: the same span is kept, not hidden');
    }

    public function testATruncatedRowsLinkIsDropped(): void
    {
        $doc = $this->render('| [aa](x.md) '.str_repeat('b', times: 20).' [cc](y.md) |', 20);
        $this->assertStringEndsWith('…', rtrim($this->rows($doc->lines)[0]));
        $this->assertCount(1, $doc->links, 'only the link before the truncation point survives');
        $this->assertSame('x.md', $doc->links[0]->target);
    }

    public function testALinksOpenSequenceIsExactlyUnderlineThenTheTabAccentColor(): void
    {
        $doc = $this->render('[l](t.md)');
        $open = Sgr::UNDERLINE.$this->styles->of(Role::TabAccent)->getAnsiRestore();
        $this->assertStringContainsString($open.'l', $doc->lines[0]);
    }

    public function testALinksCloseSequenceIsExactlyForegroundResetThenUnderlineOff(): void
    {
        $doc = $this->render('[l](t.md) x');
        $close = Sgr::DEFAULT_FG."\x1b[24m";
        $this->assertStringContainsString('l'.$close, $doc->lines[0]);
    }
}
