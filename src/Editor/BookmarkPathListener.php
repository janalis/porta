<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bookmarks follow a renamed file, and are retired with a deleted one.
 *
 * Its own class rather than an attribute on {@see Bookmarks} so the store stays
 * a pure map with no event coupling, and rather than one listener re-keying
 * every path-keyed store because that would need an `Editor > Debug` edge —
 * `Debug > Editor` already exists, so the pair would be a namespace cycle.
 * {@see \Porta\Debug\BreakpointPathListener} is the same shape on the other
 * side of that line.
 */
final readonly class BookmarkPathListener
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

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        // No repaint to follow it with: the deleting side closes the buffer,
        // and there is no new path to paint into.
        $this->bookmarks->forget($event->path);
    }
}
