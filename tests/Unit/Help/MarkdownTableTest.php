<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Help\LinkSpan;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Markdown tables on the page: column rules, the header bold over its rule,
 * each column aligned as its delimiter cell says, the columns budgeted against
 * the pane, and a cell too wide for its column wrapping down it — with every
 * link inside one surviving.
 *
 * Exact rows, on purpose: a table is where an off-by-one column shows, and
 * the decision history's "Table cells wrap now" entry is why this file gates
 * the layout to the cell.
 */
final class MarkdownTableTest extends TestCase
{
    private Styles $styles;

    protected function setUp(): void
    {
        $this->styles = new Styles(DarkTheme::definition());
    }

    private function render(string $markdown, int $width = 60): RenderedDoc
    {
        return new MarkdownRenderer($this->styles)->render($markdown, $width);
    }

    /** @return list<string> */
    private static function plain(RenderedDoc $doc): array
    {
        return \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $doc->lines);
    }

    public function testColumnsAreRuledAndAlignedAsTheirDelimitersSay(): void
    {
        $doc = $this->render("| Left | Mid | Right |\n|:-----|:---:|------:|\n| a | bb | ccc |\n| dddd | e | f |\n");
        $bar = Glyphs::MD_TABLE_BAR;
        $rule = Glyphs::MD_RULE;
        $this->assertSame(
            [
                " Left {$bar} Mid {$bar} Right",
                \str_repeat($rule, times: 5).$rule.Glyphs::MD_TABLE_CROSS.$rule.\str_repeat($rule, times: 3).$rule.Glyphs::MD_TABLE_CROSS.$rule.\str_repeat($rule, times: 5),
                " a    {$bar} bb  {$bar}   ccc",
                " dddd {$bar}  e  {$bar}     f",
            ],
            self::plain($doc),
        );
    }

    public function testTheHeaderIsBoldAndTheRuleAndBarsMuted(): void
    {
        $doc = $this->render("| a | b |\n|---|---|\n| c | d |\n");
        $muted = $this->styles->of(Role::Muted)->getAnsiRestore();
        $this->assertSame(' '.Sgr::BOLD.'a'.Sgr::RESET.' '.$muted.Glyphs::MD_TABLE_BAR.Sgr::RESET.' '.Sgr::BOLD.'b'.Sgr::RESET, $doc->lines[0]);
        $this->assertStringStartsWith($muted, $doc->lines[1]);
        $this->assertSame(' c '.$muted.Glyphs::MD_TABLE_BAR.Sgr::RESET.' d', $doc->lines[2]);
    }

    public function testACellTooWideWrapsDownItsColumnAndTheKeysKeepTheirWidth(): void
    {
        $doc = $this->render("| Key | Guide |\n|---|---|\n| `F1` | Opens the documentation browser at the page for whatever has focus |\n", 40);
        $bar = Glyphs::MD_TABLE_BAR;
        $this->assertSame(
            [
                " Key  {$bar} Guide",
                '',
                " \u{00A0}F1\u{00A0} {$bar} Opens the documentation browser",
                "      {$bar} at the page for whatever has",
                "      {$bar} focus",
            ],
            \array_map(static fn (string $line, int $i): string => 1 === $i ? '' : $line, self::plain($doc), \array_keys($doc->lines)),
            'the code span keeps its padding',
        );
        foreach ($doc->lines as $line) {
            $this->assertLessThanOrEqual(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testBelowTheColumnFloorTheRowIsWiderThanThePane(): void
    {
        $doc = $this->render('| '.\implode(' | ', \array_fill(0, count: 4, value: 'abcdefghij'))." |\n|---|---|---|---|\n", 20);
        $this->assertSame(1 + (4 * 8) + (3 * 3), AnsiUtils::visibleWidth($doc->lines[0]), 'every column at the eight-column floor; the page cuts the row');
    }

    public function testARowShortOfCellsIsPaddedAndOneWithTooManyIsCut(): void
    {
        $bar = Glyphs::MD_TABLE_BAR;
        $this->assertSame(
            [" a {$bar} b", '', " x {$bar}", " y {$bar} z"],
            \array_map(static fn (string $line, int $i): string => 1 === $i ? '' : $line, self::plain($this->render("| a | b |\n|---|---|\n| x |\n| y | z | extra |\n")), [0, 1, 2, 3]),
        );
    }

    /** @return iterable<string, array{string, int, list<string>}> */
    public static function linkedTables(): iterable
    {
        yield 'wide pane' => ["| A | B |\n|---|---|\n| one | see [the guide](g.md) |\n", 60, ['the guide']];
        yield 'wrapping cell' => ["| Key | Guide |\n|---|---|\n| k | a long cell whose link [at the very end](g.md) |\n", 30, ['at the very end']];
        yield 'links either side of an over-wide word' => ["| a | b |\n|---|---|\n| x | [one](1.md) abcdefghijklmnopqrstuvwxyz [two](2.md) |\n", 24, ['one', 'two']];
    }

    /**
     * A link in a cell covers exactly the cells it draws, wherever the wrap put it.
     *
     * @param list<string> $links
     */
    #[DataProvider('linkedTables')]
    public function testALinkInACellCoversExactlyItsText(string $markdown, int $width, array $links): void
    {
        $doc = $this->render($markdown, $width);
        $drawn = [];
        for ($group = 0; $group < $doc->groupCount(); ++$group) {
            $pieces = \array_map(static fn (LinkSpan $span): string => AnsiUtils::sliceByColumn(
                AnsiUtils::stripAnsiCodes($doc->lines[$span->line]),
                $span->col,
                $span->endCol - $span->col,
            ), $doc->spansOfGroup($group));
            $drawn[] = \implode(' ', $pieces);
        }
        $this->assertSame($links, $drawn);
    }

    public function testEveryRowOfATableKnowsTheSourceLineOfItsRow(): void
    {
        $doc = $this->render("text\n| a |\n|---|\n| b |\n| c |\n");
        $this->assertSame([0, 0, 1, 1, 3, 4], $doc->origins);
    }

    public function testATableExactlyAsWideAsThePaneIsNotNarrowed(): void
    {
        $bar = Glyphs::MD_TABLE_BAR;
        $this->assertSame(' '.\str_repeat('a', times: 12)." {$bar} bbbb", self::plain($this->render('| '.\str_repeat('a', times: 12)." | bbbb |\n|---|---|\n", 20))[0]);
        $this->assertSame(' '.\str_repeat('a', times: 19), self::plain($this->render('| '.\str_repeat('a', times: 19)." |\n|---|\n", 20))[0]);
    }

    public function testAFootnoteInACellKeepsItsBackrefAnchor(): void
    {
        $this->assertSame(0, $this->render("| a[^n] | b |\n|---|---|\n\n[^n]: Note.\n")->anchor('fnref-n'));
    }
}
