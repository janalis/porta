<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Graphics;

use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiText;
use Porta\Ui\Graphics\KittyPlaceholders;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class KittyPlaceholdersTest extends TestCase
{
    /** @return list<array{int, int}> each expanded cell's [row, column] diacritic indices */
    private static function cells(string $line): array
    {
        $table = [0x0305, 0x030D, 0x030E, 0x0310, 0x0312, 0x033D];
        \preg_match_all('/\x{10EEEE}(\p{Mn})(\p{Mn})/u', $line, $matches, \PREG_SET_ORDER);

        return \array_map(static fn (array $cell): array => [
            (int) \array_search(\mb_ord($cell[1]), $table, strict: true),
            (int) \array_search(\mb_ord($cell[2]), $table, strict: true),
        ], $matches);
    }

    public function testARowIsOneColumnPerCellInTheImagesColour(): void
    {
        $row = KittyPlaceholders::row(0x01_02_03, 2, 1, 4);

        $this->assertStringStartsWith("\x1b[38;2;1;2;3m", $row);
        $this->assertStringEndsWith("\x1b[39m", $row);
        $this->assertSame(4, AnsiUtils::visibleWidth($row));
    }

    public function testExpandingNamesEachCellsRowAndColumn(): void
    {
        $line = KittyPlaceholders::expand('ab'.KittyPlaceholders::row(1000, 2, 3, 3).'cd');

        $this->assertSame([[2, 3], [2, 4], [2, 5]], self::cells($line));
        $this->assertStringStartsWith('ab', $line);
        $this->assertStringEndsWith("\x1b[39mcd", $line);
    }

    public function testTheLastAddressableCellStillExpands(): void
    {
        $last = KittyPlaceholders::MAX_CELLS - 1;
        $line = KittyPlaceholders::expand(KittyPlaceholders::row(1000, $last, $last, 1));

        $this->assertMatchesRegularExpression('/^\x1b\[38;2;0;3;232m\x{10EEEE}\p{Mn}\p{Mn}\x1b\[39m$/u', $line);
    }

    public function testALineWithoutStandInsIsLeftAlone(): void
    {
        $this->assertSame("plain \x1b[1mtext\x1b[0m ✓", KittyPlaceholders::expand("plain \x1b[1mtext\x1b[0m ✓"));
        // Plane-16 text outside the stand-in range is not a cell.
        $this->assertSame("\u{10EEEE}\u{10FFFD}", KittyPlaceholders::expand("\u{10EEEE}\u{10FFFD}"));
    }

    public function testStandInsSurviveTheSlicesAnOverlaySpliceMakes(): void
    {
        // The reason stand-ins exist: a prefix slice sized a run of REAL
        // placeholders at three columns a cell, and cut a dropdown short.
        $line = KittyPlaceholders::row(1000, 0, 0, 11).\str_repeat('━', times: 42).KittyPlaceholders::row(1000, 0, 53, 20);

        $this->assertSame(53, AnsiUtils::visibleWidth(AnsiUtils::sliceByColumn($line, 0, 53)));
        $this->assertSame(53, AnsiUtils::visibleWidth(AnsiText::columnsFrom($line, 0, 53)));
        $this->assertSame(53, AnsiUtils::visibleWidth(AnsiUtils::truncateToWidth($line, 53)));
    }
}
