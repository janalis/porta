<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * The one place a text field is drawn, boxed like a browser input:
 *
 *   ╭ Find ──────────────╮
 *   │ search term…       │
 *   ╰──── (Tab · Alt+A) ─╯
 *
 * The label is a legend in the top border — always on screen, so the
 * placeholder never has to stand in for it, and the field stays three rows.
 * The border's shape is the field's {@see FieldState}; colour only repeats it.
 * {@see compact()} is the one-row form for a site with no rows to spare
 * (`Find ┃ foo ┃`), whose rails still change shape with focus.
 *
 * Every returned row is exactly the requested width in visible columns — the
 * compositor invariant — and an idle field refuses the caret: a frame holds
 * one marker, and it belongs to the field taking keys.
 */
final class FieldBox
{
    public const int ROWS = 3;

    /** Rail + pad on each side of the value. */
    public const int CHROME_COLS = 4;

    /** Below this width a box is all border; a site renders {@see compact()} instead. */
    public const int MIN_BOXED = 12;

    /** @return list<string> exactly {@see ROWS} rows */
    public static function render(FieldSpec $spec, TextField $field, FieldState $state, CaretVisibility $caret, int $width, Styles $styles): array
    {
        return self::frame($spec, [self::value($spec, $field, $state, $caret, $width - self::CHROME_COLS, $styles)], $state, $width, $styles);
    }

    public static function compact(FieldSpec $spec, TextField $field, FieldState $state, CaretVisibility $caret, int $width, Styles $styles): string
    {
        return self::inline($spec, static fn (int $room): string => self::value($spec, $field, $state, $caret, $room, $styles), $state, $width, $styles);
    }

    /**
     * Any control in either form: {@see ROWS} rows boxed, or the one-row
     * `Label │ … │` when $rows is 1. $content draws the inside at the width it
     * is handed, so a button can centre its caption and a select can seat its
     * ▾ at the edge whichever form the site can afford.
     *
     * @param \Closure(int): string $content
     *
     * @return list<string>
     */
    public static function control(FieldSpec $spec, \Closure $content, FieldState $state, int $width, int $rows, Styles $styles): array
    {
        return (
            self::ROWS === $rows
                ? self::frame($spec, [$content(\max(0, $width - self::CHROME_COLS))], $state, $width, $styles)
                : [self::inline($spec, $content, $state, $width, $styles)]
        );
    }

    /**
     * A push button: its caption centred in the box, the way a browser draws
     * one. The pointer underlines the caption and never touches the border —
     * the border is the button's state, and hovering is not a state change.
     * Disabled greys the caption as well as dashing the border.
     *
     * @return list<string>
     */
    public static function button(string $caption, FieldState $state, bool $hovered, int $width, int $rows, Styles $styles, string $trailing = ''): array
    {
        $style = match (true) {
            FieldState::Disabled === $state => $styles->of(Role::Faint),
            $hovered => $styles->of(Role::Bright)->withUnderline(),
            default => $styles->of(Role::Bright),
        };

        return self::control(new FieldSpec('', trailing: $trailing), static fn (int $room): string => self::centred($caption, $room, $style), $state, $width, $rows, $styles);
    }

    /** $text centred in exactly $room columns (cut when it does not fit), styled when a style is given. */
    public static function centred(string $text, int $room, ?Style $style = null): string
    {
        $text = AnsiText::truncate($text, \max(0, $room));
        $lead = AnsiText::blank(\intdiv(\max(0, $room - AnsiUtils::visibleWidth($text)), num2: 2));

        return AnsiText::padRight($lead.(null === $style ? $text : $style->apply($text)), $room);
    }

    /**
     * A closed select: the chosen option, then {@see Glyphs::SELECT_ARROW}
     * against the right rail — the browser's `<select>`. Opening the list is
     * the host's business; this is only the face it shows.
     *
     * @return list<string>
     */
    public static function select(FieldSpec $spec, string $value, FieldState $state, int $width, int $rows, Styles $styles): array
    {
        return self::control($spec, static fn (int $room): string => AnsiText::truncate($value, \max(0, $room - 2), pad: true).' '.Glyphs::SELECT_ARROW, $state, $width, $rows, $styles);
    }

    /** The width a control needs to show $content whole, with one column of air each side. */
    public static function controlWidth(string $content): int
    {
        return AnsiUtils::visibleWidth($content) + 2 + self::CHROME_COLS;
    }

    /**
     * A select's width: its widest option plus the ▾ — measured over every
     * option, so the box never resizes as the value steps.
     *
     * @param list<string> $options
     */
    public static function selectWidth(array $options): int
    {
        return \max(0, ...\array_map(AnsiUtils::visibleWidth(...), $options)) + 2 + self::CHROME_COLS;
    }

    /**
     * The one-row form: `Label │ content │ hint`. The label yields before the
     * content does; the hint rides after the closing rail only while the
     * content keeps a usable width, and goes whole, never cut.
     *
     * @param \Closure(int): string $content
     */
    private static function inline(FieldSpec $spec, \Closure $content, FieldState $state, int $width, Styles $styles): string
    {
        $rail = self::border($state, $styles)->apply($state->glyphs()[5]);
        $label = '' === $spec->label ? '' : $spec->label.' ';
        $room = $width - AnsiUtils::visibleWidth($label) - self::CHROME_COLS;
        if ($room < 1) {
            $label = ''; // the value outranks its label when only one fits
            $room = $width - self::CHROME_COLS;
        }
        $legend = '' === $label ? '' : self::legend($state, $styles)->apply($label);
        $hint = '';
        $hintWidth = AnsiUtils::visibleWidth($spec->trailing);
        if ('' !== $spec->trailing && ($room - $hintWidth - 1) >= (self::MIN_BOXED - self::CHROME_COLS)) {
            $hint = ' '.$styles->of(Role::Faint)->apply($spec->trailing);
            $room -= $hintWidth + 1;
        }

        return self::fit($spec, $legend.$rail.' '.AnsiText::padRight($content(\max(0, $room)), \max(0, $room)).' '.$rail.$hint, $width, $styles);
    }

    /**
     * The border around already-rendered $content rows, each at most
     * `$width - CHROME_COLS` columns — the multi-row form (the commit
     * message's description), and what {@see render()} is built on.
     *
     * @param list<string> $content
     *
     * @return list<string>
     */
    public static function frame(FieldSpec $spec, array $content, FieldState $state, int $width, Styles $styles): array
    {
        [$topLeft, $topRight, $bottomLeft, $bottomRight, $horizontal, $vertical] = $state->glyphs();
        $border = self::border($state, $styles);
        $inner = \max(0, $width - self::CHROME_COLS);
        $rule = static fn (int $n): string => $border->apply(\str_repeat($horizontal, \max(0, $n)));

        // corner, ' ', legend, ' ', rule (at least one), corner
        $label = AnsiText::truncate($spec->label, \max(0, $inner - 1));
        $top =
            '' === $spec->label || $inner < 2
                ? $border->apply($topLeft).$rule($width - 2).$border->apply($topRight)
                : $border->apply($topLeft.' ').self::legend($state, $styles)->apply($label).$border->apply(' ').$rule($width - 4 - AnsiUtils::visibleWidth($label)).$border->apply($topRight);

        // corner, rule, ' ', hint, ' ', one rule, corner — or no hint at all:
        // a cut hint names half a key.
        $hintWidth = AnsiUtils::visibleWidth($spec->trailing);
        $bottom =
            '' === $spec->trailing || $hintWidth > ($width - 6)
                ? $border->apply($bottomLeft).$rule($width - 2).$border->apply($bottomRight)
                : $border->apply($bottomLeft).$rule($width - 5 - $hintWidth).' '.$styles->of(Role::Faint)->apply($spec->trailing).' '.$rule(1).$border->apply($bottomRight);

        $rows = [$top];
        foreach ($content as $row) {
            $rows[] = $border->apply($vertical).' '.AnsiText::padRight($row, $inner).' '.$border->apply($vertical);
        }
        $rows[] = $bottom;

        return \array_map(static fn (string $row): string => self::fit($spec, $row, $width, $styles), $rows);
    }

    /** The value (or the placeholder), exactly $width columns, the caret kept in view. */
    private static function value(FieldSpec $spec, TextField $field, FieldState $state, CaretVisibility $caret, int $width, Styles $styles): string
    {
        $shown = CaretVisibility::Shown === $caret;
        if ($shown && !$state->takesKeys()) {
            throw new \LogicException('An idle field cannot carry the caret: a frame holds one marker, and it belongs to the field taking keys.');
        }
        $width = \max(0, $width);
        if ('' === $field->value() && '' !== $spec->placeholder) {
            // After the caret, like a browser's: still there while focused,
            // gone at the first keystroke.
            return AnsiText::padRight($field->render($caret).$styles->of(Role::FieldPlaceholder)->apply(AnsiText::truncate($spec->placeholder, $width)), $width);
        }
        if ($shown) {
            return AnsiText::padRight($field->render($caret, $width), $width);
        }
        $value = $field->render($caret);
        if ($state->takesKeys()) {
            // Taking keys with the caret drawn elsewhere (the list filter):
            // keep the end in view, where the typing lands.
            return AnsiText::padRight(AnsiText::caretWindow($value, AnsiUtils::visibleWidth($value), $width, marker: ''), $width);
        }

        return AnsiText::truncate($value, $width, pad: true);
    }

    /**
     * The border's tint alone — no bold: the heavy and double glyphs already
     * carry the weight, and a bold rail's closing SGR 22 would switch off the
     * bold of a selected row the compact form sits in, for the rest of it.
     */
    private static function border(FieldState $state, Styles $styles): Style
    {
        return $styles->of($state->borderRole());
    }

    private static function legend(FieldState $state, Styles $styles): Style
    {
        return $state->takesKeys() ? $styles->of(Role::Bright)->withBold() : $styles->of(Role::Muted);
    }

    /**
     * The row at exactly $width, over the spec's surface. By construction a
     * row already is; the cut is the degenerate-width backstop, and it runs
     * only then so a caret marker never passes through a truncation.
     */
    private static function fit(FieldSpec $spec, string $row, int $width, Styles $styles): string
    {
        $width = \max(0, $width);
        $row = AnsiUtils::visibleWidth($row) > $width ? AnsiText::truncate($row, $width, ellipsis: '') : AnsiText::padRight($row, $width);

        return null === $spec->surface ? $row : $styles->of($spec->surface)->apply($row);
    }
}
