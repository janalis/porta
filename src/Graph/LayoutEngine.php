<?php

declare(strict_types=1);

namespace Porta\Graph;

/**
 * Turns DOT source into a {@see GraphLayout} asynchronously — the seam the
 * graph viewer depends on, so tests (and any future non-Graphviz engine)
 * substitute a fake without a dot binary anywhere near the suite.
 */
interface LayoutEngine
{
    /** The resolved dot binary path, or null when graphviz is not installed. */
    public function binary(): ?string;

    /**
     * Lay out $dot; exactly one callback fires — unless a newer layout()
     * supersedes this one first (latest wins, the superseded call goes silent).
     *
     * @param \Closure(GraphLayout): void $onDone
     * @param \Closure(string): void      $onError receives dot's stderr (or a parse failure message)
     */
    public function layout(string $dot, \Closure $onDone, \Closure $onError): void;

    /**
     * Render $dot to $outPath.
     *
     * @param \Closure(?string): void $onDone null on success, the error text on failure
     */
    public function export(string $dot, GraphExportFormat $format, string $outPath, \Closure $onDone): void;

    /**
     * Render $dot to PNG bytes at $dpi — the picture the viewer shows, as
     * opposed to {@see layout()}'s geometry. Bytes, not a file: the child's
     * stdout goes straight to the decoder, so there is nothing to clean up.
     *
     * **$dpi 72 means one PostScript point per pixel**, which makes the PNG's
     * pixel grid identical to the screen-down points {@see DotJsonParser}
     * produces — that identity is what lets the viewer share ONE set of
     * zoom/pan and hit-test coordinates between the bitmap and the layout.
     * Higher values oversample for zoom.
     *
     * Like {@see layout()} this is one slot, latest wins: a superseded call
     * goes silent.
     *
     * @param \Closure(string): void $onPng   the PNG bytes
     * @param \Closure(string): void $onError dot's stderr (or why it could not run)
     */
    public function render(string $dot, int $dpi, \Closure $onPng, \Closure $onError): void;
}
