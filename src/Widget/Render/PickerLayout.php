<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\SearchModalState;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\ListFilter;
use Porta\Widget\Support\RowSelection;
use Symfony\Component\Tui\Ansi\AnsiUtils;

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
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     *
     * @return array{rows: list<string>, top: int, col: int, width: int, box: ModalBox}
     */
    public static function layoutList(array $bounds, array $overlay, Styles $styles, bool $closeHovered, string $emptyMessage = ''): array
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        $filter = $overlay['filter']->value();
        $items = ListFilter::matching($overlay['items'], $filter);
        $selected = $overlay['selected'];
        $maxItems = max(1, min(\count($items), $rows - 5, 12));
        $windowStart = IntMath::clamp($selected - $maxItems + 1, 0, \count($items) - $maxItems);
        $visible = \array_slice($items, $windowStart, $maxItems);

        $width = mb_strwidth($overlay['title']) + 8; // the frame's title-bar chrome (corners, hint, ×) needs its room
        // Measured over the UNFILTERED list, and over all of it rather than the
        // visible window: a box sized from what survives the filter shrinks and
        // re-centres on every keystroke, walking out from under the eye reading
        // it. The width is a property of the list, not of this frame's slice.
        foreach ($overlay['items'] as $item) {
            $width = max($width, mb_strwidth($item['label']) + mb_strwidth($item['hint']) + 6);
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
        $width = max($width, mb_strwidth($emptyMessage) + 2);
        $width = min($width, $columns - PopupMetrics::CENTERED_MARGIN, PopupMetrics::CENTERED_MAX_INNER);

        $content = [];
        foreach ($visible as $i => $item) {
            $style = $styles->of(ModalRowStyle::role(RowSelection::of(($windowStart + $i) === $selected, ($windowStart + $i) === $overlay['hovered'])));
            $hintWidth = mb_strwidth($item['hint']);
            $label = AnsiText::truncate($item['label'], $width - $hintWidth - 3, pad: true);
            $content[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($item['hint']).' ');
        }
        if ([] === $visible) {
            $content[] = $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$emptyMessage, $width, pad: true, ellipsis: ''));
        }
        // The filter row appears only once something is typed, so every list
        // that was fine without one looks exactly as it did — and the moment it
        // narrows, what narrowed it is on screen rather than being a mode the
        // user has to remember entering. No caret: the search modal owns the
        // one caret a frame may carry, and this is a filter, not a field.
        // The typed text tail-scrolls, the glyph does not. Truncating the whole
        // row put the ellipsis at the END, so past the box width the row stopped
        // changing as you typed and showed the first few characters of a filter
        // you could no longer read — an input that hides its own tail. The
        // search overlay's query row has always scrolled ({@see
        // AnsiText::scrollLineToCaret}); it can, because it carries a cursor
        // marker to scroll to. This row deliberately carries none, so the
        // window is taken at the end of the text instead.
        $prefix = ' '.self::FILTER_GLYPH.' ';
        $room = max(1, $width - mb_strwidth($prefix));
        $header = '' === $filter
            ? []
            : [$styles->of(Role::Toolbar)->apply(
                AnsiText::padRight($prefix.AnsiText::caretWindow($filter, AnsiUtils::visibleWidth($filter), $room, marker: ''), $width),
            )];
        $popup = [
            ModalFrame::titleBar($overlay['title'], $width + ModalFrame::CHROME_COLS, $styles, $closeHovered),
            ...ModalFrame::wrap([...$header, ...$content], $width, $styles),
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
    public static function layoutSearch(array $bounds, SearchModalState $search, Styles $styles, bool $closeHovered, CaretVisibility $caret): array
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        $maxRows = max(1, min(\count($search->rows), $rows - 6, 12));
        $windowStart = IntMath::clamp($search->selected - $maxRows + 1, 0, max(0, \count($search->rows) - $maxRows));
        $visible = \array_slice($search->rows, $windowStart, $maxRows);

        // The modal owns the caret while open, wherever pane focus sits — but
        // only the TOPMOST surface renders it (the prompt wins): two markers
        // in one frame mangle ScreenWriter's hardware-cursor conversion.
        // visibleWidth strips the field's zero-width caret marker, so the
        // width measures the query text alone.
        $queryField = $search->query->render($caret);
        $width = max(mb_strwidth($search->title) + 8, AnsiUtils::visibleWidth($queryField) + 6, 24); // + 8: the frame's title-bar chrome
        foreach ($visible as $row) {
            $width = max($width, mb_strwidth($row->hit->label ?? '') + mb_strwidth($row->hit->detail ?? '') + 6);
        }
        // Same rule as the list's filler, and unconditional for the same
        // reason. The 24-column floor happens to seat every translation of
        // "no results" today, which is luck rather than a guarantee.
        $width = max($width, mb_strwidth($search->emptyMessage) + 2);
        $width = min($width, $columns - PopupMetrics::CENTERED_MARGIN, PopupMetrics::CENTERED_MAX_INNER);

        $popup = [
            $styles->of(Role::Toolbar)->apply(AnsiText::scrollLineToCaret(' > '.$queryField, $width)),
        ];
        foreach ($visible as $i => $row) {
            if (null !== $row->header) {
                $popup[] = $styles->merge(Role::PopupRow, Role::Dim)->apply(AnsiText::truncate(' '.$row->header, $width, pad: true));

                continue;
            }
            $style = $styles->of(ModalRowStyle::role(RowSelection::of(($windowStart + $i) === $search->selected, ($windowStart + $i) === $search->hovered)));
            // ` label · detail ` — three chrome columns (leading, gap, trailing).
            // Bound the detail first (leaving ≥1 column for the label) so a long
            // path can never push the row past $width and overflow the pane.
            $avail = max(1, $width - 3);
            $detail = AnsiText::truncate($row->hit->detail ?? '', max(0, $avail - 1), pad: false);
            $detailWidth = AnsiUtils::visibleWidth($detail);
            $label = AnsiText::truncate($row->hit->label ?? '', max(1, $avail - $detailWidth), pad: true);
            $popup[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($detail).' ');
        }
        // A no-hit query gets the same empty-state filler the find-in-files
        // modal ({@see FindInFilesModal}) shows: the popup's own base row
        // (PopupRow), never blank.
        if ([] === $search->rows && '' !== $search->emptyMessage) {
            $popup[] = $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$search->emptyMessage, $width, pad: true, ellipsis: ''));
        }
        $framed = [
            ModalFrame::titleBar($search->title, $width + ModalFrame::CHROME_COLS, $styles, $closeHovered),
            ...ModalFrame::wrap($popup, $width, $styles),
        ];

        [$top, $col] = self::centered($bounds, \count($framed), $width + ModalFrame::CHROME_COLS);
        $box = new ModalBox($top, $col, $width + ModalFrame::CHROME_COLS, \count($framed), headerRows: 1 + ModalFrame::CHROME_ROWS_TOP, windowStart: $windowStart, count: \count($visible));

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
        $innerWidth = min($columns - PopupMetrics::CENTERED_MARGIN, max(30, mb_strwidth($text) + 4));
        $rendered = ' '.$prompt['value']->render(cursor: CaretVisibility::Shown);
        // The prompt is a framed 3-row modal like its siblings: the frame's
        // title bar carries the label, the Esc hint and the ×; the content
        // row holds the field through scrollLineToCaret, which keeps the
        // value's caret marker on screen instead of clipping the hardware
        // cursor off it. Centered like the list/search overlays.
        $field = $styles->merge($prompt['danger'] ? Role::DangerBg : Role::HeaderBar, Role::Bright)->apply(AnsiText::padRight(AnsiText::scrollLineToCaret($rendered, $innerWidth), $innerWidth));
        $framed = [
            ModalFrame::titleBar($prompt['label'], $innerWidth + ModalFrame::CHROME_COLS, $styles, closeHovered: $closeHovered),
            ...ModalFrame::wrap([$field], $innerWidth, $styles),
        ];
        [$top, $col] = self::centered($bounds, \count($framed), $innerWidth + ModalFrame::CHROME_COLS);
        $box = new ModalBox($top, $col, $innerWidth + ModalFrame::CHROME_COLS, 1 + ModalFrame::CHROME_ROWS, headerRows: ModalFrame::CHROME_ROWS_TOP, windowStart: 0, count: 1);

        return ['rows' => $framed, 'top' => $top, 'col' => $col, 'width' => $innerWidth + ModalFrame::CHROME_COLS, 'box' => $box];
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
            $boundTop + max(0, intdiv($boundBottom - $boundTop - $boxRows, num2: 3)),
            $boundLeft + max(0, intdiv($boundRight - $boundLeft - $boxWidth, num2: 2)),
        ];
    }
}
