<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\OptionTagState;
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

    public const int CHECKBOX_WIDTH = 3;

    /** The boxed checkbox's width: its rails with a column of padding either side of the mark, as every box pads its content. */
    public const int CHECKBOX_BOX_WIDTH = 5;

    /** Rail + pad on each side of the value. */
    public const int CHROME_COLS = 4;

    /** Below this width a box is all border; a site renders {@see compact()} instead. */
    public const int MIN_BOXED = 12;

    /**
     * The widest a control grows, frame included: a browser input's fixed
     * size, never the pane's — the find bar's fields and the settings form's
     * controls alike.
     */
    public const int MAX_WIDTH = 32;

    /**
     * The one rule for a control's height: boxed where the screen is roomy and
     * the box has room for more than its border, one row otherwise. Every host
     * asks this instead of keeping a threshold of its own — sites that each
     * picked one disagreed about the same terminal.
     *
     * @return positive-int {@see ROWS} or 1
     */
    public static function rowsFor(Density $density, int $width = \PHP_INT_MAX): int
    {
        return Density::Roomy === $density && $width >= self::MIN_BOXED ? self::ROWS : 1;
    }

    /**
     * A text field at the height {@see rowsFor()} gave: boxed, or the
     * one-row {@see compact()}.
     *
     * @return list<string>
     */
    public static function field(FieldSpec $spec, TextField $field, FieldState $state, CaretVisibility $caret, int $width, int $rows, Styles $styles): array
    {
        return self::ROWS === $rows ? self::render($spec, $field, $state, $caret, $width, $styles) : [self::compact($spec, $field, $state, $caret, $width, $styles)];
    }

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

    /**
     * A checkbox, {@see CHECKBOX_WIDTH} columns in every state so nothing
     * beside it moves: `[✓]` / `[ ]`, and `[–]` for null, the partial state.
     * Its rails are the state ({@see FieldState::checkboxRails()}). Bare
     * glyphs: the row it sits in shades it.
     *
     * With $tint the mark takes the accent, a browser's filled checked box —
     * colour repeating the shape, gone under the monochrome theme. Callers
     * pass it only off the selected row: accent on the selection's shade is
     * the one pairing too faint to read.
     */
    public static function checkbox(?bool $checked, FieldState $state = FieldState::Idle, ?Styles $tint = null): string
    {
        [$left, $right] = $state->checkboxRails();

        return $left.self::mark($checked, $tint).$right;
    }

    /**
     * A checkbox as a box of its own, {@see ROWS} tall and {@see
     * CHECKBOX_BOX_WIDTH} wide — the same family as the boxed field, select
     * and button beside it, its mark padded a column either side the way
     * their content is: `╭───╮ │ ✓ │ ╰───╯` at rest, heavy while it takes the
     * keys, dashed disabled. The frame takes the state's border tint like every
     * box; the mark takes the accent only when $tintMark (off the selected
     * row). The one-row {@see checkbox()} is its fallback.
     *
     * @return list<string>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the row is selected decides the mark's tint, the one thing the caller knows.
    public static function checkboxBox(?bool $checked, FieldState $state, Styles $styles, bool $tintMark = true): array
    {
        [$topLeft, $topRight, $bottomLeft, $bottomRight, $horizontal, $vertical] = $state->glyphs();
        $border = self::border($state, $styles);

        $rule = \str_repeat($horizontal, self::CHECKBOX_BOX_WIDTH - 2);

        return [
            $border->apply($topLeft.$rule.$topRight),
            $border->apply($vertical).' '.self::mark($checked, $tintMark ? $styles : null).' '.$border->apply($vertical),
            $border->apply($bottomLeft.$rule.$bottomRight),
        ];
    }

    /**
     * An option toggle — `.*`, `Aa`, a diff's `S split` — at the height the
     * host drew its fields: {@see ROWS} as a box of its own, `╭────╮ │ .* │
     * ╰────╯`, or the one-row tag, `[.*]` on and ` .* ` off.
     *
     * The state is the caption's: on is the selection's shade (reverse under
     * monochrome), off is faint. A boxed toggle's frame is always the idle
     * one — a toggle never takes the keys, its shortcut flips it — and the
     * pointer underlines the caption, as it does a button's. A tag has no
     * border to say "on" with, so its brackets do.
     *
     * @return list<string> each exactly {@see toggleWidth()} columns
     */
    public static function toggle(string $caption, OptionTagState $state, int $rows, Styles $styles): array
    {
        $boxed = self::ROWS === $rows;
        $on = OptionTagState::On === $state || OptionTagState::OnHovered === $state;
        $style = match ($state) {
            OptionTagState::On => $styles->of(Role::SelectedRow),
            // Lit already, so the pointer underlines rather than re-tints —
            // the one mark that reads over the selection, in colour or not.
            OptionTagState::OnHovered => $styles->of(Role::SelectedRow)->withUnderline(),
            OptionTagState::Hovered => $boxed ? $styles->of(Role::Bright)->withUnderline() : $styles->of(Role::RowHighlightBg),
            OptionTagState::Off => $styles->of(Role::Faint),
        };
        if (!$boxed) {
            return [$style->apply($on ? '['.$caption.']' : ' '.$caption.' ')];
        }

        return self::control(new FieldSpec(''), static fn (int $room): string => self::centred($caption, $room, $style), FieldState::Idle, self::toggleWidth($caption, $rows), $rows, $styles);
    }

    /** A toggle's width: the caption and its brackets as a tag, its rails and padding as a box. */
    public static function toggleWidth(string $caption, int $rows): int
    {
        return AnsiUtils::visibleWidth($caption) + (self::ROWS === $rows ? self::CHROME_COLS : 2);
    }

    /** ✓, – or the blank; the first two in the accent with $tint. */
    private static function mark(?bool $checked, ?Styles $tint): string
    {
        $mark = match ($checked) {
            true => Glyphs::CHECKBOX_MARK_ON,
            null => Glyphs::CHECKBOX_MARK_PARTIAL,
            false => Glyphs::CHECKBOX_MARK_OFF,
        };

        return false !== $checked && null !== $tint ? $tint->of(Role::TabAccent)->apply($mark) : $mark;
    }

    /** $text centred in exactly $room columns (cut when it does not fit), styled when a style is given. */
    public static function centred(string $text, int $room, ?Style $style = null): string
    {
        $text = AnsiText::truncate($text, \max(0, $room));
        $lead = AnsiText::blank(\intdiv(\max(0, $room - AnsiUtils::visibleWidth($text)), num2: 2));

        return AnsiText::padRight($lead.($style?->apply($text) ?? $text), $room);
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

        $bottom = BorderHint::bottom($bottomLeft, $border->apply($bottomRight), $horizontal, $width - 2, $border, $spec->trailing, $styles);

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

        return match (true) {
            // After the caret, like a browser's: still there while focused,
            // gone at the first keystroke.
            '' === $field->value() && '' !== $spec->placeholder => AnsiText::padRight(
                $field->render($caret).$styles->of(Role::FieldPlaceholder)->apply(AnsiText::truncate($spec->placeholder, $width)),
                $width,
            ),
            $shown => AnsiText::padRight($field->render($caret, $width), $width),
            // Taking keys with the caret drawn elsewhere (the list filter):
            // keep the end in view, where the typing lands.
            $state->takesKeys() => self::endInView($field->render($caret), $width),
            default => AnsiText::truncate($field->render($caret), $width, pad: true),
        };
    }

    /** $value exactly $width columns, cut from the left so its end stays in view. */
    private static function endInView(string $value, int $width): string
    {
        return AnsiText::padRight(AnsiText::caretWindow($value, AnsiUtils::visibleWidth($value), $width, marker: ''), $width);
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
