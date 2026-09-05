<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The jump stack follows a renamed file and forgets a deleted one.
 *
 * A third listener in this namespace rather than a clause in
 * {@see EditHistoryPathListener}: the two lists answer different questions —
 * where you JUMPED from, and where you were TYPING — and a listener named for
 * one that quietly re-keys the other is how the next store gets forgotten. This
 * one WAS forgotten, for exactly as long as it had no class of its own.
 */
final readonly class JumpHistoryPathListener
{
    public function __construct(
        private JumpHistory $history,
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
