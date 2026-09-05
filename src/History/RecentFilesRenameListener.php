<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The recent-files list follows a renamed file.
 *
 * Its own class rather than an attribute on the store, for the reason
 * {@see \Porta\Editor\BookmarkRenameListener} gives: the store stays a list of
 * paths with no event coupling, and each path-keyed store answers for its own
 * keys.
 */
final readonly class RecentFilesRenameListener
{
    public function __construct(
        private RecentFilesStore $store,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        $this->store->rename($event->from, $event->to);
    }
}
