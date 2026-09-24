<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\DiagnosticStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\ErrorStripe;
use Porta\Widget\Render\StripeMarks;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class ErrorStripeTest extends TestCase
{
    /** @return list<string> */
    private function stripe(StripeMarks $marks, int $rows, int $lineCount, int $vScroll = 0, ?int $lastVisible = null, ?Styles $styles = null): array
    {
        return ErrorStripe::cells($marks, $rows, $lineCount, $vScroll, $lastVisible ?? (\min($rows, $lineCount) - 1), $styles ?? Styles::dark());
    }

    /**
     * @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int}>> $diagnostics
     *
     * @return list<string>
     */
    private function cells(array $diagnostics, int $rows, int $lineCount, int $vScroll = 0, ?int $lastVisible = null): array
    {
        $severity = [];
        foreach ($diagnostics as $line => $list) {
            if ([] === $list) {
                continue;
            }
            $severity[$line] = DiagnosticStyle::worst($list);
        }

        return $this->stripe(new StripeMarks($severity, [], [], []), $rows, $lineCount, $vScroll, $lastVisible);
    }

    public function testMarksLandOnTheirProportionalRowWithTheWorstSeverityWinning(): void
    {
        $diagnostics = [
            0 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'warn', 'col' => 0]],
            // The same stripe row as line 0 on this scale: the error must win.
            1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'err', 'col' => 0]],
            80 => [], // an empty list contributes nothing
        ];

        $cells = $this->cells($diagnostics, rows: 10, lineCount: 100);
        $this->assertCount(10, $cells);
        $this->assertSame(Glyphs::STRIPE_ERROR, AnsiUtils::stripAnsiCodes($cells[0]), 'the error beat the warning on the shared row');
        $this->assertSame(' ', AnsiUtils::stripAnsiCodes($cells[8]), 'line 80 painted nothing');
    }

    /** The worst wins in either order: a later, milder line on the same row must not overwrite an earlier error. */
    public function testALaterMilderLineDoesNotOverwriteTheRowsError(): void
    {
        $cells = $this->cells(
            [
                0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'err', 'col' => 0]],
                1 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'hint', 'col' => 0]],
            ],
            rows: 10,
            lineCount: 100,
        );

        $this->assertSame(Glyphs::STRIPE_ERROR, AnsiUtils::stripAnsiCodes($cells[0]));
    }

    /** A diagnostic on a stale line number past the current lineCount still clamps onto the last row. */
    public function testAnOutOfRangeLineClampsOntoTheLastRow(): void
    {
        $cells = $this->cells([10 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]], rows: 10, lineCount: 5, vScroll: 0);

        $this->assertSame(Glyphs::STRIPE_ERROR, AnsiUtils::stripAnsiCodes($cells[9]), 'clamped onto the last row, not silently dropped or off the end');
    }

    public function testShortFileCollapsesToRowZeroAndShowsNoThumb(): void
    {
        $cells = $this->cells([0 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 0]]], rows: 8, lineCount: 1);

        $this->assertSame(Glyphs::STRIPE_WARNING, AnsiUtils::stripAnsiCodes($cells[0]));
        foreach (\array_slice($cells, offset: 1) as $cell) {
            $this->assertSame(Sgr::RESET.' ', $cell, 'a one-line file paints nothing else — and no thumb');
        }
    }

    public function testThumbCoversTheVisibleRangeAndMergesWithMarks(): void
    {
        // 100 lines in 10 rows, viewing lines 50-79: thumb rows 5-7.
        $diagnostics = [55 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]];
        $cells = $this->cells($diagnostics, rows: 10, lineCount: 100, vScroll: 50, lastVisible: 79);

        $this->assertSame(Glyphs::STRIPE_THUMB, AnsiUtils::stripAnsiCodes($cells[6]), 'a plain thumb row: the thumb\'s own shape, so it reads without its shade');
        $this->assertNotSame(Sgr::RESET.Glyphs::STRIPE_THUMB, $cells[6], 'and it wears the thumb background');
        $this->assertSame(Sgr::RESET.' ', $cells[0], 'above the thumb stays blank');
        $this->assertSame(Sgr::RESET.' ', $cells[9], 'below the thumb stays blank');
        $marked = $cells[5];
        $this->assertSame(Glyphs::STRIPE_ERROR, AnsiUtils::stripAnsiCodes($marked), 'the diagnostic mark rides the thumb row');
        $plainMark = $this->cells($diagnostics, rows: 10, lineCount: 100)[5];
        $this->assertNotSame($plainMark, $marked, 'the thumb background merges under the mark');
        $this->assertStringStartsWith(Sgr::RESET, $marked, 'the leading reset shields the cell from prior row state, same as every other cell');
    }

    /** The file exactly fills the viewport: no scrolling possible, so no thumb at all. */
    public function testNoThumbWhenTheFileExactlyFillsTheViewport(): void
    {
        $cells = $this->cells([], rows: 10, lineCount: 10, vScroll: 0, lastVisible: 9);

        foreach ($cells as $i => $cell) {
            $this->assertSame(Sgr::RESET.' ', $cell, "row {$i}: no thumb when nothing is scrolled off");
        }
    }

    /** A stale/out-of-range vScroll or lastVisible still clamps the thumb onto exactly the last row — no more, no less. */
    public function testThumbFromAnOutOfRangeScrollClampsOntoExactlyTheLastRow(): void
    {
        $cells = $this->cells([], rows: 10, lineCount: 100, vScroll: 200, lastVisible: 200);

        $this->assertNotSame(Sgr::RESET.' ', $cells[9], 'the thumb reaches the last row, not a phantom row past it');
        $this->assertSame(Sgr::RESET.' ', $cells[8], 'row 8 is not swept into the thumb by an over-wide clamp');
    }

    /**
     * The precedence ladder, one rung per case. Each row carries the winner AND
     * the loser, so reordering any two rungs flips the expected glyph — a case
     * that only asserted the winner on a row where it was the sole mark would
     * survive the swap.
     *
     * @return \Generator<string, array{StripeMarks, string}>
     */
    public static function precedenceCases(): \Generator
    {
        yield 'a diagnostic outranks a breakpoint' => [
            new StripeMarks([0 => DiagnosticSeverity::Error], [], [], [0]),
            Glyphs::STRIPE_ERROR,
        ];
        yield 'a breakpoint outranks a change mark' => [
            new StripeMarks([], [0 => ChangeMarkKind::Modified], [], [0]),
            Glyphs::BREAKPOINT,
        ];
        yield 'a change mark outranks a search hit' => [
            new StripeMarks([], [0 => ChangeMarkKind::Added], [0], []),
            Glyphs::VCS_ADDED,
        ];
        yield 'a search hit paints when nothing outranks it' => [
            new StripeMarks([], [], [0], []),
            Glyphs::STRIPE_SEARCH,
        ];
    }

    #[DataProvider('precedenceCases')]
    public function testOneMarkPerRowResolvesByPrecedence(StripeMarks $marks, string $expected): void
    {
        $cells = $this->stripe($marks, rows: 10, lineCount: 100);

        $this->assertSame($expected, AnsiUtils::stripAnsiCodes($cells[0]));
    }

    /** Every mark kind is shape-distinct, so the stripe still reads with the roles collapsed. */
    public function testEveryMarkKindStaysDistinctWithoutColor(): void
    {
        $mono = new Styles(MonochromeTheme::definition());
        $glyphs = [];
        foreach (self::precedenceCases() as $case) {
            $glyphs[] = AnsiUtils::stripAnsiCodes($this->stripe($case[0], rows: 10, lineCount: 100, styles: $mono)[0]);
        }
        $glyphs[] = AnsiUtils::stripAnsiCodes($this->stripe(new StripeMarks([0 => DiagnosticSeverity::Warning], [], [], []), rows: 10, lineCount: 100, styles: $mono)[0]);

        $this->assertSame($glyphs, \array_unique($glyphs), 'two marks collapsed onto the same glyph: one of them is invisible in monochrome');
    }

    /**
     * Several changed lines share a stripe row and need not agree on the kind.
     * The topmost wins — and it must win from BOTH push orders, or the test is
     * an anchor trap: "last one wins" and "topmost wins" agree whenever the
     * producer happens to hand its marks over bottom-up.
     *
     * @return \Generator<string, array{array<int, ChangeMarkKind>}>
     */
    public static function changeMarkPushOrders(): \Generator
    {
        yield 'pushed top-down' => [[3 => ChangeMarkKind::Added, 7 => ChangeMarkKind::Deleted]];
        yield 'pushed bottom-up' => [[7 => ChangeMarkKind::Deleted, 3 => ChangeMarkKind::Added]];
    }

    /** @param array<int, ChangeMarkKind> $changes */
    #[DataProvider('changeMarkPushOrders')]
    public function testTheTopmostChangedLineInASliceWinsTheRow(array $changes): void
    {
        $cells = $this->stripe(new StripeMarks([], $changes, [], []), rows: 10, lineCount: 100);

        $this->assertSame(Glyphs::VCS_ADDED, AnsiUtils::stripAnsiCodes($cells[0]), 'line 3 is above line 7, so its kind is the one drawn');
    }

    /** A mark lands on its own row and nowhere else — no smearing down the column. */
    public function testAMarkTouchesOnlyItsOwnRow(): void
    {
        $cells = $this->stripe(new StripeMarks([], [], [50], []), rows: 10, lineCount: 100);

        $this->assertSame(Glyphs::STRIPE_SEARCH, AnsiUtils::stripAnsiCodes($cells[5]));
        foreach ([0, 4, 6, 9] as $row) {
            $this->assertContains(AnsiUtils::stripAnsiCodes($cells[$row]), [' ', Glyphs::STRIPE_THUMB], "row {$row} carries no mark");
        }
    }

    public function testZeroRowsPaintsNothing(): void
    {
        $this->assertSame([], $this->cells([], rows: 0, lineCount: 5));
    }

    /** A negative row count must not crash array_fill() — it degrades to no cells, same as zero. */
    public function testNegativeRowsPaintsNothingRatherThanCrashing(): void
    {
        $this->assertSame([], $this->cells([], rows: -1, lineCount: 5));
    }

    /**
     * The newer marks and their places in the precedence: the stopped line
     * over everything, the current hit over a change, a bookmark over an
     * ordinary hit.
     */
    public function testTheDebugLineTheCurrentHitAndBookmarksTakeTheirPlaces(): void
    {
        $cells = $this->stripe(
            new StripeMarks(
                [10 => DiagnosticSeverity::Error],
                [30 => ChangeMarkKind::Modified, 50 => ChangeMarkKind::Added],
                [30, 60, 70],
                [],
                bookmarkLines: [50, 70],
                debugLine: 10,
                currentSearchLine: 30,
            ),
            rows: 10,
            lineCount: 100,
        );
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $cells);

        $this->assertSame(Glyphs::STOPPED_LINE, $plain[1], 'the stopped line over the error on its row');
        $this->assertSame(Glyphs::STRIPE_SEARCH_CURRENT, $plain[3], 'the current hit over the change');
        $this->assertSame(Glyphs::VCS_ADDED, $plain[5], 'a change over a bookmark');
        $this->assertSame(Glyphs::STRIPE_SEARCH, $plain[6]);
        $this->assertSame(Glyphs::BOOKMARK, $plain[7], 'a bookmark over an ordinary hit');
    }

    /** The store's bookmarks, stopped line and current hit reach the marks. */
    public function testTheMarksAreReadFromTheStore(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->setBookmarkLines([4, 9]);
        $store->setDebugLine(7);
        $store->setSearchMatches([2 => [['start' => 0, 'end' => 1, 'current' => false]], 5 => [['start' => 0, 'end' => 1, 'current' => true]]]);

        $marks = StripeMarks::from($store, vcsGutter: false);

        $this->assertSame([4, 9], $marks->bookmarkLines);
        $this->assertSame(7, $marks->debugLine);
        $this->assertSame(5, $marks->currentSearchLine);
    }
}
