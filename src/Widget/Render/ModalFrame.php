<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The one place a floating modal's frame is drawn: a box-glyph title bar —
 * `╭ Title ─── Esc ×╮` — and the side rails + bottom row that wrap the
 * content. Shared by the Welcome modal and the centered pickers so every
 * floating surface speaks the same visual language: accent-coloured border
 * ({@see Role::TabAccent}, the image pane's focus-frame precedent), bold
 * title, faint dismiss hint, and the tab strips' `×` affordance
 * ({@see TabCloseGlyph}) whose hit zone {@see \Porta\Overlay\Region\ModalBox::closeHit}
 * mirrors.
 *
 * Every returned row is exactly the requested width in visible columns — the
 * compositor invariant; a row that misses it blows out its host pane's line.
 */
final class ModalFrame
{
    /**
     * The full top row of a $width-column box. The title yields to the fixed
     * chrome (and the hint yields entirely on a narrow box) so the `×` stays
     * put at {@see \Porta\Overlay\Region\ModalBox::CLOSE_OFFSET} from the right edge.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public static function titleBar(string $title, int $width, Styles $styles, bool $closeHovered = false, string $hint = 'Esc'): string
    {
        $accent = $styles->of(Role::TabAccent);
        $close = $closeHovered ? TabCloseGlyph::hovered($styles) : TabCloseGlyph::inactive($styles);

        // '╭ ' + label + ' ' + fill + [' ' + hint] + ' ' + × + '┐': the fixed
        // chrome is 6 columns, plus the hint and its separator when shown.
        $hintWidth = \mb_strwidth($hint);
        $room = $width - 6 - ($hintWidth > 0 ? $hintWidth + 1 : 0);
        if ($room < 8 && $hintWidth > 0) {
            $hint = '';
            $hintWidth = 0;
            $room = $width - 6;
        }
        $label = AnsiText::truncate($title, \max(0, $room));
        $fill = \str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $room - AnsiUtils::visibleWidth($label)));

        $bar =
            $accent->apply(Glyphs::MODAL_TOP_LEFT)
            .' '
            .$styles->of(Role::Bright)->withBold()->apply($label)
            .' '
            .$accent->apply($fill)
            .($hintWidth > 0 ? ' '.$styles->of(Role::Faint)->apply($hint) : '')
            .' '
            .$close
            .$accent->apply(Glyphs::MODAL_TOP_RIGHT);

        // By construction the bar is exactly $width columns; the truncate is
        // the degenerate-width backstop (a box too narrow for its chrome) that
        // keeps the compositor invariant unconditional.
        return AnsiText::truncate($bar, \max(0, $width), pad: true);
    }

    /**
     * The chrome a frame adds around content of W columns × H rows: one rail
     * and one padding column each side (width = W + CHROME_COLS), and — below
     * the title bar — a padding row, the content, a padding row and the bottom
     * border (rows = H + CHROME_ROWS, of which CHROME_ROWS_TOP sit above the
     * content). Every consumer derives its geometry from these three numbers.
     */
    public const int CHROME_COLS = 4;
    public const int CHROME_ROWS = 4;
    public const int CHROME_ROWS_TOP = 2;

    /** The tee row {@see wrapSections()} draws between two sections. */
    public const int SECTION_ROWS = 1;

    /**
     * The rails, padding and bottom border around already-rendered content
     * rows — {@see wrapSections()} with a single section.
     *
     * @param list<string> $content
     *
     * @return list<string>
     */
    public static function wrap(array $content, int $innerWidth, Styles $styles, bool $resizeCornerHot = false, ?ListOverflow $overflow = null): array
    {
        return self::wrapSections([$content], $innerWidth, $styles, $resizeCornerHot, $overflow);
    }

    /**
     * The frame body below the title bar: a padding row, each section's rows
     * (`│ ` + row + ` │`) with a `├──┤` tee row between sections, a padding
     * row, then the bottom border. Each content row is padded/clipped to
     * $innerWidth once, here — the single place the invariant is enforced.
     * Total row width: $innerWidth + CHROME_COLS.
     *
     * $legends[$i] names the section after the $i-th tee, set into it:
     * `├─ Files ───┤`, a group's heading drawn as the line that parts it.
     *
     * @param list<list<string>> $sections
     * @param list<string>       $legends
     *
     * @return list<string>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public static function wrapSections(array $sections, int $innerWidth, Styles $styles, bool $resizeCornerHot = false, ?ListOverflow $overflow = null, array $legends = []): array
    {
        $accent = $styles->of(Role::TabAccent);
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);
        $pad = $rail.AnsiText::blank($innerWidth + 2).$rail;
        // A clipped list says so in the padding it already has: ▲ above, ▼
        // below, centred and faint, the tab strip's and settings nav's cue.
        $cue = static fn (bool $more, string $glyph): string => $more ? $rail.' '.EdgeIndicator::row($glyph, $innerWidth, $styles).' '.$rail : $pad;

        $rows = [$cue($overflow->above ?? false, Glyphs::SCROLL_UP)];
        foreach ($sections as $i => $section) {
            if ($i > 0) {
                // At least its two tees, like every edge of the frame: a degenerate
                // width shuts the box rather than opening it.
                $rows[] = Rule::horizontal(\max(2, $innerWidth + 4), $styles, $legends[$i - 1] ?? '', Role::TabAccent);
            }
            foreach ($section as $line) {
                $rows[] = $rail.' '.AnsiText::truncate($line, \max(0, $innerWidth), pad: true).' '.$rail;
            }
        }
        $rows[] = $cue($overflow->below ?? false, Glyphs::SCROLL_DOWN);
        // The bottom-right corner is the resizable modals' grab: thin rounded
        // at rest (identical to a non-resizable box — the hover reveals it,
        // the SeparatorWidget convention), heavy and bright while the pointer
        // is on it or a resize drag is running.
        $corner = $resizeCornerHot
            ? $styles->of(Role::Bright)->withBold()->apply(Glyphs::MODAL_RESIZE_GRAB)
            : $accent->apply(Glyphs::MODAL_BOTTOM_RIGHT);
        // Where the reader is in a clipped list — `3/40` — set into the bottom
        // border, as a trailing hint sits in a field's.
        $position = null !== $overflow && $overflow->clipped() ? $overflow->position() : '';
        $rows[] = BorderHint::bottom(Glyphs::MODAL_BOTTOM_LEFT, $corner, Glyphs::FRAME_HORIZONTAL, $innerWidth + 2, $accent, $position, $styles);

        return $rows;
    }
}
