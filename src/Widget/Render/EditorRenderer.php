<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\CompletionBox;
use Porta\Editor\CompletionItem;
use Porta\Editor\DiagnosticSeverity;
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
        $line = $doc->cursorLine;
        $col = $doc->cursorCol;
        $text = $doc->getLine($line);

        // Bracket under the cursor, else just before it
        $char = $text[$col] ?? '';
        if (!\array_key_exists($char, $pairs) && !\in_array($char, $pairs, strict: true)) {
            --$col;
            $char = $col >= 0 ? $text[$col] ?? '' : '';
        }

        $isOpen = \array_key_exists($char, $pairs);
        $isClose = \in_array($char, $pairs, strict: true);
        if (!$isOpen && !$isClose) {
            return null;
        }

        $open = $isOpen ? $char : \array_search($char, $pairs, strict: true);
        $close = $pairs[$open];
        $direction = $isOpen ? 1 : -1;

        $depth = 0;
        $scanned = 0;
        $l = $line;
        $c = $col;
        $lines = $doc->lines;
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
        $line = $doc->getLine($doc->cursorLine);
        $col = $doc->cursorCol;

        $start = $col;
        while ($start > 0 && 1 === \preg_match('/[\w$]/', $line[$start - 1])) {
            --$start;
        }
        $end = $col;
        $len = \strlen($line);
        while ($end < $len && 1 === \preg_match('/\w/', $line[$end])) {
            ++$end;
        }
        if ($end <= $start) {
            return null;
        }
        $word = \substr($line, $start, $end - $start);

        return 1 === \preg_match('/^\$?[A-Za-z_]\w*$/', $word) ? $word : null;
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
        if (null === $word || \strlen(\ltrim($word, characters: '$')) < 2) {
            return [];
        }

        $pattern = '/(?<![\w$])'.\preg_quote($word, delimiter: '/').'(?!\w)/';
        $occurrences = [];
        $matches = [];
        for ($line = $fromLine; $line <= $toLine; ++$line) {
            $count = \preg_match_all($pattern, $doc->getLine($line), $matches, \PREG_OFFSET_CAPTURE);
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

            $shift += \mb_strwidth($hint['text']);
        }

        return $shift;
    }

    /**
     * Insert a line's inlay hints as dim virtual text, right-to-left so offsets stay valid.
     *
     * Each hint opens on a clean pen. It lands on a column boundary of a line
     * that is already decorated, so whatever span is open there — the
     * word-under-caret highlight, a search match, a selection — would
     * otherwise paint the hint as part of the word. The slice after it
     * replays the state still open, and the caret-line band re-asserts its
     * background after every reset ({@see LineDecorator::applyFullWidth}).
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
            $placed[] = [$doc->displayColAt($line, $hint['col']), -$i, Sgr::RESET.$styles->of(Role::Inlay)->apply($hint['text'])];
        }
        \usort($placed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $lineWidth = AnsiUtils::visibleWidth($hlLine);
        $out = '';
        $prev = 0;
        foreach ($placed as [$col, , $text]) {
            $col = \min($col, $lineWidth);
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
        $wsBytes = \strspn($raw, characters: " \t");
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
            return AnsiText::padRight(self::window($highlightedLine, $hScroll, $contentWidth).Sgr::RESET, $contentWidth);
        }

        // Slice the whole visible window in one pass (correct SGR throughout),
        // then splice the zero-width cursor marker in by byte offset. Splitting
        // the line into two independent slices at the cursor would place the cut
        // exactly where a forward selection's background closes; the vendor
        // slicer re-emits the carried style after that close, re-opening the
        // selection background for the rest of the line. A from-column-0 prefix
        // slice ("slicePrefix") carries no such state and is a literal byte
        // prefix, so the marker lands cleanly without disturbing any styling.
        $text = self::window($highlightedLine, $hScroll, $contentWidth);
        $cursorOffset = \max(0, $cursorDisplayCol - $hScroll);
        $byte = \strlen(AnsiUtils::sliceByColumn($text, 0, $cursorOffset));
        $withCursorText = \substr($text, offset: 0, length: $byte).$cursorMarker.\substr($text, $byte);

        return AnsiText::padRight($withCursorText.Sgr::RESET, $contentWidth);
    }

    /**
     * The $width columns of $line from $start. `sliceWithWidth()` keeps a wide
     * glyph that straddles either edge: on the right the row comes out one
     * column too wide, which the Renderer refuses with a RenderException that
     * ends the session; on the left the glyph is dropped and the rest shifts a
     * column away from the caret. `sliceToWidth()` blanks the cut half instead,
     * but walks every cell: ~4x the cost on a highlighted line (29 vs 7 us,
     * measured), and the indent guide alone makes most lines non-ASCII. So the
     * fast slice stays wherever it is right: unscrolled, or on a pure-ASCII
     * line, it can only go wrong by reporting more columns than asked. The slow
     * one is asked for the line's own columns only, because past them it pads
     * INSIDE the slice, before the caller's reset, and the current-line
     * background that paints the padding would stop at the text.
     */
    private static function window(string $line, int $start, int $width): string
    {
        if (0 === $start || 1 !== \preg_match('/[\x80-\xFF]/', $line)) {
            $fast = AnsiUtils::sliceWithWidth($line, $start, $width);
            if ($fast['width'] <= $width) {
                return $fast['text'];
            }
        }

        return AnsiUtils::sliceToWidth($line, $start, \min($width, AnsiUtils::visibleWidth($line) - $start));
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
     * @param list<array{message?: string, severity?: DiagnosticSeverity, col?: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}> $diags
     */
    public static function appendInlineDiagnostic(int $hScroll, int $textDisplayWidth, array $diags, string $content, int $contentWidth, Styles $styles): string
    {
        // Show the worst-severity diagnostic's message (the first one at that
        // severity); an empty list falls through to the empty-message guard below.
        $worst = DiagnosticStyle::worst($diags);
        $message = '';
        foreach ($diags as $diag) {
            if (($diag['severity'] ?? DiagnosticSeverity::Error) !== $worst) {
                continue;
            }

            $message = $diag['message'] ?? '';
            break;
        }
        if ('' === $message) {
            return $content;
        }

        // Where the decorated text ends on screen (accounting for horizontal scroll).
        $textEnd = \max(0, $textDisplayWidth - $hScroll);
        $start = \min($contentWidth, $textEnd) + 2; // two-space gap
        $available = $contentWidth - $start;
        if ($available < 8) {
            return $content; // not enough room to be useful
        }

        $extra = \count($diags) > 1 ? ' +'.(\count($diags) - 1) : '';
        // Severity glyph prefix, so error vs warning reads without color.
        $label = AnsiText::truncate('‹ '.$worst->glyph().' '.(string) \preg_replace('/\s+/', replacement: ' ', subject: $message).$extra.' ›', $available);
        $head = AnsiText::padRight(AnsiUtils::sliceByColumn($content, 0, $start), $start);

        return AnsiText::padRight($head.$styles->of($worst->dimRole())->apply($label), $contentWidth);
    }

    /**
     * Append a dim informational annotation (e.g. a blame line) in the padding
     * after the code — the same placement as {@see appendInlineDiagnostic},
     * which wins the slot when both apply. $textDisplayWidth is the decorated
     * line's on-screen width (see appendInlineDiagnostic).
     */
    public static function appendAnnotation(int $hScroll, int $textDisplayWidth, string $text, string $content, int $contentWidth, Styles $styles, Role $role = Role::Inlay): string
    {
        $textEnd = \max(0, $textDisplayWidth - $hScroll);
        $start = \min($contentWidth, $textEnd) + 2; // two-space gap
        $available = $contentWidth - $start;
        if ($available < 8) {
            return $content; // not enough room to be useful
        }

        $label = AnsiText::truncate((string) \preg_replace('/\s+/', replacement: ' ', subject: $text), $available);
        $head = AnsiText::padRight(AnsiUtils::sliceByColumn($content, 0, $start), $start);

        return AnsiText::padRight($head.$styles->of($role)->apply($label), $contentWidth);
    }

    // --- Overlays ----------------------------------------------------------

    private const int POPUP_MAX_ITEMS = PopupMetrics::COMPLETION_ROWS;

    /**
     * Draw the autocomplete popup anchored at the caret, in the heavy frame of
     * a list that holds the keys (the open select's `┏━┓`) plus a drop shadow.
     * Returns the on-screen geometry of the INNER item box so callers can
     * hit-test mouse clicks, and the frame and docblock pane so a click there
     * is swallowed rather than placing the caret in the code beneath.
     *
     * Its shapes read without colour: the selected row's `▌`, a deprecated
     * item struck through, a `▲`/`▼` in the border when the list goes on
     * past what is shown.
     *
     * @param list<string>         $lines
     * @param list<CompletionItem> $items filtered
     */
    public static function completionPopup(
        array &$lines,
        int $columns,
        int $rows,
        int $cursorScreenRow,
        int $anchorCol,
        array $items,
        int $selected,
        ?int $hovered,
        Styles $styles,
        int $docScroll = 0,
    ): CompletionBox {
        // Below the caret when the whole list fits there, else above when it
        // fits there; when it fits neither, the roomier side, with the list
        // cut to the room — never over the line being typed.
        $roomBelow = $rows - $cursorScreenRow - 1 - PopupFrame::CHROME_ROWS;
        $roomAbove = $cursorScreenRow - PopupFrame::CHROME_ROWS;
        $wanted = \min(\count($items), self::POPUP_MAX_ITEMS);
        $below = $roomBelow >= $wanted || $roomAbove < $wanted && $roomBelow >= $roomAbove;
        $shown = \max(1, \min($wanted, $below ? $roomBelow : $roomAbove));

        $windowOffset = \max(0, $selected - $shown + 1);
        $visible = \array_slice($items, $windowOffset, $shown);
        $selectedInWindow = $selected - $windowOffset;

        $width = 12;
        foreach ($visible as $item) {
            $width = \max($width, \mb_strwidth($item->label) + \mb_strwidth($item->kind) + 5);
            // The server's `detail` — a signature for a method, a type for a
            // property — asked for but never drawn until now. Guarded on the
            // detail being there at all: the extra column this term adds is the
            // SEPARATOR, and an item with no type needs no separator. Without
            // the guard every popup in the IDE grew by one column.
            if ('' !== $item->detail) {
                $width = \max($width, \mb_strwidth($item->label) + \mb_strwidth($item->detail) + \mb_strwidth($item->kind) + 6);
            }
        }
        $width = \min($width, PopupMetrics::COMPLETION_MAX_INNER, $columns - 2 - PopupFrame::CHROME_COLS);

        $framedHeight = \count($visible) + PopupFrame::CHROME_ROWS;
        $borderTop = $below ? $cursorScreenRow + 1 : \max(0, $cursorScreenRow - $framedHeight);

        // The content column stays anchored at the typed prefix (the border
        // sits one cell left of it), clamped so the framed box fits the pane.
        $framedWidth = $width + PopupFrame::CHROME_COLS;
        $borderCol = IntMath::clamp($anchorCol - 1, 0, $columns - $framedWidth);

        $heavy = FieldState::Focused;
        $framed = [PopupFrame::top($width, $styles, $heavy, $windowOffset > 0 ? Glyphs::SCROLL_UP : '')];
        foreach ($visible as $i => $item) {
            $style = $styles->of(self::rowRole($i, $selectedInWindow, null === $hovered ? null : $hovered - $windowOffset));
            $kindTag = $styles->of(Role::CompletionKind)->apply($item->kind);
            // The label column absorbs the slack, so the kind tag stays flush
            // right whatever the item is called; the detail is what is left
            // between them, which puts it flush right against the kind.
            $avail = $width - \mb_strwidth($item->kind) - 3;
            // The NAME is served first and the type takes what is left: a
            // completion whose label you cannot read is not a completion, while
            // the type is what you check once the name is already the one you
            // meant. At a narrow width the type disappears rather than the name.
            $detailWidth = \min(\mb_strwidth($item->detail), \max(0, $avail - \min(\mb_strwidth($item->label), $avail) - 1));
            $detail = 0 === $detailWidth
                ? ''
                // No pad: $detailWidth is capped BY the detail's own width, so
                // the truncation always fills the cell exactly and padding
                // could never do anything.
                : ' '.$styles->of(Role::CompletionHint)->apply(AnsiText::truncate($item->detail, $detailWidth));
            $label = AnsiText::truncate($item->label, $avail - $detailWidth - (0 === $detailWidth ? 0 : 1), pad: true);
            if ($item->deprecated) {
                // Still offered, marked as going: dim and struck through,
                // attributes that survive --no-ansi.
                $label = $style->withDim()->withStrikethrough()->apply(\rtrim($label)).$style->getAnsiRestore().\str_repeat(' ', \max(0, \mb_strwidth($label) - \mb_strwidth(\rtrim($label))));
            }
            $bar = SelectionBar::of($i === $selectedInWindow, focused: true)->value;
            $framed[] = PopupFrame::rail($style->apply($bar.$label.$detail.' '.$kindTag.' '), $width, $styles, $heavy);
        }
        $framed[] = PopupFrame::bottom($width, $styles, $heavy, ($windowOffset + \count($visible)) < \count($items) ? Glyphs::SCROLL_DOWN : '');

        foreach ($framed as $i => $framedRow) {
            $row = $borderTop + $i;
            if ($row < 0 || $row >= \count($lines)) {
                continue;
            }
            $lines[$row] = OverlayCompositor::into($lines[$row], $borderCol, $framedWidth, $columns, $framedRow);
        }
        $lines = ModalShadow::blit($lines, $borderTop, $borderCol, $framedWidth, $framedHeight, $columns, $styles);
        $doc = self::completionDoc($lines, $columns, $borderCol, $borderTop, $framedWidth, $framedHeight, $below, $visible[$selectedInWindow] ?? null, $styles, $docScroll);

        return new CompletionBox($borderCol + 1, $width, $borderTop + 1, \count($visible), $windowOffset, $doc['rect'] ?? null, $doc['maxScroll'] ?? 0);
    }

    /**
     * The selected item's docblock, in its own frame beside the popup.
     *
     * Both bundled servers send `documentation` and neither sends it with the
     * completion — it arrives on `completionItem/resolve`, the same round-trip
     * the type column already makes — so this costs no extra traffic.
     *
     * Beside, not below: the popup already sits under the caret, and a panel
     * under IT would be reading a docblock through a keyhole two rows from the
     * bottom of the terminal. It goes right of the popup when there is room and
     * left when there is not; when neither fits, there is no panel, which is
     * the correct amount of a docblock to show in twenty columns.
     *
     * Its height is its OWN, not the popup's: two matching completions must not
     * mean two lines of docblock. It grows down from the popup's top, or up
     * from its bottom when the popup itself opened upward — the caret is on the
     * other side of that edge, and a panel that grew the wrong way would cover
     * the line being typed.
     *
     * The text is what fits, scrolled $docScroll wrapped rows in (Ctrl+↓/↑);
     * a `▲`/`▼` in its border says there is more above or below.
     *
     * @param list<string> $lines
     *
     * @return array{rect: array{int, int, int, int}, maxScroll: int}|null the pane as drawn — [top, col, width, height] — and how far it can scroll; null when none is
     */
    private static function completionDoc(
        array &$lines,
        int $columns,
        int $popupCol,
        int $popupTop,
        int $popupWidth,
        int $popupHeight,
        // @mago-expect lint:no-boolean-flag-parameter -- not a mode switch: this is the
        // popup's own placement, computed once by the caller and passed down so the panel
        // grows AWAY from the caret instead of re-deriving geometry that has moved on.
        bool $below,
        ?CompletionItem $selected,
        Styles $styles,
        int $docScroll = 0,
    ): ?array {
        $text = null === $selected ? '' : $selected->documentation;
        if ('' === $text) {
            return null;
        }
        $framedWidth = PopupMetrics::COMPLETION_DOC_INNER + PopupFrame::CHROME_COLS;
        $right = $popupCol + $popupWidth;
        // A column of clearance on the left placement, because the panel's own
        // drop shadow falls to ITS right: sat flush against the popup it paints
        // over the popup's left border, which reads as a box missing a wall.
        $col = match (true) {
            ($right + $framedWidth) <= $columns => $right,
            ($popupCol - $framedWidth - 1) >= 0 => $popupCol - $framedWidth - 1,
            default => null,
        };
        if (null === $col) {
            return null;
        }

        $body = [];
        foreach (\explode("\n", $text) as $paragraph) {
            // Padded a column either side, as every box pads its content.
            foreach (AnsiText::wrap($paragraph, PopupMetrics::COMPLETION_DOC_INNER - 2) as $wrapped) {
                $body[] = $wrapped;
            }
        }
        // Down from the popup's top, or up from its bottom when the popup
        // opened upward — the caret is on the other side of that edge, and a
        // panel growing the wrong way would cover the line being typed.
        //
        // The room in that direction is what caps the height, BEFORE the text
        // is cut to fit: clamping the position instead would slide the panel
        // back over the caret whenever the docblock was taller than the room.
        // Counted off the rows themselves rather than the pane height: they are
        // the thing being written into, and deriving the room from anything
        // else leaves a gap where a row could land past the end of the array.
        $room = $below ? \count($lines) - $popupTop : $popupTop + $popupHeight;
        $capacity = \min(self::POPUP_MAX_ITEMS, $room - PopupFrame::CHROME_ROWS);
        if ($capacity < 1) {
            return null; // a frame with no line of the docblock in it says nothing
        }
        $maxScroll = \max(0, \count($body) - $capacity);
        $scroll = \min($docScroll, $maxScroll);
        $body = \array_slice($body, offset: $scroll, length: $capacity);
        $framedHeight = \count($body) + PopupFrame::CHROME_ROWS;
        $top = $below ? $popupTop : $popupTop + $popupHeight - $framedHeight;

        $framed = [PopupFrame::top(PopupMetrics::COMPLETION_DOC_INNER, $styles, mark: $scroll > 0 ? Glyphs::SCROLL_UP : '')];
        foreach ($body as $row) {
            // Truncated but not padded: {@see PopupFrame::rail()} pads to the
            // inner width itself — it calls that the single place the invariant
            // is enforced — and a second pad here could only ever agree with it.
            $framed[] = PopupFrame::rail(
                $styles->of(Role::CompletionHint)->apply(AnsiText::truncate(' '.$row, PopupMetrics::COMPLETION_DOC_INNER)),
                PopupMetrics::COMPLETION_DOC_INNER,
                $styles,
            );
        }
        $framed[] = PopupFrame::bottom(PopupMetrics::COMPLETION_DOC_INNER, $styles, mark: $scroll < $maxScroll ? Glyphs::SCROLL_DOWN : '');

        // No bounds guard: the height was capped by the room above, so every
        // row of this frame is inside the array by construction — below,
        // `$top + $framedHeight <= count($lines)`; above, it is the popup's own
        // bottom edge, and the popup was placed inside the pane.
        foreach ($framed as $i => $framedRow) {
            $lines[$top + $i] = OverlayCompositor::into($lines[$top + $i], $col, $framedWidth, $columns, $framedRow);
        }
        $lines = ModalShadow::blit($lines, $top, $col, $framedWidth, $framedHeight, $columns, $styles);

        return ['rect' => [$top, $col, $framedWidth, $framedHeight], 'maxScroll' => $maxScroll];
    }
}
