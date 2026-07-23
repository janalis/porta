<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * {@see ModalFrame}'s anchored-popup sibling: the same rounded corners, accent
 * border and tee rows, but tight — one border cell per side, no padding, no
 * title bar, no Esc/× affordance, no resize grab. Menu dropdowns, the context
 * menu and the caret-anchored editor popups wear this frame, because every
 * row/column of footprint fights the anchor they hang from.
 *
 * Every returned row is exactly $innerWidth + CHROME_COLS visible columns —
 * the compositor invariant; a row that misses it blows out its host pane.
 */
final class PopupFrame
{
    /**
     * The chrome a popup frame adds around content of W columns × H rows: one
     * border cell each side (width = W + CHROME_COLS) and the top and bottom
     * border rows (rows = H + CHROME_ROWS). Consumers derive their anchor
     * clamps from these two numbers.
     */
    public const int CHROME_COLS = 2;
    public const int CHROME_ROWS = 2;

    /** The top border: `╭──╮`, exactly $innerWidth + CHROME_COLS columns. */
    public static function top(int $innerWidth, Styles $styles): string
    {
        return $styles->of(Role::TabAccent)->apply(Glyphs::MODAL_TOP_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, max(0, $innerWidth)).Glyphs::MODAL_TOP_RIGHT);
    }

    /** The bottom border: `╰──╯`. */
    public static function bottom(int $innerWidth, Styles $styles): string
    {
        return $styles->of(Role::TabAccent)->apply(Glyphs::MODAL_BOTTOM_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, max(0, $innerWidth)).Glyphs::MODAL_BOTTOM_RIGHT);
    }

    /** A section separator: `├──┤` — the tee row {@see ModalFrame::wrapSections} draws between zones. */
    public static function tee(int $innerWidth, Styles $styles): string
    {
        return $styles->of(Role::TabAccent)->apply(Glyphs::MODAL_TEE_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, max(0, $innerWidth)).Glyphs::MODAL_TEE_RIGHT);
    }

    /**
     * `│` + row + `│`, the row padded/clipped to exactly $innerWidth — the
     * single place the invariant is enforced for popup content.
     */
    public static function rail(string $row, int $innerWidth, Styles $styles): string
    {
        $edge = $styles->of(Role::TabAccent)->apply(Glyphs::FRAME_VERTICAL);

        return $edge.AnsiText::truncate($row, max(0, $innerWidth), pad: true).$edge;
    }

    /**
     * Frame a menu's segment rows: top border, one railed row per item — a
     * separator row becomes a tee row — bottom border. Framed row 1 + i always
     * maps back to item i (separators keep their slot), so hit-testing stays a
     * fixed offset.
     *
     * @param list<array{segment: string, separator: bool}> $rows
     *
     * @return list<string> each exactly $innerWidth + CHROME_COLS columns
     */
    public static function wrapMenu(array $rows, int $innerWidth, Styles $styles): array
    {
        $out = [self::top($innerWidth, $styles)];
        foreach ($rows as $row) {
            $out[] = $row['separator'] ? self::tee($innerWidth, $styles) : self::rail($row['segment'], $innerWidth, $styles);
        }
        $out[] = self::bottom($innerWidth, $styles);

        return $out;
    }
}
