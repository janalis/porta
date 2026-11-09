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
 * third of the pane (a comfortable label's width while half allows, and
 * while that leaves the hints room to wrap), widening toward half for as
 * long as no hint pays a line for it, and never past forty columns, so one
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
 * its ` · ` stays beside the label, and spills downward — or, with too
 * little room beside the control, starts on the row under it, in the
 * control's column. The item grows to hold either. Measured from the width each frame offers, so a window resized
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

    /**
     * The widest a label column grows, however wide the pane: past it a long
     * label wraps rather than parting every short one from its control — a
     * 140-column form gave `Enabled` a 58-column gap to cross.
     */
    private const int MAX_LABEL = 40;

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
        /** @var list<list<string>|null> each item's detail, wrapped beside its control or under it; null where there is too little room for either */
        public array $details,
        /** @var list<bool> whether each item's detail wraps under its control, there being too little room beside it */
        public array $under,
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
        $third = \min($longest, \max(self::MIN_LABEL, \min(\intdiv($width, num2: 3), self::MAX_LABEL), \min($word, \intdiv($width * 2, num2: 3))));
        $label = \min($longest, \max($third, \min(\intdiv($width, num2: 2), self::COMFORTABLE_LABEL)));
        // The comfortable width gives way to a third when that is what keeps
        // the hints beside their controls rather than under them, or cut: a
        // 62-column form cut every hint to `· e…` beside a 28-column label
        // column.
        $cost = self::cost($rows, $details, $width, $label, $density, $needed);
        $thirdCost = self::cost($rows, $details, $width, $third, $density, $needed);
        if ([$thirdCost[0], $thirdCost[1]] < [$cost[0], $cost[1]]) {
            [$label, $cost] = [$third, $thirdCost];
        }
        // And widens toward the longest label, up to half the pane and
        // {@see self::MAX_LABEL}, for as
        // long as no hint pays a line for it: `phpantom_lsp — the PHP
        // language server` wrapped in a third of a 101-column form while its
        // hint beside it took two lines either way.
        for ($wider = \min($longest, \intdiv($width, num2: 2), self::MAX_LABEL); $wider > $label; --$wider) {
            if (self::cost($rows, $details, $width, $wider, $density, $needed) !== $cost) {
                continue;
            }
            $label = $wider;
            break;
        }
        $layout = self::columns($label, $width, $density, $needed);

        $heights = [];
        $legends = [];
        $labels = [];
        $wrapped = [];
        $under = [];
        $previous = null;
        foreach ($rows as $index => $row) {
            $legend = null !== $row->fieldset && $row->fieldset !== $previous ? $row->fieldset : null;
            $previous = $row->fieldset;
            $legends[] = $legend;
            // A button whose caption fell back to its own label leaves the
            // label column blank rather than saying it twice.
            $text = SettingsRowKind::Action === $row->kind && '' === (string) $row->value ? '' : self::label($row->label, $direction);
            $lines = self::anchored(AnsiText::wrapProse($text, $label), $direction);
            $labels[] = $lines;
            $detail = $details[$index] ?? '';
            [$room, $below] = self::room($row, $width, $layout);
            $detailLines = match (true) {
                '' === $detail => [],
                $room < self::MIN_DETAIL => null,
                default => self::anchored(AnsiText::wrapProse($detail, $room), $direction),
            };
            $wrapped[] = $detailLines;
            $under[] = $below;
            $controlRows = $layout->boxed ? FieldBox::ROWS : 1;
            $heights[] =
                (null === $legend ? 0 : self::LEGEND_ROWS)
                + \max(
                    $controlRows,
                    $layout->top(\count($lines)) + \count($lines),
                    null === $detailLines ? 0 : ($below ? $controlRows : $layout->top(1)) + \count($detailLines),
                );
        }

        return new self($label, $layout->control, $layout->boxed, $heights, $legends, $labels, $wrapped, $under);
    }

    /** The columns a label column of $label leaves the controls, as a layout with no items yet. */
    private static function columns(int $label, int $width, Density $density, int $needed): self
    {
        $room = \max(0, $width - self::LABEL_CHROME - $label);

        return new self($label, \min($needed, FieldBox::MAX_WIDTH, $room), FieldBox::ROWS === FieldBox::rowsFor($density, $room), [], [], [], [], []);
    }

    /**
     * What a label column of $label costs the hints: how many it leaves too
     * little room to wrap in, how many it sends under their control, how many
     * lines they wrap to, and the controls' width — a wider label must not
     * narrow the boxes either.
     *
     * @param list<SettingsRow> $rows
     * @param list<string> $details
     *
     * @return array{int, int, int, int}
     */
    private static function cost(array $rows, array $details, int $width, int $label, Density $density, int $needed): array
    {
        $layout = self::columns($label, $width, $density, $needed);
        $cut = 0;
        $under = 0;
        $lines = 0;
        foreach ($rows as $index => $row) {
            $detail = $details[$index] ?? '';
            [$room, $below] = self::room($row, $width, $layout);
            if ('' === $detail) {
                continue;
            }
            if ($room < self::MIN_DETAIL) {
                ++$cut;
                continue;
            }
            $under += (int) $below;
            $lines += \count(AnsiText::wrapProse($detail, $room));
        }

        return [$cut, $under, $lines, $layout->control];
    }

    /**
     * The columns one row's detail wraps in, its ` · ` taken out, and whether
     * under its control: beside it while {@see self::MIN_DETAIL} columns are
     * left there, else on the rows under the control, from its column to the
     * pane's edge — a 62-column form cut every hint to `· e…`. Under
     * {@see self::MIN_DETAIL} there too, the room beside it, where it is cut.
     *
     * @return array{int, bool}
     */
    private static function room(SettingsRow $row, int $width, self $layout): array
    {
        $beside = self::beside($row, $width, $layout);
        $under = $width - self::LABEL_CHROME - $layout->label - self::DETAIL_LEAD;

        return match (true) {
            $beside >= self::MIN_DETAIL => [$beside, false],
            $under >= self::MIN_DETAIL => [$under, true],
            default => [$beside, false],
        };
    }

    /** The columns one row's detail has beside its control, its ` · ` taken out. */
    private static function beside(SettingsRow $row, int $width, self $layout): int
    {
        return $width - self::LABEL_CHROME - $layout->label - self::controlWidth($row, $layout->control, $layout->boxed) - self::DETAIL_LEAD;
    }

    /**
     * Each wrapped line of a mirrored form with a left-to-right mark on an
     * edge its Latin reaches: a line is its own run, so the punctuation a
     * break leaves at its edge took the form's direction and left for the
     * far side — `~/.porta/bin` drew as `porta/bin./~`, and a bracket split
     * across two lines mirrored on both. {@see self::label()} marks a whole
     * label's end; a wrap makes an edge of every line break.
     *
     * @param non-empty-list<string> $lines
     *
     * @return non-empty-list<string>
     */
    private static function anchored(array $lines, BaseDirection $direction): array
    {
        if (BaseDirection::Rtl !== $direction) {
            return $lines;
        }

        return \array_map(Bidi::pinLatinEdges(...), $lines);
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
