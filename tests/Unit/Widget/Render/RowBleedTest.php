<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\RowBleed;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

final class RowBleedTest extends TestCase
{
    /** @return iterable<string, array{?Style, ?Style, string}> */
    public static function sides(): iterable
    {
        $hover = new Style(background: 236);
        $selected = new Style(background: 31, color: 231, bold: true);
        $reversed = new Style(reverse: true);

        yield 'no row either side' => [null, null, '     '];
        yield 'two rows at rest' => [new Style(color: 110), new Style(), '     '];
        yield 'an underline is no shade' => [new Style(underline: true), null, '     '];
        yield 'the shaded row above: its bottom half' => [$hover, null, '▀▀▀▀▀'];
        yield 'the shaded row below: its top half' => [null, $hover, '▄▄▄▄▄'];
        yield 'a shaded row on each side' => [$selected, $hover, '▀▀▀▀▀'];
        yield 'a reversed row above a coloured one' => [$reversed, $hover, '▀▀▀▀▀'];
        yield 'a coloured row above a reversed one' => [$hover, $reversed, '▄▄▄▄▄'];
        yield 'two reversed rows' => [$reversed, $reversed, '█████'];
    }

    #[DataProvider('sides')]
    public function testTheGapDrawsTheHalvesOfTheRowsBesideIt(?Style $above, ?Style $below, string $glyphs): void
    {
        $gap = RowBleed::gap(5, $above, $below);

        $this->assertSame($glyphs, AnsiUtils::stripAnsiCodes($gap));
        $this->assertSame(5, AnsiUtils::visibleWidth($gap), 'exactly the width, like every row it sits between');
    }

    /** The half block wears the row's background as its foreground: that is the whole trick. */
    public function testAHalfIsTheRowsBackgroundAsAForeground(): void
    {
        $hover = new Style(background: 236);

        $this->assertSame(new Style(color: 236)->apply('▀▀'), RowBleed::gap(2, $hover, null));
        $this->assertSame(new Style(color: 236)->apply('▄▄'), RowBleed::gap(2, null, $hover));
    }

    /** Between two shades the upper row's colour is the glyph, the lower row's its background. */
    public function testBetweenTwoShadesEachKeepsItsOwnHalf(): void
    {
        $selected = new Style(background: 31, color: 231, bold: true);
        $hover = new Style(background: 236);

        $this->assertSame(new Style(color: 31, background: 236)->apply('▀▀'), RowBleed::gap(2, $selected, $hover));
        $this->assertSame(new Style(color: 236, background: 31)->apply('▀▀'), RowBleed::gap(2, $hover, $selected));
    }

    /**
     * A reversed row shows the terminal's foreground, which only a glyph can
     * paint: the coloured side takes the background, whichever side it is.
     */
    public function testAReversedRowsHalfIsTheTerminalsForeground(): void
    {
        $reversed = new Style(reverse: true);
        $hover = new Style(background: 236);

        $this->assertSame(new Style(background: 236)->apply('▀▀'), RowBleed::gap(2, $reversed, $hover));
        $this->assertSame(new Style(background: 236)->apply('▄▄'), RowBleed::gap(2, $hover, $reversed));
    }

    /** The monochrome theme's unfocused selection is dim and reversed: its half is dim too. */
    public function testADimReversedRowBleedsDim(): void
    {
        $dim = new Style(dim: true, reverse: true);

        $this->assertSame(new Style(dim: true)->apply('▀▀'), RowBleed::gap(2, $dim, null));
        $this->assertSame(new Style(dim: true)->apply('▄▄'), RowBleed::gap(2, null, $dim));
        $this->assertSame(new Style(dim: true)->apply('██'), RowBleed::gap(2, $dim, new Style(reverse: true)), "the upper row's paint");
    }

    /** Only a blank line bleeds: a rule or an edge row beside a shaded row is a border and keeps its glyphs. */
    public function testAroundBleedsOnlyTheBlankLinesBesideAShadedRow(): void
    {
        $hover = new Style(background: 236);
        $lines = ['─────', 'a    ', '     ', 'b    ', '     ', '     '];

        $bled = RowBleed::around($lines, [1 => null, 3 => $hover], 5);

        $this->assertSame(['─────', 'a    ', '▄▄▄▄▄', 'b    ', '▀▀▀▀▀', '     '], \array_map(AnsiUtils::stripAnsiCodes(...), $bled));
        $this->assertSame([], RowBleed::around([], [], 5));
    }

    /** A pane too narrow for a column has no gap to draw: nothing, never a stray half. */
    public function testNoWidthDrawsNothing(): void
    {
        foreach ([0, -2] as $width) {
            $this->assertSame('', AnsiUtils::stripAnsiCodes(RowBleed::gap($width, new Style(background: 236), null)), "width {$width}");
        }
    }
}
