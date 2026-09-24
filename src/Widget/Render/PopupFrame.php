<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
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

    /**
     * The top border: `╭──╮`, exactly $innerWidth + CHROME_COLS columns — or
     * the given field state's corners, a select's open list being `┏━━┓`.
     */
    public static function top(int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle, string $mark = ''): string
    {
        [$left, $right, , , $rule] = $state->glyphs();

        return $styles->of(Role::TabAccent)->apply($left.self::rule($rule, $innerWidth, $mark).$right);
    }

    /**
     * The bottom border: `╰──╯`, or the given field state's. A $mark — the
     * ▼ of a clipped list — sits centred in the rule, taking none of the
     * rows the list has.
     */
    public static function bottom(int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle, string $mark = ''): string
    {
        [, , $left, $right, $rule] = $state->glyphs();

        return $styles->of(Role::TabAccent)->apply($left.self::rule($rule, $innerWidth, $mark).$right);
    }

    /** $innerWidth columns of $rule, with a one-column $mark centred in it when given. */
    private static function rule(string $rule, int $innerWidth, string $mark): string
    {
        if ('' === $mark || $innerWidth < 3) {
            return \str_repeat($rule, \max(0, $innerWidth));
        }
        $before = \max(0, \intdiv($innerWidth - 1, num2: 2));

        return \str_repeat($rule, $before).$mark.\str_repeat($rule, \max(0, $innerWidth - 1 - $before));
    }

    /**
     * A section separator: `├──┤` — the tee row {@see ModalFrame::wrapSections}
     * draws between zones — or `┣━━┫` in a heavy frame, so the separator
     * joins the frame it cuts across.
     */
    public static function tee(int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle): string
    {
        [$left, $right] = $state->tees();

        return $styles->of(Role::TabAccent)->apply($left.\str_repeat($state->glyphs()[4], \max(0, $innerWidth)).$right);
    }

    /**
     * `│` + row + `│`, the row padded/clipped to exactly $innerWidth — the
     * single place the invariant is enforced for popup content.
     */
    public static function rail(string $row, int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle): string
    {
        $edge = $styles->of(Role::TabAccent)->apply($state->glyphs()[5]);

        return $edge.AnsiText::truncate($row, \max(0, $innerWidth), pad: true).$edge;
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
    public static function wrapMenu(array $rows, int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle): array
    {
        $out = [self::top($innerWidth, $styles, $state)];
        foreach ($rows as $row) {
            $out[] = $row['separator'] ? self::tee($innerWidth, $styles, $state) : self::rail($row['segment'], $innerWidth, $styles, $state);
        }
        $out[] = self::bottom($innerWidth, $styles, $state);

        return $out;
    }
}
