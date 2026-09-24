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
    /** @var array<int, list<array{start: int, end: int, current: bool}>> search matches by line (byte cols) */
    public private(set) array $searchMatches = [];

    /** @var array<int, ChangeMarkKind> 0-based line => kind */
    public private(set) array $vcsMarks = [];

    /**
     * The document's line count as of the last edit, for {@see
     * remapVcsMarksForEdit()} — nothing reports how many lines an edit added or
     * removed, so it is the difference from this. Rebased by {@see
     * resetForBuffer()}, since another buffer's count would read as an edit of
     * the difference on this one's first keystroke.
     */
    private int $vcsMarkLineCount = 0;

    /** @var array<int, string> 0-based line => trailing annotation (blame) */
    public private(set) array $lineAnnotations = [];

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

    /** @var array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>> keyed by 0-based line */
    public private(set) array $diagnostics = [];

    /** @var array{errors: int, warnings: int} maintained by {@see recountDiagnostics} on every diagnostics change */
    public private(set) array $diagnosticCounts = ['errors' => 0, 'warnings' => 0];

    /** @var array<int, list<array{col: int, text: string}>> inlay hints per line: byte col => dim label */
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
     * @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>> $diagnostics
     */
    public function resetForBuffer(array $diagnostics, int $lineCount = 0): void
    {
        $this->vcsMarkLineCount = $lineCount;
        $this->diagnostics = $diagnostics;
        $this->recountDiagnostics();
        $this->inlayHints = [];
        $this->lineAnnotations = [];
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
    public function remapVcsMarksForEdit(int $cursorLine, int $lineCount): void
    {
        $delta = $lineCount - $this->vcsMarkLineCount;
        $this->vcsMarkLineCount = $lineCount;
        // WHERE the edit happened is not reported either, so it is derived: the
        // caret is on the edit, and the count of lines it added tells us how far
        // the caret has already moved past the line the edit STARTED on. Insert
        // three lines and the caret sits three below the first of them; delete a
        // selection and the caret is left at its start, which is that line
        // already. Exact for a single caret, which is every ordinary edit.
        $this->remapVcsMarks(\max(0, $cursorLine - \max(0, $delta)), $delta);
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

    /** @param array<int, string> $byLine 0-based line => trailing annotation (blame); [] clears */
    public function setLineAnnotations(array $byLine): void
    {
        $this->lineAnnotations = $byLine;
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

    public function isBookmarked(int $line): bool
    {
        return ($this->bookmarkLines[$line] ?? null) !== null;
    }

    /** Highlight (or clear) the debugger's stopped line. */
    public function setDebugLine(?int $line): void
    {
        $this->debugLine = $line;
        ($this->invalidate)();
    }

    /** @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>> $byLine */
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
            if (null === $worst || $d['severity']->outranks($worst['severity'])) {
                $worst = ['message' => $d['message'], 'severity' => $d['severity']];
            }
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

    /** Whether any line has a lens — the cheap check that keeps the unwrapped hit-test on its fast path. */
    public function hasCodeLenses(): bool
    {
        return [] !== $this->codeLenses;
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

    public function setInlayHints(array $hints): void
    {
        $this->inlayHints = $hints;
        ($this->invalidate)();
    }

    /** @return list<array{col: int, text: string}> the inlay hints on one line */
    public function inlayHintsAt(int $line): array
    {
        return $this->inlayHints[$line] ?? [];
    }
}
