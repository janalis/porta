<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\SearchModalState;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\ListFilter;
use Porta\Widget\Support\RowSelection;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * Lays the three centered picker surfaces out into framed, styled rows: the
 * list overlay, Search Everywhere and the mini prompt.
 *
 * Pure and static: each takes the surface's state plus the paintable bounds and
 * returns the rows, the placement and the {@see ModalBox} geometry every
 * hit-test reads — the box is *returned* rather than written back, which is what
 * lets this live in the stateless render tier while {@see
 * \Porta\Overlay\State\OverlayHost} keeps owning the state and storing the box.
 */
final class PickerLayout
{
    /**
     * What marks the speed-search row. A magnifier would read as "search",
     * which this is not — the list is already fetched and nothing is being
     * asked of a server; it is a sieve over rows already on screen.
     */
    private const string FILTER_GLYPH = '≡';

    /**
     * @param array{int, int, int, int}                                                                                                                             $bounds
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     *
     * @return array{rows: list<string>, top: int, col: int, width: int, box: ModalBox}
     */
    public static function layoutList(
        array $bounds,
        array $overlay,
        Styles $styles,
        bool $closeHovered,
        string $emptyMessage = '',
        string $placeholder = '',
        BaseDirection $direction = BaseDirection::Ltr,
    ): array {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        $filter = $overlay['filter']->value();
        $items = ListFilter::matching($overlay['items'], $filter);
        $selected = $overlay['selected'];
        // Rows, like the width below, are a property of the whole list: the box
        // keeps its height while the filter narrows it, padding what the
        // filter took, so it never re-centres under the reader.
        $boxRows = \max(1, \min(\count($overlay['items']), $rows - 5 - FieldBox::ROWS, 12));
        $maxItems = \min($boxRows, \max(1, \count($items)));
        $windowStart = IntMath::clamp($selected - $maxItems + 1, 0, \count($items) - $maxItems);
        $visible = \array_slice($items, $windowStart, $maxItems);

        $width = \mb_strwidth($overlay['title']) + 8; // the frame's title-bar chrome (corners, hint, ×) needs its room
        // Measured over the UNFILTERED list, and over all of it rather than the
        // visible window: a box sized from what survives the filter shrinks and
        // re-centres on every keystroke, walking out from under the eye reading
        // it. The width is a property of the list, not of this frame's slice.
        foreach ($overlay['items'] as $item) {
            $width = \max($width, self::boxWidth($item) + \mb_strwidth($item['label']) + \mb_strwidth($item['hint']) + 6);
        }
        // The filler row shown when nothing matches: measured ALWAYS, not only
        // when it is about to be drawn. Nothing else in this width measures it
        // — and it truncates with no ellipsis, so a cut is invisible rather
        // than merely ugly (German's "keine Treffer" drew as "keine Tref" in a
        // box sized from a three-letter title and two short rows).
        //
        // Unconditionally, because the width above is deliberately taken over
        // the UNFILTERED items so the box cannot resize under the reader's eye,
        // and counting this one only when the filter stops matching puts that
        // resize straight back: measured at 15 columns, then 27 on the
        // keystroke that emptied the list.
        $width = \max($width, \mb_strwidth($emptyMessage) + 2, \mb_strwidth($placeholder) + 2 + FieldBox::CHROME_COLS + 2);
        $width = \min($width, $columns - PopupMetrics::CENTERED_MARGIN, PopupMetrics::CENTERED_MAX_INNER);

        $content = [];
        foreach ($visible as $i => $item) {
            $row = RowSelection::of(($windowStart + $i) === $selected, ($windowStart + $i) === $overlay['hovered']);
            $style = $styles->of(ModalRowStyle::role($row));
            $hintWidth = \mb_strwidth($item['hint']);
            // A checklist's box leads its label, as a browser's does, and is
            // not part of it: the filter matches the words alone.
            $box = \array_key_exists('checked', $item) ? FieldBox::checkbox($item['checked'], tint: RowSelection::Selected === $row ? null : $styles).' ' : '';
            $selectedRow = RowSelection::Selected === $row;
            $label = MatchMarks::apply(AnsiText::truncate($item['label'], $width - $hintWidth - 3 - self::boxWidth($item), pad: true), $item['label'], $filter, $selectedRow);
            if (true === ($item['disabled'] ?? null)) {
                // Greyed like a menu's disabled item: dim and struck through.
                $label = $style->withDim()->withStrikethrough()->apply(\rtrim($label)).$style->getAnsiRestore().\str_repeat(' ', \max(0, \mb_strwidth($label) - \mb_strwidth(\rtrim($label))));
            }
            // The first column is the selection's shape, the ▌ every list in
            // the IDE draws, so the row reads as chosen without its shade.
            $content[] = $style->apply(SelectionBar::of($selectedRow, focused: true)->value.$box.$label.self::gap($direction).$styles->of(Role::CompletionHint)->apply($item['hint']).' ');
        }
        if ([] === $visible) {
            $content[] = $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$emptyMessage, $width, pad: true, ellipsis: ''));
        }
        while (\count($content) < $boxRows) {
            $content[] = $styles->of(Role::PopupRow)->apply(\str_repeat(' ', \max(0, $width)));
        }
        // The filter field is there from the start, a combobox's input: a
        // placeholder says typing narrows the list before the first key, and
        // the box keeps one height instead of growing three rows and
        // re-centring on the keystroke that summoned the field. It takes the
        // keys, so its border is the focused one; but no caret: the search
        // modal owns the one caret a frame may carry. The typed text
        // tail-scrolls ({@see FieldBox} keeps the end of a caret-less focused
        // value in view).
        $header = FieldBox::render(
            new FieldSpec(self::FILTER_GLYPH, placeholder: $placeholder, surface: Role::Toolbar),
            $overlay['filter'],
            FieldState::Focused,
            CaretVisibility::Hidden,
            $width,
            $styles,
        );
        $popup = [
            ModalFrame::titleBar($overlay['title'], $width + ModalFrame::CHROME_COLS, $styles, $closeHovered),
            ...ModalFrame::wrap([...$header, ...$content], $width, $styles, overflow: ListOverflow::of($windowStart, \count($visible), $selected, \count($items))),
        ];

        [$top, $col] = self::centered($bounds, \count($popup), $width + ModalFrame::CHROME_COLS);
        $box = new ModalBox(
            $top,
            $col,
            $width + ModalFrame::CHROME_COLS,
            \count($popup),
            headerRows: \count($header) + ModalFrame::CHROME_ROWS_TOP,
            windowStart: $windowStart,
            count: \count($visible),
        );

        return ['rows' => $popup, 'top' => $top, 'col' => $col, 'width' => $width + ModalFrame::CHROME_COLS, 'box' => $box];
    }

    /**
     * @param array{int, int, int, int} $bounds
     *
     * @return array{rows: list<string>, top: int, col: int, width: int, box: ModalBox}
     */
    public static function layoutSearch(
        array $bounds,
        SearchModalState $search,
        Styles $styles,
        bool $closeHovered,
        CaretVisibility $caret,
        string $placeholder = '',
        BaseDirection $direction = BaseDirection::Ltr,
    ): array {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        // As tall as the most rows seen since it opened, like its width: a
        // narrowing query pads the rows it drops rather than shrinking the box
        // and re-centring it under the reader.
        $boxRows = \max(0, \min(\max(\count($search->rows), $search->tallest), $rows - 6 - (FieldBox::ROWS - 1), 12));
        $maxRows = \max(1, \min($boxRows, \count($search->rows)));
        $windowStart = IntMath::clamp($search->selected - $maxRows + 1, 0, \max(0, \count($search->rows) - $maxRows));
        $visible = \array_slice($search->rows, $windowStart, $maxRows);

        // The modal owns the caret while open, wherever pane focus sits — but
        // only the TOPMOST surface renders it (the prompt wins): two markers
        // in one frame mangle ScreenWriter's hardware-cursor conversion.
        // visibleWidth strips the field's zero-width caret marker, so the
        // width measures the query text alone.
        $queryField = $search->query->render($caret);
        $width = \max(\mb_strwidth($search->title) + 8, AnsiUtils::visibleWidth($queryField) + 2 + FieldBox::CHROME_COLS, \mb_strwidth($placeholder) + 3 + FieldBox::CHROME_COLS, 24); // + 8: the frame's title-bar chrome
        // The widest row seen since the modal opened, not this frame's: the box
        // holds its size while results stream in and the query narrows.
        $width = \max($width, $search->widest);
        // Same rule as the list's filler, and unconditional for the same
        // reason. The 24-column floor happens to seat every translation of
        // "no results" today, which is luck rather than a guarantee.
        $width = \max($width, \mb_strwidth($search->emptyMessage) + 2);
        $width = \min($width, $columns - PopupMetrics::CENTERED_MARGIN, PopupMetrics::CENTERED_MAX_INNER);

        // No legend: the title bar already names what is being searched.
        $popup = FieldBox::render(new FieldSpec('', placeholder: $placeholder, surface: Role::Toolbar), $search->query, FieldState::of(CaretVisibility::Shown === $caret), $caret, $width, $styles);
        foreach ($visible as $i => $row) {
            $hit = $row->hit;
            if (null !== $row->header) {
                // A category is a fieldset's legend, `─ Files ───`: a rule that
                // groups by shape, where a dim word needed its colour.
                $popup[] = $styles->of(Role::PopupRow)->apply(FieldBox::fieldsetLegend($row->header, $width, $styles));

                continue;
            }
            if (null === $hit) {
                // A note under a header ("Searching…") — no result.
                $popup[] = $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.($row->note ?? ''), $width, pad: true));

                continue;
            }
            $selected = ($windowStart + $i) === $search->selected;
            $style = $styles->of(ModalRowStyle::role(RowSelection::of($selected, ($windowStart + $i) === $search->hovered)));
            $popup[] = $style->apply(SelectionBar::of($selected, focused: true)->value.self::hitCells($hit, \max(1, $width - 3), $style, $styles, $search->query->value(), $selected, $direction).' ');
        }
        // A no-hit query gets the same empty-state filler the find-in-files
        // modal ({@see FindInFilesModal}) shows: the popup's own base row
        // (PopupRow), never blank.
        if ([] === $search->rows && '' !== $search->emptyMessage) {
            $popup[] = $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$search->emptyMessage, $width, pad: true, ellipsis: ''));
        }
        while (\count($popup) < (FieldBox::ROWS + $boxRows)) {
            $popup[] = $styles->of(Role::PopupRow)->apply(\str_repeat(' ', \max(0, $width)));
        }
        $framed = [
            ModalFrame::titleBar($search->title, $width + ModalFrame::CHROME_COLS, $styles, $closeHovered),
            ...ModalFrame::wrap($popup, $width, $styles, overflow: self::searchOverflow($search, $windowStart, \count($visible))),
        ];

        [$top, $col] = self::centered($bounds, \count($framed), $width + ModalFrame::CHROME_COLS);
        $box = new ModalBox(
            $top,
            $col,
            $width + ModalFrame::CHROME_COLS,
            \count($framed),
            headerRows: FieldBox::ROWS + ModalFrame::CHROME_ROWS_TOP,
            windowStart: $windowStart,
            count: \count($visible),
        );

        return ['rows' => $framed, 'top' => $top, 'col' => $col, 'width' => $width + ModalFrame::CHROME_COLS, 'box' => $box];
    }

    /**
     * @param array{int, int, int, int}                                                              $bounds
     * @param array{label: string, value: TextField, onSubmit: callable(string): void, danger: bool} $prompt
     *
     * @return array{rows: list<string>, top: int, col: int, width: int, box: ModalBox}
     */
    public static function layoutPrompt(array $bounds, array $prompt, Styles $styles, bool $closeHovered): array
    {
        [, $boundLeft, , $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;

        $text = ' '.$prompt['label'].': '.$prompt['value']->value();
        $innerWidth = \min($columns - PopupMetrics::CENTERED_MARGIN, \max(30, \mb_strwidth($text) + 2 + FieldBox::CHROME_COLS));
        // The prompt is a framed modal like its siblings: the frame's title
        // bar carries the label, the Esc hint and the ×, so the boxed field
        // needs no legend. It always takes the keys; a destructive prompt's
        // border is the double one, so "this answer deletes" reads by shape
        // under --no-ansi as well as by its red. Centered like the list and
        // search overlays.
        $state = $prompt['danger'] ? FieldState::Danger : FieldState::Focused;
        $field = FieldBox::render(new FieldSpec(''), $prompt['value'], $state, CaretVisibility::Shown, $innerWidth, $styles);
        $framed = [
            ModalFrame::titleBar($prompt['label'], $innerWidth + ModalFrame::CHROME_COLS, $styles, closeHovered: $closeHovered),
            ...ModalFrame::wrap($field, $innerWidth, $styles),
        ];
        [$top, $col] = self::centered($bounds, \count($framed), $innerWidth + ModalFrame::CHROME_COLS);
        $box = new ModalBox($top, $col, $innerWidth + ModalFrame::CHROME_COLS, FieldBox::ROWS + ModalFrame::CHROME_ROWS, headerRows: ModalFrame::CHROME_ROWS_TOP, windowStart: 0, count: 1);

        return ['rows' => $framed, 'top' => $top, 'col' => $col, 'width' => $innerWidth + ModalFrame::CHROME_COLS, 'box' => $box];
    }

    /**
     * A hit's `label  detail` in exactly $avail columns. The label is the answer
     * and keeps at least half the row when it needs it; the detail gives way
     * first, cut in the MIDDLE, since a directory or a `file:line` is known by
     * both ends. A disabled hit (a command whose context is absent) is dim
     * and struck through, as its greyed menu item is: attributes, so the state
     * survives --no-ansi.
     */
    private static function hitCells(SearchHit $hit, int $avail, Style $row, Styles $styles, string $query, bool $selected, BaseDirection $direction): string
    {
        // The row that grows the list reads as an action, not a result: led
        // by an ellipsis, and never marked as matching the query.
        $text = $hit->keepsOpen ? Glyphs::ELLIPSIS.' '.$hit->label : $hit->label;
        $labelFloor = \min(\mb_strwidth($text), \max(1, \intdiv($avail + 1, num2: 2)));
        $detail = AnsiText::elideMiddle($hit->detail, \max(0, $avail - $labelFloor - 1));
        $label = AnsiText::truncate($text, \max(1, $avail - AnsiUtils::visibleWidth($detail)), pad: true);
        if ($hit->enabled && !$hit->keepsOpen) {
            $label = MatchMarks::apply($label, $text, $query, $selected);
        }
        if (!$hit->enabled) {
            $label = $row->withDim()->withStrikethrough()->apply(\rtrim($label)).$row->getAnsiRestore().\str_repeat(' ', \max(0, \mb_strwidth($label) - \mb_strwidth(\rtrim($label))));
        }

        return $label.self::gap($direction).$styles->of(Role::CompletionHint)->apply($detail);
    }

    /**
     * The column between a row's label and its hint. Under RTL it is a
     * right-to-left mark, spent for a space when the row is drawn: a Latin
     * label, the spaces and a Latin hint would otherwise resolve into ONE
     * left-to-right run and never mirror, so the label would stay left and a
     * row with no hint would sit right — the mark ends the label's run, and
     * every row puts its label where an RTL reader starts.
     */
    private static function gap(BaseDirection $direction): string
    {
        return BaseDirection::Rtl === $direction ? Bidi::RTL_MARK : ' ';
    }

    /**
     * Where the search window sits: rows hidden above and below, and the
     * selected hit's place among the HITS — a header is no place to be.
     */
    private static function searchOverflow(SearchModalState $search, int $windowStart, int $shown): ListOverflow
    {
        $hits = \array_keys(\array_filter($search->rows, static fn (SearchRow $row): bool => $row->isHit()));
        $place = \array_search($search->selected, $hits, strict: true);

        return new ListOverflow($windowStart > 0, ($windowStart + $shown) < \count($search->rows), false === $place ? 0 : $place + 1, \count($hits));
    }

    /**
     * Screen position of a box centered against the pane union: a third down
     * (the placement the pickers have always had), centered horizontally.
     *
     * @param array{int, int, int, int} $bounds
     *
     * @return array{int, int} [top, col]
     */
    private static function centered(array $bounds, int $boxRows, int $boxWidth): array
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;

        return [
            $boundTop + \max(0, \intdiv($boundBottom - $boundTop - $boxRows, num2: 3)),
            $boundLeft + \max(0, \intdiv($boundRight - $boundLeft - $boxWidth, num2: 2)),
        ];
    }

    /**
     * The columns a checklist item's box and its gap take, 0 for any other item.
     *
     * @param array{label: string, hint: string, value: string, checked?: bool, disabled?: bool} $item
     */
    private static function boxWidth(array $item): int
    {
        return \array_key_exists('checked', $item) ? FieldBox::CHECKBOX_WIDTH + 1 : 0;
    }
}
