<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Event\FileRenamed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Breakpoints follow a renamed file — see
 * {@see \Porta\Editor\BookmarkRenameListener} for why the two stores are
 * re-keyed by a listener apiece rather than one shared listener.
 */
final readonly class BreakpointRenameListener
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
}
