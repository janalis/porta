<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\DropConflict;

/**
 * A file-tree drag was dropped onto a directory: move (or copy) these paths
 * into it.
 *
 * A COMMAND — exactly one listener, {@see \Porta\Tree\TreeOperations}, which
 * owns every filesystem operation the tree offers. The router that tracks the
 * drag has no business performing one, and {@see \Porta\Input\MouseRouter} is
 * already at both of mago's ceilings (its parameter list and its cyclomatic
 * complexity are the two the thresholds are bisected against), so reaching the
 * operations through the container was not available either.
 *
 * The same shape {@see DockTabMoveRequested} uses for the same reason: the
 * widget that owns the gesture owns no model, and says what happened instead
 * of doing it.
 *
 * Paths are absolute and raw — the tree's own space, not realpath's. The
 * listener resolves them, because only it knows which of them still exist by
 * the time it runs.
 *
 * A drop from outside the IDE — a file dragged in from Finder — rides the same
 * command with {@see DropConflict::KeepBoth}: its sources lie anywhere on disk,
 * it always copies, and a taken name becomes `name (2)` rather than a question.
 */
final readonly class TreeDropRequested
{
    /** @param list<string> $sources absolute paths, in tree order */
    public function __construct(
        public array $sources,
        public string $targetDir,
        public bool $copy,
        public DropConflict $conflict = DropConflict::Ask,
    ) {}
}
