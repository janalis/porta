<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Ui\ColorPalette;
use Porta\Ui\MonochromePalette;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MarkdownRendererTest extends TestCase
{
    private function render(string $markdown, int $width = 80): RenderedDoc
    {
        return new MarkdownRenderer(ColorPalette::dark())->render($markdown, $width);
    }

    /** @return list<string> */
    private function plain(RenderedDoc $doc): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $doc->lines);
    }

    public function testHeadingsRenderStyledWithoutTheHashes(): void
    {
        $doc = $this->render("# Title\n\n## Section\n\n### Sub");
        $plain = $this->plain($doc);

        $this->assertSame('Title', $plain[0]);
        $this->assertStringContainsString("\x1b[1m", $doc->lines[0], 'headings are bold');
        $this->assertSame('═════', $plain[1], 'an H1 carries a double rule as wide as its title');
        $this->assertSame('───────', $plain[array_search('Section', $plain, true) + 1], 'an H2 carries a single rule');
        $this->assertContains('Sub', $plain);
        $this->assertSame('Sub', $plain[array_key_last($plain)], 'H3+ has no trailing rule');
        $this->assertNotContains('# Title', $plain);
    }

    public function testHeadingSpacingIsTwoBlanksAboveAndOneBelowRegardlessOfTheSource(): void
    {
        $doc = $this->render("intro\n\n\n\n## Head\n\n\ntext\nmore\n\ntail");
        $plain = $this->plain($doc);

        $this->assertSame(['intro', '', '', 'Head', '────', '', 'text more', '', 'tail'], array_map(rtrim(...), $plain));

        $doc = $this->render("# Top\ntight");
        $this->assertSame(['Top', '═══', '', 'tight'], array_map(rtrim(...), $this->plain($doc)), 'no blanks above at the document top, one below even without a source blank');
    }

    public function testInlineStylesAndParagraphJoining(): void
    {
        $doc = $this->render("Some **bold** and *italic* and `code` here\njoined onto one source line.");

        $this->assertCount(1, $doc->lines, 'hard-wrapped source joins, then re-wraps to the width');
        $plain = $this->plain($doc)[0];
        $this->assertSame('Some bold and italic and code here joined onto one source line.', $plain);
        $this->assertStringContainsString("\x1b[1mbold\x1b[22m", $doc->lines[0]);
        $this->assertStringContainsString("\x1b[3mitalic\x1b[23m", $doc->lines[0]);
    }

    public function testWordWrapWithHangingIndentAndOverWideWords(): void
    {
        $doc = $this->render('- alpha beta gamma delta epsilon zeta', 20);
        $plain = $this->plain($doc);
        $this->assertSame('• alpha beta gamma', rtrim($plain[0]));
        $this->assertSame('  delta epsilon zeta', rtrim($plain[1]), 'continuations hang under the marker');

        $doc = $this->render('averyveryverylongunbrokenword', 20);
        $plain = $this->plain($doc);
        $this->assertSame('averyveryverylongu', rtrim($plain[0]), 'over-wide words hard-break');
        $this->assertSame('nbrokenword', rtrim($plain[1]));

        $doc = $this->render('tiny', 5);
        $this->assertSame('tiny', rtrim($this->plain($doc)[0]), 'width clamps to a sane minimum');
    }

    public function testOrderedListsBlockquotesAndRules(): void
    {
        $doc = $this->render("1. first\n2. second\n\n> quoted words\n\n---");
        $plain = $this->plain($doc);

        $this->assertSame('1. first', rtrim($plain[0]));
        $this->assertSame('2. second', rtrim($plain[1]));
        $this->assertContains('│ quoted words', array_map(rtrim(...), $plain));
        $this->assertContains(str_repeat('─', 80), $plain, 'horizontal rule spans the width');
    }

    public function testFencedCodeBlocksBandTruncateAndNeverWrap(): void
    {
        $doc = $this->render("```php\n\$x = 1;\n".str_repeat('x', 200)."\n```", 30);
        $plain = $this->plain($doc);

        $this->assertSame('  $x = 1;', rtrim($plain[0]));
        $this->assertSame(30, mb_strwidth($plain[0]), 'code lines pad to the full width (bg band)');
        $this->assertStringEndsWith('…', rtrim($plain[1]), 'over-wide code truncates');
        $this->assertCount(2, array_filter($plain, static fn (string $l): bool => '' !== $l), 'no fence markers rendered');
    }

    public function testTablesAlignBoldTheHeaderAndCarryCellLinks(): void
    {
        $doc = $this->render("| Key | Action |\n| --- | --- |\n| `F1` | Open [docs](guide.md) now |");
        $plain = $this->plain($doc);

        $this->assertSame(' Key  Action', rtrim($plain[0]));
        $this->assertStringContainsString("\x1b[1m", $doc->lines[0], 'header row is bold');
        $this->assertMatchesRegularExpression('/^─+\s+─+/u', trim($plain[1]), 'separator renders as rules');
        $this->assertSame(' F1   Open docs now', rtrim($plain[2]));
        $this->assertSame(strpos($plain[0], 'Action'), strpos($plain[2], 'Open'), 'columns align to natural widths');

        $this->assertSame(1, $doc->groupCount());
        $span = $doc->links[0];
        $this->assertSame('guide.md', $span->target);
        $this->assertSame(2, $span->line);
        $this->assertSame('docs', AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($doc->lines[2], $span->col, $span->endCol - $span->col)), 'the span brackets exactly the link text');
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

        $this->assertStringContainsString('anchor', implode("\n", $this->plain($doc)), 'the anchor text still renders');
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
        $doc = new MarkdownRenderer(new MonochromePalette())->render($markdown, 40);

        $this->assertStringNotContainsString("\x1b", implode("\n", $doc->lines), 'no ANSI without color support');
        $this->assertSame(1, $doc->groupCount(), 'links still tracked for Tab-cycling');
    }

    public function testEmptyInputRendersNothing(): void
    {
        $doc = $this->render('');
        $this->assertSame([], $doc->lines);
        $this->assertSame(0, $doc->groupCount());
    }
}
