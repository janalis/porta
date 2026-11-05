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
    /** The UTF-8 byte-order mark, stripped on load and re-emitted on save. */
    public const string BOM = "\u{FEFF}";

    public const int TAB_SIZE = 4;

    /** @var string[] */
    public private(set) array $lines = [''];
    public private(set) int $cursorLine = 0;
    public private(set) int $cursorCol = 0;
    public private(set) int $version = 1;
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
     * Where the last typed text ended. Typing merges into one undo step only
     * when it goes on from there: typed within the second but somewhere else,
     * it used to merge too, and one Ctrl+Z took both and put the caret back
     * at the first place.
     *
     * @var array{int, int}|null
     */
    private ?array $typedTo = null;

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

    /**
     * xxh128 of the text as of the last save/load, for "undo back to saved
     * state clears dirty". A digest, not the text: the full copy retained one
     * extra file-size of memory per open buffer for a comparison that only
     * ever needs equality.
     */
    private string $savedDigest;
    private int $dirtyCheckedVersion = 0;

    /** The joined text of {@see $textMemoVersion}; version identifies content (the PositionMapper invariant). */
    private ?string $textMemo = null;
    private int $textMemoVersion = -1;

    /**
     * A hook called once per MUTATION, with the caret's line as the document
     * has it at that moment.
     *
     * On the same optional footing as {@see setCaretHooks()}, and for the same
     * reason: a Document works standalone in tests, and the thing that wants
     * this ({@see EditHistory}) knows the file's path, which a Document does
     * not. Called from `touch()` because that is the one place every one of the
     * twenty-odd mutating methods already funnels through — an edit that
     * arrives from a command, a formatter or the language server counts the
     * same as a keystroke.
     *
     * @var (\Closure(int): void)|null receives the 0-based caret line
     */
    public ?\Closure $onEdit = null;

    /**
     * Whether the text this document was loaded from began with a UTF-8 BOM.
     *
     * Held rather than carried, because a BOM in the TEXT is wrong twice over:
     * `mb_strwidth()` counts U+FEFF as one column where a terminal draws none,
     * so line 0 measures wider than it renders and every column mapped on it is
     * off by one — and it sits at column 0, where the caret starts, so the
     * first character typed goes in front of it and it stops being a mark.
     *
     * The save path reads this back, so a file that arrived with one leaves
     * with one. Everything else — the digest, the LSP's copy, a search — sees
     * text without it, which is what they all want.
     */
    public private(set) bool $hasByteOrderMark = false;

    /**
     * The line ending the loaded text used, for the save path to write back.
     *
     * Lines are held LF-joined whatever the file used, because every consumer —
     * the digest, the LSP, a search, the width arithmetic — wants one shape.
     * Which shape the FILE used is a different question, and forgetting the
     * answer rewrote every line of a CRLF file the first time it was saved.
     */
    public private(set) string $eol = "\n";

    /**
     * Whether the USER may change this text: a read-only document still moves
     * its caret and selection, and an edit from code — a collaborator's, a
     * reload — still lands. What gives back a keystroke's change is
     * {@see ReadOnlyInput}, which the editor runs its input through. A change
     * of who may edit starts a fresh undo history: the steps from before were
     * someone else's to take back.
     */
    public bool $readOnly = false {
        set {
            $this->readOnly = $value;
            $this->history->clear();
        }
    }

    /**
     * Undo and redo, answered by someone else while set — each closure says
     * whether it changed anything. For a shared session, where undo must take
     * back only this user's own edits, which a snapshot of the whole text
     * cannot do once someone else has typed since. Either hand-over forgets
     * the snapshots so far: from before one they would restore text others
     * have changed since, and from during one they were never this history's
     * to replay.
     *
     * @var array{undo: \Closure(): bool, redo: \Closure(): bool}|null
     */
    public ?array $historyDelegate = null {
        set {
            $this->historyDelegate = $value;
            $this->history->clear();
        }
    }

    public function __construct(string $text = '')
    {
        $text = $this->adopt($text);
        $this->lines = '' === $text ? [''] : \explode("\n", $text);
        // The normalized $text IS what implode would rebuild — digest it
        // directly instead of re-joining a copy of the file.
        $this->savedDigest = \hash('xxh128', $text);
        $this->indent = IndentModel::detect($this->lines, self::TAB_SIZE);
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

    public function usesTabs(): bool
    {
        return $this->indent->usesTabs;
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
        return $this->indent->indentSize;
    }

    /** Display columns per hard tab (EditorConfig tab_width). */
    public function tabWidth(): int
    {
        return $this->indent->tabWidth;
    }

    /**
     * The `FormattingOptions` a language server formats this document by:
     * its own indentation, so a format does not re-indent the whole file.
     *
     * @return array{tabSize: int, insertSpaces: bool}
     */
    public function formattingOptions(): array
    {
        return ['tabSize' => $this->usesTabs() ? $this->tabWidth() : $this->indentSize(), 'insertSpaces' => !$this->usesTabs()];
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
        // Memoized on the version counter: isDirty, the LSP sync and the
        // breadcrumb scan all join the buffer around the same keystroke, and
        // only touch() moves the version.
        if ($this->version !== $this->textMemoVersion) {
            $this->textMemo = \implode("\n", $this->lines);
            $this->textMemoVersion = $this->version;
        }

        /** @var string */
        return $this->textMemo;
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
            if (\hash('xxh128', $this->getText()) === $this->savedDigest) {
                $this->dirty = false;
            }
        }

        return $this->dirty;
    }

    public function markSaved(): void
    {
        $this->dirty = false;
        $this->savedDigest = \hash('xxh128', $this->getText());
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
        $prefix = \substr($this->lines[$this->cursorLine], offset: 0, length: $this->cursorCol);
        $m = [];
        if (\preg_match('/[$\w]+$/u', $prefix, $m)) {
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
            return \substr($this->lines[$startLine], $startCol, $endCol - $startCol);
        }

        $parts = [\substr($this->lines[$startLine], $startCol)];
        for ($i = $startLine + 1; $i < $endLine; ++$i) {
            $parts[] = $this->lines[$i];
        }
        $parts[] = \substr($this->lines[$endLine], offset: 0, length: $endCol);

        return \implode("\n", $parts);
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
        return WordRange::at($this->lines[$line] ?? '', $col);
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
        $this->removeBetween($range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']);
        $this->selection->clear();
    }

    /** Removes the text from one position up to a later one, and leaves the cursor where it started. */
    private function removeBetween(int $fromLine, int $fromCol, int $toLine, int $toCol): void
    {
        $head = \substr($this->lines[$fromLine], offset: 0, length: $fromCol);
        $tail = \substr($this->lines[$toLine], $toCol);
        \array_splice($this->lines, $fromLine, $toLine - $fromLine + 1, [$head.$tail]);
        $this->cursorLine = $fromLine;
        $this->cursorCol = $fromCol;
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
        \array_splice($this->lines, $last + 1, length: 0, replacement: $block);
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
        \array_splice($this->lines, $first, $last - $first + 1);
        if ([] === $this->lines) {
            $this->lines = [''];
        }
        $this->selection->clear();
        $this->cursorLine = \min($first, \count($this->lines) - 1);
        $this->cursorCol = \min($this->cursorCol, \strlen($this->lines[$this->cursorLine]));
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
        $target = LineOperations::statementTarget($this->lines, $delta, $first, $last);
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
        \array_splice($this->lines, $first, $last - $first + 1);
        \array_splice($this->lines, $target, length: 0, replacement: $block);
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
        $sorted = LineOperations::sorted($block);

        return $sorted !== $block && $this->replaceLineBlock($first, \count($block), $sorted);
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
            if (($first + 1) >= \count($this->lines)) {
                return false;
            }
            $last = $first + 1;
        }

        return $this->replaceLineBlock($first, $last - $first + 1, [LineOperations::joined($this->lines, $first, $last)]);
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
        $toggled = LineOperations::caseToggled($block);
        if ($toggled === $block) {
            return false;
        }

        return $this->replaceLineBlock($first, \count($block), $toggled);
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
        \array_splice($this->lines, $first, $length, $replacement);
        $this->selection->clear();
        $this->cursorLine = \min($this->cursorLine, \count($this->lines) - 1);
        $this->cursorCol = \min($this->cursorCol, \strlen($this->lines[$this->cursorLine]));
        $this->touch();

        return true;
    }

    /**
     * Comment out the current line or selected block, one line at a time.
     *
     * Prefers the language's LINE form and falls back to wrapping each line in
     * its block pair, which is what gives Twig, HTML, Markdown and CSS a
     * working `Ctrl+/` — none of them has a line comment, and the old
     * signature (a bare prefix string) had no way to say so.
     */
    public function toggleComment(CommentSyntax $syntax): void
    {
        $this->pushUndo();
        [$first, $last] = $this->selectedLineSpan();
        $lines = \array_slice($this->lines, $first, $last - $first + 1);

        // The `['', '']` cannot be reached: a CommentSyntax with neither form
        // is not constructible, so a null line guarantees a block pair. It is
        // here because the type system cannot see that invariant, not because
        // a language might have nothing.
        [$open, $close] = $syntax->block ?? ['', ''];
        $block = null !== $syntax->line
            ? LineOperations::commentToggled($lines, $syntax->line)
            : LineOperations::blockCommentToggled($lines, $open, $close);
        foreach ($block as $offset => $line) {
            $this->lines[$first + $offset] = $line;
        }
        $this->cursorCol = \min($this->cursorCol, \strlen($this->lines[$this->cursorLine]));
        $this->touch();
    }

    /**
     * Wrap the selection — or, with none, the current line's content — in the
     * language's block pair, and unwrap it when it is already exactly wrapped.
     *
     * Character-precise, unlike {@see toggleComment()}: `foo(bar, baz)` with
     * `bar` selected becomes `foo(/* bar *\/, baz)`. A language with no block
     * form delegates to the line verb, so this key is never dead where the
     * other one works.
     *
     * The limit worth knowing: unwrapping needs the TARGET RANGE to be the
     * comment. With no selection the target is the whole line, so a one-line
     * block comment un-toggles from anywhere on it; a comment spanning several
     * lines has to be selected. Scanning outwards for an enclosing comment is a
     * parser's job, not a range operation's.
     */
    public function toggleBlockComment(CommentSyntax $syntax): void
    {
        if (null === $syntax->block) {
            $this->toggleComment($syntax);

            return;
        }

        [$open, $close] = $syntax->block;
        $range = $this->getSelectionRange() ?? $this->contentRangeOfCursorLine();
        $text = $this->getTextInRange($range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']);
        $inner = LineOperations::blockUnwrapped($text, $open, $close);

        $this->beginCompound();
        $this->setSelectionRange($range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']);
        // Deleted explicitly rather than left to insert(): unwrapping an empty
        // comment produces an empty replacement, and insert('') returns before
        // it clears the selection.
        $this->deleteSelection();
        $this->insert($inner ?? $open.' '.$text.' '.$close);
        // An empty target means the caret sat on a blank line and the user is
        // OPENING a comment rather than commenting something out, so leave the
        // caret between the delimiters instead of after the closer. No need to
        // ask whether this was an unwrap: empty text is shorter than any pair
        // of delimiters, so $inner is null whenever $text is ''.
        if ('' === $text) {
            $this->cursorCol -= \strlen($close) + 1;
        }
        $this->endCompound();
    }

    /**
     * The cursor line's content span: its first non-blank byte to its end.
     *
     * The indent is deliberately outside it — a block comment wrapping the
     * leading whitespace would be legal and would ruin the file's shape.
     *
     * @return array{startLine: int, startCol: int, endLine: int, endCol: int}
     */
    private function contentRangeOfCursorLine(): array
    {
        $line = $this->lines[$this->cursorLine];
        $start = \strlen($line) - \strlen(\ltrim($line));

        // max(), because rtrim() takes a WHOLLY blank line to length 0 while
        // $start is its full width — an inverted range, which reads as text
        // through substr's negative length and then deletes the indent on its
        // way to inserting the comment. An indented blank line is exactly where
        // someone opens a comment.
        return [
            'startLine' => $this->cursorLine,
            'startCol' => $start,
            'endLine' => $this->cursorLine,
            'endCol' => \max($start, \strlen(\rtrim($line))),
        ];
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
            $removed = \str_starts_with($line, "\t")
                ? 1
                : \min($size, \strlen($line) - \strlen(\ltrim($line, characters: ' ')));
            if (0 === $removed) {
                continue;
            }
            $this->lines[$i] = \substr($line, $removed);
            if ($i === $this->cursorLine) {
                $cursorRemoved = $removed;
            }
            if ($i === $this->selection->anchorLine()) {
                $anchorRemoved = $removed;
            }
        }

        $this->cursorCol = \max(0, $this->cursorCol - $cursorRemoved);
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
            $this->cursorCol = TextMetrics::prevGrapheme($this->lines[$this->cursorLine], $this->cursorCol);

            return true;
        }
        $moved = $this->cursorLine > 0;
        if ($moved) {
            --$this->cursorLine;
            $this->cursorCol = \strlen($this->lines[$this->cursorLine]);
        }

        return $moved;
    }

    public function moveRight(): bool
    {
        $this->goalCol = null;
        $line = $this->lines[$this->cursorLine];
        if ($this->cursorCol < \strlen($line)) {
            $this->cursorCol = TextMetrics::nextGrapheme($line, $this->cursorCol);

            return true;
        }
        $moved = $this->cursorLine < (\count($this->lines) - 1);
        if ($moved) {
            ++$this->cursorLine;
            $this->cursorCol = 0;
        }

        return $moved;
    }

    public function moveUp(): bool
    {
        if (0 === $this->cursorLine) {
            $moved = $this->cursorCol > 0;
            if ($moved) {
                $this->cursorCol = 0;
                $this->goalCol = null;
            }

            return $moved;
        }

        $this->goalCol ??= $this->getCursorDisplayCol();
        --$this->cursorLine;
        $this->cursorCol = $this->byteColForDisplayCol($this->cursorLine, $this->goalCol);

        return true;
    }

    public function moveDown(): bool
    {
        if ($this->cursorLine >= (\count($this->lines) - 1)) {
            $len = \strlen($this->lines[$this->cursorLine]);
            $moved = $this->cursorCol < $len;
            if ($moved) {
                $this->cursorCol = $len;
                $this->goalCol = null;
            }

            return $moved;
        }

        $this->goalCol ??= $this->getCursorDisplayCol();
        ++$this->cursorLine;
        $this->cursorCol = $this->byteColForDisplayCol($this->cursorLine, $this->goalCol);

        return true;
    }

    public function moveToLineStart(): bool
    {
        $this->goalCol = null;
        // Smart home: first non-blank, then column 0
        $line = $this->lines[$this->cursorLine];
        $indent = \strlen($line) - \strlen(\ltrim($line, characters: " \t")); // a tab-indented line has an indent too
        $target = $this->cursorCol === $indent || \strlen($line) === $indent ? 0 : $indent;
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
        $prefix = \substr($this->lines[$this->cursorLine], offset: 0, length: $this->cursorCol);
        $m = [];
        $this->cursorCol = \preg_match('/(?:[$\w]+|[^\s\w$]+)\s*$/u', $prefix, $m, \PREG_OFFSET_CAPTURE) ? (int) $m[0][1] : 0;

        return true;
    }

    public function moveWordRight(): bool
    {
        $this->goalCol = null;
        $line = $this->lines[$this->cursorLine];
        if ($this->cursorCol >= \strlen($line)) {
            return $this->moveRight();
        }
        $suffix = \substr($line, $this->cursorCol);
        $m = [];
        $this->cursorCol = \preg_match('/^\s*(?:[$\w]+|[^\s\w$]+)/u', $suffix, $m) ? $this->cursorCol + \strlen($m[0]) : \strlen($line);

        return true;
    }

    // --- Mutation ---------------------------------------------------------

    /**
     * The caret is where the typing goes on: an auto-pair steps back inside
     * the pair it typed, a type-over steps over the closer, and the next key
     * belongs to the same undo step as the ones before.
     */
    public function typingGoesOnHere(): void
    {
        $this->typedTo = [$this->cursorLine, $this->cursorCol];
    }

    public function insert(string $text): void
    {
        if ('' === $text) {
            return;
        }
        $coalesce = null === $this->getSelectionRange() && \strlen($text) <= 4 && !\str_contains($text, "\n") ? 'type' : null;
        if (null !== $coalesce && [$this->cursorLine, $this->cursorCol] !== $this->typedTo) {
            $this->history->checkpoint();
        }
        $this->pushUndo($coalesce);
        $this->removeSelectedText();
        $text = \str_replace(["\r\n", "\r"], replace: "\n", subject: $text);

        $line = $this->lines[$this->cursorLine];
        $before = \substr($line, offset: 0, length: $this->cursorCol);
        $after = \substr($line, $this->cursorCol);

        $parts = \explode("\n", $text);
        if (1 === \count($parts)) {
            $this->lines[$this->cursorLine] = $before.$text.$after;
            $this->cursorCol += \strlen($text);
            $this->typedTo = [$this->cursorLine, $this->cursorCol];
            $this->touch();

            return;
        }
        $insertLines = $parts;
        $insertLines[0] = $before.$parts[0];
        $lastIndex = \count($insertLines) - 1;
        $lastLen = \strlen($insertLines[$lastIndex]);
        $insertLines[$lastIndex] .= $after;
        \array_splice($this->lines, $this->cursorLine, length: 1, replacement: $insertLines);
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
        $before = \substr($line, offset: 0, length: $this->cursorCol);
        $after = \substr($line, $this->cursorCol);
        $baseIndent = '';
        $m = [];
        if (\preg_match('/^[\t ]+/', $line, $m)) {
            $baseIndent = \substr($m[0], offset: 0, length: \min(\strlen($m[0]), $this->cursorCol));
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
            \array_splice($this->lines, $this->cursorLine + 1, length: 0, replacement: [$inner, $baseIndent.$after]);
            ++$this->cursorLine;
            $this->cursorCol = \strlen($inner);
            $this->touch();

            return;
        }

        $indent = $baseIndent;
        // Increase indent after an opening brace/bracket/paren
        if (\preg_match('/[{([]\s*$/', $before)) {
            $indent .= $unit;
        }

        $this->lines[$this->cursorLine] = $before;
        \array_splice($this->lines, $this->cursorLine + 1, length: 0, replacement: [$indent.$after]);
        ++$this->cursorLine;
        $this->cursorCol = \strlen($indent);
        $this->touch();
    }

    /**
     * Open an empty line above or below the caret's, and put the caret on it.
     *
     * PhpStorm's `Shift+Enter` / `Ctrl+Alt+Enter`, and the reason they are
     * different verbs from Enter is the WHOLE point of them: Enter splits the
     * line at the caret, so starting a new statement from the middle of one
     * means End first, and starting one above means Home, Enter, Up. These two
     * ignore the column entirely.
     *
     * The new line copies its neighbour's indent — plus one unit when opening
     * BELOW a line that ends in an opener, which is the same rule
     * {@see insertNewLine()} follows and the case where the two verbs would
     * otherwise disagree about the same keystroke.
     *
     * There is deliberately no matching rule for Above. A line above a `}` is
     * inside the block and a line above an `if` is outside it, and nothing on
     * the line itself says which — guessing would be wrong half the time,
     * where copying the indent is merely neutral.
     */
    public function openLine(LinePlacement $placement): void
    {
        $this->pushUndo();
        $this->selection->clear();
        $line = $this->lines[$this->cursorLine];
        $m = [];
        $indent = \preg_match('/^[\t ]+/', $line, $m) ? $m[0] : '';
        if (LinePlacement::Below === $placement && 1 === \preg_match('/[{([]\s*$/', $line)) {
            $indent .= $this->indentUnit();
        }

        $at = LinePlacement::Below === $placement ? $this->cursorLine + 1 : $this->cursorLine;
        \array_splice($this->lines, $at, length: 0, replacement: [$indent]);
        $this->cursorLine = $at;
        $this->cursorCol = \strlen($indent);
        $this->goalCol = null;
        $this->touch();
    }

    /**
     * PhpStorm's `Ctrl+Shift+Enter`: finish the line and start the next one.
     *
     * Three steps, in order, each skipped when it does not apply:
     *
     * 1. **Close what this line opened.** Unbalanced `(` and `[` get their
     *    partners, so `foo(bar` becomes `foo(bar)`.
     * 2. **Open a block, or end a statement.** A line whose first word is one
     *    of the language's block keywords gets the opener and a closing line
     *    beneath; anything else gets the terminator, unless it already ends in
     *    one or in punctuation that says the statement continues.
     * 3. **Leave the caret on a fresh line**, indented to match.
     *
     * **The bracket count does not know about strings.** `echo "a(b"` counts an
     * unclosed paren and would add one. A parser would not, and Porta has none
     * here — the mitigation is that this is an explicit keystroke and the whole
     * thing is ONE undo step, not that the case cannot arise. Recorded rather
     * than hidden.
     */
    public function completeStatement(StatementSyntax $syntax): void
    {
        // The indent comes from the ORIGINAL line and the statement from the
        // rtrimmed one, because on a whitespace-only line those disagree:
        // rtrim leaves nothing, so taking the indent from it dropped the caret
        // to column 0 on a blank line inside an indented block — where Enter
        // and Shift+Enter both keep their place.
        $raw = $this->lines[$this->cursorLine];
        $indent = \substr($raw, offset: 0, length: \strlen($raw) - \strlen(\ltrim($raw)));
        $line = \rtrim($raw);
        $body = $line.LineOperations::missingClosers($line);

        // The block pair when this line opens one, null otherwise — carried as
        // the VALUE rather than as a boolean beside it, so every use below
        // narrows on the same null check instead of asserting a fact the
        // analyser then calls redundant.
        $block = $syntax->block;
        $opened = null !== $block && $syntax->opensABlock($line) && LineOperations::wantsBlockOpener($line, $block[0], $block[1]) ? $block : null;
        $terminator = $syntax->terminator;

        // A block and a terminator are alternatives, never both: `if ($x) {;`
        // is nobody's intention.
        $body .= match (true) {
            null !== $opened => $opened[0],
            null !== $terminator && LineOperations::wantsTerminator($body, $terminator) => $terminator,
            default => '',
        };

        // A null CLOSER means the block is the indent itself (Python), so there
        // is a line to open under the header and nothing to put below it.
        $closer = null !== $opened && null !== $opened[1] ? [$indent.$opened[1]] : [];
        $caretLine = $indent.(null === $opened ? '' : $this->indentUnit());

        $this->pushUndo();
        $this->selection->clear();
        \array_splice($this->lines, $this->cursorLine, length: 1, replacement: [$body, $caretLine, ...$closer]);
        ++$this->cursorLine;
        $this->cursorCol = \strlen($caretLine);
        $this->goalCol = null;
        $this->touch();
    }

    public function deleteBackward(): bool
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }
        $deleted = 0 !== $this->cursorCol || 0 !== $this->cursorLine;
        if ($deleted) {
            $this->pushUndo('backspace');
            $line = $this->cursorLine;
            $col = $this->cursorCol;
            // At a line start, the line joins the one above it.
            [$fromLine, $fromCol] = $col > 0
                ? [$line, TextMetrics::prevGrapheme($this->lines[$line], $col)]
                : [$line - 1, \strlen($this->lines[$line - 1])];
            $this->removeBetween($fromLine, $fromCol, $line, $col);
            $this->touch();
        }

        return $deleted;
    }

    public function deleteForward(): bool
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }
        $line = $this->lines[$this->cursorLine];
        $deleted = $this->cursorCol < \strlen($line) || $this->cursorLine < (\count($this->lines) - 1);
        if ($deleted) {
            $this->pushUndo();
            // At a line end, the next line joins this one.
            [$toLine, $toCol] = $this->cursorCol >= \strlen($line)
                ? [$this->cursorLine + 1, 0]
                : [$this->cursorLine, TextMetrics::nextGrapheme($line, $this->cursorCol)];
            $this->removeBetween($this->cursorLine, $this->cursorCol, $toLine, $toCol);
            $this->touch();
        }

        return $deleted;
    }

    public function deleteWordBackward(): bool
    {
        // deleteBackward() deletes the selection when there is one.
        if ($this->hasSelection() || 0 === $this->cursorCol) {
            return $this->deleteBackward();
        }
        $this->pushUndo();
        $line = $this->lines[$this->cursorLine];
        $prefix = \substr($line, offset: 0, length: $this->cursorCol);
        $newCol = 0;
        $m = [];
        if (\preg_match('/(?:[$\w]+|[^\s\w$]+)\s*$/u', $prefix, $m, \PREG_OFFSET_CAPTURE)) {
            $newCol = (int) $m[0][1];
        }
        $this->lines[$this->cursorLine] = \substr($line, offset: 0, length: $newCol).\substr($line, $this->cursorCol);
        $this->cursorCol = $newCol;
        $this->touch();

        return true;
    }

    public function deleteToLineEnd(): bool
    {
        // deleteForward() deletes the selection when there is one.
        $line = $this->lines[$this->cursorLine];
        if ($this->hasSelection() || $this->cursorCol >= \strlen($line)) {
            return $this->deleteForward();
        }
        $this->pushUndo();
        $this->lines[$this->cursorLine] = \substr($line, offset: 0, length: $this->cursorCol);
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
        $start = \max(0, $this->cursorCol - $byteCount);
        $this->lines[$this->cursorLine] = \substr($line, offset: 0, length: $start).$replacement.\substr($line, $this->cursorCol);
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
                $lineShift += \substr_count($edit['newText'] ?? '', needle: "\n") - ($endLine - $startLine);
            }
        }

        // Apply bottom-up so earlier positions stay valid as we mutate.
        \usort(
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

    /**
     * Take incoming file text and make it this document's: remember the shape
     * it arrived in, then hand back the one shape everything here works on.
     *
     * ONE seam for both doors — the constructor and {@see replaceAll()} — and
     * it exists because they had drifted. Line-ending normalisation was in both
     * but tested in only one, and the BOM and the line-ending memory each had
     * to be added to both by hand. A third concern arriving should not be a
     * third chance to miss a door.
     *
     * Everything recorded here is recorded UNCONDITIONALLY, so a reload of a
     * file that lost its mark, or gained CRLF, answers for what it is now
     * rather than leaving the previous answer standing.
     */
    private function adopt(string $text): string
    {
        $this->hasByteOrderMark = \str_starts_with($text, self::BOM);
        $text = $this->hasByteOrderMark ? \substr($text, offset: \strlen(self::BOM)) : $text;
        // The FIRST ending decides, because a mixed file has no majority worth
        // honouring and the one it opens with is the one a reader saw.
        $this->eol = match (true) {
            \str_contains($text, "\r\n") => "\r\n",
            \str_contains($text, "\r") => "\r",
            default => "\n",
        };

        return \str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
    }

    public function replaceAll(string $text): void
    {
        $this->pushUndo();
        $text = $this->adopt($text);
        $this->lines = '' === $text ? [''] : \explode("\n", $text);
        $this->cursorLine = $this->clampLine($this->cursorLine);
        $this->cursorCol = $this->clampCol($this->cursorLine, $this->cursorCol);
        $this->selection->clear();
        $this->touch();
    }

    public function undo(): bool
    {
        if (null !== $this->historyDelegate) {
            return $this->historyDelegate['undo']();
        }
        $state = $this->history->undo($this->snapshot());
        if (null !== $state) {
            $this->restore($state);
            $this->touch();
        }

        return null !== $state;
    }

    public function redo(): bool
    {
        if (null !== $this->historyDelegate) {
            return $this->historyDelegate['redo']();
        }
        $state = $this->history->redo($this->snapshot());
        if (null !== $state) {
            $this->restore($state);
            $this->touch();
        }

        return null !== $state;
    }

    // --- Internals --------------------------------------------------------

    /**
     * @param array{lines: string[], line: int, col: int, anchor?: array{line: int, col: int}|null, extras?: list<Caret>} $state
     */
    private function restore(array $state): void
    {
        $this->lines = $state['lines'];
        $this->cursorLine = \min($state['line'], \count($this->lines) - 1);
        $this->cursorCol = \min($state['col'], \strlen($this->lines[$this->cursorLine]));
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
     * Run $fn as part of the last undo step rather than a step of its own:
     * one Ctrl+Z takes back both ({@see UndoHistory::beginAmend()}).
     */
    public function amendLastStep(callable $fn): void
    {
        $this->history->beginAmend();
        try {
            $fn();
        } finally {
            $this->endCompound();
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
        // Last, so an observer reading the document sees the edit rather than
        // the state before it. A plain guard, not a null-coalesced no-op
        // closure: this runs on every keystroke and a closure per press is a
        // per-press allocation for nothing.
        if (null !== $this->onEdit) {
            ($this->onEdit)($this->cursorLine);
        }
    }
}
