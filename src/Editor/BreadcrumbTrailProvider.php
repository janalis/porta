<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Widget\CodeEditorWidget;

/**
 * The caret's scope path for the breadcrumbs bar. The PHP scan is memoized by
 * document version so caret-only moves (the common case) just re-select from
 * the cached scopes; non-PHP files show no bar.
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
        $path = $this->editor->getFilePath();
        if (null === $path || !str_ends_with($path, '.php')) {
            return [];
        }

        return BreadcrumbScanner::select($this->editor->scopes(), $this->editor->getDocument()->getCursorLine());
    }
}
