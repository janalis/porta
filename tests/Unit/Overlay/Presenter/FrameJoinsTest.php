<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Presenter\FrameJoins;
use Porta\Overlay\Region\DropdownRegion;

/**
 * The joins read off a painted frame: a rule meeting a divider turns into it,
 * and nothing else is touched — not a hovered divider, not a cell under a
 * floating surface, not a rule that stops short of the divider.
 */
final class FrameJoinsTest extends TestCase
{
    /**
     * @param list<string>               $grid
     * @param list<array{int, int, int}> $dividers
     * @param list<DropdownRegion>       $floating
     *
     * @return list<string> the grid with the joins drawn in
     */
    private static function joined(array $grid, array $dividers, array $floating = []): array
    {
        $cells = \array_map(\mb_str_split(...), $grid);
        foreach (FrameJoins::junctions($grid, $dividers, $floating) as [$row, $col, $glyph]) {
            $cells[$row][$col] = $glyph;
        }

        return \array_map(\implode(...), $cells);
    }

    public function testTheEdgeRulesTurnIntoTheDividersEnds(): void
    {
        $this->assertSame(
            [
                'menu    ',
                '───┬────',
                'tre│ed  ',
                'tre│ed  ',
                '───┴────',
                'status  ',
            ],
            self::joined(['menu    ', '────────', 'tre│ed  ', 'tre│ed  ', '────────', 'status  '], [[2, 3, 2]]),
        );
    }

    public function testARuleRunningUpToTheDividerIsATeeAndOneCrossingItACross(): void
    {
        $this->assertSame(
            ['─ A─┤ b ', 'x   ├─ C', '────┼───', 'x─  │ d '],
            self::joined(['─ A─│ b ', 'x   │─ C', '────│───', 'x─  │ d '], [[0, 4, 4]]),
            'a rule that stops a column short of the divider is not joined (the last row)',
        );
    }

    public function testAHoveredDividerIsLeftAlone(): void
    {
        $grid = ['────────', 'a  ┃ b  ', '───┃────'];

        $this->assertSame($grid, self::joined($grid, [[1, 3, 2]]), 'the heavy grab is not a line');
    }

    public function testNothingUnderAFloatingSurfaceIsTouched(): void
    {
        $grid = ['────────', 'a  │────', 'a  │────'];
        $dropdown = new DropdownRegion(1, 2, 4, 1); // row 1, columns 2-5

        $this->assertSame(
            ['───┬────', 'a  │────', 'a  ├────'],
            self::joined($grid, [[1, 3, 2]], [$dropdown]),
            'the covered row keeps its divider; the rows around it still join',
        );
        $this->assertSame(
            ['────────', 'a  ├────', 'a  ├────'],
            self::joined($grid, [[1, 3, 2]], [new DropdownRegion(0, 3, 1, 1)]),
            'nor is a rule cell under one',
        );
    }

    public function testAMirroredDividerIsFoundWhereItIsDrawn(): void
    {
        // Laid out at column 1; a right-to-left screen draws it at 6 of 8.
        $this->assertSame(
            ['──────┬─', 'ed    │t', '──────┴─'],
            self::joined(['────────', 'ed    │t', '────────'], [[1, 1, 1]]),
        );
    }

    public function testADividerNotDrawnAnywhereJoinsNothing(): void
    {
        $grid = ['────────', 'a       ', '────────'];

        $this->assertSame($grid, self::joined($grid, [[1, 3, 1]]));
    }

    public function testAnEmptyFrameHasNoJoins(): void
    {
        $this->assertSame([], FrameJoins::junctions([], [[0, 0, 1]], []));
    }

    public function testTheFramesEdgesAreNotRules(): void
    {
        $this->assertSame(['│a', '│a'], self::joined(['│a', '│a'], [[0, 0, 2]]), 'nothing above the first row, below the last or left of column 0');
    }
}
