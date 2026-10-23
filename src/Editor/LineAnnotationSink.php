<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Receives the text a plugin writes onto the active document's lines: dim
 * trailing annotations — the slot `git blame` uses — and blocks of rows under
 * a line.
 *
 * Narrow for {@see DiagnosticsSink}'s reason: {@see EditorDecorationSink} has
 * twelve setters covering gutter marks, breakpoints and the stopped-line band,
 * and a plugin that wants to annotate lines has no business with any of them.
 * Implemented by {@see DecorationStore}, which already carried the method;
 * this only names the slice.
 *
 * The map REPLACES whatever THAT OWNER was showing, so an empty array clears
 * its notes and leaves another writer's alone. The widget drops annotations
 * whenever it shows a document, so a writer that wants them to survive a
 * buffer switch has to re-publish.
 */
interface LineAnnotationSink
{
    /**
     * @param array<int, string> $byLine 0-based line => the text shown after it
     * @param string             $owner  who is writing — a plugin's name; notes from two owners on one line are joined
     */
    public function setLineAnnotations(array $byLine, string $owner = ''): void;

    /**
     * Rows of text under the active document's lines — a whole conversation
     * under the line it is about — kept per owner like the notes.
     *
     * @param array<int, list<string>> $byLine 0-based line => the rows under it
     */
    public function setLineBlocks(array $byLine, string $owner = ''): void;
}
