<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The recent-files list follows a renamed file, and drops a deleted one.
 *
 * Its own class rather than an attribute on the store, for the reason
 * {@see \Porta\Editor\BookmarkPathListener} gives: the store stays a list of
 * paths with no event coupling, and each path-keyed store answers for its own
 * keys.
 */
final readonly class RecentFilesPathListener
{
    public function __construct(
        private RecentFilesStore $store,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        $this->store->rename($event->from, $event->to);
    }

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        $this->store->forget($event->path);
    }
}
