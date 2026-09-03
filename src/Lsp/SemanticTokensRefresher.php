<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the semantic colouring pointed at the file you are looking at, on the
 * same two facts {@see CodeLensRefresher} uses and for the same reason: a token
 * pass is a real round trip over the whole document, so a save is the settle
 * point rather than every keystroke.
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
}
