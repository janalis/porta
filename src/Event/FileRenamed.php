<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A file was renamed on disk — by the tree's rename, or by a language server's
 * `RenameFile` inside a refactoring.
 *
 * A FACT, so any number of listeners: everything keyed by path has to follow,
 * and each store knows only its own keys. Distinct from
 * {@see BufferRepointRequested}, which is a COMMAND with exactly one listener
 * and exists to break a container cycle — this one carries no obligation.
 *
 * **Both paths are canonical**, spelled the way buffers key them: the
 * dispatcher resolves them, because only it can. By the time a listener runs,
 * `$from` no longer exists and `realpath()` on it returns false.
 */
final readonly class FileRenamed
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
