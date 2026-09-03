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
    private array $searchMatches = [];

    /** @var array<int, ChangeMarkKind> 0-based line => kind */
    private array $vcsMarks = [];

    /** @var array<int, string> 0-based line => trailing annotation (blame) */
    private array $lineAnnotations = [];

    /** @var list<int> 0-based lines with a breakpoint (● in the gutter) */
    private array $breakpointLines = [];

    /** @var array<int, list<array{title: string, command: string, arguments: list<mixed>}>> 0-based line => the lenses shown ABOVE it */
    private array $codeLenses = [];

    /** @var array<int, list<array{col: int, endCol: int, type: string, modifiers: list<string>}>> 0-based line => semantic tokens (utf16 cols) */
    private array $semanticTokens = [];

    /** @var array<int, true> 0-based bookmarked lines, keyed for the per-row lookup the gutter does */
    private array $bookmarkLines = [];

    /** @var list<int> the subset of breakpoint lines carrying a condition (◆ in the gutter) */
    private array $conditionalBreakpointLines = [];

    /** @var list<int> the subset of breakpoint lines carrying a log message (◇ in the gutter) */
    private array $logpointLines = [];

    /** The debugger's stopped line (full-width band), or null when not paused here. */
    private ?int $debugLine = null;

    /** @var array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> keyed by 0-based line */
    private array $diagnostics = [];

    /** @var array{errors: int, warnings: int} maintained by {@see recountDiagnostics} on every diagnostics change */
    private array $counts = ['errors' => 0, 'warnings' => 0];

    /** @var array<int, list<array{col: int, text: string}>> inlay hints per line: byte col => dim label */
    private array $inlayHints = [];

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
     * @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $diagnostics
     */
    public function resetForBuffer(array $diagnostics): void
    {
        $this->diagnostics = $diagnostics;
        $this->recountDiagnostics();
        $this->inlayHints = [];
        $this->lineAnnotations = [];
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

    /** @return array<int, list<array{start: int, end: int, current: bool}>> */
    public function searchMatches(): array
    {
        return $this->searchMatches;
    }

    /** @param array<int, ChangeMarkKind> $byLine 0-based line => kind */
    public function setVcsMarks(array $byLine): void
    {
        $this->vcsMarks = $byLine;
        ($this->invalidate)();
    }

    /** @return array<int, ChangeMarkKind> */
    public function vcsMarks(): array
    {
        return $this->vcsMarks;
    }

    /** @param array<int, string> $byLine 0-based line => trailing annotation (blame); [] clears */
    public function setLineAnnotations(array $byLine): void
    {
        $this->lineAnnotations = $byLine;
        ($this->invalidate)();
    }

    /** @return array<int, string> */
    public function lineAnnotations(): array
    {
        return $this->lineAnnotations;
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
        $this->bookmarkLines = array_fill_keys($lines, value: true);
        ($this->invalidate)();
    }

    public function isBookmarked(int $line): bool
    {
        return ($this->bookmarkLines[$line] ?? null) !== null;
    }

    /** @return list<int> */
    public function breakpointLines(): array
    {
        return $this->breakpointLines;
    }

    /** @return list<int> */
    public function conditionalBreakpointLines(): array
    {
        return $this->conditionalBreakpointLines;
    }

    /** @return list<int> */
    public function logpointLines(): array
    {
        return $this->logpointLines;
    }

    /** Highlight (or clear) the debugger's stopped line. */
    public function setDebugLine(?int $line): void
    {
        $this->debugLine = $line;
        ($this->invalidate)();
    }

    public function debugLine(): ?int
    {
        return $this->debugLine;
    }

    /** @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $byLine */
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
                1 === $d['severity'] ? ++$errors : ++$warnings;
            }
        }
        $this->counts = ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @return array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return array{errors: int, warnings: int} */
    public function diagnosticCounts(): array
    {
        return $this->counts;
    }

    /** The first diagnostic message on $line (the status bar's caret readout), or null. */
    public function diagnosticMessageAt(int $line): ?string
    {
        $list = $this->diagnostics[$line] ?? null;
        if (!$list) {
            return null;
        }

        return $list[0]['message'];
    }

    /**
     * The code lenses to show above each line — "3 usages", "2 implementations".
     *
     * Unlike every other decoration these do not ride IN a row; each one adds a
     * row of its own above the line it describes. That is why the row count
     * flows through {@see codeLensesAt()} to the walk rather than being painted
     * into an existing row.
     *
     * @param array<int, list<array{title: string, command: string, arguments: list<mixed>}>> $byLine 0-based line => lenses; [] clears
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

    /** @return list<array{title: string, command: string, arguments: list<mixed>}> the lenses above one line */
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
