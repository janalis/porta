<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\MenuOverlay;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MenuOverlayTest extends TestCase
{
    public function testASpanClippedToExactlyZeroWidthIsSkipped(): void
    {
        $lines = [\str_repeat(' ', times: 10)];
        // col=10 on a 10-column line leaves 0 columns of room: min(width, 10-10) === 0.
        $span = ['row' => 0, 'col' => 10, 'width' => 5, 'segment' => 'XXXXX'];

        $out = MenuOverlay::apply($lines, [$span], columns: 10);

        $this->assertSame($lines, $out, 'a zero-width span must not be composited at all');
    }

    public function testASpanExactlyAsWideAsItsColumnBudgetIsCompositedUnclipped(): void
    {
        $lines = [\str_repeat(' ', times: 10)];
        // width === span width exactly: no clipping needed, the raw segment is used
        // untouched — slicing it (even at its own full length) would strip its
        // trailing reset, since AnsiUtils::sliceByColumn does not preserve one.
        $span = ['row' => 0, 'col' => 0, 'width' => 5, 'segment' => "\x1b[7mXXXXX\x1b[0m"];

        $out = MenuOverlay::apply($lines, [$span], columns: 10);

        // Sliced (even at the segment's own full length) loses its trailing reset;
        // OverlayCompositor::into() then only adds its own single reset at the tail.
        // Untouched, the segment's reset survives, doubling up with that same tail one.
        $this->assertSame("\x1b[0m\x1b[7mXXXXX\x1b[0m\x1b[0m     \x1b[0m", $out[0]);
    }

    public function testCellsThatRunOnAlongARowShowAsTheyWouldOneSpliceAtATime(): void
    {
        $line = self::styledRow(20);
        $spans = [
            // A half in the cell's shade ending in a bare 39m, as RuleHalves draws
            // one: its background stays set, and the reset a splice put after it
            // is all that keeps it off the plain cell next to it.
            ['row' => 0, 'col' => 3, 'width' => 1, 'segment' => "\x1b[48;5;1m\x1b[38;5;2m▄\x1b[39m"],
            ['row' => 0, 'col' => 4, 'width' => 1, 'segment' => 'x'],
            ['row' => 0, 'col' => 5, 'width' => 2, 'segment' => "\x1b[7myz\x1b[0m"],
            ['row' => 0, 'col' => 7, 'width' => 1, 'segment' => "\x1b[38;2;9;9;9m▀\x1b[0m"],
        ];
        $oneByOne = [$line];
        foreach ($spans as $span) {
            $oneByOne = MenuOverlay::apply($oneByOne, [$span], columns: 20);
        }

        $merged = MenuOverlay::apply([$line], $spans, columns: 20);

        $this->assertSame(self::cells($oneByOne[0]), self::cells($merged[0]));
        $this->assertSame([], self::cells($merged[0])[4]['sgr'], 'the plain cell after a half keeps none of its shade');
    }

    public function testACellPerSpanAcrossARowDoesNotGrowTheRow(): void
    {
        // A head bar reaching into a bar's half, a cell per span: spliced one at
        // a time, each re-slice replayed the row's prefix escapes — 7.8 KB to
        // 579 KB, 1.5 s a frame.
        $line = self::styledRow(226);
        $spans = [];
        for ($col = 25; $col < 200; ++$col) {
            $spans[] = ['row' => 0, 'col' => $col, 'width' => 1, 'segment' => "\x1b[38;2;1;2;3;48;2;4;5;6m▄\x1b[0m"];
        }

        $out = MenuOverlay::apply([$line], $spans, columns: 226);

        $this->assertSame(226, AnsiUtils::visibleWidth($out[0]));
        $this->assertLessThan(3 * \strlen($line), \strlen($out[0]));
    }

    public function testALaterSpanStillWinsWhereItOverlapsARun(): void
    {
        $spans = [
            ['row' => 0, 'col' => 2, 'width' => 3, 'segment' => 'AAA'],
            ['row' => 0, 'col' => 5, 'width' => 1, 'segment' => 'B'],
            ['row' => 0, 'col' => 4, 'width' => 1, 'segment' => 'C'],
        ];

        $out = MenuOverlay::apply([\str_repeat('.', times: 8)], $spans, columns: 8);

        $this->assertSame('..AACB..', AnsiUtils::stripAnsiCodes($out[0]));
    }

    public function testSpansWithAGapOrOnAnotherRowAreNotRun(): void
    {
        $spans = [
            ['row' => 0, 'col' => 0, 'width' => 1, 'segment' => 'A'],
            ['row' => 0, 'col' => 2, 'width' => 1, 'segment' => 'B'],
            ['row' => 1, 'col' => 3, 'width' => 1, 'segment' => 'C'],
        ];

        $out = MenuOverlay::apply(\array_fill(0, count: 2, value: \str_repeat('.', times: 5)), $spans, columns: 5);

        $this->assertSame(['A.B..', '...C.'], \array_map(AnsiUtils::stripAnsiCodes(...), $out));
    }

    /** A row with its own colours on every cell, as a highlighted editor line is. */
    private static function styledRow(int $columns): string
    {
        $line = '';
        for ($col = 0; $col < $columns; ++$col) {
            $line .= "\x1b[38;2;".($col % 255).';100;200;48;2;30;30;30m'.(0 === ($col % 2) ? '▀' : 'a');
        }

        return $line."\x1b[0m";
    }

    /**
     * Each cell's glyph and the SGR parameters in force over it, reset to
     * nothing by a 0 — what a terminal would draw, whatever bytes got it there.
     *
     * @return list<array{glyph: string, sgr: list<string>}>
     */
    private static function cells(string $line): array
    {
        $cells = [];
        $sgr = [];
        $parts = \preg_split('/(\x1b\[[0-9;]*m)/', $line, flags: \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        foreach (false === $parts ? [] : $parts as $part) {
            $codes = [];
            if (1 === \preg_match('/^\x1b\[([0-9;]*)m$/', $part, $codes)) {
                $sgr = \in_array($codes[1], ['', '0'], strict: true) ? [] : [...$sgr, $codes[1]];
                continue;
            }
            foreach (\mb_str_split($part) as $glyph) {
                $cells[] = ['glyph' => $glyph, 'sgr' => $sgr];
            }
        }

        return $cells;
    }
}
