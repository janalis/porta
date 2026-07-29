<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The LSP reactions to buffer lifecycle events: didSave notifications and the
 * inlay-hint request on every text show. (didOpen stays inline in BufferService
 * — it must precede showBuffer so the inlay request is ordered after the
 * server knows the file.)
 */
final class LspBufferListener
{
    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly CompletionController $completion,
    ) {}

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->lsp->notifyDidSave($event->buffer->path);
    }

    #[AsEventListener]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if (!$event->buffer->isViewer()) {
            $this->lsp->whenReady(fn () => $this->completion->requestInlayHints());
        }
    }
}
