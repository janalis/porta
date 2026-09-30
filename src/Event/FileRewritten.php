<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A file with no open buffer was rewritten on disk by a refactoring.
 *
 * A FACT, so any number of listeners, and it carries the text the write
 * REPLACED — not the text it produced. That is the whole point of the event:
 * the new content is on disk and needs no announcement, while the old content
 * exists nowhere else the moment `dumpFile()` returns.
 *
 * **What it is for.** A cross-file rename touches every file that mentions the
 * symbol, and most of them have no buffer open. Those were written blind:
 * `local-history` snapshots on {@see BufferSaved}, which needs a buffer, and
 * the editor's undo stack is per document — so a twenty-file rename left
 * eighteen files changed with NO recovery path at all, which
 * the decision history carried as a known hole (`docs/internals/decisions/history-02.md`,
 * "A rewritten file keeps what it replaced").
 *
 * It is deliberately not a save. A save is something the user did and the
 * buffer's own history covers it; this is something a server asked for, to a
 * file the user may never have opened.
 *
 * Observable by plugins, because the recovery UI is one — `local-history` turns
 * this into a snapshot, so `Ctrl+Alt+H` on the file offers the version from
 * before the refactoring.
 */
final readonly class FileRewritten implements PluginObservable
{
    public function __construct(
        public string $path,
        /** What was on disk before the write. */
        public string $previousText,
    ) {}
}
