<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\CompletionBox;
use Porta\Editor\CompletionItem;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Overlay\ModalBox;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Overlay\RowSelection;
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
            'added' => $styles->of(Role::GitAdded)->apply(Glyphs::GIT_ADDED), // green solid bar
            'modified' => $styles->of(Role::GitModified)->apply(Glyphs::GIT_MODIFIED), // blue dashed bar
            'deleted' => $styles->of(Role::GitDeleted)->apply(Glyphs::GIT_DELETED), // red underline
            default => ' ',
        };
    }

    public static function utf16ToDisplayCol(Document $doc, int $line, int $utf16Col): int
    {
        return $doc->displayColAt($line, $doc->utf16ColToByteCol($line, $utf16Col));
    }

    /** The shading for a modal list row: the selection wins, then the hover tint, then plain. */
    private static function rowRole(int $index, int $selected, ?int $hovered): Role
    {
        return ModalRowStyle::role(RowSelection::of($index === $selected, $index === $hovered));
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
            $hlLine = LineDecorator::overlayGlyph($hlLine, $col, Glyphs::INDENT_GUIDE, $styles->of(Role::IndentGuide));
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
        $label = AnsiText::truncate('‹ '.DiagnosticStyle::glyph($worst).' '.(string) preg_replace('/\s+/', replacement: ' ', subject: $message).$extra.' ›', $available);
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

        $label = AnsiText::truncate((string) preg_replace('/\s+/', replacement: ' ', subject: $text), $available);
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
        $popup = $styles->merge(Role::InlayPopupBg, Role::InlayPopupFg)->apply(AnsiText::truncate($text, $width, pad: true));

        $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popup);
    }

    /**
     * Draw the centred list overlay (recent files, quick fixes, surround, …).
     *
     * Returns the on-screen box geometry so callers can hit-test mouse clicks:
     * the frame's title bar sits at row `startRow`; visible item `i` (0-based)
     * at row `startRow + 1 + i`, mapped by {@see ModalBox::itemAt}.
     *
     * @param list<string>                                                                  $lines
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, onPick: callable} $overlay
     */
    public static function listOverlay(array &$lines, int $columns, int $rows, array $overlay, Styles $styles): ModalBox
    {
        $items = $overlay['items'];
        $selected = $overlay['selected'];
        $hovered = $overlay['hovered'] ?? null;
        $maxItems = max(1, min(\count($items), $rows - 3, 12));
        $windowStart = IntMath::clamp($selected - $maxItems + 1, 0, \count($items) - $maxItems);
        $visible = \array_slice($items, $windowStart, $maxItems);

        $width = mb_strwidth($overlay['title']) + 8; // the frame's title-bar chrome (corners, hint, ×) needs its room
        foreach ($visible as $item) {
            $width = max($width, mb_strwidth($item['label']) + mb_strwidth($item['hint']) + 6);
        }
        $width = min($width, $columns - 6, 70); // inner width; the frame adds a rail each side
        $col = max(0, intdiv($columns - $width - 2, num2: 2));

        $content = [];
        foreach ($visible as $i => $item) {
            $style = $styles->of(self::rowRole($windowStart + $i, $selected, $hovered));
            $hintWidth = mb_strwidth($item['hint']);
            $label = AnsiText::truncate($item['label'], $width - $hintWidth - 3, pad: true);
            $content[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($item['hint']).' ');
        }
        $popup = [ModalFrame::titleBar($overlay['title'], $width + 2, $styles), ...ModalFrame::wrap($content, $width, $styles)];

        [$lines, $startRow] = OverlayCompositor::placeCentered($lines, $popup, $col, $width + 2, $columns, $rows);

        return new ModalBox($startRow, $col, $width + 2, \count($popup), headerRows: 1, windowStart: $windowStart, count: \count($visible));
    }

    /**
     * Draw the centered Search Everywhere modal: the frame's title bar, a query
     * row, then the grouped result rows (category headers + hits), windowed
     * around $selected (a hit-row index into $rowModels). The first list row
     * sits at `startRow + 2` (after the title bar and query row), mapped by
     * {@see ModalBox::itemAt}.
     *
     * @param list<string>                                                    $lines
     * @param list<array{header: string|null, label: string, detail: string}> $rowModels a header row when `header` is set
     */
    public static function searchOverlay(
        array &$lines,
        int $columns,
        int $rows,
        string $title,
        string $queryField,
        array $rowModels,
        int $selected,
        ?int $hovered,
        Styles $styles,
        string $emptyMessage,
    ): ModalBox {
        $maxRows = max(1, min(\count($rowModels), $rows - 4, 12));
        $windowStart = IntMath::clamp($selected - $maxRows + 1, 0, max(0, \count($rowModels) - $maxRows));
        $visible = \array_slice($rowModels, $windowStart, $maxRows);

        // visibleWidth strips the field's zero-width caret marker, so the width
        // measures the query text alone.
        $width = max(mb_strwidth($title) + 8, AnsiUtils::visibleWidth($queryField) + 6, 24); // + 8: the frame's title-bar chrome
        foreach ($visible as $row) {
            $width = max($width, mb_strwidth($row['label']) + mb_strwidth($row['detail']) + 6);
        }
        $width = min($width, $columns - 6, 70); // inner width; the frame adds a rail each side
        $col = max(0, intdiv($columns - $width - 2, num2: 2));

        $popup = [
            $styles->of(Role::Toolbar)->apply(AnsiText::scrollLineToCaret(' > '.$queryField, $width)),
        ];
        foreach ($visible as $i => $row) {
            if (null !== $row['header']) {
                $popup[] = $styles->merge(Role::PopupRow, Role::Dim)->apply(AnsiText::truncate(' '.$row['header'], $width, pad: true));

                continue;
            }
            $style = $styles->of(self::rowRole($windowStart + $i, $selected, $hovered));
            // ` label · detail ` — three chrome columns (leading, gap, trailing).
            // Bound the detail first (leaving ≥1 column for the label) so a long
            // path can never push the row past $width and overflow the pane.
            $avail = max(1, $width - 3);
            $detail = AnsiText::truncate($row['detail'], max(0, $avail - 1), pad: false);
            $detailWidth = AnsiUtils::visibleWidth($detail);
            $label = AnsiText::truncate($row['label'], max(1, $avail - $detailWidth), pad: true);
            $popup[] = $style->apply(' '.$label.' '.$styles->of(Role::CompletionHint)->apply($detail).' ');
        }
        // A no-hit query gets the same empty-state filler the find-in-files
        // modal ({@see \Porta\Widget\Overlay\FindInFilesModal}) shows: the
        // popup's own base row (PopupRow), never blank.
        if ([] === $rowModels && '' !== $emptyMessage) {
            $popup[] = $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$emptyMessage, $width, pad: true, ellipsis: ''));
        }
        $framed = [ModalFrame::titleBar($title, $width + 2, $styles), ...ModalFrame::wrap($popup, $width, $styles)];

        [$lines, $startRow] = OverlayCompositor::placeCentered($lines, $framed, $col, $width + 2, $columns, $rows);

        return new ModalBox($startRow, $col, $width + 2, \count($framed), headerRows: 2, windowStart: $windowStart, count: \count($visible));
    }

    /**
     * Draw the autocomplete popup anchored at the caret. Returns the on-screen
     * box geometry so callers can hit-test mouse clicks.
     *
     * @param string[]             $lines
     * @param list<CompletionItem> $items filtered
     */
    public static function completionPopup(array &$lines, int $columns, int $rows, int $cursorScreenRow, int $anchorCol, array $items, int $selected, ?int $hovered, Styles $styles): CompletionBox
    {
        $windowOffset = max(0, $selected - self::POPUP_MAX_ITEMS + 1);
        $visible = \array_slice($items, $windowOffset, self::POPUP_MAX_ITEMS);
        $selectedInWindow = min($selected, self::POPUP_MAX_ITEMS - 1);

        $width = 12;
        foreach ($visible as $item) {
            $width = max($width, mb_strwidth($item->label) + mb_strwidth($item->kind) + 5);
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

            $style = $styles->of(self::rowRole($i, $selectedInWindow, null === $hovered ? null : $hovered - $windowOffset));
            $kindTag = $styles->of(Role::CompletionKind)->apply($item->kind);
            $labelWidth = $width - mb_strwidth($item->kind) - 3;
            $label = AnsiText::truncate($item->label, $labelWidth, pad: true);
            $popupLine = $style->apply(' '.$label.' '.$kindTag.' ');

            $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popupLine);
        }

        return new CompletionBox($col, $width, $startRow, $popupHeight, $windowOffset);
    }

    /**
     * Centre a fully-built popup a third of the way down the pane and blit it
     * into $lines at columns [$col, $col + $width); returns the box geometry the
     * caller hands to its mouse hit-tester. Shared by the centered overlays
     * ({@see listOverlay()}, {@see searchOverlay()}); the completion popup
     * anchors to the caret and places itself instead. $count is the number of
     * selectable item rows (the popup array also holds the header rows).
     *
     * @param list<string> $lines
     * @param list<string> $popup
     *
     * @return array{col: int, width: int, startRow: int, windowStart: int, count: int}
     */
}
