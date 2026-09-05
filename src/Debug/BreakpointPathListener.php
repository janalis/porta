<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Breakpoints follow a renamed file and retire with a deleted one — see
 * {@see \Porta\Editor\BookmarkPathListener} for why the two stores are
 * re-keyed by a listener apiece rather than one shared listener.
 */
final readonly class BreakpointPathListener
{
    public function __construct(
        private BreakpointStore $breakpoints,
        private EditorDecorations $decorations,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        $this->breakpoints->rename($event->from, $event->to);
        $this->decorations->apply($event->to);
    }

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        // A breakpoint in a file that no longer exists is worse than a stale
        // mark: the adapter is handed it on the next launch, and a debugger
        // that rejects one unknown path can reject the whole set.
        $this->breakpoints->forget($event->path);
    }
}
