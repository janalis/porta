<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\Event\LspStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The three settle points a capability-gated request needs: a buffer switch, a
 * save, and a server becoming ready — without the last, the file you launch
 * with is opened before any session exists and never gets a trail.
 *
 * This listener is also what CONSTRUCTS the controller: nothing else injects it.
 */
final readonly class DocumentScopeRefresher
{
    public function __construct(
        private DocumentScopeController $scopes,
    ) {}

    #[AsEventListener]
    public function onActiveBufferChanged(ActiveBufferChanged $event): void
    {
        $this->scopes->requestScopes();
    }

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->scopes->requestScopes();
    }

    #[AsEventListener]
    public function onLspStatusChanged(LspStatusChanged $event): void
    {
        $this->scopes->requestScopes();
    }
}
