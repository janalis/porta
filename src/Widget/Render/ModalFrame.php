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
 * `┌ Title ─── Esc ×┐` — and the side rails + bottom row that wrap the
 * content. Shared by the Welcome modal and the centered pickers so every
 * floating surface speaks the same visual language: accent-coloured border
 * ({@see Role::TabAccent}, the image pane's focus-frame precedent), bold
 * title, faint dismiss hint, and the tab strips' `×` affordance
 * ({@see TabCloseGlyph}) whose hit zone {@see \Porta\Overlay\ModalBox::closeHit}
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
     * put at {@see \Porta\Overlay\ModalBox::CLOSE_OFFSET} from the right edge.
     */
    public static function titleBar(string $title, int $width, Styles $styles, bool $closeHovered = false, string $hint = 'Esc'): string
    {
        $accent = $styles->of(Role::TabAccent);
        $close = $closeHovered ? TabCloseGlyph::active($styles) : TabCloseGlyph::inactive($styles);

        // '┌ ' + label + ' ' + fill + [' ' + hint] + ' ' + × + '┐': the fixed
        // chrome is 6 columns, plus the hint and its separator when shown.
        $hintWidth = mb_strwidth($hint);
        $room = $width - 6 - ($hintWidth > 0 ? $hintWidth + 1 : 0);
        if ($room < 8 && $hintWidth > 0) {
            $hint = '';
            $hintWidth = 0;
            $room = $width - 6;
        }
        $label = AnsiText::truncate($title, max(0, $room));
        $fill = str_repeat(Glyphs::FRAME_HORIZONTAL, max(0, $room - AnsiUtils::visibleWidth($label)));

        $bar =
            $accent->apply(Glyphs::FRAME_TOP_LEFT)
            .' '
            .$styles->of(Role::Bright)->withBold()->apply($label)
            .' '
            .$accent->apply($fill)
            .($hintWidth > 0 ? ' '.$styles->of(Role::Faint)->apply($hint) : '')
            .' '
            .$close
            .$accent->apply(Glyphs::FRAME_TOP_RIGHT);

        // By construction the bar is exactly $width columns; the truncate is
        // the degenerate-width backstop (a box too narrow for its chrome) that
        // keeps the compositor invariant unconditional.
        return AnsiText::truncate($bar, max(0, $width), pad: true);
    }

    /**
     * The side rails and bottom border around already-rendered content rows.
     * Each content row is padded/clipped to $innerWidth once, here — the
     * single place the invariant is enforced. Total row width: $innerWidth+2.
     *
     * @param list<string> $content
     *
     * @return list<string>
     */
    public static function wrap(array $content, int $innerWidth, Styles $styles): array
    {
        $accent = $styles->of(Role::TabAccent);
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);

        $rows = [];
        foreach ($content as $line) {
            $rows[] = $rail.AnsiText::truncate($line, max(0, $innerWidth), pad: true).$rail;
        }
        $rows[] = $accent->apply(Glyphs::FRAME_BOTTOM_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, max(0, $innerWidth)).Glyphs::FRAME_BOTTOM_RIGHT);

        return $rows;
    }
}
