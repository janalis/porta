<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The working tree changed under version control — a stage, a stash, a commit,
 * a reset, a pull.
 *
 * A fact: whatever moved has already moved when this is dispatched. It exists
 * so a version-control plugin can recompute change marks without core knowing
 * which plugin, or which VCS, is listening — and so the verbs that cause it can
 * say so truthfully. {@see GitCommitted} names one specific cause and would be
 * a lie after a stage.
 *
 * Payload-free on purpose: a producer recomputes for the file it cares about,
 * and naming one path here would be wrong for the operations (pull, reset)
 * that touch many.
 */
final readonly class WorkingTreeChanged implements PluginObservable {}
