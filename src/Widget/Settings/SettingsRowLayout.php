<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Widget\Render\FieldBox;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The geometry one page of settings renders in: the label column, the control
 * column, and how many screen rows each item takes.
 *
 * The form used to concatenate a hard-coded two spaces after each label, so the
 * value column started wherever that row's label happened to end — a ragged
 * edge that made a page impossible to scan down. Worse, a `Bool` row put its
 * checkbox BEFORE the label while every other kind put its control after, so
 * the two halves of a mixed page disagreed about which column meant what.
 *
 * Measured per page rather than per row: the label column is capped at half
 * the pane so one pathological label cannot push every value off the right
 * edge, and every boxed control on the page shares ONE width, the way a form's
 * inputs line up — sized for the widest control, capped at a browser input's
 * size rather than the pane's.
 *
 * Items are {@see FieldBox::ROWS} tall when boxed, a checkbox too, and the
 * first item of each fieldset carries its legend row too: scrolling to that
 * item scrolls its legend into view with it. When the pane cannot seat a
 * box beside the labels, the whole page falls back to one-row controls.
 */
final readonly class SettingsRowLayout
{
    /** A label column narrower than this is not worth aligning to. */
    private const int MIN_LABEL = 8;

    /** A text field's width, value or not: a field, not a label that grows. */
    private const int TEXT_FIELD = 24;

    /** The modified marker's two columns before the label, and the gap's two after it. */
    public const int LABEL_CHROME = 4;

    private function __construct(
        /** Columns the label occupies, gap excluded. */
        public int $label,
        /** Columns every control on the page is drawn in. */
        public int $control,
        /** Whether controls are three-row boxes, or the narrow pane's one-row form. */
        public bool $boxed,
        /** @var list<int> screen rows per item, legend included */
        public array $heights,
        /** @var list<string|null> the legend drawn above each item, if it opens a fieldset */
        public array $legends,
    ) {}

    /**
     * A label as a row draws it, measured here so the column fits it whole.
     *
     * In a mirrored form a label that ENDS in punctuation — `חיפוש בקבצי
     * vendor/` — gets a left-to-right mark after it: the slash sat between the
     * Latin it ends and the gap's right-to-left mark, took the paragraph
     * direction and left for the far side, and the row read `/vendor`. A
     * closing bracket no longer needs it ({@see \Porta\I18n\Bidi} pairs
     * brackets), but the mark does it no harm. A label ending in a letter
     * needs no mark, and gets none.
     */
    public static function label(string $label, BaseDirection $direction): string
    {
        return BaseDirection::Rtl === $direction && 1 === \preg_match('/[^\p{L}\p{N}]$/u', $label) ? $label.Bidi::LTR_MARK : $label;
    }

    /**
     * @param list<SettingsRow> $rows
     */
    public static function measure(array $rows, int $width, Density $density, BaseDirection $direction = BaseDirection::Ltr): self
    {
        $longest = 0;
        $needed = FieldBox::MIN_BOXED;
        foreach ($rows as $row) {
            $longest = \max($longest, AnsiUtils::visibleWidth(self::label($row->label, $direction)));
            $needed = \max($needed, self::needs($row));
        }
        // @mago-expect lint:literal-named-argument -- intdiv's parameter is called `num2`; `intdiv($width, num2: 2)` names the divisor after nothing anyone thinks in
        $label = \min($longest, \max(self::MIN_LABEL, \intdiv($width, 2)));
        $room = \max(0, $width - self::LABEL_CHROME - $label);
        $boxed = FieldBox::ROWS === FieldBox::rowsFor($density, $room);

        $heights = [];
        $legends = [];
        $previous = null;
        foreach ($rows as $row) {
            $legend = null !== $row->fieldset && $row->fieldset !== $previous ? $row->fieldset : null;
            $previous = $row->fieldset;
            $legends[] = $legend;
            $heights[] = (null === $legend ? 0 : 1) + ($boxed ? FieldBox::ROWS : 1);
        }

        return new self($label, \min($needed, FieldBox::MAX_WIDTH, $room), $boxed, $heights, $legends);
    }

    /** The width one row's control asks for; a checkbox asks for none. */
    private static function needs(SettingsRow $row): int
    {
        return match ($row->kind) {
            SettingsRowKind::Bool => 0,
            SettingsRowKind::Text => self::TEXT_FIELD,
            SettingsRowKind::Choice => FieldBox::selectWidth(\array_map(static fn (string $option): string => $row->optionLabels[$option] ?? $option, $row->options ?? [(string) $row->value])),
            // As many digits as the value or its bound, whichever is longer,
            // so stepping never resizes the box.
            SettingsRowKind::Int => FieldBox::controlWidth(Glyphs::STEPPER_DOWN.' '.\str_repeat('0', \max(\strlen((string) $row->value), \strlen((string) ($row->max ?? 0)))).' '.Glyphs::STEPPER_UP),
            SettingsRowKind::Action => FieldBox::controlWidth('' === (string) $row->value ? $row->label : (string) $row->value),
        };
    }
}
