<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Support\IntMath;

/**
 * Multi-line text buffer with cursor, undo/redo and change tracking.
 *
 * Lines are stored without trailing newlines. The cursor column is a byte
 * offset into the current line; movement helpers keep it on UTF-8 character
 * boundaries. Tabs are preserved verbatim; display columns are computed with
 * tab stops from the {@see IndentModel} (seeded from {@see self::TAB_SIZE}, and
 * overridable per file via {@see applyEditorConfig()}) so a file's original
 * indentation round-trips through load and save unchanged.
 */
final class Document
{
    public const TAB_SIZE = 4;

    /** @var string[] */
    private array $lines = [''];
    private int $cursorLine = 0;
    private int $cursorCol = 0;
    private int $version = 1;
    private bool $dirty = false;

    /** Tabs-vs-spaces, indent size and tab width for this file. */
    private readonly IndentModel $indent;

    /** Byte/UTF-16/display-column conversions + the tab-expanded visual-line cache. */
    private readonly PositionMapper $positionMapper;

    /** Preferred display column kept while moving vertically. */
    private ?int $goalCol = null;

    /** The primary selection's anchor (the selection is anchor..cursor) and its range math. */
    private readonly SelectionModel $selection;

    /** Undo/redo stacks, coalescing and compound grouping. */
    private readonly UndoHistory $history;

    /**
     * Secondary-caret state lives on the widget ({@see \Porta\Editor\MultiCaret});
     * these hooks let a snapshot capture/restore it so undo brings the carets back.
     * $captureCarets returns the current extra carets; $restoreCarets applies them.
     *
     * Design note: this is a deliberate model→widget back-reference. Inverting it
     * (e.g. a snapshot interface implemented by MultiCaret) was considered and
     * rejected — undo/redo regressions are the costliest failure class here and
     * the closures already keep the coupling to two narrow, optional hooks.
     *
     * @var (\Closure(): list<Caret>)|null
     */
    private ?\Closure $captureCarets = null;
    /** @var (\Closure(list<Caret>): void)|null */
    private ?\Closure $restoreCarets = null;

    /**
     * Caret state captured when the outermost compound opens — before MultiCaret
     * moves the cursor across carets — so the compound's single snapshot records
     * the true pre-edit selection and caret set.
     *
     * @var array{line: int, col: int, anchor: array{line: int, col: int}|null, extras: list<Caret>}|null
     */
    private ?array $compoundCaret = null;

    /** Text as of the last save/load, for "undo back to saved state clears dirty". */
    private string $savedText = '';
    private int $dirtyCheckedVersion = 0;

    public function __construct(string $text = '')
    {
        $text = str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
        $this->lines = '' === $text ? [''] : explode("\n", $text);
        $this->savedText = implode("\n", $this->lines);
        $this->indent = new IndentModel($this->detectTabIndentation(), self::TAB_SIZE, self::TAB_SIZE);
        $this->selection = new SelectionModel();
        $this->history = new UndoHistory();
        $this->positionMapper = new PositionMapper($this);
    }

    /**
     * Register hooks so undo/redo snapshots carry the widget-owned secondary
     * carets. Both are optional (the Document works standalone in tests without them).
     *
     * @param (\Closure(): list<Caret>)|null      $capture
     * @param (\Closure(list<Caret>): void)|null  $restore
     */
    public function setCaretHooks(?\Closure $capture, ?\Closure $restore): void
    {
        $this->captureCarets = $capture;
        $this->restoreCarets = $restore;
    }

    /**
     * A file is "tab-indented" when the first line that begins with whitespace
     * begins with a tab. Used to pick the unit inserted by Tab and auto-indent.
     */
    private function detectTabIndentation(): bool
    {
        foreach ($this->lines as $line) {
            if (str_starts_with($line, "\t")) {
                return true;
            }
            if (str_starts_with($line, ' ')) {
                return false;
            }
        }

        return false;
    }

    public function usesTabs(): bool
    {
        return $this->indent->usesTabs();
    }

    /**
     * Force the effective indentation (tabs vs spaces and its size). Used by the
     * editor's per-language indent settings, which win over auto-detection and
     * `.editorconfig`. Invalidates the tab-expansion cache.
     */
    public function setIndent(IndentKind $kind, int $size): void
    {
        $this->indent->set($kind, $size);
        $this->positionMapper->invalidateVisualLines();
    }

    /** The indentation unit for one level: a tab, or {@see IndentModel} spaces. */
    public function indentUnit(): string
    {
        return $this->indent->unit();
    }

    /** Spaces per indent level (EditorConfig indent_size). */
    public function indentSize(): int
    {
        return $this->indent->indentSize();
    }

    /** Display columns per hard tab (EditorConfig tab_width). */
    public function tabWidth(): int
    {
        return $this->indent->tabWidth();
    }

    /** Display width of one indent level: the tab width for tabbed files, else the indent size. */
    public function indentDisplayWidth(): int
    {
        return $this->indent->displayWidth();
    }

    /**
     * Override indentation/save defaults from a resolved `.editorconfig`.
     * Invalidates the tab-expansion cache so the next render reflects a changed
     * tab width.
     */
    public function applyEditorConfig(ResolvedEditorConfig $config): void
    {
        $this->indent->applyEditorConfig($config);
        $this->positionMapper->invalidateVisualLines();
    }

    // --- Accessors -------------------------------------------------------

    /** @return string[] */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getLine(int $index): string
    {
        return $this->lines[$index] ?? '';
    }

    public function getLineCount(): int
    {
        return \count($this->lines);
    }

    public function getText(): string
    {
        return implode("\n", $this->lines);
    }

    public function getCursorLine(): int
    {
        return $this->cursorLine;
    }

    public function getCursorCol(): int
    {
        return $this->cursorCol;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isDirty(): bool
    {
        if (!$this->dirty) {
            return false;
        }
        // The dirty flag is set eagerly by every mutation (incl. undo/redo), but
        // undoing back to the saved content should read as clean. Reconcile against
        // the saved text at most once per version (cheap for the status bar, which
        // polls isDirty every frame without editing).
        if ($this->version !== $this->dirtyCheckedVersion) {
            $this->dirtyCheckedVersion = $this->version;
            if ($this->getText() === $this->savedText) {
                $this->dirty = false;
            }
        }

        return $this->dirty;
    }

    public function markSaved(): void
    {
        $this->dirty = false;
        $this->savedText = $this->getText();
        // Make the save a coalescing boundary so a later undo stops exactly here.
        $this->history->checkpoint();
    }

    /** Clamp a line index into the document's bounds. */
    public function clampLine(int $line): int
    {
        return IntMath::clamp($line, 0, \count($this->lines) - 1);
    }

    /** Clamp a byte column into the given line's bounds (the line is clamped too). */
    public function clampCol(int $line, int $col): int
    {
        return IntMath::clamp($col, 0, \strlen($this->lines[$this->clampLine($line)]));
    }

    /** Byte column → UTF-16 code-unit column on the given line (for LSP positions). */
    public function byteColToUtf16(int $line, int $byteCol): int
    {
        return $this->positionMapper->byteColToUtf16($line, $byteCol);
    }

    /**
     * Cursor column as UTF-16 code units, as required by LSP positions.
     */
    public function getCursorColUtf16(): int
    {
        return $this->positionMapper->byteColToUtf16($this->cursorLine, $this->cursorCol);
    }

    /**
     * Convert a UTF-16 code-unit column (LSP position) on a line to a byte offset.
     */
    public function utf16ColToByteCol(int $line, int $utf16Col): int
    {
        return $this->positionMapper->utf16ColToByteCol($line, $utf16Col);
    }

    /**
     * Display column of the cursor (terminal cells before the cursor).
     */
    public function getCursorDisplayCol(): int
    {
        return $this->positionMapper->displayColAt($this->cursorLine, $this->cursorCol);
    }

    /** Display column (terminal cells) of a byte offset on the given line. */
    public function displayColAt(int $line, int $byteCol): int
    {
        return $this->positionMapper->displayColAt($line, $byteCol);
    }

    /** Full display width (terminal cells) of a line. */
    public function lineDisplayWidth(int $line): int
    {
        return $this->positionMapper->lineDisplayWidth($line);
    }

    /**
     * The document's lines with tabs expanded to spaces at tab stops, for the
     * highlighter and renderer. Cached per version; lines without tabs are
     * returned unchanged so tab-free files are untouched.
     *
     * @return string[]
     */
    public function getVisualLines(): array
    {
        return $this->positionMapper->visualLines();
    }

    /**
     * Byte offset of a (line, col) position within the full text (lines joined
     * by "\n"). Clamps out-of-range positions to the document bounds.
     */
    public function offsetAt(int $line, int $col): int
    {
        return $this->positionMapper->offsetAt($line, $col);
    }

    /**
     * The (line, col) position of a byte offset within the full text — the
     * inverse of {@see offsetAt()}. Clamps to the document bounds.
     *
     * @return array{line: int, col: int}
     */
    public function positionAt(int $offset): array
    {
        return $this->positionMapper->positionAt($offset);
    }

    /**
     * The identifier-ish word fragment immediately before the cursor.
     * Includes '$' so PHP variables filter correctly.
     */
    public function getWordBeforeCursor(): string
    {
        $prefix = substr($this->lines[$this->cursorLine], offset: 0, length: $this->cursorCol);
        $m = [];
        if (preg_match('/[$\w]+$/u', $prefix, $m)) {
            return $m[0];
        }

        return '';
    }

    // --- Selection ----------------------------------------------------------

    /**
     * Set the anchor at the cursor if no selection is active. Call before
     * moving the cursor for a shift+movement ("select") action.
     */
    public function beginSelect(): void
    {
        $this->selection->begin($this->cursorLine, $this->cursorCol);
    }

    public function clearSelection(): void
    {
        $this->selection->clear();
    }

    public function hasSelection(): bool
    {
        return null !== $this->getSelectionRange();
    }

    /**
     * Normalized selection range, or null when empty.
     *
     * @return array{startLine: int, startCol: int, endLine: int, endCol: int}|null
     */
    public function getSelectionRange(): ?array
    {
        return $this->selection->range($this->cursorLine, $this->cursorCol);
    }

    public function getSelectedText(): string
    {
        $range = $this->getSelectionRange();

        return null === $range
            ? ''
            : $this->getTextInRange($range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']);
    }

    /**
     * The text between two ordered byte positions, lines joined by "\n".
     * Shared by the primary selection and each extra caret's selection.
     */
    public function getTextInRange(int $startLine, int $startCol, int $endLine, int $endCol): string
    {
        if ($startLine === $endLine) {
            return substr($this->lines[$startLine], $startCol, $endCol - $startCol);
        }

        $parts = [substr($this->lines[$startLine], $startCol)];
        for ($i = $startLine + 1; $i < $endLine; ++$i) {
            $parts[] = $this->lines[$i];
        }
        $parts[] = substr($this->lines[$endLine], offset: 0, length: $endCol);

        return implode("\n", $parts);
    }

    public function selectAll(): void
    {
        $this->selection->set(0, 0);
        $this->cursorLine = \count($this->lines) - 1;
        $this->cursorCol = \strlen($this->lines[$this->cursorLine]);
        $this->goalCol = null;
    }

    /**
     * The byte range of the word at ($line, $col) — the run of `[\w$]` characters
     * containing or adjacent to the column — for double-click word selection.
     * Returns null when the column is not on a word character.
     *
     * @return array{startCol: int, endCol: int}|null
     */
    public function wordRangeAt(int $line, int $col): ?array
    {
        $text = $this->lines[$line] ?? '';
        $len = \strlen($text);
        $col = IntMath::clamp($col, 0, $len);

        $start = $col;
        while ($start > 0 && 1 === preg_match('/[\w$]/', $text[$start - 1])) {
            --$start;
        }
        $end = $col;
        while ($end < $len && 1 === preg_match('/[\w$]/', $text[$end])) {
            ++$end;
        }

        return $end > $start ? ['startCol' => $start, 'endCol' => $end] : null;
    }

    public function deleteSelection(): bool
    {
        if (null === $this->getSelectionRange()) {
            $this->selection->clear();

            return false;
        }
        $this->pushUndo();
        $this->removeSelectedText();
        $this->touch();

        return true;
    }

    private function removeSelectedText(): void
    {
        $range = $this->getSelectionRange();
        if (null === $range) {
            return;
        }
        $head = substr($this->lines[$range['startLine']], offset: 0, length: $range['startCol']);
        $tail = substr($this->lines[$range['endLine']], $range['endCol']);
        array_splice($this->lines, $range['startLine'], $range['endLine'] - $range['startLine'] + 1, [$head.$tail]);
        $this->cursorLine = $range['startLine'];
        $this->cursorCol = $range['startCol'];
        $this->selection->clear();
    }

    // --- Line operations ------------------------------------------------------

    /**
     * Duplicate the current line, or the whole lines covered by the
     * selection, below the block. Cursor moves onto the duplicate.
     */
    public function duplicateLines(): void
    {
        $this->pushUndo();
        [$first, $last] = $this->selectedLineSpan();
        $block = \array_slice($this->lines, $first, $last - $first + 1);
        array_splice($this->lines, $last + 1, length: 0, replacement: $block);
        $offset = $last - $first + 1;
        $this->cursorLine += $offset;
        $this->selection->shiftLine($offset);
        $this->touch();
    }

    /**
     * Delete the current line or all lines covered by the selection.
     */
    public function deleteLines(): bool
    {
        [$first, $last] = $this->selectedLineSpan();
        if (1 === \count($this->lines) && '' === $this->lines[0]) {
            return false;
        }
        $this->pushUndo();
        array_splice($this->lines, $first, $last - $first + 1);
        if ([] === $this->lines) {
            $this->lines = [''];
        }
        $this->selection->clear();
        $this->cursorLine = min($first, \count($this->lines) - 1);
        $this->cursorCol = min($this->cursorCol, \strlen($this->lines[$this->cursorLine]));
        $this->touch();

        return true;
    }

    /**
     * Move the current line or selected block up (-1) or down (+1).
     */
    public function moveLines(int $delta): bool
    {
        [$first, $last] = $this->selectedLineSpan();
        $target = $first + $delta;
        if ($target < 0 || ($last + $delta) >= \count($this->lines)) {
            return false;
        }
        $this->relocateBlock($first, $last, $target);

        return true;
    }

    /**
     * Move the current line or selected block up (-1) or down (+1) like
     * {@see moveLines()}, but hop over an adjacent run of blank lines in one
     * step so a statement jumps a whitespace gap to sit next to its neighbour.
     */
    public function moveStatement(int $delta): bool
    {
        [$first, $last] = $this->selectedLineSpan();
        $target = $this->statementTargetLine($delta, $first, $last);
        if (null === $target) {
            return false;
        }

        $this->relocateBlock($first, $last, $target);

        return true;
    }

    /**
     * Lift the [$first, $last] line block out and reinsert it starting at
     * $target, moving the cursor and selection by the same shift. One undo
     * step. Shared by {@see moveLines()} and {@see moveStatement()}.
     */
    private function relocateBlock(int $first, int $last, int $target): void
    {
        $shift = $target - $first;
        $this->pushUndo();
        $block = \array_slice($this->lines, $first, $last - $first + 1);
        array_splice($this->lines, $first, $last - $first + 1);
        array_splice($this->lines, $target, length: 0, replacement: $block);
        $this->cursorLine += $shift;
        $this->selection->shiftLine($shift);
        $this->touch();
    }

    /**
     * Sort the selected line block ascending (byte order). A no-op — returning
     * false — when fewer than two lines are covered or they are already sorted.
     */
    public function sortLines(): bool
    {
        [$first, $last] = $this->selectedLineSpan();
        if ($last <= $first) {
            return false;
        }
        $block = \array_slice($this->lines, $first, $last - $first + 1);
        $sorted = $block;
        sort($sorted, \SORT_STRING);
        if ($sorted === $block) {
            return false;
        }

        return $this->replaceLineBlock($first, \count($block), $sorted);
    }

    /**
     * Join the selected line block into one line (single space between the
     * trimmed pieces); with no multi-line selection, join the current line with
     * the one below. False when there is no line below to join.
     */
    public function joinLines(): bool
    {
        [$first, $last] = $this->selectedLineSpan();
        if ($first === $last) {
            if ($first + 1 >= \count($this->lines)) {
                return false;
            }
            $last = $first + 1;
        }
        $joined = rtrim($this->lines[$first]);
        for ($i = $first + 1; $i <= $last; ++$i) {
            $joined = rtrim($joined.' '.ltrim($this->lines[$i]));
        }

        return $this->replaceLineBlock($first, $last - $first + 1, [$joined]);
    }

    /**
     * Toggle the case of the selected line block: all-uppercase content becomes
     * lowercase, otherwise uppercase. False when there is no cased letter to
     * flip.
     */
    public function toggleCaseLines(): bool
    {
        [$first, $last] = $this->selectedLineSpan();
        $block = \array_slice($this->lines, $first, $last - $first + 1);
        $joined = implode("\n", $block);
        $upper = mb_strtoupper($joined);
        $transformed = $joined === $upper ? mb_strtolower($joined) : $upper;
        if ($transformed === $joined) {
            return false;
        }

        return $this->replaceLineBlock($first, \count($block), explode("\n", $transformed));
    }

    /**
     * Replace $length lines starting at $first with $replacement in one undo
     * step, clearing the selection and clamping the caret into the result.
     * Shared by {@see sortLines()}, {@see joinLines()} and {@see toggleCaseLines()}.
     *
     * @param list<string> $replacement
     */
    private function replaceLineBlock(int $first, int $length, array $replacement): bool
    {
        $this->pushUndo();
        array_splice($this->lines, $first, $length, $replacement);
        $this->selection->clear();
        $this->cursorLine = min($this->cursorLine, \count($this->lines) - 1);
        $this->cursorCol = min($this->cursorCol, \strlen($this->lines[$this->cursorLine]));
        $this->touch();

        return true;
    }

    /**
     * The destination line for {@see moveStatement()} in direction $delta,
     * hopping a run of blank lines in one step, or null when the block is
     * already at the edge and cannot move.
     */
    private function statementTargetLine(int $delta, int $first, int $last): ?int
    {
        $n = \count($this->lines);
        if ($delta < 0) {
            if (0 === $first) {
                return null;
            }
            $step = 1;
            if ('' === trim($this->lines[$first - 1])) {
                $g = $first - 1;
                while ($g > 0 && '' === trim($this->lines[$g - 1])) {
                    --$g;
                }
                $step = $first - $g;
            }

            return max(0, $first - $step);
        }
        if ($last === ($n - 1)) {
            return null;
        }
        $step = 1;
        if ('' === trim($this->lines[$last + 1])) {
            $g = $last + 1;
            while ($g < ($n - 1) && '' === trim($this->lines[$g + 1])) {
                ++$g;
            }
            $step = $g - $last;
        }

        return min($first + $step, $first + ($n - 1 - $last));
    }

    /**
     * Toggle a line comment prefix on the current line or selected block.
     */
    public function toggleComment(string $prefix): void
    {
        $this->pushUndo();
        [$first, $last] = $this->selectedLineSpan();

        $allCommented = true;
        for ($i = $first; $i <= $last; ++$i) {
            $trimmed = ltrim($this->lines[$i]);
            if ('' !== $trimmed && !str_starts_with($trimmed, $prefix)) {
                $allCommented = false;
                break;
            }
        }

        for ($i = $first; $i <= $last; ++$i) {
            $line = $this->lines[$i];
            $trimmed = ltrim($line);
            if ('' === $trimmed) {
                continue;
            }
            $indent = \strlen($line) - \strlen($trimmed);
            if ($allCommented) {
                $rest = substr($trimmed, \strlen($prefix));
                $this->lines[$i] = substr($line, offset: 0, length: $indent).ltrim($rest, characters: ' ');

                continue;
            }
            $this->lines[$i] = substr($line, offset: 0, length: $indent).$prefix.' '.$trimmed;
        }
        $this->cursorCol = min($this->cursorCol, \strlen($this->lines[$this->cursorLine]));
        $this->touch();
    }

    /**
     * Indent every line the selection (or cursor line) spans by one level,
     * preserving the selection. Blank lines are left empty. One undo step.
     */
    public function indentSelection(): void
    {
        $this->pushUndo();
        [$first, $last] = $this->selectedLineSpan();
        $unit = $this->indentUnit();
        $width = \strlen($unit);

        $cursorDelta = 0;
        $anchorDelta = 0;
        for ($i = $first; $i <= $last; ++$i) {
            if ('' === $this->lines[$i]) {
                continue; // leave blank lines empty
            }
            $this->lines[$i] = $unit.$this->lines[$i];
            // Text inserted at column 0 pushes a mid-line column right; a column
            // at 0 stays 0 so the new indent falls inside the selection.
            if ($i === $this->cursorLine && $this->cursorCol > 0) {
                $cursorDelta = $width;
            }
            if ($i === $this->selection->anchorLine() && ($this->selection->anchorCol() ?? 0) > 0) {
                $anchorDelta = $width;
            }
        }

        $this->cursorCol += $cursorDelta;
        $this->selection->shiftColClamped($anchorDelta);
        $this->touch();
    }

    /**
     * Outdent every line the selection (or cursor line) spans by one level:
     * a leading tab, else up to {@see indentSize()} leading spaces. Preserves the
     * selection, clamped at the left margin. One undo step.
     */
    public function dedentSelection(): void
    {
        $this->pushUndo();
        [$first, $last] = $this->selectedLineSpan();
        $size = $this->indentSize();

        $cursorRemoved = 0;
        $anchorRemoved = 0;
        for ($i = $first; $i <= $last; ++$i) {
            $line = $this->lines[$i];
            $removed = str_starts_with($line, "\t")
                ? 1
                : min($size, \strlen($line) - \strlen(ltrim($line, characters: ' ')));
            if (0 === $removed) {
                continue;
            }
            $this->lines[$i] = substr($line, $removed);
            if ($i === $this->cursorLine) {
                $cursorRemoved = $removed;
            }
            if ($i === $this->selection->anchorLine()) {
                $anchorRemoved = $removed;
            }
        }

        $this->cursorCol = max(0, $this->cursorCol - $cursorRemoved);
        $this->selection->shiftColClamped(-$anchorRemoved);
        $this->touch();
    }

    /** @return array{0: int, 1: int} first and last line covered by selection or cursor */
    private function selectedLineSpan(): array
    {
        $range = $this->getSelectionRange();
        if (null === $range) {
            return [$this->cursorLine, $this->cursorLine];
        }
        $last = $range['endLine'];
        // A selection ending at column 0 does not include that line
        if ($last > $range['startLine'] && 0 === $range['endCol']) {
            --$last;
        }

        return [$range['startLine'], $last];
    }

    // --- Cursor movement --------------------------------------------------

    /**
     * Byte offset on $line for a given display column (terminal cell).
     * Public wrapper used by mouse click-to-cursor.
     */
    public function byteColForDisplayCol(int $line, int $displayCol): int
    {
        return $this->positionMapper->byteColForDisplayCol($line, $displayCol);
    }

    /**
     * Set an explicit selection from ($startLine,$startCol) as anchor to
     * ($endLine,$endCol) as the caret. Columns are byte offsets. Used by the
     * structural "expand selection" feature.
     */
    public function setSelectionRange(int $startLine, int $startCol, int $endLine, int $endCol): void
    {
        $this->selection->set($startLine, $startCol);
        $this->cursorLine = $this->clampLine($endLine);
        $this->cursorCol = $this->clampCol($this->cursorLine, $endCol);
        $this->goalCol = null;
    }

    public function setCursor(int $line, int $col, SelectionMode $selection = SelectionMode::Clear): void
    {
        $this->cursorLine = $this->clampLine($line);
        $this->cursorCol = $this->clampCol($this->cursorLine, $col);
        $this->goalCol = null;
        if (SelectionMode::Clear === $selection) {
            $this->selection->clear();
        }
    }

    public function moveLeft(): bool
    {
        $this->goalCol = null;
        if ($this->cursorCol > 0) {
            $this->cursorCol = TextMetrics::prevCharBoundary($this->lines[$this->cursorLine], $this->cursorCol);

            return true;
        }
        if ($this->cursorLine > 0) {
            --$this->cursorLine;
            $this->cursorCol = \strlen($this->lines[$this->cursorLine]);

            return true;
        }

        return false;
    }

    public function moveRight(): bool
    {
        $this->goalCol = null;
        $line = $this->lines[$this->cursorLine];
        if ($this->cursorCol < \strlen($line)) {
            $this->cursorCol = TextMetrics::nextCharBoundary($line, $this->cursorCol);

            return true;
        }
        if ($this->cursorLine < (\count($this->lines) - 1)) {
            ++$this->cursorLine;
            $this->cursorCol = 0;

            return true;
        }

        return false;
    }

    public function moveUp(): bool
    {
        if (0 === $this->cursorLine) {
            if ($this->cursorCol > 0) {
                $this->cursorCol = 0;
                $this->goalCol = null;

                return true;
            }

            return false;
        }

        $this->goalCol ??= $this->getCursorDisplayCol();
        --$this->cursorLine;
        $this->cursorCol = TextMetrics::byteColForDisplayCol($this->lines[$this->cursorLine], $this->goalCol, $this->tabWidth());

        return true;
    }

    public function moveDown(): bool
    {
        if ($this->cursorLine >= (\count($this->lines) - 1)) {
            $len = \strlen($this->lines[$this->cursorLine]);
            if ($this->cursorCol < $len) {
                $this->cursorCol = $len;
                $this->goalCol = null;

                return true;
            }

            return false;
        }

        $this->goalCol ??= $this->getCursorDisplayCol();
        ++$this->cursorLine;
        $this->cursorCol = TextMetrics::byteColForDisplayCol($this->lines[$this->cursorLine], $this->goalCol, $this->tabWidth());

        return true;
    }

    public function moveToLineStart(): bool
    {
        $this->goalCol = null;
        // Smart home: first non-blank, then column 0
        $line = $this->lines[$this->cursorLine];
        $indent = \strlen($line) - \strlen(ltrim($line, characters: ' '));
        $target = $this->cursorCol === $indent || $indent === \strlen($line) ? 0 : $indent;
        if ($this->cursorCol === $target) {
            return false;
        }
        $this->cursorCol = $target;

        return true;
    }

    public function moveToLineEnd(): bool
    {
        $this->goalCol = null;
        $len = \strlen($this->lines[$this->cursorLine]);
        if ($this->cursorCol === $len) {
            return false;
        }
        $this->cursorCol = $len;

        return true;
    }

    public function moveToDocumentStart(): void
    {
        $this->cursorLine = 0;
        $this->cursorCol = 0;
        $this->goalCol = null;
    }

    public function moveToDocumentEnd(): void
    {
        $this->cursorLine = \count($this->lines) - 1;
        $this->cursorCol = \strlen($this->lines[$this->cursorLine]);
        $this->goalCol = null;
    }

    public function moveWordLeft(): bool
    {
        $this->goalCol = null;
        if (0 === $this->cursorCol) {
            return $this->moveLeft();
        }
        $prefix = substr($this->lines[$this->cursorLine], offset: 0, length: $this->cursorCol);
        $m = [];
        if (preg_match('/(?:[$\w]+|[^\s\w$]+)\s*$/u', $prefix, $m, \PREG_OFFSET_CAPTURE)) {
            $this->cursorCol = (int) $m[0][1];

            return true;
        }
        $this->cursorCol = 0;

        return true;
    }

    public function moveWordRight(): bool
    {
        $this->goalCol = null;
        $line = $this->lines[$this->cursorLine];
        if ($this->cursorCol >= \strlen($line)) {
            return $this->moveRight();
        }
        $suffix = substr($line, $this->cursorCol);
        $m = [];
        if (preg_match('/^\s*(?:[$\w]+|[^\s\w$]+)/u', $suffix, $m)) {
            $this->cursorCol += \strlen($m[0]);

            return true;
        }
        $this->cursorCol = \strlen($line);

        return true;
    }

    // --- Mutation ---------------------------------------------------------

    public function insert(string $text): void
    {
        if ('' === $text) {
            return;
        }
        $coalesce = null === $this->getSelectionRange() && \strlen($text) <= 4 && !str_contains($text, "\n") ? 'type' : null;
        $this->pushUndo($coalesce);
        $this->removeSelectedText();
        $text = str_replace(["\r\n", "\r"], replace: "\n", subject: $text);

        $line = $this->lines[$this->cursorLine];
        $before = substr($line, offset: 0, length: $this->cursorCol);
        $after = substr($line, $this->cursorCol);

        $parts = explode("\n", $text);
        if (1 === \count($parts)) {
            $this->lines[$this->cursorLine] = $before.$text.$after;
            $this->cursorCol += \strlen($text);
            $this->touch();

            return;
        }
        $insertLines = $parts;
        $insertLines[0] = $before.$parts[0];
        $lastIndex = \count($insertLines) - 1;
        $lastLen = \strlen($insertLines[$lastIndex]);
        $insertLines[$lastIndex] .= $after;
        array_splice($this->lines, $this->cursorLine, length: 1, replacement: $insertLines);
        $this->cursorLine += $lastIndex;
        $this->cursorCol = $lastLen;
        $this->touch();
    }

    /**
     * Insert a newline, copying the current line's indentation.
     */
    public function insertNewLine(): void
    {
        $this->pushUndo();
        $this->removeSelectedText();
        $line = $this->lines[$this->cursorLine];
        $before = substr($line, offset: 0, length: $this->cursorCol);
        $after = substr($line, $this->cursorCol);
        $baseIndent = '';
        $m = [];
        if (preg_match('/^[\t ]+/', $line, $m)) {
            $baseIndent = substr($m[0], offset: 0, length: min(\strlen($m[0]), $this->cursorCol));
        }
        $unit = $this->indentUnit();

        // Smart enter between a matching pair, e.g. "{|}" -> opener line, a
        // blank indented caret line, and the closer dropped to base indent.
        $pairs = AutoPairService::NEWLINE_PAIRS;
        $openCh = '' !== $before ? $before[\strlen($before) - 1] : '';
        $closeCh = '' !== $after ? $after[0] : '';
        if (\array_key_exists($openCh, $pairs) && $pairs[$openCh] === $closeCh) {
            $inner = $baseIndent.$unit;
            $this->lines[$this->cursorLine] = $before;
            array_splice($this->lines, $this->cursorLine + 1, length: 0, replacement: [$inner, $baseIndent.$after]);
            ++$this->cursorLine;
            $this->cursorCol = \strlen($inner);
            $this->touch();

            return;
        }

        $indent = $baseIndent;
        // Increase indent after an opening brace/bracket/paren
        if (preg_match('/[{([]\s*$/', $before)) {
            $indent .= $unit;
        }

        $this->lines[$this->cursorLine] = $before;
        array_splice($this->lines, $this->cursorLine + 1, length: 0, replacement: [$indent.$after]);
        ++$this->cursorLine;
        $this->cursorCol = \strlen($indent);
        $this->touch();
    }

    public function deleteBackward(): bool
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }
        if (0 === $this->cursorCol && 0 === $this->cursorLine) {
            return false;
        }
        $this->pushUndo('backspace');
        if ($this->cursorCol > 0) {
            $line = $this->lines[$this->cursorLine];
            $newCol = TextMetrics::prevCharBoundary($line, $this->cursorCol);
            $this->lines[$this->cursorLine] = substr($line, offset: 0, length: $newCol).substr($line, $this->cursorCol);
            $this->cursorCol = $newCol;
            $this->touch();

            return true;
        }
        $current = $this->lines[$this->cursorLine];
        $this->cursorCol = \strlen($this->lines[$this->cursorLine - 1]);
        $this->lines[$this->cursorLine - 1] .= $current;
        array_splice($this->lines, $this->cursorLine, length: 1);
        --$this->cursorLine;
        $this->touch();

        return true;
    }

    public function deleteForward(): bool
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }
        $line = $this->lines[$this->cursorLine];
        if ($this->cursorCol >= \strlen($line) && $this->cursorLine >= (\count($this->lines) - 1)) {
            return false;
        }
        $this->pushUndo();
        if ($this->cursorCol < \strlen($line)) {
            $next = TextMetrics::nextCharBoundary($line, $this->cursorCol);
            $this->lines[$this->cursorLine] = substr($line, offset: 0, length: $this->cursorCol).substr($line, $next);
            $this->touch();

            return true;
        }
        $this->lines[$this->cursorLine] .= $this->lines[$this->cursorLine + 1];
        array_splice($this->lines, $this->cursorLine + 1, length: 1);
        $this->touch();

        return true;
    }

    public function deleteWordBackward(): bool
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }
        if (0 === $this->cursorCol) {
            return $this->deleteBackward();
        }
        $this->pushUndo();
        $line = $this->lines[$this->cursorLine];
        $prefix = substr($line, offset: 0, length: $this->cursorCol);
        $newCol = 0;
        $m = [];
        if (preg_match('/(?:[$\w]+|[^\s\w$]+)\s*$/u', $prefix, $m, \PREG_OFFSET_CAPTURE)) {
            $newCol = (int) $m[0][1];
        }
        $this->lines[$this->cursorLine] = substr($line, offset: 0, length: $newCol).substr($line, $this->cursorCol);
        $this->cursorCol = $newCol;
        $this->touch();

        return true;
    }

    public function deleteToLineEnd(): bool
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }
        $line = $this->lines[$this->cursorLine];
        if ($this->cursorCol >= \strlen($line)) {
            return $this->deleteForward();
        }
        $this->pushUndo();
        $this->lines[$this->cursorLine] = substr($line, offset: 0, length: $this->cursorCol);
        $this->touch();

        return true;
    }

    /**
     * Replace the last $byteCount bytes before the cursor with $replacement.
     * Used by completion acceptance to swap the typed prefix.
     */
    public function replaceBeforeCursor(int $byteCount, string $replacement): void
    {
        $this->pushUndo();
        $line = $this->lines[$this->cursorLine];
        $start = max(0, $this->cursorCol - $byteCount);
        $this->lines[$this->cursorLine] = substr($line, offset: 0, length: $start).$replacement.substr($line, $this->cursorCol);
        $this->cursorCol = $start + \strlen($replacement);
        $this->touch();
    }

    /**
     * Apply a list of LSP text edits (UTF-16 ranges) as one atomic compound
     * edit, keeping the caret at its current logical position by shifting it
     * down by the net number of lines inserted on lines strictly above it.
     *
     * Used to apply completion `additionalTextEdits` (e.g. auto-imports at the
     * top of the file) without moving the caret away from the completed word.
     *
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText?: string}> $edits
     */
    public function applyEditsKeepingCursor(array $edits): void
    {
        if ([] === $edits) {
            return;
        }

        $curLine = $this->cursorLine;
        $curCol = $this->cursorCol;

        // Net vertical shift for the caret: edits that end above the caret line
        // move it by (lines added − lines spanned).
        $lineShift = 0;
        foreach ($edits as $edit) {
            $endLine = $edit['range']['end']['line'] ?? 0;
            if ($endLine < $curLine) {
                $startLine = $edit['range']['start']['line'] ?? 0;
                $lineShift += substr_count($edit['newText'] ?? '', needle: "\n") - ($endLine - $startLine);
            }
        }

        // Apply bottom-up so earlier positions stay valid as we mutate.
        usort(
            $edits,
            static fn (array $a, array $b): int => (
                [$b['range']['start']['line'] ?? 0, $b['range']['start']['character'] ?? 0] <=> [$a['range']['start']['line'] ?? 0, $a['range']['start']['character'] ?? 0]
            ),
        );

        $this->beginCompound();
        foreach ($edits as $edit) {
            $start = $edit['range']['start'];
            $end = $edit['range']['end'];
            $this->setCursor($start['line'] ?? 0, $this->utf16ColToByteCol($start['line'] ?? 0, $start['character'] ?? 0));
            $this->beginSelect();
            $this->setCursor($end['line'] ?? 0, $this->utf16ColToByteCol($end['line'] ?? 0, $end['character'] ?? 0), selection: SelectionMode::Keep);
            if ($this->hasSelection()) {
                $this->deleteSelection();
            }
            $this->insert($edit['newText'] ?? '');
        }
        $this->endCompound();

        $this->setCursor($curLine + $lineShift, $curCol);
    }

    /**
     * Replace the entire buffer content as one undoable edit (used by
     * project-wide replace). The caret is clamped into the new text.
     */
    public function replaceAll(string $text): void
    {
        $this->pushUndo();
        $text = str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
        $this->lines = '' === $text ? [''] : explode("\n", $text);
        $this->cursorLine = $this->clampLine($this->cursorLine);
        $this->cursorCol = $this->clampCol($this->cursorLine, $this->cursorCol);
        $this->selection->clear();
        $this->touch();
    }

    public function undo(): bool
    {
        $state = $this->history->undo($this->snapshot());
        if (null === $state) {
            return false;
        }
        $this->restore($state);
        $this->touch();

        return true;
    }

    public function redo(): bool
    {
        $state = $this->history->redo($this->snapshot());
        if (null === $state) {
            return false;
        }
        $this->restore($state);
        $this->touch();

        return true;
    }

    // --- Internals --------------------------------------------------------

    /**
     * @param array{lines: string[], line: int, col: int, anchor?: array{line: int, col: int}|null, extras?: list<Caret>} $state
     */
    private function restore(array $state): void
    {
        $this->lines = $state['lines'];
        $this->cursorLine = min($state['line'], \count($this->lines) - 1);
        $this->cursorCol = min($state['col'], \strlen($this->lines[$this->cursorLine]));
        // Restore the selection anchor (clamped; dropped if it no longer fits).
        $anchor = $state['anchor'] ?? null;
        $this->selection->restore(
            null !== $anchor && $anchor['line'] < \count($this->lines) && $anchor['col'] <= \strlen($this->lines[$anchor['line']])
                ? $anchor
                : null,
        );
        if (null !== $this->restoreCarets) {
            ($this->restoreCarets)($state['extras'] ?? []);
        }
    }

    /**
     * @return array{lines: string[], line: int, col: int, anchor: array{line: int, col: int}|null, extras: list<Caret>}
     */
    private function snapshot(): array
    {
        // Inside a compound, use the caret state captured before the edit moved
        // the cursor across carets; otherwise the live cursor/selection/carets.
        if (null !== $this->compoundCaret) {
            return ['lines' => $this->lines] + $this->compoundCaret;
        }

        return [
            'lines' => $this->lines,
            'line' => $this->cursorLine,
            'col' => $this->cursorCol,
            'anchor' => $this->selection->snapshot(),
            'extras' => null !== $this->captureCarets ? ($this->captureCarets)() : [],
        ];
    }

    /**
     * Begin an atomic edit group: every mutation until the matching
     * endCompound() collapses into a single undo step. Nestable.
     */
    public function beginCompound(): void
    {
        // Capture the true pre-edit caret set once, as the outermost group opens
        // (before MultiCaret walks the cursor across carets), so the compound's
        // single snapshot restores the right selection and carets on undo.
        if (!$this->history->inCompound()) {
            $this->compoundCaret = [
                'line' => $this->cursorLine,
                'col' => $this->cursorCol,
                'anchor' => $this->selection->snapshot(),
                'extras' => null !== $this->captureCarets ? ($this->captureCarets)() : [],
            ];
        }
        $this->history->beginCompound();
    }

    public function endCompound(): void
    {
        $this->history->endCompound();
        if (!$this->history->inCompound()) {
            $this->compoundCaret = null;
        }
    }

    /**
     * Run $fn as a single atomic (compound) edit — every mutation inside it
     * collapses into one undo step, even if $fn throws.
     */
    public function transact(callable $fn): void
    {
        $this->beginCompound();
        try {
            $fn();
        } finally {
            $this->endCompound();
        }
    }

    /**
     * @param string|null $coalesceKey when set, consecutive edits with the same
     *                                 key within 1s share one undo snapshot
     */
    private function pushUndo(?string $coalesceKey = null): void
    {
        $this->history->push($this->snapshot(), $coalesceKey);
    }

    private function touch(): void
    {
        ++$this->version;
        $this->dirty = true;
        $this->goalCol = null;
    }
}
