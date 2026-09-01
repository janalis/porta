<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Editor\ChangeMarkKind;

/**
 * Receives the working tree's per-FILE version-control status — what the file
 * tree marks its rows with.
 *
 * The per-line counterpart is {@see \Porta\Editor\ChangeMarkSink}, and the two
 * answer different questions from different commands: that one is a diff of the
 * file you are looking at, this one is the status of every file in the project,
 * including ones no buffer has ever opened. A whole map replaces whole, because
 * a file dropping OUT of it is the news that it went back to unmodified.
 *
 * Owned by the consuming namespace (the {@see \Porta\Workbench\Dock\DockToolSource}
 * precedent), so `Porta\Tree` never depends on `Porta\Plugin` even though every
 * row here arrives from a plugin.
 *
 * The KINDS are core's vocabulary, exactly as they are for change marks: a
 * producer says which of the three a file is, and core owns what that looks
 * like. That is what stops a plugin inventing a colour the theme lock never saw.
 */
interface FileStatusSink
{
    /** @param array<string, ChangeMarkKind> $statuses absolute path => kind; an empty array clears every file */
    public function applyFileStatuses(array $statuses): void;
}
