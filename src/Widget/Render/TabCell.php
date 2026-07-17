<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * One horizontal tab of the strip family: ` label ●× ` plus its trailing
 * divider column, as drawn by the bottom dock's strip
 * ({@see TabStripRenderer}) and the editor's horizontal tab bar
 * ({@see \Porta\Widget\TabBarWidget}).
 *
 * The two windowed the strip differently — the dock drops leading tabs, the
 * editor bar carries a sticky scroll offset and reserves columns for its ‹/›
 * indicators — but their *cells* were byte-identical, measured and assembled
 * twice. The windowing is deliberate per-widget policy and stays theirs; the
 * cell is what must not drift, so it lives here.
 *
 * Widths are display columns throughout: `width` includes the divider, and
 * `closeOffset` is the ×'s column within the cell. Both are consumed as click
 * zones, so a line whose visible width disagrees with them garbles the strip.
 */
final class TabCell
{
    /** The × cell plus its trailing space — the close zone's clickable width. */
    public const int CLOSE_WIDTH = 2;

    /**
     * Measure ` $label $marker× ` + its divider. $marker is the dirty ● (or the
     * session ○ some callers bake into the label); pass '' for a bare tab.
     *
     * @return array{text: string, width: int, closeOffset: int}
     */
    public static function measure(string $label, string $marker = ''): array
    {
        $prefix = ' '.$label.' ';
        $text = $prefix.$marker.Glyphs::CLOSE.' ';

        return [
            'text' => $text,
            'width' => mb_strwidth($text) + 1, // + the divider column
            'closeOffset' => mb_strwidth($prefix) + mb_strwidth($marker),
        ];
    }

    /**
     * The styled cell + its divider, from a $text that {@see measure()} built.
     *
     * The × is measured as a bare glyph and only styled here, then spliced in:
     * styling it earlier would leave measure() counting escape bytes as
     * display columns. The splice is safe because {@see TabCloseGlyph} emits a
     * foreground-only span (closed with `\e[39m`, never a full reset), so the
     * tab's background runs underneath it uninterrupted.
     */
    public static function render(string $text, Styles $styles, TabState $state): string
    {
        $close = match ($state) {
            TabState::Active => TabCloseGlyph::active($styles),
            TabState::Hovered, TabState::Plain => TabCloseGlyph::inactive($styles),
        };

        return TabStyle::of($styles, $state)->apply(str_replace(Glyphs::CLOSE, $close, $text)).$styles->of(Role::Placeholder)->apply(Glyphs::SEPARATOR);
    }
}
