<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Event\BufferSwitched;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Feeds {@see RecentFilesStore} from the editor area.
 *
 * Listens to {@see BufferSwitched} rather than the deduped
 * {@see \Porta\Event\ActiveBufferChanged}, because that one carries a path and
 * this needs the buffer: a viewer tab (help page, image, graph) must never be
 * recorded. Its path would come back as an offer to reopen a help page as
 * editable source — the same trap {@see SessionRestorer} sidesteps on save.
 * The store dedups a re-show itself, so listening to every show costs nothing.
 */
final readonly class RecentFilesRecorder
{
    public function __construct(
        private RecentFilesStore $store,
    ) {}

    #[AsEventListener]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if ($event->buffer->isViewer()) {
            return;
        }

        $this->store->record($event->buffer->path);
    }
}
