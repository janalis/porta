<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
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
        $rail = self::border($state, $styles)->apply($state->glyphs()[5]);
        $label = '' === $spec->label ? '' : $spec->label.' ';
        $room = $width - AnsiUtils::visibleWidth($label) - self::CHROME_COLS;
        if ($room < 1) {
            $label = ''; // the value outranks its label when only one fits
            $room = $width - self::CHROME_COLS;
        }
        $legend = '' === $label ? '' : self::legend($state, $styles)->apply($label);
        // The hint follows the closing rail while the value keeps a usable
        // width; past that it goes whole, never cut.
        $hint = '';
        $hintWidth = AnsiUtils::visibleWidth($spec->trailing);
        if ('' !== $spec->trailing && ($room - $hintWidth - 1) >= (self::MIN_BOXED - self::CHROME_COLS)) {
            $hint = ' '.$styles->of(Role::Faint)->apply($spec->trailing);
            $room -= $hintWidth + 1;
        }

        return self::fit($spec, $legend.$rail.' '.self::value($spec, $field, $state, $caret, $room, $styles).' '.$rail.$hint, $width, $styles);
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
