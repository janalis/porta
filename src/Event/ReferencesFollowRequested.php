<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The user moved or renamed a file in the tree — have the code that names it
 * follow: its namespace, and the `use` statements that point at it.
 *
 * A command, with one listener ({@see \Porta\Lsp\FileMoveFollower}), and
 * apart from {@see FileRenamed} on purpose: that fact is dispatched for a
 * language server's own `RenameFile` as well, and asking the server to follow
 * a move it made would ask it to redo its own refactoring. Only the tree
 * dispatches this. Both paths are canonical, as {@see FileRenamed}'s are.
 */
final readonly class ReferencesFollowRequested
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
