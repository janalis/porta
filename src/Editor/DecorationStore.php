<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's per-buffer decoration state — git gutter marks, diagnostics,
 * search matches, inlay hints, blame annotations, breakpoints and the
 * debugger's stopped line — pushed in by the controllers through the widget's
 * delegating setters and read each frame by the {@see EditorRowAssembler}.
 * Extracted from CodeEditorWidget (the codebase's kitchen-sink widget) like
 * the other per-concern sub-objects its constructor builds; every mutation
 * requests a repaint.
 */
final class DecorationStore implements EditorDecorationSink, LineAnnotationSink
{
    /** Between two writers' notes on one line. */
    private const string ANNOTATION_JOIN = '  · ';

    /** @var array<int, list<array{start: int, end: int, current: bool}>> search matches by line (byte cols) */
    public private(set) array $searchMatches = [];

    /** @var array<int, ChangeMarkKind> 0-based line => kind */
    public private(set) array $vcsMarks = [];

    /**
     * The document's line count as of the last edit, for {@see
     * remapForEdit()} — nothing reports how many lines an edit added or
     * removed, so it is the difference from this. Rebased by {@see
     * resetForBuffer()}, since another buffer's count would read as an edit of
     * the difference on this one's first keystroke.
     */
    private int $vcsMarkLineCount = 0;

    /**
     * Every writer's trailing notes joined per line — what the row assembler
     * paints. Derived from {@see $annotationsByOwner} on every publish.
     *
     * @var array<int, string> 0-based line => trailing annotation
     */
    public private(set) array $lineAnnotations = [];

    /**
     * The same notes kept apart by who wrote them, for {@see Buffer::$diagnosticsByOwner}'s
     * reason: blame and a review plugin both annotate lines, and the map one
     * writer publishes REPLACES only its own.
     *
     * @var array<string, array<int, string>> owner => 0-based line => text
     */
    public private(set) array $annotationsByOwner = [];

    /** @var array<int, string> 0-based line => the conversation's one-line summary, for the pointer's hint */
    public private(set) array $commentMarks = [];

    /**
     * Not cleared per buffer: an agent's caret belongs to its file.
     *
     * @var array<string, array<string, array{line: int, col: int, label: string, slot: int, anchor?: array{line: int, col: int}|null}>> path => owner => caret
     */
    private array $remoteCarets = [];

    /**
     * The length each agent caret's line had when it was last measured — at
     * the frame after it was placed, and after every edit since — so an edit
     * on that line tells how far it moved the text after it. Absent until
     * measured.
     *
     * @var array<string, array<string, int>> path => owner => bytes
     */
    private array $remoteLineLengths = [];

    /**
     * Rows of a writer's text shown UNDER a line — a review thread, whole —
     * by writer, for {@see $annotationsByOwner}'s reason.
     *
     * @var array<string, array<int, list<string>>> owner => 0-based line => rows
     */
    public private(set) array $blocksByOwner = [];

    /** @var array<int, list<string>> every writer's rows merged, 0-based line => rows */
    private array $lineBlocks = [];

    /** @var list<int> 0-based lines with a breakpoint (● in the gutter) */
    public private(set) array $breakpointLines = [];

    /** @var array<int, list<array{title: string, command: string, arguments: list<mixed>, owner: string}>> 0-based line => the lenses shown ABOVE it */
    private array $codeLenses = [];

    /** @var array<int, list<array{col: int, endCol: int, type: string, modifiers: list<string>}>> 0-based line => semantic tokens (utf16 cols) */
    private array $semanticTokens = [];

    /** @var array<int, true> 0-based bookmarked lines, keyed for the per-row lookup the gutter does */
    private array $bookmarkLines = [];

    /** @var list<int> the subset of breakpoint lines carrying a condition (◆ in the gutter) */
    public private(set) array $conditionalBreakpointLines = [];

    /** @var list<int> the subset of breakpoint lines carrying a log message (◇ in the gutter) */
    public private(set) array $logpointLines = [];

    /** The debugger's stopped line (full-width band), or null when not paused here. */
    public private(set) ?int $debugLine = null;

    /** @var array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>> keyed by 0-based line */
    public private(set) array $diagnostics = [];

    /** @var array{errors: int, warnings: int} maintained by {@see recountDiagnostics} on every diagnostics change */
    public private(set) array $diagnosticCounts = ['errors' => 0, 'warnings' => 0];

    /** @var array<int, list<InlayHint>> inlay hints per line, unfiltered: {@see InlayLines} decides which show */
    private array $inlayHints = [];
    /** @var array<int, string> 0-based line => the debugger's values for it */
    public private(set) array $debugValues = [];

    /** @param \Closure(): void $invalidate request a redraw */
    public function __construct(
        private readonly \Closure $invalidate,
    ) {}

    /**
     * A new buffer takes over: it brings its own diagnostics, and the
     * per-buffer overlays clear (the blame and debug controllers re-apply
     * theirs per buffer). Git marks and search matches are replaced by their
     * own follow-up pushes.
     *
     * @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>> $diagnostics
     */
    public function resetForBuffer(array $diagnostics, int $lineCount = 0): void
    {
        $this->vcsMarkLineCount = $lineCount;
        $this->diagnostics = $diagnostics;
        $this->recountDiagnostics();
        $this->inlayHints = [];
        $this->lineAnnotations = [];
        $this->annotationsByOwner = [];
        $this->commentMarks = [];
        $this->blocksByOwner = [];
        $this->lineBlocks = [];
        $this->debugValues = [];
        $this->breakpointLines = [];
        $this->bookmarkLines = [];
        $this->codeLenses = [];
        $this->semanticTokens = [];
        $this->conditionalBreakpointLines = [];
        $this->logpointLines = [];
        $this->debugLine = null;
    }

    /** @param array<int, list<array{start: int, end: int, current: bool}>> $byLine byte columns */
    public function setSearchMatches(array $byLine): void
    {
        $this->searchMatches = $byLine;
        ($this->invalidate)();
    }

    /**
     * @param array<int, ChangeMarkKind> $byLine    0-based line => kind
     * @param int|null                   $lineCount the document these marks describe, which REBASES the
     *                                              remap baseline; null leaves it, for a caller that is
     *                                              clearing the marks rather than answering about a file
     */
    public function setVcsMarks(array $byLine, ?int $lineCount = null): void
    {
        $this->vcsMarks = $byLine;
        if (null !== $lineCount) {
            // These marks describe a document of this many lines, so they are
            // the baseline the next edit is measured from. Without it a RELOAD
            // — which replaces the text in place, with no showDocument() to
            // rebase through — left the count from before the reload, and the
            // next keystroke read the whole difference as its own edit.
            $this->vcsMarkLineCount = $lineCount;
        }
        ($this->invalidate)();
    }

    /**
     * Move the gutter marks with an edit, so they keep pointing at the lines
     * they describe between one `git diff` and the next.
     *
     * The marks are computed from the file ON DISK, by a producer that only
     * hears about saves, reloads and buffer switches. Everything between those
     * left them frozen: add three lines at the top of a changed file and every
     * mark below still sat where the saved file had put it, so the gutter
     * pointed three lines wrong and stayed that way for as long as the editing
     * went on. The stripe and the marker column read the same map, so both were
     * wrong together.
     *
     * $atLine is where the edit happened and $lineDelta how many lines it added
     * (positive) or removed (negative):
     *
     *  - marks BELOW the edit shift by the delta — the point of the exercise;
     *  - lines the edit REMOVED lose their marks rather than shifting onto a
     *    line that no longer exists;
     *  - the edited line becomes Modified, and lines the edit ADDED become
     *    Added, unless they already carry a mark. That is what makes the
     *    gutter answer a keystroke at all: typing on an unmarked line lights
     *    it immediately instead of at the next save.
     *
     * An APPROXIMATION, deliberately, and a self-correcting one: the producer
     * re-runs on every save, reload and buffer switch, and its answer replaces
     * all of this. What it buys is that the marks are never silently stale in
     * between, which is the state a user spends most of their time in.
     */
    public function remapForEdit(int $cursorLine, int $lineCount, ?string $path = null, ?Document $document = null): void
    {
        $delta = $lineCount - $this->vcsMarkLineCount;
        $this->vcsMarkLineCount = $lineCount;
        // WHERE the edit happened is not reported either, so it is derived: the
        // caret is on the edit, and the count of lines it added tells us how far
        // the caret has already moved past the line the edit STARTED on. Insert
        // three lines and the caret sits three below the first of them; delete a
        // selection and the caret is left at its start, which is that line
        // already. Exact for a single caret, which is every ordinary edit.
        $atLine = \max(0, $cursorLine - \max(0, $delta));
        $this->shiftRemoteCarets($path, $atLine, $delta);
        if (null !== $path && null !== $document) {
            if (0 === $delta) {
                $this->shiftRemoteColumns($path, $document, $cursorLine);
            }
            $this->remeasureRemoteLines($path, $document);
        }
        $this->remapVcsMarks($atLine, $delta);
    }

    /**
     * An edit within one line moved the text after it along that line: an
     * agent's caret there, past where the edit started — or at it, for typed
     * text, which pushes a caret along as it would the user's own — moves
     * with it, by how much the line grew or shrank. The edit started at the
     * user's caret less what was just typed; a deletion started where the
     * caret is now.
     */
    private function shiftRemoteColumns(string $path, Document $document, int $line): void
    {
        foreach ($this->remoteCarets[$path] ?? [] as $owner => $caret) {
            $before = $this->remoteLineLengths[$path][$owner] ?? null;
            if ($line !== $caret['line'] || null === $before) {
                continue;
            }
            $grew = \strlen($document->getLine($line)) - $before;
            $start = $grew > 0 ? $document->cursorCol - $grew : $document->cursorCol;
            $after = $grew > 0 ? $caret['col'] >= $start : $caret['col'] > $start;
            if (0 !== $grew && $after) {
                $this->remoteCarets[$path][$owner]['col'] = \max($start, $caret['col'] + $grew);
            }
        }
    }

    /** After an edit every caret's remembered line length in $path is stale: measure them all again. */
    private function remeasureRemoteLines(string $path, Document $document): void
    {
        unset($this->remoteLineLengths[$path]);
        $this->measureNewRemoteLines($path, $document);
    }

    /** At a frame: remember the line length of each caret in $path that has none yet. */
    private function measureNewRemoteLines(string $path, Document $document): void
    {
        foreach ($this->remoteCarets[$path] ?? [] as $owner => $caret) {
            if (\array_key_exists($owner, $this->remoteLineLengths[$path] ?? [])) {
                continue;
            }
            $this->remoteLineLengths[$path][$owner] = \strlen($document->getLine($caret['line']));
        }
    }

    /**
     * The user's edit moved the lines under $path's agent carets: one below
     * the edit shifts with its line, one in a removed region lands on the
     * edit — the same rule the git marks follow.
     */
    private function shiftRemoteCarets(?string $path, int $atLine, int $lineDelta): void
    {
        if (0 === $lineDelta) {
            return;
        }
        foreach ($this->remoteCarets[$path ?? ''] ?? [] as $owner => $caret) {
            if ($caret['line'] <= $atLine) {
                continue;
            }
            $inRemoved = $lineDelta < 0 && $caret['line'] <= ($atLine - $lineDelta);
            $this->remoteCarets[(string) $path][$owner]['line'] = $inRemoved ? $atLine : $caret['line'] + $lineDelta;
        }
    }

    private function remapVcsMarks(int $atLine, int $lineDelta): void
    {
        if ([] === $this->vcsMarks && 0 === $lineDelta) {
            return; // nothing to move and nothing to light
        }

        $moved = [];
        foreach ($this->vcsMarks as $line => $kind) {
            if ($line <= $atLine) {
                $moved[$line] = $kind;

                continue;
            }
            if ($lineDelta < 0 && $line <= ($atLine - $lineDelta)) {
                continue; // inside the removed region: the line is gone
            }
            $moved[$line + $lineDelta] = $kind;
        }
        $moved[$atLine] ??= ChangeMarkKind::Modified;
        for ($line = $atLine + 1; $line <= ($atLine + $lineDelta); ++$line) {
            $moved[$line] ??= ChangeMarkKind::Added;
        }
        \ksort($moved);

        $this->vcsMarks = $moved;
        ($this->invalidate)();
    }

    /**
     * The debugger's variable values for this file, 0-based line => the text
     * shown after it; [] clears.
     *
     * Its own slot rather than {@see setLineAnnotations()}: that one is the
     * plugin channel (git blame writes it) and the map REPLACES, so sharing it
     * would mean two writers clobbering each other every time the debugger
     * stepped. They compete for the same CELL, which the row assembler settles
     * by precedence — but they do not compete for the same variable.
     *
     * @param array<int, string> $byLine
     */
    public function setDebugValues(array $byLine): void
    {
        $this->debugValues = $byLine;
        ($this->invalidate)();
    }

    /**
     * Replace $owner's trailing notes; [] clears them. Another writer's notes
     * stay, and a line two writers annotate shows both, in the order the
     * writers first spoke.
     *
     * @param array<int, string> $byLine 0-based line => trailing annotation
     */
    public function setLineAnnotations(array $byLine, string $owner = ''): void
    {
        // Assigned in place, so a writer that republishes keeps its turn in the order.
        $this->annotationsByOwner[$owner] = $byLine;
        if ([] === $byLine) {
            unset($this->annotationsByOwner[$owner]);
        }
        $merged = [];
        foreach ($this->annotationsByOwner as $notes) {
            foreach ($notes as $line => $text) {
                $merged[$line] = \array_key_exists($line, $merged) ? $merged[$line].self::ANNOTATION_JOIN.$text : $text;
            }
        }
        $this->lineAnnotations = $merged;
        ($this->invalidate)();
    }

    /**
     * Replace $owner's rows under the active document's lines; [] clears them.
     * Like code lenses these are rows of their own — a line with a block is
     * taller by its rows — but under the line rather than above it, and the
     * text is the writer's, painted dim.
     *
     * @param array<int, list<string>> $byLine 0-based line => the rows under it
     */
    public function setLineBlocks(array $byLine, string $owner = ''): void
    {
        $this->blocksByOwner[$owner] = $byLine;
        if ([] === $byLine) {
            unset($this->blocksByOwner[$owner]);
        }
        $merged = [];
        foreach ($this->blocksByOwner as $blocks) {
            foreach ($blocks as $line => $rows) {
                $merged[$line] = [...($merged[$line] ?? []), ...$rows];
            }
        }
        $this->lineBlocks = $merged;
        ($this->invalidate)();
    }

    /** @return list<string> the rows under one line */
    public function lineBlocksAt(int $line): array
    {
        return $this->lineBlocks[$line] ?? [];
    }

    /**
     * Whether any line is taller than its text — a lens above it or a block
     * under it — the cheap check that keeps the row arithmetic on its
     * one-row-per-line fast path.
     */
    public function hasExtraRows(): bool
    {
        return [] !== $this->codeLenses || [] !== $this->lineBlocks;
    }

    /**
     * The lines a conversation is attached to — a review comment's gutter
     * glyph and stripe mark — with the summary the pointer's hint reads.
     *
     * @param array<int, string> $byLine 0-based line => one-line summary; [] clears
     */
    public function setCommentMarks(array $byLine): void
    {
        $this->commentMarks = $byLine;
        ($this->invalidate)();
    }

    /**
     * @param list<int> $lines            0-based lines carrying a breakpoint in this file
     * @param list<int> $conditionalLines the subset carrying a condition (◆ instead of ●)
     * @param list<int> $logpointLines    the subset carrying a log message (◇ instead of ●)
     */
    public function setBreakpointLines(array $lines, array $conditionalLines = [], array $logpointLines = []): void
    {
        $this->breakpointLines = $lines;
        $this->conditionalBreakpointLines = $conditionalLines;
        $this->logpointLines = $logpointLines;
        ($this->invalidate)();
    }

    /** @param list<int> $lines 0-based bookmarked lines; [] clears */
    public function setBookmarkLines(array $lines): void
    {
        $this->bookmarkLines = \array_fill_keys($lines, value: true);
        ($this->invalidate)();
    }

    /** @return list<int> the bookmarked lines, for the stripe's overview */
    public function bookmarkedLines(): array
    {
        return \array_keys($this->bookmarkLines);
    }

    /**
     * The gutter mark under the pointer — its line, and whether it is the
     * change strip's or the marker column's — so the row assembler can
     * underline it; null when the pointer is on no mark.
     *
     * @var array{line: int, part: GutterPart}|null
     */
    public private(set) ?array $gutterHover = null;

    /** Point at a gutter mark, or at none; returns whether that changed (and asks for a frame when it did). */
    public function hoverGutter(?int $line, GutterPart $part = GutterPart::Marker): bool
    {
        $next = null === $line ? null : ['line' => $line, 'part' => $part];
        if ($next === $this->gutterHover) {
            return false;
        }
        $this->gutterHover = $next;
        ($this->invalidate)();

        return true;
    }

    /** The marker-stripe row whose mark is under the pointer, or null. */
    public private(set) ?int $stripeHover = null;

    /** Point at a stripe row's mark, or at none; returns whether that changed (and asks for a frame when it did). */
    public function hoverStripe(?int $row): bool
    {
        if ($row === $this->stripeHover) {
            return false;
        }
        $this->stripeHover = $row;
        ($this->invalidate)();

        return true;
    }

    public function isBookmarked(int $line): bool
    {
        return null !== ($this->bookmarkLines[$line] ?? null);
    }

    /** Highlight (or clear) the debugger's stopped line. */
    public function setDebugLine(?int $line): void
    {
        $this->debugLine = $line;
        ($this->invalidate)();
    }

    /** @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>> $byLine */
    public function setDiagnostics(array $byLine): void
    {
        $this->diagnostics = $byLine;
        $this->recountDiagnostics();
        ($this->invalidate)();
    }

    /**
     * Counted once per diagnostics change, not once per status-bar frame: the
     * bar polls the counts every frame, and only the two diagnostics mutators
     * ({@see setDiagnostics}, {@see resetForBuffer}) can move them.
     */
    private function recountDiagnostics(): void
    {
        $warnings = 0;
        $errors = 0;
        foreach ($this->diagnostics as $list) {
            foreach ($list as $d) {
                DiagnosticSeverity::Error === $d['severity'] ? ++$errors : ++$warnings;
            }
        }
        $this->diagnosticCounts = ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * The status bar's caret readout for $line: its WORST diagnostic — an error
     * under a hint is the one worth reading — the first of equals, or null.
     *
     * @return array{message: string, severity: DiagnosticSeverity}|null
     */
    public function diagnosticAt(int $line): ?array
    {
        $worst = null;
        foreach ($this->diagnostics[$line] ?? [] as $d) {
            if (null !== $worst && !$d['severity']->outranks($worst['severity'])) {
                continue;
            }
            $worst = ['message' => $d['message'], 'severity' => $d['severity']];
        }

        return $worst;
    }

    /**
     * The code lenses to show above each line — "3 usages", "2 implementations".
     *
     * Unlike every other decoration these do not ride IN a row; each one adds a
     * row of its own above the line it describes. That is why the row count
     * flows through {@see codeLensesAt()} to the walk rather than being painted
     * into an existing row.
     *
     * `owner` names the server that published the lens, so running it goes
     * back to the one whose command id it is — several servers can lens one
     * file, and a command means nothing to the others.
     *
     * @param array<int, list<array{title: string, command: string, arguments: list<mixed>, owner: string}>> $byLine 0-based line => lenses; [] clears
     */
    public function setCodeLenses(array $byLine): void
    {
        $this->codeLenses = $byLine;
        ($this->invalidate)();
    }

    /** @return list<array{title: string, command: string, arguments: list<mixed>, owner: string}> the lenses above one line */
    public function codeLensesAt(int $line): array
    {
        return $this->codeLenses[$line] ?? [];
    }

    /**
     * The server's semantic tokens, keyed by 0-based line — what the language
     * server knows each identifier actually IS, as opposed to what the lexer
     * can infer from its shape.
     *
     * Columns are UTF-16, the unit LSP speaks; they are converted to display
     * columns at render time by the same helper the diagnostics use.
     *
     * @param array<int, list<array{col: int, endCol: int, type: string, modifiers: list<string>}>> $byLine 0-based line => tokens; [] clears
     */
    public function setSemanticTokens(array $byLine): void
    {
        $this->semanticTokens = $byLine;
        ($this->invalidate)();
    }

    /** @return list<array{col: int, endCol: int, type: string, modifiers: list<string>}> the semantic tokens on one line */
    public function semanticTokensAt(int $line): array
    {
        return $this->semanticTokens[$line] ?? [];
    }

    #[\Override]
    public function setRemoteCaret(string $path, string $owner, ?array $caret): void
    {
        unset($this->remoteCarets[$path][$owner], $this->remoteLineLengths[$path][$owner]);
        if (null !== $caret) {
            $this->remoteCarets[$path][$owner] = $caret;
        }
        ($this->invalidate)();
    }

    /**
     * The carets of other writers in one file, by 0-based line. Given the
     * file's document — the editor's frame does — a caret placed since the
     * last frame has its line measured, so the next edit can move it along.
     *
     * @return array<int, list<array{col: int, label: string, slot: int}>>
     */
    public function remoteCaretsIn(?string $path, ?Document $document = null): array
    {
        if (null !== $path && null !== $document) {
            $this->measureNewRemoteLines($path, $document);
        }
        $byLine = [];
        foreach ($this->remoteCarets[$path ?? ''] ?? [] as $caret) {
            $byLine[$caret['line']][] = ['col' => $caret['col'], 'label' => $caret['label'], 'slot' => $caret['slot']];
        }

        return $byLine;
    }

    /**
     * The selections of other writers in one file, by 0-based line: on each
     * line the byte columns it covers — the end PHP_INT_MAX where it runs on
     * past the line's end — and its owner's colour slot.
     *
     * @return array<int, list<array{start: int, end: int, slot: int}>>
     */
    public function remoteSelectionsIn(?string $path): array
    {
        $byLine = [];
        foreach ($this->remoteCarets[$path ?? ''] ?? [] as $caret) {
            $anchor = $caret['anchor'] ?? null;
            if (null === $anchor) {
                continue;
            }
            [$from, $to] = [[$anchor['line'], $anchor['col']], [$caret['line'], $caret['col']]];
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }
            for ($line = $from[0]; $line <= $to[0]; ++$line) {
                $byLine[$line][] = [
                    'start' => $line === $from[0] ? $from[1] : 0,
                    'end' => $line === $to[0] ? $to[1] : \PHP_INT_MAX,
                    'slot' => $caret['slot'],
                ];
            }
        }

        return $byLine;
    }

    public function setInlayHints(array $hints): void
    {
        $this->inlayHints = $hints;
        ($this->invalidate)();
    }

    /** @return list<InlayHint> every inlay hint on one line, shown or not */
    public function inlayHintsAt(int $line): array
    {
        return $this->inlayHints[$line] ?? [];
    }
}
