<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The overlays a subsystem paints onto the active buffer without owning the
 * editor: git marks, blame annotations, breakpoints, the debugger's stopped
 * line, diagnostics, fold regions, search matches and inlay hints. Implemented
 * by the editor widget, which forwards each to its {@see DecorationStore}.
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
     * @param array<int, string> $byLine 0-based line => 'added'|'modified'|'deleted'
     */
    public function setGitMarks(array $byLine): void;

    /**
     * @param array<int, string> $byLine 0-based line => trailing annotation (blame); [] clears
     */
    public function setLineAnnotations(array $byLine): void;

    /**
     * @param list<int> $lines            0-based lines carrying a breakpoint in this file
     * @param list<int> $conditionalLines the subset carrying a condition/hit-count (◆ instead of ●)
     * @param list<int> $logpointLines    the subset carrying a log message (◇ — logs instead of pausing)
     */
    public function setBreakpointLines(array $lines, array $conditionalLines = [], array $logpointLines = []): void;

    /** Highlight (or clear) the debugger's stopped line. */
    public function setDebugLine(?int $line): void;

    /** @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $byLine */
    public function setDiagnostics(array $byLine): void;

    /**
     * Supply LSP fold regions for the current document version — {@see \Porta\Editor\FoldModel}
     * falls back to its brace scan once the document changes.
     *
     * @param list<array{0: int, 1: int}> $regions [startLine, endLine]
     */
    public function setFoldRegions(array $regions): void;

    /**
     * Replace the inlay hints (parameter names / inferred types) shown inline.
     *
     * @param array<int, list<array{col: int, text: string}>> $hints line => byte-col hints
     */
    public function setInlayHints(array $hints): void;
}
