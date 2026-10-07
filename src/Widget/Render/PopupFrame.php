<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * {@see ModalFrame}'s anchored-popup sibling: the same rounded corners, accent
 * border and tee rows, but tight — one border cell per side, no padding, no
 * title bar, no Esc/✖ affordance, no resize grab. Menu dropdowns, the context
 * menu and the caret-anchored editor popups wear this frame, because every
 * row/column of footprint fights the anchor they hang from.
 *
 * The frame wears its panel's shade under its accent — the rows'
 * {@see Role::PopupRow}, or for a menu the {@see Role::HeaderBar} of the bar
 * it drops from — so border, tees and padding read as one solid panel rather
 * than a dark outline around lighter strips.
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
    public static function top(int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle, string $mark = '', Role $panel = Role::PopupRow): string
    {
        [$left, $right, , , $rule] = $state->glyphs();

        return self::frame($styles, $panel)->apply($left.self::rule($rule, $innerWidth, $mark).$right);
    }

    /**
     * The top border left open under the title the box hangs from, so the
     * title's rails run on into the frame — `┃    ┗━━┓`, or `┏━┛    ┗━┓`
     * where the box was pushed left of its title. $offset is the title's
     * first column within the box and $width its full width, rails included.
     * A title the box cannot hold gets the plain {@see top()}.
     */
    public static function topOpenAt(int $innerWidth, Styles $styles, FieldState $state, int $offset, int $width, string $mark = '', Role $panel = Role::PopupRow): string
    {
        $boxWidth = $innerWidth + self::CHROME_COLS;
        if ($offset < 0 || $width < 2 || ($offset + $width) > $boxWidth) {
            return self::top($innerWidth, $styles, $state, $mark, $panel);
        }
        [$left, $right, $bottomLeft, $bottomRight, $rule, $vertical] = $state->glyphs();
        $last = $offset + $width - 1;
        $accent = self::frame($styles, $panel);
        // A $mark — the ▲ of a scrolled list — is centred in the rule past
        // the title, or before it when that run is too short to hold one.
        $runAfter = \max(0, $boxWidth - $last - 2);
        $before = 0 === $offset ? $vertical : $left.self::rule($rule, $offset - 1, $runAfter < 3 ? $mark : '').$bottomRight;
        $after = ($boxWidth - 1) === $last ? $vertical : $bottomLeft.self::rule($rule, $runAfter, $mark).$right;

        return $accent->apply($before).$styles->of($panel)->apply(\str_repeat(' ', $width - 2)).$accent->apply($after);
    }

    /**
     * The bottom border: `╰──╯`, or the given field state's. A $mark — the
     * ▼ of a clipped list — sits centred in the rule, taking none of the
     * rows the list has.
     */
    public static function bottom(int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle, string $mark = '', Role $panel = Role::PopupRow): string
    {
        [, , $left, $right, $rule] = $state->glyphs();

        return self::frame($styles, $panel)->apply($left.self::rule($rule, $innerWidth, $mark).$right);
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
    public static function tee(int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle, Role $panel = Role::PopupRow): string
    {
        [$left, $right] = $state->tees();

        return self::frame($styles, $panel)->apply($left.\str_repeat($state->glyphs()[4], \max(0, $innerWidth)).$right);
    }

    /**
     * `│` + row + `│`, the row padded/clipped to exactly $innerWidth — the
     * single place the invariant is enforced for popup content.
     */
    public static function rail(string $row, int $innerWidth, Styles $styles, FieldState $state = FieldState::Idle, Role $panel = Role::PopupRow): string
    {
        $edge = self::frame($styles, $panel)->apply($state->glyphs()[5]);

        // The panel's shade alone, under the row: its own colours stay its.
        $shade = new Style(background: $styles->of($panel)->getBackground());

        return $edge.$shade->apply(AnsiText::truncate($row, \max(0, $innerWidth), pad: true)).$edge;
    }

    /** The accent border on the panel's shade — the frame belongs to the panel it rings. */
    private static function frame(Styles $styles, Role $panel): Style
    {
        return $styles->merge($panel, Role::TabAccent);
    }

    /**
     * Frame a menu's segment rows: top border, one railed row per item — a
     * separator row becomes a tee row — bottom border. Framed row 1 + i always
     * maps back to item i (separators keep their slot), so hit-testing stays a
     * fixed offset. An $opening leaves the top border open under the title the
     * menu drops from ({@see topOpenAt()}). $above and $below are the marks
     * of a list shown in part — ▲ and ▼ where items run on past the box.
     * $panel is the shade the frame wears: a menu passes its rows'.
     *
     * @param list<array{segment: string, separator: bool}> $rows
     * @param ?array{offset: int, width: int}               $opening
     *
     * @return list<string> each exactly $innerWidth + CHROME_COLS columns
     */
    public static function wrapMenu(
        array $rows,
        int $innerWidth,
        Styles $styles,
        FieldState $state = FieldState::Idle,
        ?array $opening = null,
        string $above = '',
        string $below = '',
        Role $panel = Role::PopupRow,
    ): array {
        $out = [null === $opening ? self::top($innerWidth, $styles, $state, $above, $panel) : self::topOpenAt($innerWidth, $styles, $state, $opening['offset'], $opening['width'], $above, $panel)];
        foreach ($rows as $row) {
            $out[] = $row['separator'] ? self::tee($innerWidth, $styles, $state, $panel) : self::rail($row['segment'], $innerWidth, $styles, $state, $panel);
        }
        $out[] = self::bottom($innerWidth, $styles, $state, $below, $panel);

        return $out;
    }
}
