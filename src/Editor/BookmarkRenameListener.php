<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bookmarks follow a renamed file.
 *
 * Its own class rather than an attribute on {@see Bookmarks} so the store stays
 * a pure map with no event coupling, and rather than one listener re-keying
 * every path-keyed store because that would need an `Editor > Debug` edge —
 * `Debug > Editor` already exists, so the pair would be a namespace cycle.
 * {@see \Porta\Debug\BreakpointRenameListener} is the same shape on the other
 * side of that line.
 */
final readonly class BookmarkRenameListener
{
    public function __construct(
        private Bookmarks $bookmarks,
        private BookmarkDecorations $decorations,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        $this->bookmarks->rename($event->from, $event->to);
        // The buffer showing it was re-pointed, not re-shown, so nothing else
        // repaints the gutter for the new path.
        $this->decorations->apply($event->to);
    }
}
