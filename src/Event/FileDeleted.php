<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A file or directory was deleted — by the tree's delete, or by a language
 * server's `DeleteFile` inside a refactoring.
 *
 * The counterpart of {@see FileRenamed}, and a FACT for the same reason:
 * everything keyed by path has to hear about it, and each store knows only its
 * own keys. A rename moves a key; this one retires it.
 *
 * **The path is canonical**, resolved BEFORE the removal — after it,
 * `realpath()` returns false and there is nothing left to resolve.
 *
 * **A directory carries its whole tree.** Deleting `src/Old/` deletes every
 * file under it, and a path-keyed store is keyed by the FILES, so a listener
 * asks {@see \Porta\Support\ProjectPaths::isUnder()} rather than comparing for
 * equality. For a plain file that question answers the same as `===` — nothing
 * is ever keyed under `A.php/`.
 */
final readonly class FileDeleted
{
    public function __construct(
        public string $path,
    ) {}
}
