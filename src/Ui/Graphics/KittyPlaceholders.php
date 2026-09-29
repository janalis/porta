<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

/**
 * The text side of a kitty image shown through Unicode placeholders: each cell
 * of the placement is the private-use character U+10EEEE followed by two
 * combining diacritics — the cell's row, then its column, within the
 * placement — in a foreground colour whose 24 bits ARE the image id.
 *
 * Every cell carries its full row+column pair (the protocol lets later cells
 * infer theirs, but inference runs from the cell to the LEFT, which an overlay
 * may have replaced).
 *
 * ## Why a pane never paints the real thing
 *
 * The combining marks break the vendor's column arithmetic: its prefix and
 * truncation fast paths size a text run with `mb_strwidth()`, which counts
 * every combining mark as a column. A placeholder then measures three columns
 * wide the moment a run of them is sliced from column 0 — and slicing from
 * column 0 is exactly what an overlay splice does to the head of a row. The
 * symptom was a dropdown cut short and padded blank wherever it crossed a
 * picture.
 *
 * So a pane paints a STAND-IN: one plane-16 private-use codepoint per cell,
 * which encodes the row and column and is one column wide to every width
 * function there is. Every splice, reorder and join runs over those;
 * {@see expand()} turns them into real placeholders on the finished frame,
 * just before it is written. The stand-ins stop short of U+10EEEE itself, which
 * is what caps a placement at {@see MAX_CELLS}.
 *
 * The table is kitty's `gen/rowcolumn-diacritics.txt`, the spec's own list.
 */
final class KittyPlaceholders
{
    public const string BASE = "\u{10EEEE}";

    /** @var list<int> the spec's row/column diacritics, index = row or column number */
    private const array DIACRITICS = [
        0x0305,
        0x030D,
        0x030E,
        0x0310,
        0x0312,
        0x033D,
        0x033E,
        0x033F,
        0x0346,
        0x034A,
        0x034B,
        0x034C,
        0x0350,
        0x0351,
        0x0352,
        0x0357,
        0x035B,
        0x0363,
        0x0364,
        0x0365,
        0x0366,
        0x0367,
        0x0368,
        0x0369,
        0x036A,
        0x036B,
        0x036C,
        0x036D,
        0x036E,
        0x036F,
        0x0483,
        0x0484,
        0x0485,
        0x0486,
        0x0487,
        0x0592,
        0x0593,
        0x0594,
        0x0595,
        0x0597,
        0x0598,
        0x0599,
        0x059C,
        0x059D,
        0x059E,
        0x059F,
        0x05A0,
        0x05A1,
        0x05A8,
        0x05A9,
        0x05AB,
        0x05AC,
        0x05AF,
        0x05C4,
        0x0610,
        0x0611,
        0x0612,
        0x0613,
        0x0614,
        0x0615,
        0x0616,
        0x0617,
        0x0657,
        0x0658,
        0x0659,
        0x065A,
        0x065B,
        0x065D,
        0x065E,
        0x06D6,
        0x06D7,
        0x06D8,
        0x06D9,
        0x06DA,
        0x06DB,
        0x06DC,
        0x06DF,
        0x06E0,
        0x06E1,
        0x06E2,
        0x06E4,
        0x06E7,
        0x06E8,
        0x06EB,
        0x06EC,
        0x0730,
        0x0732,
        0x0733,
        0x0735,
        0x0736,
        0x073A,
        0x073D,
        0x073F,
        0x0740,
        0x0741,
        0x0743,
        0x0745,
        0x0747,
        0x0749,
        0x074A,
        0x07EB,
        0x07EC,
        0x07ED,
        0x07EE,
        0x07EF,
        0x07F0,
        0x07F1,
        0x07F3,
        0x0816,
        0x0817,
        0x0818,
        0x0819,
        0x081B,
        0x081C,
        0x081D,
        0x081E,
        0x081F,
        0x0820,
        0x0821,
        0x0822,
        0x0823,
        0x0825,
        0x0826,
        0x0827,
        0x0829,
        0x082A,
        0x082B,
        0x082C,
        0x082D,
        0x0951,
        0x0953,
        0x0954,
        0x0F82,
        0x0F83,
        0x0F86,
        0x0F87,
        0x135D,
        0x135E,
        0x135F,
        0x17DD,
        0x193A,
        0x1A17,
        0x1A75,
        0x1A76,
        0x1A77,
        0x1A78,
        0x1A79,
        0x1A7A,
        0x1A7B,
        0x1A7C,
        0x1B6B,
        0x1B6D,
        0x1B6E,
        0x1B6F,
        0x1B70,
        0x1B71,
        0x1B72,
        0x1B73,
        0x1CD0,
        0x1CD1,
        0x1CD2,
        0x1CDA,
        0x1CDB,
        0x1CE0,
        0x1DC0,
        0x1DC1,
        0x1DC3,
        0x1DC4,
        0x1DC5,
        0x1DC6,
        0x1DC7,
        0x1DC8,
        0x1DC9,
        0x1DCB,
        0x1DCC,
        0x1DD1,
        0x1DD2,
        0x1DD3,
        0x1DD4,
        0x1DD5,
        0x1DD6,
        0x1DD7,
        0x1DD8,
        0x1DD9,
        0x1DDA,
        0x1DDB,
        0x1DDC,
        0x1DDD,
        0x1DDE,
        0x1DDF,
        0x1DE0,
        0x1DE1,
        0x1DE2,
        0x1DE3,
        0x1DE4,
        0x1DE5,
        0x1DE6,
        0x1DFE,
        0x20D0,
        0x20D1,
        0x20D4,
        0x20D5,
        0x20D6,
        0x20D7,
        0x20DB,
        0x20DC,
        0x20E1,
        0x20E7,
        0x20E9,
        0x20F0,
        0x2CEF,
        0x2CF0,
        0x2CF1,
        0x2DE0,
        0x2DE1,
        0x2DE2,
        0x2DE3,
        0x2DE4,
        0x2DE5,
        0x2DE6,
        0x2DE7,
        0x2DE8,
        0x2DE9,
        0x2DEA,
        0x2DEB,
        0x2DEC,
        0x2DED,
        0x2DEE,
        0x2DEF,
        0x2DF0,
        0x2DF1,
        0x2DF2,
        0x2DF3,
        0x2DF4,
        0x2DF5,
        0x2DF6,
        0x2DF7,
        0x2DF8,
        0x2DF9,
        0x2DFA,
        0x2DFB,
        0x2DFC,
        0x2DFD,
        0x2DFE,
        0x2DFF,
        0xA66F,
        0xA67C,
        0xA67D,
        0xA6F0,
        0xA6F1,
        0xA8E0,
        0xA8E1,
        0xA8E2,
        0xA8E3,
        0xA8E4,
        0xA8E5,
        0xA8E6,
        0xA8E7,
        0xA8E8,
        0xA8E9,
        0xA8EA,
        0xA8EB,
        0xA8EC,
        0xA8ED,
        0xA8EE,
        0xA8EF,
        0xA8F0,
        0xA8F1,
        0xAAB0,
        0xAAB2,
        0xAAB3,
        0xAAB7,
        0xAAB8,
        0xAABE,
        0xAABF,
        0xAAC1,
        0xFE20,
        0xFE21,
        0xFE22,
        0xFE23,
        0xFE24,
        0xFE25,
        0xFE26,
        0x1_0A0F,
        0x1_0A38,
        0x1_D185,
        0x1_D186,
        0x1_D187,
        0x1_D188,
        0x1_D189,
        0x1_D1AA,
        0x1_D1AB,
        0x1_D1AC,
        0x1_D1AD,
        0x1_D242,
        0x1_D243,
        0x1_D244,
    ];

    /**
     * How many rows or columns one placement can address: the stand-ins
     * `row * MAX_CELLS + column` must stay below U+10EEEE (the diacritic table
     * itself would allow 297).
     */
    public const int MAX_CELLS = 239;

    /** The first stand-in codepoint; cell (row, column) is this plus `row * MAX_CELLS + column`. */
    private const int STAND_IN = 0x10_0000;

    /**
     * $count stand-in cells of placement row $row, starting at placement
     * column $firstColumn, in $imageId's colour — {@see expand()} makes them
     * real on the finished frame.
     */
    public static function row(int $imageId, int $row, int $firstColumn, int $count): string
    {
        $cells = '';
        for ($column = $firstColumn; $column < ($firstColumn + $count); ++$column) {
            $cells .= \mb_chr(self::STAND_IN + ($row * self::MAX_CELLS) + $column);
        }

        return \sprintf("\x1b[38;2;%d;%d;%dm%s\x1b[39m", ($imageId >> 16) & 0xFF, ($imageId >> 8) & 0xFF, $imageId & 0xFF, $cells);
    }

    /** $line with every stand-in replaced by the placeholder cell it stands for. */
    public static function expand(string $line): string
    {
        // Every stand-in's UTF-8 starts with 0xF4, which almost no line has.
        if (!\str_contains($line, "\xF4")) {
            return $line;
        }

        return (string) \preg_replace_callback(
            '/[\x{100000}-\x{10DF20}]/u',
            static function (array $match): string {
                $index = \mb_ord($match[0]) - self::STAND_IN;

                return self::BASE.\mb_chr(self::DIACRITICS[\intdiv($index, self::MAX_CELLS)]).\mb_chr(self::DIACRITICS[$index % self::MAX_CELLS]);
            },
            $line,
        );
    }
}
