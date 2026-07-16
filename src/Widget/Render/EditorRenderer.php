<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Stateless rendering algorithms for the code editor: matching-bracket lookup,
 * identifier-occurrence highlighting, gutter git cells, and the per-line overlays
 * for inlay hints and indent guides. Extracting these pure computations from the
 * widget separates "how a line is decorated" from the widget's frame
 * orchestration and mutable state, and makes each piece unit-testable in isolation.
 */
final class EditorRenderer
{
    /**
     * Find the bracket at (or just before) the cursor and its match.
     * Naive scan that ignores strings/comments, capped for large files.
     *
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null
     *                                                                       pairs of [line, displayCol]
     */
    public static function matchingBrackets(Document $doc): ?array
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $line = $doc->getCursorLine();
        $col = $doc->getCursorCol();
        $text = $doc->getLine($line);

        // Bracket under the cursor, else just before it
        $char = $text[$col] ?? '';
        if (!array_key_exists($char, $pairs) && !\in_array($char, $pairs, strict: true)) {
            --$col;
            $char = $col >= 0 ? $text[$col] ?? '' : '';
        }

        $isOpen = array_key_exists($char, $pairs);
        $isClose = \in_array($char, $pairs, strict: true);
        if (!$isOpen && !$isClose) {
            return null;
        }

        $open = $isOpen ? $char : array_search($char, $pairs, strict: true);
        $close = $pairs[$open];
        $direction = $isOpen ? 1 : -1;

        $depth = 0;
        $scanned = 0;
        $l = $line;
        $c = $col;
        $lines = $doc->getLines();
        $lineCount = \count($lines);

        while ($l >= 0 && $l < $lineCount && $scanned < 20_000) {
            $current = $lines[$l];
            $len = \strlen($current);
            while ($c >= 0 && $c < $len) {
                $ch = $current[$c];
                if ($ch === ($isOpen ? $open : $close)) {
                    ++$depth;
                }
                if ($ch === ($isOpen ? $close : $open)) {
                    --$depth;
                    if (0 === $depth) {
                        return [
                            [$line, $doc->displayColAt($line, $col)],
                            [$l, $doc->displayColAt($l, $c)],
                        ];
                    }
                }
                $c += $direction;
                ++$scanned;
            }
            $l += $direction;
            $c = $direction > 0 ? 0 : \strlen($lines[$l] ?? '') - 1;
        }

        return null;
    }

    /**
     * The identifier under the caret, or null when the caret is not on one.
     * Includes a leading `$` so PHP variables are treated as one token.
     */
    public static function identifierUnderCaret(Document $doc): ?string
    {
        $line = $doc->getLine($doc->getCursorLine());
        $col = $doc->getCursorCol();

        $start = $col;
        while ($start > 0 && 1 === preg_match('/[\w$]/', $line[$start - 1])) {
            --$start;
        }
        $end = $col;
        $len = \strlen($line);
        while ($end < $len && 1 === preg_match('/\w/', $line[$end])) {
            ++$end;
        }
        if ($end <= $start) {
            return null;
        }
        $word = substr($line, $start, $end - $start);

        return 1 === preg_match('/^\$?[A-Za-z_]\w*$/', $word) ? $word : null;
    }

    /**
     * Whole-word occurrences of the identifier under the caret within the
     * visible line range — PhpStorm-style identifier highlight. Returns nothing
     * unless there are at least two matches (so a lone symbol is not lit up).
     *
     * @return list<array{0: int, 1: int, 2: int}> [line, startDisplayCol, endDisplayCol]
     */
    public static function identifierOccurrences(Document $doc, PaneFocus $focused, int $fromLine, int $toLine): array
    {
        if (PaneFocus::Blurred === $focused || $doc->hasSelection()) {
            return [];
        }
        $word = self::identifierUnderCaret($doc);
        if (null === $word || \strlen(ltrim($word, characters: '$')) < 2) {
            return [];
        }

        $pattern = '/(?<![\w$])'.preg_quote($word, delimiter: '/').'(?!\w)/';
        $occurrences = [];
        $matches = [];
        for ($line = $fromLine; $line <= $toLine; ++$line) {
            if (0 === preg_match_all($pattern, $doc->getLine($line), $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            /** @var mixed $rawMatches */
            $rawMatches = $matches[0];
            /** @var list<array{0: string, 1: int}> $group */
            $group = $rawMatches;
            foreach ($group as [$match, $offset]) {
                $occurrences[] = [
                    $line,
                    $doc->displayColAt($line, $offset),
                    $doc->displayColAt($line, $offset + \strlen($match)),
                ];
            }
        }

        return \count($occurrences) >= 2 ? $occurrences : [];
    }

    /** One-cell git change indicator for the gutter's leftmost column — shape-distinct glyphs so the states read without color. */
    public static function gitCell(?string $mark, Styles $styles): string
    {
        return match ($mark) {
            'added' => $styles->of(Role::GitAdded)->apply('▎'), // green solid bar
            'modified' => $styles->of(Role::GitModified)->apply('┆'), // blue dashed bar
            'deleted' => $styles->of(Role::GitDeleted)->apply('▁'), // red underline
            default => ' ',
        };
    }

    public static function utf16ToDisplayCol(Document $doc, int $line, int $utf16Col): int
    {
        return $doc->displayColAt($line, $doc->utf16ColToByteCol($line, $utf16Col));
    }

    /**
     * Total display width of a line's inlay hints at or before display column $displayCol.
     *
     * @param list<array{col: int, text: string}> $lineHints
     */
    public static function inlayShiftAt(Document $doc, int $line, array $lineHints, int $displayCol): int
    {
        $shift = 0;
        foreach ($lineHints as $hint) {
            if ($doc->displayColAt($line, $hint['col']) > $displayCol) {
                continue;
            }

            $shift += mb_strwidth($hint['text']);
        }

        return $shift;
    }

    /**
     * Insert a line's inlay hints as dim virtual text, right-to-left so offsets stay valid.
     *
     * @param list<array{col: int, text: string}> $lineHints
     */
    public static function applyInlayHints(Document $doc, int $line, array $lineHints, string $hlLine, Styles $styles): string
    {
        if ([] === $lineHints) {
            return $hlLine;
        }
        // Insert from the rightmost column first so earlier insertions do not
        // shift the columns of the ones still to be placed.
        usort($lineHints, static fn (array $a, array $b) => $b['col'] <=> $a['col']);
        foreach ($lineHints as $hint) {
            $col = $doc->displayColAt($line, $hint['col']);
            $hlLine = LineDecorator::insertAt($hlLine, $col, $styles->of(Role::Inlay)->apply($hint['text']));
        }

        return $hlLine;
    }

    /**
     * Overlay dim vertical guides at each indent level within a line's leading
     * whitespace, so nested blocks are easy to scan. Cell width is preserved.
     */
    public static function applyIndentGuides(Document $doc, string $hlLine, int $lineIndex, Styles $styles): string
    {
        $raw = $doc->getLine($lineIndex);
        $wsBytes = strspn($raw, characters: " \t");
        if (0 === $wsBytes) {
            return $hlLine;
        }
        $leadingWidth = $doc->displayColAt($lineIndex, $wsBytes);
        for ($col = 0; $col < $leadingWidth; $col += $doc->indentDisplayWidth()) {
            $hlLine = LineDecorator::overlayGlyph($hlLine, $col, '│', $styles->of(Role::IndentGuide));
        }

        return $hlLine;
    }

    /**
     * Slice a highlighted line to the visible window, inserting the caret marker
     * on the cursor line. Output is exactly $contentWidth cells wide.
     */
    public static function renderContentLine(int $hScroll, string $highlightedLine, int $contentWidth, CursorVisibility $withCursor, int $cursorDisplayCol, string $cursorMarker): string
    {
        if (CursorVisibility::Hidden === $withCursor) {
            $slice = AnsiUtils::sliceWithWidth($highlightedLine, $hScroll, $contentWidth);

            return AnsiText::padRight($slice['text'].Sgr::RESET, $contentWidth);
        }

        // Slice the whole visible window in one pass (correct SGR throughout),
        // then splice the zero-width cursor marker in by byte offset. Splitting
        // the line into two independent slices at the cursor would place the cut
        // exactly where a forward selection's background closes; the vendor
        // slicer re-emits the carried style after that close, re-opening the
        // selection background for the rest of the line. A from-column-0 prefix
        // slice ("slicePrefix") carries no such state and is a literal byte
        // prefix, so the marker lands cleanly without disturbing any styling.
        $windowed = AnsiUtils::sliceWithWidth($highlightedLine, $hScroll, $contentWidth);
        $text = $windowed['text'];
        $cursorOffset = max(0, $cursorDisplayCol - $hScroll);
        $byte = \strlen(AnsiUtils::sliceByColumn($text, 0, $cursorOffset));
        $withCursorText = substr($text, offset: 0, length: $byte).$cursorMarker.substr($text, $byte);

        return AnsiText::padRight($withCursorText.Sgr::RESET, $contentWidth);
    }

    /**
     * Overlay a line's worst-severity diagnostic message into the padding after
     * the code, dimmed and colored by severity, with a "+N" suffix when the line
     * has more diagnostics. $content is exactly $contentWidth wide going in and out.
     *
     * @param int                                                                    $lineDisplayWidth the line's tab-expanded display width
     *                                                                                                 (content is rendered tab-expanded, so a
     *                                                                                                 raw-byte width would misplace the message
     *                                                                                                 on tab-indented lines)
     * @param list<array{message?: string, severity?: int, col?: int, endCol?: int}> $diags
     */
    public static function appendInlineDiagnostic(int $hScroll, int $lineDisplayWidth, array $diags, string $content, int $contentWidth, Styles $styles): string
    {
        // Show the worst-severity diagnostic's message (the first one at that
        // severity); an empty list falls through to the empty-message guard below.
        $worst = DiagnosticStyle::worst($diags);
        $message = '';
        foreach ($diags as $diag) {
            if (DiagnosticStyle::normalize($diag['severity'] ?? 1) !== $worst) {
                continue;
            }

            $message = $diag['message'] ?? '';
            break;
        }
        if ('' === $message) {
            return $content;
        }

        // Where the code text ends on screen (accounting for horizontal scroll).
        $textEnd = max(0, $lineDisplayWidth - $hScroll);
        $start = min($contentWidth, $textEnd) + 2; // two-space gap
        $available = $contentWidth - $start;
        if ($available < 8) {
            return $content; // not enough room to be useful
        }

        $extra = \count($diags) > 1 ? ' +'.(\count($diags) - 1) : '';
        // Severity glyph prefix, so error vs warning reads without color.
        $label = AnsiUtils::truncateToWidth('‹ '.DiagnosticStyle::glyph($worst).' '.(string) preg_replace('/\s+/', replacement: ' ', subject: $message).$extra.' ›', $available, '…');
        $head = AnsiText::padRight(AnsiUtils::sliceByColumn($content, 0, $start), $start);

        return AnsiText::padRight($head.$styles->of(DiagnosticStyle::dimRole($worst))->apply($label), $contentWidth);
    }

    /**
     * Append a dim informational annotation (e.g. a blame line) in the padding
     * after the code — the same placement as {@see appendInlineDiagnostic},
     * which wins the slot when both apply.
     */
    public static function appendAnnotation(int $hScroll, int $lineDisplayWidth, string $text, string $content, int $contentWidth, Styles $styles): string
    {
        $textEnd = max(0, $lineDisplayWidth - $hScroll);
        $start = min($contentWidth, $textEnd) + 2; // two-space gap
        $available = $contentWidth - $start;
        if ($available < 8) {
            return $content; // not enough room to be useful
        }

        $label = AnsiUtils::truncateToWidth((string) preg_replace('/\s+/', replacement: ' ', subject: $text), $available, '…');
        $head = AnsiText::padRight(AnsiUtils::sliceByColumn($content, 0, $start), $start);

        return AnsiText::padRight($head.$styles->of(Role::Inlay)->apply($label), $contentWidth);
    }

    // --- Overlays ----------------------------------------------------------

    private const POPUP_MAX_ITEMS = 8;
    private const POPUP_MAX_WIDTH = 46;

    /**
     * Draw the signature hint just above (or below) the caret's screen row.
     *
     * @param string[] $lines
     */
    public static function signatureHint(array &$lines, int $columns, int $gutterWidth, int $cursorScreenRow, string $hint, Styles $styles): void
    {
        $row = $cursorScreenRow > 0 ? $cursorScreenRow - 1 : $cursorScreenRow + 1;
        if ($row < 0 || $row >= \count($lines)) {
            return;
        }

        $text = ' '.$hint.' ';
        $width = min(mb_strwidth($text), $columns - $gutterWidth - 1);
        if ($width < 4) {
            return;
        }
        $col = $gutterWidth;
        $popup = $styles->merge(Role::InlayPopupBg, Role::InlayPopupFg)->apply(AnsiUtils::truncateToWidth($text, $width, '…', pad: true));

        $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popup);
    }

    /**
     * Draw the centred list overlay (recent files, quick fixes, surround, …).
     *
     * Returns the on-screen box geometry so callers can hit-test mouse clicks:
     * the title sits at row `startRow`; visible item `i` (0-based) at row
     * `startRow + 1 + i` and maps to absolute item index `windowStart + i`; the
     * box spans columns `[col, col + width)`; `count` is the number of item rows.
     *
     * @param string[]                                                                      $lines
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, onPick: callable} $overlay
     *
     * @return array{col: int, width: int, startRow: int, windowStart: int, count: int}
     */
    public static function listOverlay(array &$lines, int $columns, int $rows, array $overlay, Styles $styles): array
    {
        $items = $overlay['items'];
        $selected = $overlay['selected'];
        $maxItems = max(1, min(\count($items), $rows - 3, 12));
        $windowStart = IntMath::clamp($selected - $maxItems + 1, 0, \count($items) - $maxItems);
        $visible = \array_slice($items, $windowStart, $maxItems);

        $width = mb_strwidth($overlay['title']) + 4;
        foreach ($visible as $item) {
            $width = max($width, mb_strwidth($item['label']) + mb_strwidth($item['hint']) + 6);
        }
        $width = min($width, $columns - 4, 70);
        $col = max(0, intdiv($columns - $width, num2: 2));

        $popup = [];
        $popup[] = HeaderRenderer::render(' '.$overlay['title'], $width, $styles->merge(Role::HeaderBar, Role::Bright)->withBold()->getAnsiRestore());
        foreach ($visible as $i => $item) {
            $isSelected = ($windowStart + $i) === $selected;
            $style = $styles->of($isSelected ? Role::SelectedRow : Role::PopupRow);
            $hintWidth = mb_strwidth($item['hint']);
            $label = AnsiUtils::truncateToWidth($item['label'], $width - $hintWidth - 3, '…', pad: true);
            $popup[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($item['hint']).' ');
        }

        return self::placeCenteredPopup($lines, $popup, $col, $width, $columns, $rows, $windowStart, \count($visible));
    }

    /**
     * Draw the centered Search Everywhere modal: a title bar, a query row, then
     * the grouped result rows (category headers + hits), windowed around
     * $selected (a hit-row index into $rowModels). The first list row sits at
     * `startRow + 2` (after the title and query rows), so a click on screen row R
     * maps to `windowStart + (R - startRow - 2)` in $rowModels.
     *
     * @param string[]                                                       $lines
     * @param list<array{header: string|null, label: string, detail: string}> $rowModels a header row when `header` is set
     *
     * @return array{col: int, width: int, startRow: int, windowStart: int, count: int}
     */
    public static function searchOverlay(
        array &$lines,
        int $columns,
        int $rows,
        string $title,
        string $query,
        PaneFocus $focused,
        array $rowModels,
        int $selected,
        Styles $styles,
        string $cursorMarker,
    ): array {
        $maxRows = max(1, min(\count($rowModels), $rows - 4, 12));
        $windowStart = IntMath::clamp($selected - $maxRows + 1, 0, max(0, \count($rowModels) - $maxRows));
        $visible = \array_slice($rowModels, $windowStart, $maxRows);

        $width = max(mb_strwidth($title) + 4, mb_strwidth($query) + 6, 24);
        foreach ($visible as $row) {
            $width = max($width, mb_strwidth($row['label']) + mb_strwidth($row['detail']) + 6);
        }
        $width = min($width, $columns - 4, 70);
        $col = max(0, intdiv($columns - $width, num2: 2));

        $popup = [
            HeaderRenderer::render(' '.$title, $width, $styles->merge(Role::HeaderBar, Role::Bright)->withBold()->getAnsiRestore()),
            $styles->of(Role::Toolbar)->apply(AnsiText::scrollLineToCaret(' > '.$query.(PaneFocus::Focused === $focused ? $cursorMarker : ''), $width)),
        ];
        foreach ($visible as $i => $row) {
            if (null !== $row['header']) {
                $popup[] = $styles->merge(Role::PopupRow, Role::Dim)->apply(AnsiUtils::truncateToWidth(' '.$row['header'], $width, '…', pad: true));

                continue;
            }
            $style = $styles->of(($windowStart + $i) === $selected ? Role::SelectedRow : Role::PopupRow);
            // ` label · detail ` — three chrome columns (leading, gap, trailing).
            // Bound the detail first (leaving ≥1 column for the label) so a long
            // path can never push the row past $width and overflow the pane.
            $avail = max(1, $width - 3);
            $detail = AnsiUtils::truncateToWidth($row['detail'], max(0, $avail - 1), '…', pad: false);
            $detailWidth = AnsiUtils::visibleWidth($detail);
            $label = AnsiUtils::truncateToWidth($row['label'], max(1, $avail - $detailWidth), '…', pad: true);
            $popup[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($detail).' ');
        }

        return self::placeCenteredPopup($lines, $popup, $col, $width, $columns, $rows, $windowStart, \count($visible));
    }

    /**
     * Draw the autocomplete popup anchored at the caret.
     *
     * Returns the on-screen box geometry so callers can hit-test mouse clicks:
     * visible item `i` (0-based) sits at row `startRow + i` and maps to filtered
     * index `windowOffset + i`; the box spans columns `[col, col + width)`;
     * `count` is the number of item rows drawn.
     *
     * @param string[]                                                          $lines
     * @param list<array{label: string, insertText: string, kind: string, detail: string, additionalTextEdits?: list<array>, snippet?: string|null, raw?: array<string, mixed>}> $items filtered
     *
     * @return array{col: int, width: int, startRow: int, windowOffset: int, count: int}
     */
    public static function completionPopup(array &$lines, int $columns, int $rows, int $cursorScreenRow, int $anchorCol, array $items, int $selected, Styles $styles): array
    {
        $windowOffset = max(0, $selected - self::POPUP_MAX_ITEMS + 1);
        $visible = \array_slice($items, $windowOffset, self::POPUP_MAX_ITEMS);
        $selectedInWindow = min($selected, self::POPUP_MAX_ITEMS - 1);

        $width = 12;
        foreach ($visible as $item) {
            $width = max($width, mb_strwidth($item['label']) + mb_strwidth($item['kind']) + 5);
        }
        $width = min($width, self::POPUP_MAX_WIDTH, $columns - 2);

        $popupHeight = \count($visible);
        $below = ($cursorScreenRow + 1 + $popupHeight) <= $rows;
        $startRow = $below ? $cursorScreenRow + 1 : max(0, $cursorScreenRow - $popupHeight);

        $col = IntMath::clamp($anchorCol, 0, $columns - $width);

        foreach ($visible as $i => $item) {
            $row = $startRow + $i;
            if ($row < 0 || $row >= \count($lines)) {
                continue;
            }

            $isSelected = $i === $selectedInWindow;
            $style = $styles->of($isSelected ? Role::SelectedRow : Role::PopupRow);
            $kindTag = $styles->of(Role::CompletionKind)->apply($item['kind']);
            $labelWidth = $width - mb_strwidth($item['kind']) - 3;
            $label = AnsiUtils::truncateToWidth($item['label'], $labelWidth, '…', pad: true);
            $popupLine = $style->apply(' '.$label.' '.$kindTag.' ');

            $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popupLine);
        }

        return ['col' => $col, 'width' => $width, 'startRow' => $startRow, 'windowOffset' => $windowOffset, 'count' => $popupHeight];
    }

    /**
     * Centre a fully-built popup a third of the way down the pane and blit it
     * into $lines at columns [$col, $col + $width); returns the box geometry the
     * caller hands to its mouse hit-tester. Shared by the centered overlays
     * ({@see listOverlay()}, {@see searchOverlay()}); the completion popup
     * anchors to the caret and places itself instead. $count is the number of
     * selectable item rows (the popup array also holds the header rows).
     *
     * @param string[]     $lines
     * @param list<string> $popup
     *
     * @return array{col: int, width: int, startRow: int, windowStart: int, count: int}
     */
    private static function placeCenteredPopup(array &$lines, array $popup, int $col, int $width, int $columns, int $rows, int $windowStart, int $count): array
    {
        $startRow = max(0, intdiv($rows - \count($popup), num2: 3));
        foreach ($popup as $i => $popupLine) {
            $row = $startRow + $i;
            if ($row >= \count($lines)) {
                break;
            }
            $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popupLine);
        }

        return ['col' => $col, 'width' => $width, 'startRow' => $startRow, 'windowStart' => $windowStart, 'count' => $count];
    }
}
