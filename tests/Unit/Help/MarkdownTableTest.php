<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Markdown tables in the F1 browser: how the columns are budgeted against the
 * pane, how a cell too wide for its column wraps down it, and what a link
 * inside one survives.
 *
 * Split from {@see MarkdownRendererTest} when it reached mago's method
 * threshold — which `mago.toml` bisected to this very class's count, so raising
 * it to fit new tests would have retired the gate rather than passed it.
 */
final class MarkdownTableTest extends TestCase
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

    public function testTablesAlignBoldTheHeaderAndCarryCellLinks(): void
    {
        $doc = $this->render("| Key | Action |\n| --- | --- |\n| `F1` | Open [docs](guide.md) now |");
        $plain = $this->rows($doc->lines);

        $this->assertSame(' Key  Action', \rtrim($plain[0]));
        $this->assertStringContainsString("\x1b[1m", $doc->lines[0], 'header row is bold');
        $this->assertMatchesRegularExpression('/^─+\s+─+/u', \trim($plain[1]), 'separator renders as rules');
        $this->assertSame(' F1   Open docs now', \rtrim($plain[2]));
        $this->assertSame(\strpos($plain[0], needle: 'Action'), \strpos($plain[2], needle: 'Open'), 'columns align to natural widths');

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
        $sep = \rtrim($this->rows($doc->lines)[1]);
        $this->assertSame(' '.\str_repeat('─', times: 1).'  '.\str_repeat('─', times: 4), $sep);
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
        $plain = \array_map(rtrim(...), $this->rows($doc->lines));
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
        $this->assertSame(' x', \rtrim($doc->lines[2]), 'no leading bold or trailing reset on a plain data row');
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
        $this->assertSame('l', \mb_substr($text, $span->col, $span->endCol - $span->col), 'the span column is offset past column 0s full padded width');
    }

    public function testAnEmptyTableCellStillContributesAZeroWidthColumnNotOne(): void
    {
        // A lone row with an empty cell: the running max's own default must
        // be 0, or a never-otherwise-populated column would pad to width 1
        // instead of collapsing to nothing.
        $doc = $this->render('|  |');
        $this->assertSame('', \rtrim($this->rows($doc->lines)[0]));
    }

    /**
     * Every row starts its columns where the rule says they start.
     *
     * The geometry is summed three ways — once to budget the widths, once for
     * the separator rule, once per physical row — and they have to agree. The
     * rule row is the oracle: its runs of `─` ARE the columns, so a content row
     * is right exactly when nothing crosses into the padding before a run.
     * That holds whatever the widths turn out to be, so it does not go stale
     * when a glyph or a floor changes.
     *
     * Written for a mutant that nothing else here catches: the empty-fragment
     * fallback a SHORT cell gets on a tall row carries `width => 0`, and at
     * `-1` every later column on that continuation row shifts one place right.
     * The text is all still present, so every content-shaped assertion in this
     * class passes while the table is visibly crooked.
     *
     * @param non-empty-string $markdown
     */
    #[DataProvider('alignmentTables')]
    public function testEveryRowStartsItsColumnsWhereTheRuleSaysTheyStart(string $markdown, int $width): void
    {
        $rows = $this->rows($this->render($markdown, $width)->lines);

        $ruleRow = '';
        foreach ($rows as $row) {
            if (!\str_contains($row, '─')) {
                continue;
            }
            $ruleRow = $row;
            break;
        }
        $this->assertNotSame('', $ruleRow, 'the table drew its separator rule');

        $rule = \mb_str_split($ruleRow);
        $starts = [];
        foreach ($rule as $index => $character) {
            if ('─' !== $character || '─' === ($rule[$index - 1] ?? ' ')) {
                continue;
            }
            $starts[] = $index;
        }
        $this->assertGreaterThan(1, \count($starts), 'a multi-column table, or this proves nothing');

        $begun = [];
        foreach ($rows as $index => $row) {
            $this->assertLessThanOrEqual($width, \mb_strwidth($row), "row {$index} overflows the pane");
            if ($row === $ruleRow || '' === \trim($row)) {
                continue;
            }
            $characters = \mb_str_split($row);
            foreach ($starts as $column => $start) {
                // A cell is left-aligned in its column, so within the span this
                // column owns, the first non-space must be the column's own
                // first cell — one place late is a cell pushed right by a
                // padding error, one place early is a cell bleeding out of the
                // column before it.
                $span = \array_slice($characters, $start, ($starts[$column + 1] ?? (\count($characters) + 2)) - $start - 2);
                $text = \implode('', $span);
                if ('' === \trim($text)) {
                    continue; // this row leaves the column empty
                }
                $this->assertSame($start, $start + \strlen($text) - \strlen(\ltrim($text)), "row {$index} does not start column {$column} at the rule");
                if (0 !== $column) {
                    $this->assertSame(' ', $characters[$start - 1] ?? ' ', "row {$index} runs into column {$column}");
                }
                $begun[$column] = true;
            }
        }

        // Alignment is only proven if cells actually START at each boundary —
        // a table of blank rows satisfies every assertion above.
        $this->assertSame(\range(start: 0, end: \count($starts) - 1), \array_keys($begun), 'every column has a row whose cell begins at the rule');
    }

    /**
     * Cells of DIFFERING heights in one row, which is what puts a short cell on
     * the empty-fragment fallback the mutant above targets.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function alignmentTables(): iterable
    {
        yield 'wrapping beside short' => ["| Key | Guide |\n| --- | --- |\n| `F1` | some long leading words then more after it |\n| `F2` | short |", 46];
        yield 'three columns' => ["| A | B | C |\n| --- | --- | --- |\n| one | a longer middle cell that must wrap | three |", 40];
        yield 'wide pane, no wrapping' => ["| A | B |\n| --- | --- |\n| one | two |", 80];
    }

    /**
     * A table of nothing but separator rows yields no columns at all.
     *
     * Degenerate, and reachable: any `|`-led block whose every row matches the
     * separator pattern. It is here because the column budgeter calls `max()`
     * over the widths, which throws on an empty array — the loop simply cannot
     * run in that case (one leading space is never wider than the pane), and
     * this is what says so out loud.
     */
    public function testATableOfOnlySeparatorRowsRendersWithoutColumns(): void
    {
        $doc = $this->render("| --- |\n| --- |", 40);

        $this->assertCount(2, $doc->lines);
        $this->assertSame(['', ''], $this->rows($doc->lines), 'two rules over no columns is two empty rules');
    }

    /**
     * A cell too wide for its column wraps down the column instead of losing
     * its tail.
     *
     * This row used to render as `xxxxxxxx  words then a li…` — everything past
     * the pane simply gone, and the link with it. There is no horizontal scroll
     * in the help viewer and `Ctrl+F` searches the RENDERED lines, so a cut
     * cell was neither readable nor findable. Measured before the change: 192
     * of 3019 rows across `docs/` came out elided at a 91-column pane.
     */
    public function testATableCellWrapsDownItsColumnRatherThanLosingItsTail(): void
    {
        $doc = $this->render("| A | B |\n| --- | --- |\n| xxxxxxxxxx | words then a [link](far.md) |", 20);

        $body = \implode(' ', \array_map(rtrim(...), \array_slice($this->rows($doc->lines), offset: 2)));
        foreach (['xxxxxxxx', 'words', 'then', 'a', 'link'] as $word) {
            $this->assertStringContainsString($word, $body, 'every word of both cells is on screen somewhere');
        }
        $this->assertCount(1, $doc->links, 'and the link survives the wrap');
        $this->assertSame('far.md', $doc->links[0]->target);
    }

    /**
     * A link at the very end of a cell used to be the case most likely to be
     * lost: it sat where the truncate cut, and the row filled exactly to width
     * so there was not even an ellipsis to show it had gone. Wrapped, it is on
     * the next row and comfortably inside the margin.
     */
    public function testALinkAtTheEndOfAWrappedCellSurvives(): void
    {
        $doc = $this->render('| '.\str_repeat('a', times: 16).' [bb](t.md) |', 20);

        $rows = \array_map(rtrim(...), $this->rows($doc->lines));
        $this->assertSame(' '.\str_repeat('a', times: 16), $rows[0]);
        $this->assertSame(' bb', $rows[1], 'the tail wrapped rather than being cut');
        $this->assertNotEmpty($doc->links, 'and it is still a link');
    }

    /**
     * Both links, either side of an unbreakable twenty-character run: the run
     * hard-breaks the way an over-long URL does in a paragraph, and neither
     * link is on the cut side of anything any more.
     */
    public function testBothLinksAroundAnOverWideWordSurviveTheWrap(): void
    {
        $doc = $this->render('| [aa](x.md) '.\str_repeat('b', times: 20).' [cc](y.md) |', 20);

        $this->assertCount(2, $doc->links, 'the link after the over-wide run used to be truncated away');
        $this->assertSame(['x.md', 'y.md'], \array_map(static fn (object $l): string => $l->target, $doc->links));
    }

    public function testAnIndentedTableRowIsStillRecognized(): void
    {
        $doc = $this->render("  | A | B |\n  | --- | --- |\n  | x | y |");
        $plain = $this->rows($doc->lines);
        $this->assertSame(' A  B', \rtrim($plain[0]));
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
        $this->assertSame(' A  B', \rtrim($plain[0]));
        $this->assertSame(' x  y', \rtrim($plain[2]));
    }

    public function testContentImmediatelyAfterATableWithNoBlankLineStillRenders(): void
    {
        $doc = $this->render("| A |\n| --- |\n| x |\nafter");
        $plain = $this->rows($doc->lines);
        $this->assertSame('after', \end($plain), 'the boundary line was reprocessed as a paragraph, not swallowed by the table scan');
    }

    public function testATableRowOneColumnUnderWidthKeepsASpanEndingAtItsLastColumn(): void
    {
        $doc = $this->render('| '.\str_repeat('a', times: 15).' [bb](t.md) |', 20);
        $this->assertNotEmpty($doc->links, 'one column of slack: the same span is kept, not hidden');
    }
}
