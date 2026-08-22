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
     * @param array{int, int, int, int}                                                                                                                             $bounds
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, onPick: callable(string): void} $overlay
     *
     * @return array{rows: list<string>, top: int, col: int, width: int, box: ModalBox}
     */
    public static function layoutList(array $bounds, array $overlay, Styles $styles, bool $closeHovered): array
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        $items = $overlay['items'];
        $selected = $overlay['selected'];
        $maxItems = max(1, min(\count($items), $rows - 5, 12));
        $windowStart = IntMath::clamp($selected - $maxItems + 1, 0, \count($items) - $maxItems);
        $visible = \array_slice($items, $windowStart, $maxItems);

        $width = mb_strwidth($overlay['title']) + 8; // the frame's title-bar chrome (corners, hint, ×) needs its room
        foreach ($visible as $item) {
            $width = max($width, mb_strwidth($item['label']) + mb_strwidth($item['hint']) + 6);
        }
        $width = min($width, $columns - PopupMetrics::CENTERED_MARGIN, PopupMetrics::CENTERED_MAX_INNER);

        $content = [];
        foreach ($visible as $i => $item) {
            $style = $styles->of(ModalRowStyle::role(RowSelection::of(($windowStart + $i) === $selected, ($windowStart + $i) === $overlay['hovered'])));
            $hintWidth = mb_strwidth($item['hint']);
            $label = AnsiText::truncate($item['label'], $width - $hintWidth - 3, pad: true);
            $content[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($item['hint']).' ');
        }
        $popup = [
            ModalFrame::titleBar($overlay['title'], $width + ModalFrame::CHROME_COLS, $styles, $closeHovered),
            ...ModalFrame::wrap($content, $width, $styles),
        ];

        [$top, $col] = self::centered($bounds, \count($popup), $width + ModalFrame::CHROME_COLS);
        $box = new ModalBox($top, $col, $width + ModalFrame::CHROME_COLS, \count($popup), headerRows: ModalFrame::CHROME_ROWS_TOP, windowStart: $windowStart, count: \count($visible));

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
