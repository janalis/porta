<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The working tree changed — a stage, a stash, a commit, a reset, a pull, or
 * any of the file tree's own verbs: new file, new folder, move, copy, delete.
 *
 * A fact: whatever moved has already moved when this is dispatched. It exists
 * so a version-control plugin can recompute change marks without core knowing
 * which plugin, or which VCS, is listening — and so the verbs that cause it can
 * say so truthfully. {@see GitCommitted} names one specific cause and would be
 * a lie after a stage.
 *
 * The second half of that list was missing for a long time, and it is worth
 * saying why it belongs: this event is defined by its LISTENER's obligation —
 * "the tree moved, recompute" — not by who moved it. Core creating a file in a
 * repository changes what `git status` says exactly as much as `git add` does.
 * {@see \Porta\Tree\TreeOperations::withFs()} is where core says so.
 *
 * Payload-free on purpose: a producer recomputes for the file it cares about,
 * and naming one path here would be wrong for the operations (pull, reset)
 * that touch many.
 */
final readonly class WorkingTreeChanged implements PluginObservable {}
