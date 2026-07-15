<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Event\BufferSwitched;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Applies a file's debug decorations — the breakpoint gutter dots and the
 * stopped-line band — to the editor. The buffer service calls this on every
 * buffer switch; the debug controller calls it when the debuggee stops.
 */
final class EditorDecorations
{
    public function __construct(
        private readonly BreakpointStore $breakpoints,
        private readonly DebugSessionState $state,
        private readonly CodeEditorWidget $editor,
    ) {}

    public function apply(string $path): void
    {
        $this->editor->setBreakpointLines($this->breakpoints->linesFor($path), $this->breakpoints->conditionalLinesFor($path));
        $this->editor->setDebugLine($this->state->stoppedLineFor($path));
    }

    /** Every text show re-applies (re-shows included); viewer tabs have no gutter. */
    #[AsEventListener]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if (!$event->buffer->isImage() && !$event->buffer->isHelp()) {
            $this->apply($event->buffer->path);
        }
    }
}
