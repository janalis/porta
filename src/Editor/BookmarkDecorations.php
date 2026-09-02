<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\BufferSwitched;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Paints a file's bookmarks into the editor gutter, the way
 * {@see \Porta\Debug\EditorDecorations} does for breakpoints: the store owns
 * them, and every buffer show re-applies the ones belonging to that file.
 */
final readonly class BookmarkDecorations
{
    public function __construct(
        private Bookmarks $bookmarks,
        private EditorDecorationSink $editor,
    ) {}

    public function apply(string $path): void
    {
        $this->editor->setBookmarkLines($this->bookmarks->linesFor($path));
    }

    /** Every text show re-applies; a viewer tab has no gutter to paint. */
    #[AsEventListener]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if (!$event->buffer->isViewer()) {
            $this->apply($event->buffer->path);
        }
    }
}
