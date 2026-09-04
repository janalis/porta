<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * Keeps {@see DocumentScopes} filled for the buffer on screen, from
 * `textDocument/documentSymbol`.
 *
 * The request already existed for the Structure panel, which FLATTENS the tree
 * into dock rows — dropping the nesting and the full ranges, which are exactly
 * what a breadcrumb trail is made of. This asks for the same answer and keeps
 * the shape instead.
 *
 * **PHP is deliberately skipped.** Its offline scanner is instant, needs no
 * server, and already drives the bar; spending a round trip to arrive at the
 * same trail would be worse in every way.
 *
 * **Gated on the capability**, like every other optional request here.
 */
#[AsIdeService]
final readonly class DocumentScopeController
{
    public function __construct(
        private LspSessionPool $lsp,
        private CodeEditorWidget $editor,
        private DocumentScopes $scopes,
        private Tui $tui,
    ) {}

    public function requestScopes(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || str_ends_with($path, '.php')) {
            return;
        }

        if (false === ($this->lsp->serverCapability($path, 'documentSymbolProvider') ?? false)) {
            return;
        }

        $this->lsp->request(
            'textDocument/documentSymbol',
            ['textDocument' => ['uri' => Uri::fromPath($path)]],
            function (mixed $result) use ($path): void {
                // A stale answer after a buffer switch describes a file that is
                // no longer on screen; its trail would name the wrong classes.
                if (!\is_array($result) || $path !== $this->editor->getFilePath()) {
                    return;
                }

                /** @var list<array<array-key, mixed>|scalar|null> $result */
                $this->scopes->set($path, LspMapper::symbolScopes($result));
                $this->tui->requestRender();
            },
        );
    }
}
