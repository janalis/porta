<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\CompletionBox;
use Porta\Editor\CompletionItem;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\RowSelection;
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
     * A line with more same-word matches than this contributes no occurrence
     * spans: identifiers in code repeat at most a few times per line, so a
     * denser line means the caret sits on a prose word — and per-line span
     * count is what the decoration cost scales with.
     */
    public const int MAX_OCCURRENCES_PER_LINE = 8;

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
     * Every match is reported as a READ: a textual scan cannot tell an
     * assignment from a use. The write distinction comes from the language
     * server ({@see \Porta\Lsp\HighlightController}), which is why this stays
     * the fallback rather than the source of truth.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: bool}> [line, startDisplayCol, endDisplayCol, isWrite]
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
            $count = preg_match_all($pattern, $doc->getLine($line), $matches, \PREG_OFFSET_CAPTURE);
            if (0 === $count || false === $count) {
                continue;
            }
            if ($count > self::MAX_OCCURRENCES_PER_LINE) {
                // Code never repeats an identifier this densely on one line —
                // this is the caret parked on a prose stopword ('the', 'and')
                // in an unwrapped paragraph. Lighting those up is noise, and
                // per-line skipping keeps highlighting alive on the code lines
                // of mixed files.
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
                    false,
                ];
            }
        }

        return \count($occurrences) >= 2 ? $occurrences : [];
    }

    /** One-cell git change indicator for the gutter's leftmost column. */
    public static function vcsCell(?ChangeMarkKind $mark, Styles $styles): string
    {
        return null === $mark ? ' ' : $styles->of($mark->role())->apply($mark->glyph());
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
        // One left-to-right pass over the raw line, NOT a per-hint insert fold: each
        // insert re-slices the accumulated result and the slicer's escape
        // replay multiplies the escapes per hint (the indent-guide/span-fold
        // disease) — a parameter-hint-happy LSP line went exponential. Equal
        // columns keep the fold's order: the later-listed hint lands left.
        $placed = [];
        foreach ($lineHints as $i => $hint) {
            $placed[] = [$doc->displayColAt($line, $hint['col']), -$i, $styles->of(Role::Inlay)->apply($hint['text'])];
        }
        usort($placed, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $lineWidth = AnsiUtils::visibleWidth($hlLine);
        $out = '';
        $prev = 0;
        foreach ($placed as [$col, , $text]) {
            $col = min($col, $lineWidth);
            if ($col > $prev) {
                $out .= AnsiUtils::sliceByColumn($hlLine, $prev, $col - $prev);
                $prev = $col;
            }
            $out .= $text;
        }
        if ($prev < $lineWidth) {
            $out .= AnsiUtils::sliceByColumn($hlLine, $prev, $lineWidth - $prev);
        }

        return $out;
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
        // One pass over the raw line, NOT a per-glyph overlay fold: each overlay
        // re-slices the accumulated result, and the slicer's escape replay
        // DOUBLES the escapes per glyph — 16 indent levels (a deeply indented
        // ASCII-art block in a README) turned one row into 2^20 bytes and a
        // frame into 5MB. Slicing the raw line once per gap stays linear.
        $leadingWidth = $doc->displayColAt($lineIndex, $wsBytes);
        $lineWidth = AnsiUtils::visibleWidth($hlLine);
        $glyph = $styles->of(Role::IndentGuide)->apply(Glyphs::INDENT_GUIDE);
        $out = '';
        $prev = 0;
        for ($col = 0; $col < $leadingWidth && $col < $lineWidth; $col += $doc->indentDisplayWidth()) {
            if ($col > $prev) {
                $out .= AnsiUtils::sliceByColumn($hlLine, $prev, $col - $prev);
            }
            $out .= $glyph;
            $prev = $col + 1;
        }
        if ($prev < $lineWidth) {
            $out .= AnsiUtils::sliceByColumn($hlLine, $prev, $lineWidth - $prev);
        }

        return $out;
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
     * @param int                                                                    $textDisplayWidth the *decorated* line's on-screen display
     *                                                                                                 width — the visible width of the fully
     *                                                                                                 assembled line (tab-expanded code plus any
     *                                                                                                 inlay hints and the collapsed-fold marker),
     *                                                                                                 not the raw doc line's width, or the message
     *                                                                                                 would slice into those decorations
     * @param list<array{message?: string, severity?: int, col?: int, endCol?: int}> $diags
     */
    public static function appendInlineDiagnostic(int $hScroll, int $textDisplayWidth, array $diags, string $content, int $contentWidth, Styles $styles): string
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

        // Where the decorated text ends on screen (accounting for horizontal scroll).
        $textEnd = max(0, $textDisplayWidth - $hScroll);
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
     * which wins the slot when both apply. $textDisplayWidth is the decorated
     * line's on-screen width (see appendInlineDiagnostic).
     */
    public static function appendAnnotation(int $hScroll, int $textDisplayWidth, string $text, string $content, int $contentWidth, Styles $styles): string
    {
        $textEnd = max(0, $textDisplayWidth - $hScroll);
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

    /**
     * Draw the signature hint as a {@see PopupFrame}d one-liner above the
     * caret's screen row, flipping below when there's no headroom and
     * degrading to the bare unframed row when neither three-row slot fits
     * (tiny panes) — so the hint stays total on a two-row pane. Border only,
     * no shadow: a one-liner casting a four-row footprint reads too heavy —
     * the one deliberate divergence from the other framed popups.
     *
     * @param list<string>               $lines
     * @param array{0: int, 1: int}|null $activeRange byte range within $hint holding the argument being typed
     */
    public static function signatureHint(array &$lines, int $columns, int $gutterWidth, int $cursorScreenRow, string $hint, Styles $styles, ?array $activeRange = null): void
    {
        $text = ' '.$hint.' ';
        $col = $gutterWidth;
        $frameMax = $columns - $gutterWidth - 1 - PopupFrame::CHROME_COLS;
        $width = min(mb_strwidth($text), $frameMax);
        if ($width >= 4) {
            $top = $cursorScreenRow >= 3 ? $cursorScreenRow - 3 : $cursorScreenRow + 1;
            if ($top >= 0 && ($top + 3) <= \count($lines)) {
                $content = self::signatureContent($text, $width, $activeRange, $styles);
                $framed = [PopupFrame::top($width, $styles), PopupFrame::rail($content, $width, $styles), PopupFrame::bottom($width, $styles)];
                foreach ($framed as $i => $framedRow) {
                    $lines[$top + $i] = OverlayCompositor::into($lines[$top + $i], $col, $width + PopupFrame::CHROME_COLS, $columns, $framedRow);
                }

                return;
            }
        }

        $row = $cursorScreenRow > 0 ? $cursorScreenRow - 1 : $cursorScreenRow + 1;
        $width = min(mb_strwidth($text), $columns - $gutterWidth - 1);
        if ($row < 0 || $row >= \count($lines) || $width < 4) {
            return;
        }
        $popup = self::signatureContent($text, $width, $activeRange, $styles);

        $lines[$row] = OverlayCompositor::into($lines[$row], $col, $width, $columns, $popup);
    }

    /**
     * The signature popup's styled content, with the argument you are typing
     * shown bold.
     *
     * Width math happens on the PLAIN text and the styling is applied
     * afterwards, in three pieces: handing an ANSI-carrying string to
     * `mb_strwidth()` is the classic over-wide row in this codebase. Each piece
     * re-applies the popup style, so the background cannot drop out mid-line.
     *
     * @param array{0: int, 1: int}|null $activeRange byte range within the hint,
     *                                                before the leading pad space
     */
    private static function signatureContent(string $text, int $width, ?array $activeRange, Styles $styles): string
    {
        $style = $styles->merge(Role::InlayPopupBg, Role::InlayPopupFg);
        $plain = AnsiText::truncate($text, $width, pad: true);
        // Offsets are into the UNTRUNCATED hint, and truncation both drops text
        // and appends an ellipsis — a multi-byte character the offsets could
        // land inside, which produces invalid UTF-8 and a garbled row. A hint
        // that does not fit is already degraded; it degrades the rest of the
        // way rather than being spliced.
        if (null === $activeRange || mb_strwidth($text) > $width) {
            return $style->apply($plain);
        }

        // +1 for the space `signatureHint()` pads the hint with.
        $start = $activeRange[0] + 1;
        $end = $activeRange[1] + 1;

        return $style->apply(substr($plain, offset: 0, length: $start)).$style->withBold()->apply(substr($plain, $start, $end - $start)).$style->apply(substr($plain, $end));
    }

    /**
     * Draw the autocomplete popup anchored at the caret, wearing the
     * {@see PopupFrame} (rounded border + drop shadow). Returns the on-screen
     * geometry of the INNER item box so callers can hit-test mouse clicks —
     * the frame is chrome, not items; a border click falls through to caret
     * placement, which closes the popup like any beside-the-popup click.
     *
     * @param list<string>         $lines
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
        $width = min($width, PopupMetrics::COMPLETION_MAX_INNER, $columns - 2 - PopupFrame::CHROME_COLS);

        $framedHeight = \count($visible) + PopupFrame::CHROME_ROWS;
        $below = ($cursorScreenRow + 1 + $framedHeight) <= $rows;
        $borderTop = $below ? $cursorScreenRow + 1 : max(0, $cursorScreenRow - $framedHeight);

        // The content column stays anchored at the typed prefix (the border
        // sits one cell left of it), clamped so the framed box fits the pane.
        $framedWidth = $width + PopupFrame::CHROME_COLS;
        $borderCol = IntMath::clamp($anchorCol - 1, 0, $columns - $framedWidth);

        $framed = [PopupFrame::top($width, $styles)];
        foreach ($visible as $i => $item) {
            $style = $styles->of(self::rowRole($i, $selectedInWindow, null === $hovered ? null : $hovered - $windowOffset));
            $kindTag = $styles->of(Role::CompletionKind)->apply($item->kind);
            $labelWidth = $width - mb_strwidth($item->kind) - 3;
            $label = AnsiText::truncate($item->label, $labelWidth, pad: true);
            $framed[] = PopupFrame::rail($style->apply(' '.$label.' '.$kindTag.' '), $width, $styles);
        }
        $framed[] = PopupFrame::bottom($width, $styles);

        foreach ($framed as $i => $framedRow) {
            $row = $borderTop + $i;
            if ($row < 0 || $row >= \count($lines)) {
                continue;
            }
            $lines[$row] = OverlayCompositor::into($lines[$row], $borderCol, $framedWidth, $columns, $framedRow);
        }
        $lines = ModalShadow::blit($lines, $borderTop, $borderCol, $framedWidth, $framedHeight, $columns, $styles);

        return new CompletionBox($borderCol + 1, $width, $borderTop + 1, \count($visible), $windowOffset);
    }
}
