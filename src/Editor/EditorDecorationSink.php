<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The overlays a subsystem paints onto the active buffer without owning the
 * editor: git marks, blame annotations, breakpoints, the debugger's stopped
 * line, diagnostics, search matches and inlay hints.
 *
 * Implemented by {@see DecorationStore} itself, which the editor widget owns
 * and the container reaches through `CodeEditorWidget::decorations()`. The
 * widget used to implement this and forward all of it, which made every new
 * decoration a method on the codebase's largest class — three features running
 * moved that ceiling before the forwarding went instead.
 *
 * Fold regions are NOT here: their only caller takes the concrete widget, and
 * applying them needs the fold model and the document version rather than the
 * store.
 *
 * One interface rather than eight single-verb ones: every method is the same
 * "replace this decoration layer for the current buffer" operation against the
 * same store, and a caller that publishes one layer is already coupled to the
 * store's lifecycle. Callers still touch only the layer they own — git never
 * calls the debug setters.
 */
interface EditorDecorationSink
{
    /**
     * @param array<int, list<array{start: int, end: int, current: bool}>> $byLine byte columns
     */
    public function setSearchMatches(array $byLine): void;

    /**
     * @param array<int, ChangeMarkKind> $byLine 0-based line => kind
     */
    public function setVcsMarks(array $byLine, ?int $lineCount = null): void;

    /**
     * @param array<int, string> $byLine 0-based line => trailing annotation (blame); [] clears
     */
    public function setLineAnnotations(array $byLine): void;

    /**
     * The debugger's variable values for the active file, 0-based line => text.
     *
     * @param array<int, string> $byLine
     */
    public function setDebugValues(array $byLine): void;

    /**
     * @param list<int> $lines            0-based lines carrying a breakpoint in this file
     * @param list<int> $conditionalLines the subset carrying a condition/hit-count (◆ instead of ●)
     * @param list<int> $logpointLines    the subset carrying a log message (◇ — logs instead of pausing)
     */
    public function setBreakpointLines(array $lines, array $conditionalLines = [], array $logpointLines = []): void;

    /**
     * The file's bookmarked lines (0-based) — the gutter's lowest-priority
     * marker, since a breakpoint or a diagnostic on the same line matters more.
     *
     * @param list<int> $lines
     */
    public function setBookmarkLines(array $lines): void;

    /** Highlight (or clear) the debugger's stopped line. */
    public function setDebugLine(?int $line): void;

    /**
     * Code lenses, keyed by the 0-based line they sit ABOVE. The only
     * decoration that adds rows rather than painting into one.
     *
     * @param array<int, list<array{title: string, command: string, arguments: list<mixed>}>> $byLine
     */
    public function setCodeLenses(array $byLine): void;

    /**
     * The server's semantic tokens, keyed by the 0-based line they sit on, in
     * UTF-16 columns. Recolours identifiers by what the server knows them to
     * be — a property, a parameter, a static member — where the lexer can only
     * guess from shape.
     *
     * @param array<int, list<array{col: int, endCol: int, type: string, modifiers: list<string>}>> $byLine
     */
    public function setSemanticTokens(array $byLine): void;

    /** @param array<int, list<array{severity: int, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>> $byLine */
    public function setDiagnostics(array $byLine): void;

    /**
     * Replace the inlay hints (parameter names / inferred types) shown inline.
     *
     * @param array<int, list<array{col: int, text: string}>> $hints line => byte-col hints
     */
    public function setInlayHints(array $hints): void;
}
