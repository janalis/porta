<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Style;

/**
 * The blank gap a roomy list leaves between two rows, with the shade of each
 * row beside it reaching halfway in: a hovered or selected row reads as one
 * shape two lines tall, centred on its text, instead of a strip between two
 * empty lines.
 *
 * A cell cannot be half shaded by its background, so the half is a block
 * glyph wearing the row's background as its FOREGROUND — `▄` in the gap above
 * a row, `▀` in the gap below it. Between two shaded rows it is one `▀`: the
 * upper row's colour on the lower row's.
 *
 * Only a line that would be blank becomes one: a rule, an edge indicator or a
 * tab's outline is a border, and a cell cannot hold a border and a half.
 *
 * A row reversed instead of coloured (the monochrome theme's selection) is
 * shaded in the terminal's foreground, which a glyph can paint but a
 * background cannot. A row with neither (the monochrome hover's underline,
 * `--no-ansi`) has no shade to bleed. Every line is exactly $width columns.
 */
final class RowBleed
{
    /**
     * $lines with every blank one beside a shaded row bled into: $shades holds
     * each row's style by line. A line with anything on it is left alone.
     *
     * @param list<string>       $lines
     * @param array<int, ?Style> $shades
     *
     * @return list<string>
     */
    public static function around(array $lines, array $shades, int $width): array
    {
        $blank = AnsiText::blank($width);
        foreach ($lines as $i => $line) {
            if ($line !== $blank) {
                continue;
            }
            $lines[$i] = self::gap($width, $shades[$i - 1] ?? null, $shades[$i + 1] ?? null);
        }

        return $lines;
    }

    /** The half of a row styled $row on the line above it: `▄` in its shade, blank for none. */
    public static function over(int $width, ?Style $row): string
    {
        return self::gap($width, null, $row);
    }

    /** The half of a row styled $row on the line below it: `▀` in its shade, blank for none. */
    public static function under(int $width, ?Style $row): string
    {
        return self::gap($width, $row, null);
    }

    /**
     * The gap between a row styled $above and one styled $below; null, or a
     * style with no shade, for a side with no row or an unshaded one.
     */
    public static function gap(int $width, ?Style $above, ?Style $below): string
    {
        $up = self::shade($above);
        $down = self::shade($below);
        $run = static fn (string $glyph): string => \str_repeat($glyph, \max(0, $width));

        return match (true) {
            null === $up && null === $down => AnsiText::blank($width),
            null === $down => self::paint($up)->apply($run(Glyphs::HALF_UPPER)),
            null === $up => self::paint($down)->apply($run(Glyphs::HALF_LOWER)),
            // The glyph's foreground can be the terminal's own colour, its
            // background cannot: the coloured half takes the background.
            $down instanceof Color => self::paint($up)->withBackground($down)->apply($run(Glyphs::HALF_UPPER)),
            $up instanceof Color => self::paint($down)->withBackground($up)->apply($run(Glyphs::HALF_LOWER)),
            default => self::paint($up)->apply($run(Glyphs::HALF_BOTH)),
        };
    }

    /**
     * A row's shade: its background colour; for a reversed row, the style
     * that paints the terminal's foreground as the row shows it (dim kept);
     * null for none.
     */
    private static function shade(?Style $row): Color|Style|null
    {
        // @noinspection NullPointerExceptionInspection -- each arm's own `?->` test has ruled the null row out
        return match (true) {
            null !== $row?->getBackground() => $row->getBackground(),
            true === $row?->getReverse() => new Style(dim: $row->getDim()),
            default => null,
        };
    }

    /** The style a half block takes to show $shade as its foreground. */
    private static function paint(Color|Style $shade): Style
    {
        return $shade instanceof Style ? $shade : new Style(color: $shade);
    }
}
