<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Support\AnsiText;
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
 * Measured per page rather than per row: the label column is capped at a
 * third of the pane (a comfortable label's width while half allows) so one
 * pathological label cannot push every value off the right edge, and every
 * boxed control on the page shares ONE width, the way a form's
 * inputs line up — sized for the widest control, capped at a browser input's
 * size rather than the pane's.
 *
 * A label longer than its column wraps inside it, and so does the detail in
 * what the control leaves: the label used to be padded, never cut, so a long
 * one pushed its control off the column every other row keeps, and the detail
 * was cut to one line whose rest only a tooltip could say. A wrapped label is
 * centred on the control's value row; a wrapped detail starts ON that row, so
 * its ` · ` stays beside the label, and spills downward. The item grows to
 * hold either. Measured from the width each frame offers, so a window resized
 * by its edge or its splitter re-wraps on the next frame.
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

    /**
     * The column a label keeps whole before a third of the pane would wrap
     * it, while half the pane allows: `Relative line numbers` is not a label
     * that should wrap in a sixty-column form.
     */
    private const int COMFORTABLE_LABEL = 28;

    /** A text field's width, value or not: a field, not a label that grows. */
    private const int TEXT_FIELD = 24;

    /**
     * The narrowest a detail wraps in. Below it a wrapped hint is a tower of
     * one word a row, so the detail keeps one cut row and the tooltip says it.
     */
    public const int MIN_DETAIL = 12;

    /** What opens the detail beside its control, ` · `, and indents its next rows. */
    public const int DETAIL_LEAD = 3;

    /**
     * A section heading's rows: a blank row, the legend rule, a blank row —
     * the heading stands apart from the fields above and below it.
     */
    public const int LEGEND_ROWS = 3;

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
        /** @var list<non-empty-list<string>> each item's label, wrapped to the label column */
        public array $labels,
        /** @var list<list<string>|null> each item's detail, wrapped beside its control; null where there is too little room to */
        public array $details,
    ) {}

    /**
     * The item row $lines of wrapped text start on: centred on the control's
     * value row, so one line sits exactly where an unwrapped label did, and
     * never above the item's first row — what does not fit spills downward.
     */
    public function top(int $lines): int
    {
        return \max(0, \intdiv($this->boxed ? FieldBox::ROWS : 1, num2: 2) - \intdiv($lines - 1, num2: 2));
    }

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
     * @param list<string> $details each row's detail ({@see SettingsRowDetail::of()}), '' or absent for none
     */
    public static function measure(array $rows, int $width, Density $density, BaseDirection $direction = BaseDirection::Ltr, array $details = []): self
    {
        $longest = 0;
        $word = 0;
        $needed = FieldBox::MIN_BOXED;
        foreach ($rows as $row) {
            $longest = \max($longest, AnsiUtils::visibleWidth(self::label($row->label, $direction)));
            $word = \max($word, ...\array_map(AnsiUtils::visibleWidth(...), \explode(' ', $row->label)));
            $needed = \max($needed, self::needs($row));
        }
        // A third of the pane, or a comfortable label's width while half the
        // pane allows: a longer label wraps, and the rest is the control's and
        // its detail's — half left a hint fourteen columns to wrap in beside
        // one long label's column. A narrow pane widens the column to the
        // longest word, short of two thirds of the pane, so wrapping breaks
        // between words: `Auto-pairs` in nine columns was `Auto-pair` over a
        // lone `s`.
        $share = \min(\intdiv($width, num2: 2), \max(\intdiv($width, num2: 3), self::COMFORTABLE_LABEL));
        $label = \min($longest, \max(self::MIN_LABEL, $share, \min($word, \intdiv($width * 2, num2: 3))));
        $room = \max(0, $width - self::LABEL_CHROME - $label);
        $boxed = FieldBox::ROWS === FieldBox::rowsFor($density, $room);

        $control = \min($needed, FieldBox::MAX_WIDTH, $room);
        $layout = new self($label, $control, $boxed, [], [], [], []);

        $heights = [];
        $legends = [];
        $labels = [];
        $wrapped = [];
        $previous = null;
        foreach ($rows as $index => $row) {
            $legend = null !== $row->fieldset && $row->fieldset !== $previous ? $row->fieldset : null;
            $previous = $row->fieldset;
            $legends[] = $legend;
            // A button whose caption fell back to its own label leaves the
            // label column blank rather than saying it twice.
            $text = SettingsRowKind::Action === $row->kind && '' === (string) $row->value ? '' : self::label($row->label, $direction);
            $lines = self::wrap($text, $label);
            $labels[] = $lines;
            $detail = $details[$index] ?? '';
            $beside = $width - self::LABEL_CHROME - $label - self::controlWidth($row, $control, $boxed) - self::DETAIL_LEAD;
            $detailLines = match (true) {
                '' === $detail => [],
                $beside < self::MIN_DETAIL => null,
                default => self::wrap($detail, $beside),
            };
            $wrapped[] = $detailLines;
            $heights[] = (null === $legend ? 0 : self::LEGEND_ROWS)
            + \max(
                $boxed ? FieldBox::ROWS : 1,
                $layout->top(\count($lines)) + \count($lines),
                null === $detailLines ? 0 : $layout->top(1) + \count($detailLines),
            );
        }

        return new self($label, $control, $boxed, $heights, $legends, $labels, $wrapped);
    }

    /**
     * {@see AnsiText::wrap()}, but a word wider than the line breaks after its
     * last `-` or `/` that fits rather than at whatever letter the width
     * lands on: `comma-separated;` in fourteen columns was `comma-separat`
     * over `ed;`. Only a word with no such break left is cut mid-letter.
     *
     * The break is a space put after the hyphen or slash, which never shows:
     * the two halves were wider than the line together, so wrap never puts
     * them back on one.
     *
     * @return non-empty-list<string>
     */
    private static function wrap(string $text, int $width): array
    {
        $words = [];
        foreach (\explode(' ', $text) as $word) {
            while (AnsiUtils::visibleWidth($word) > $width) {
                $head = [];
                if (1 !== \preg_match('/^(.+[-\/])/us', AnsiUtils::sliceByColumn($word, startCol: 0, length: $width), $head)) {
                    break; // nowhere to break: wrap cuts it
                }
                $words[] = $head[1];
                $word = \substr($word, \strlen($head[1]));
            }
            $words[] = $word;
        }

        return AnsiText::wrap(\implode(' ', $words), $width);
    }

    /** The columns one row's control is drawn in: a checkbox its own, every other kind the page's. */
    private static function controlWidth(SettingsRow $row, int $control, bool $boxed): int
    {
        return match (true) {
            SettingsRowKind::Bool !== $row->kind => $control,
            $boxed => FieldBox::CHECKBOX_BOX_WIDTH,
            default => FieldBox::CHECKBOX_WIDTH,
        };
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
