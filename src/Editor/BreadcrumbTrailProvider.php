<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Lsp\DocumentScopes;
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
        private readonly DocumentScopes $documentScopes,
    ) {}

    /** @return list<array{label: string, line: int}> */
    public function trail(): array
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            return [];
        }

        // PHP is scanned offline — instant, and right with no server running.
        // Every other language was simply blank until the server's own symbols
        // were kept in a shape `select()` could read.
        $scopes = str_ends_with($path, '.php')
            ? $this->editor->scopes()
            : $this->documentScopes->forPath($path);

        return BreadcrumbScanner::select($scopes, $this->editor->getDocument()->getCursorLine());
    }
}
