<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Widget\CodeEditorWidget;

/**
 * The caret's scope path for the breadcrumbs bar. The PHP scan is memoized by
 * document version so caret-only moves (the common case) just re-select from
 * the cached scopes; other languages select from the language server's own
 * symbols ({@see \Porta\Lsp\DocumentScopes}), which reach the same shape.
 */
final class BreadcrumbTrailProvider
{
    public function __construct(
        // Deliberately concrete: scopes() lives in no role interface, and
        // widening ActiveDocumentProvider for one consumer is not worth it.
        private readonly CodeEditorWidget $editor,
    ) {}

    /** @return list<array{label: string, line: int}> */
    public function trail(): array
    {
        // `scopes()` chooses the source — the offline PHP scan or the language
        // server's symbols — so the trail and the sticky band above it can
        // never disagree about what encloses the caret.
        return BreadcrumbScanner::select($this->editor->scopes(), $this->editor->getDocument()->getCursorLine());
    }
}
