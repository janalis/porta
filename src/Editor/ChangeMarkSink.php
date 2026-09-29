<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Receives the gutter marks a writer publishes for a file: version-control
 * change marks — the added/modified/deleted stripe — and the lines a
 * conversation (a review comment) is attached to.
 *
 * Path-keyed rather than active-buffer-keyed, for {@see DiagnosticsSink}'s
 * reason: the answer arrives asynchronously and the user may have switched
 * tabs in the meantime, so the writer names the file it computed and the sink
 * decides whether that file is on screen. Implemented by {@see BufferService},
 * which stores the marks on the buffer and pushes them to the editor only when
 * that buffer is active — the same replay it does on every buffer switch.
 *
 * The KINDS are core's vocabulary, not the writer's: a producer supplies lines
 * and says which of the three each is, and core maps those to `Role::Vcs*`.
 * That is what stops a plugin inventing a colour, which is what the theme lock
 * exists for.
 */
interface ChangeMarkSink
{
    /** @param array<int, ChangeMarkKind> $marks 0-based line => kind; an empty array clears */
    public function applyChangeMarks(string $path, array $marks): void;

    /**
     * Replace $owner's comment marks for a file; another owner's stay.
     *
     * @param array<int, string> $marks 0-based line => one-line summary; an empty array clears
     */
    public function applyCommentMarks(string $path, array $marks, string $owner): void;
}
