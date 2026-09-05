<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The edit-location history follows a renamed file, and forgets a deleted one.
 *
 * Its own class beside {@see BookmarkPathListener} rather than folded into
 * it: the two stores are unrelated, and a listener named for one that quietly
 * re-keys the other is how a store gets forgotten the next time.
 */
final readonly class EditHistoryPathListener
{
    public function __construct(
        private EditHistory $history,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        $this->history->rename($event->from, $event->to);
    }

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        $this->history->forget($event->path);
    }
}
