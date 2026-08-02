<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Receives version-control change marks for a file — the gutter's
 * added/modified/deleted stripe.
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
    /** @param array<int, 'added'|'modified'|'deleted'> $marks 0-based line => kind; an empty array clears */
    public function applyChangeMarks(string $path, array $marks): void;
}
