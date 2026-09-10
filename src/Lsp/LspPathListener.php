<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A file that moved or was deleted is CLOSED at the language server.
 *
 * The gap this fills: a move re-points the buffer — close the old path, open
 * the new one — but only the second half ever reached the server, because
 * `didClose` was sent from the two tab-closing paths in {@see
 * \Porta\Editor\BufferService} and from nowhere else. The server went on
 * holding a document at a URI with no file behind it, and everything it
 * indexed from that copy stayed live: the class defined twice, "go to
 * definition" offering a path that no longer exists.
 *
 * Its own class, and the naming, follow the path-listener family — {@see
 * \Porta\Editor\BookmarkPathListener}, {@see \Porta\Debug\BreakpointPathListener}
 * and four more — each of which re-keys or retires ONE store on the same two
 * facts. The session pool is that store here.
 *
 * Ordering is deliberate and safe either way: the mover has already sent
 * `didOpen` for the new path by the time the fact is dispatched, and the two
 * URIs are different, so a close arriving after an open closes nothing the
 * open just created.
 */
final readonly class LspPathListener
{
    public function __construct(
        private LspSessionPool $lsp,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        // The OLD path — and, when it names a directory, every open file that
        // used to be inside it. The new paths were opened by the mover.
        $this->lsp->notifyDidCloseUnder($event->from);
    }

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        $this->lsp->notifyDidCloseUnder($event->path);
    }
}
