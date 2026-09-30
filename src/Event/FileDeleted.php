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
 *
 * **It carries what went.** `$previousText` is the file's text as the user
 * last had it — the open buffer's, unsaved edits included, else the disk's —
 * or null for a directory or a file too large to be text. It is what makes a
 * delete recoverable: `local-history` keeps it as a snapshot, and restoring
 * that snapshot brings the file back.
 *
 * Observable by plugins, for that recovery and for anything else a plugin
 * keys by path.
 */
final readonly class FileDeleted implements PluginObservable
{
    public function __construct(
        public string $path,
        public ?string $previousText = null,
    ) {}
}
