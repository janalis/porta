<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\Event\LspStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the semantic colouring pointed at the file you are looking at. A token
 * pass is a real round trip over the whole document, so a save is the settle
 * point rather than every keystroke — plus a server becoming ready, without
 * which the file you launch with is never coloured at all.
 *
 * Between saves the lexer's colours stand, which is why the semantic layer had
 * to be additive rather than a replacement — a stale semantic answer over an
 * edited line simply stops applying, instead of leaving the line uncoloured.
 *
 * This listener is also what CONSTRUCTS the controller: nothing else injects
 * it, and a service nothing injects is never built.
 */
final readonly class SemanticTokensRefresher
{
    public function __construct(
        private SemanticTokensController $tokens,
    ) {}

    #[AsEventListener]
    public function onActiveBufferChanged(ActiveBufferChanged $event): void
    {
        $this->tokens->requestSemanticTokens();
    }

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->tokens->requestSemanticTokens();
    }

    /**
     * A server coming up is the third settle point, and the one the other two
     * cannot cover: the file you launch with is opened BEFORE any session
     * exists, so its `ActiveBufferChanged` finds no capability and asks
     * nothing. Without this the buffer you actually start looking at is the one
     * buffer that never gets coloured — until you happen to save it.
     */
    #[AsEventListener]
    public function onLspStatusChanged(LspStatusChanged $event): void
    {
        $this->tokens->requestSemanticTokens();
    }
}
