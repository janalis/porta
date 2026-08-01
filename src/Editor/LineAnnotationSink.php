<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Receives dim trailing per-line annotations for the active document — the
 * slot `git blame` uses, and the only editor decoration a plugin may write.
 *
 * Narrow for {@see DiagnosticsSink}'s reason: {@see EditorDecorationSink} has
 * eight setters covering gutter marks, breakpoints and the stopped-line band,
 * and a plugin that wants to annotate lines has no business with any of them.
 * Implemented by {@see \Porta\Widget\CodeEditorWidget}, which already carried
 * the method; this only names the slice.
 *
 * The map REPLACES whatever was showing, so an empty array clears. The widget
 * drops annotations whenever it shows a document, so a writer that wants them
 * to survive a buffer switch has to re-publish.
 */
interface LineAnnotationSink
{
    /** @param array<int, string> $byLine 0-based line => the text shown after it */
    public function setLineAnnotations(array $byLine): void;
}
