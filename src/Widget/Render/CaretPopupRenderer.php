<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * The read-outs drawn at the caret besides the completion list: the signature
 * hint, the documentation popup, and the note an asked-for completion shows
 * when it has no list. Each writes its framed rows into the editor's lines in
 * place, like {@see EditorRenderer::completionPopup()}, and keeps the
 * compositor's width invariant. Apart from {@see EditorRenderer} because that
 * class carries the row assembly, and the two together passed what one class
 * should branch on.
 */
final class CaretPopupRenderer
{
    /**
     * Draw the signature hint as a {@see PopupFrame}d one-liner above the
     * caret's screen row, flipping below when there's no headroom and
     * degrading to the bare unframed row when neither three-row slot fits
     * (tiny panes) — so the hint stays total on a two-row pane. Border only,
     * no shadow: a one-liner casting a four-row footprint reads too heavy —
     * the one deliberate divergence from the other framed popups.
     *
     * $counter — `2/3` — names which of a function's overloads this is.
     *
     * @param list<string>               $lines
     * @param array{0: int, 1: int}|null $activeRange byte range within $hint holding the argument being typed
     */
    public static function signatureHint(
        array &$lines,
        int $columns,
        int $gutterWidth,
        int $cursorScreenRow,
        string $hint,
        Styles $styles,
        ?array $activeRange = null,
        string $counter = '',
        ?CaretSide $taken = null,
    ): void {
        // Above the caret by preference, below when above has no room — and
        // never on the side another caret popup ($taken) already holds: the
        // completion list drawn over the hint hid the very signature being
        // typed into. With neither side free, the hint yields.
        $above = CaretSide::Above !== $taken;
        $below = CaretSide::Below !== $taken;
        $tail = '' === $counter ? 0 : \mb_strwidth($counter) + 2;
        $natural = \mb_strwidth($hint) + 2 + $tail;
        $col = $gutterWidth;
        $frameMax = $columns - $gutterWidth - 1 - PopupFrame::CHROME_COLS;
        $width = \min($natural, $frameMax);
        if ($width >= 4) {
            $top = match (true) {
                $above && $cursorScreenRow >= 3 => $cursorScreenRow - 3,
                $below => $cursorScreenRow + 1,
                default => -1,
            };
            if ($top >= 0 && ($top + 3) <= \count($lines)) {
                $content = self::signatureContent($hint, $width, $activeRange, $styles, $counter);
                $framed = [PopupFrame::top($width, $styles), PopupFrame::rail($content, $width, $styles), PopupFrame::bottom($width, $styles)];
                foreach ($framed as $i => $framedRow) {
                    $lines[$top + $i] = OverlayCompositor::into($lines[$top + $i], $col, $width + PopupFrame::CHROME_COLS, $columns, $framedRow);
                }

                return;
            }
        }

        $row = match (true) {
            $above && $cursorScreenRow > 0 => $cursorScreenRow - 1,
            $below => $cursorScreenRow + 1,
            default => -1,
        };
        $width = \min($natural, $columns - $gutterWidth - 1);
        if ($row < 0 || $row >= \count($lines) || $width < 4) {
            return;
        }
        $popup = self::signatureContent($hint, $width, $activeRange, $styles, $counter);

        $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popup);
    }

    /**
     * The signature popup's styled content: the argument being typed bold and
     * underlined — attributes, so it reads in monochrome — and the overload
     * counter dim at the end.
     *
     * A signature wider than the popup is cut around that argument rather
     * than from the end: the parameter you are on is the one you need, and
     * a five-parameter signature cut at the third comma hid it from the
     * fourth onwards. Each side gets an ellipsis where it was cut.
     *
     * Width math happens on the PLAIN pieces and the styling is applied
     * afterwards: handing an ANSI-carrying string to `mb_strwidth()` is the
     * classic over-wide row in this codebase. Each piece re-applies the popup
     * style, so the background cannot drop out mid-line.
     *
     * @param array{0: int, 1: int}|null $activeRange byte range within $hint
     */
    private static function signatureContent(string $hint, int $width, ?array $activeRange, Styles $styles, string $counter = ''): string
    {
        $style = $styles->merge(Role::InlayPopupBg, Role::InlayPopupFg);
        $tail = '' === $counter ? '' : '  '.$counter;
        $room = \max(0, $width - 2 - \mb_strwidth($tail));
        if (null === $activeRange) {
            // Cut at the end, the ellipsis ends the row; the counter, when
            // there is one, keeps its place after it.
            return '' === $tail
                ? $style->apply(AnsiText::truncate(' '.$hint.' ', $width, pad: true))
                : $style->apply(AnsiText::truncate(' '.$hint, $width - \mb_strwidth($tail) - 1, pad: true)).self::counterTail($tail, $style).$style->apply(' ');
        }
        $before = \substr($hint, offset: 0, length: $activeRange[0]);
        $active = \substr($hint, $activeRange[0], $activeRange[1] - $activeRange[0]);
        $after = \substr($hint, $activeRange[1]);

        $active = AnsiText::truncate($active, $room);
        $left = \intdiv(\max(0, $room - \mb_strwidth($active)), num2: 2);
        $right = \max(0, $room - \mb_strwidth($active) - $left);
        // What the right side does not need, the left may use; the right then
        // takes whatever the left left.
        $left += \max(0, $right - \mb_strwidth($after));
        $before = self::tailOf($before, \min($left, \max(0, $room - \mb_strwidth($active))));
        $after = AnsiText::truncate($after, \max(0, $room - \mb_strwidth($active) - \mb_strwidth($before)));

        $line = $style->apply(' '.$before).$style->withBold()->withUnderline()->apply($active).$style->apply(AnsiText::padRight($after, $room - \mb_strwidth($before) - \mb_strwidth($active)));

        return $line.self::counterTail($tail, $style).$style->apply(' ');
    }

    /** The last $width columns of $text, an ellipsis standing for what went. */
    private static function tailOf(string $text, int $width): string
    {
        if (\mb_strwidth($text) <= $width) {
            return $text;
        }
        if ($width < 1) {
            return '';
        }

        return Glyphs::ELLIPSIS.AnsiUtils::sliceByColumn($text, \mb_strwidth($text) - ($width - 1), $width - 1);
    }

    private static function counterTail(string $tail, Style $style): string
    {
        return '' === $tail ? '' : $style->withDim()->apply($tail);
    }

    /**
     * A symbol's documentation in a read-only popup at the caret: under it
     * when it fits, above when that fits, else on the roomier side cut to the
     * room — never over the line the question was asked on. Wrapped to the
     * docblock pane's width, scrolled $scroll rows in, with a `▲`/`▼` in the
     * border while there is more that way.
     *
     * @param list<string> $lines
     *
     * @return array{rect: array{int, int, int, int}, maxScroll: int}|null the popup as drawn — [top, col, width, height] — and how far it can scroll; null when no room
     */
    public static function docPopup(array &$lines, int $columns, int $rows, int $cursorScreenRow, int $anchorCol, string $text, int $scroll, Styles $styles): ?array
    {
        $width = \min(PopupMetrics::COMPLETION_DOC_INNER, $columns - PopupFrame::CHROME_COLS - 1);
        if ($width < 8) {
            return null;
        }
        $body = [];
        foreach (\explode("\n", $text) as $paragraph) {
            foreach (AnsiText::wrap($paragraph, $width) as $wrapped) {
                $body[] = $wrapped;
            }
        }
        $wanted = \min(\count($body), PopupMetrics::COMPLETION_ROWS);
        $roomBelow = $rows - $cursorScreenRow - 1 - PopupFrame::CHROME_ROWS;
        $roomAbove = $cursorScreenRow - PopupFrame::CHROME_ROWS;
        $below = $roomBelow >= $wanted || $roomAbove < $wanted && $roomBelow >= $roomAbove;
        $shown = \min($wanted, $below ? $roomBelow : $roomAbove);
        if ($shown < 1) {
            return null;
        }
        $maxScroll = \count($body) - $shown;
        $scroll = \max(0, \min($scroll, $maxScroll));
        $height = $shown + PopupFrame::CHROME_ROWS;
        $top = $below ? $cursorScreenRow + 1 : $cursorScreenRow - $height;
        $framedWidth = $width + PopupFrame::CHROME_COLS;
        $col = IntMath::clamp($anchorCol - 1, 0, $columns - $framedWidth);

        $framed = [PopupFrame::top($width, $styles, mark: $scroll > 0 ? Glyphs::SCROLL_UP : '')];
        foreach (\array_slice($body, $scroll, $shown) as $row) {
            $framed[] = PopupFrame::rail($styles->of(Role::PopupRow)->apply(AnsiText::truncate($row, $width, pad: true)), $width, $styles);
        }
        $framed[] = PopupFrame::bottom($width, $styles, mark: $scroll < $maxScroll ? Glyphs::SCROLL_DOWN : '');
        foreach ($framed as $i => $row) {
            $lines[$top + $i] = OverlayCompositor::into($lines[$top + $i], $col, $framedWidth, $columns, $row);
        }
        $lines = ModalShadow::blit($lines, $top, $col, $framedWidth, $height, $columns, $styles);

        return ['rect' => [$top, $col, $framedWidth, $height], 'maxScroll' => $maxScroll];
    }

    /**
     * An asked-for completion that has no list — "◌ Loading…", "No
     * suggestions", "No language server" — as a one-row popup where the list
     * would have opened, so Ctrl+Space is always answered where it was
     * pressed. Framed like the list, dim like a hint.
     *
     * @param list<string> $lines
     */
    public static function completionNote(array &$lines, int $columns, int $rows, int $cursorScreenRow, int $anchorCol, string $text, Styles $styles): ?CaretSide
    {
        $width = \min(\mb_strwidth($text) + 2, $columns - PopupFrame::CHROME_COLS);
        if ($width < 3) {
            return null;
        }
        $top = ($cursorScreenRow + 1 + 3) <= $rows ? $cursorScreenRow + 1 : $cursorScreenRow - 3;
        if ($top < 0) {
            return null;
        }
        $col = IntMath::clamp($anchorCol - 1, 0, $columns - $width - PopupFrame::CHROME_COLS);
        $framed = [
            PopupFrame::top($width, $styles),
            PopupFrame::rail($styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$text, $width, pad: true)), $width, $styles),
            PopupFrame::bottom($width, $styles),
        ];
        foreach ($framed as $i => $row) {
            $lines[$top + $i] = OverlayCompositor::into($lines[$top + $i], $col, $width + PopupFrame::CHROME_COLS, $columns, $row);
        }

        return CaretSide::of($top, $cursorScreenRow);
    }
}
