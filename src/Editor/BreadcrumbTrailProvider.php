<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;

/**
 * The navigation bar's trail: where the active file sits in the project, then
 * the caret's scope path inside it. The PHP scan is memoized by document
 * version so caret-only moves (the common case) just re-select from the
 * cached scopes; other languages select from the language server's own
 * symbols ({@see \Porta\Lsp\DocumentScopes}), which reach the same shape.
 */
final readonly class BreadcrumbTrailProvider
{
    public function __construct(
        // Deliberately concrete: scopes() lives in no role interface, and
        // widening ActiveDocumentProvider for one consumer is not worth it.
        private CodeEditorWidget $editor,
        private BufferManager $buffers,
        private ProjectPaths $paths,
    ) {}

    /** @return list<array{label: string, line: ?int, path?: ?string}> */
    public function trail(): array
    {
        // `scopes()` chooses the source — the offline PHP scan or the language
        // server's symbols — so the trail and the sticky band above it can
        // never disagree about what encloses the caret.
        return [...$this->pathCrumbs(), ...BreadcrumbScanner::select($this->editor->scopes(), $this->editor->getDocument()->cursorLine)];
    }

    /**
     * One crumb per folder from the project root down, then the file, each
     * carrying the path a click reveals in the tree. None for a viewer tab or
     * a titled buffer, whose path names no file the tree holds; a file
     * outside the project is its name alone, since its folders are not the
     * project's.
     *
     * @return list<array{label: string, line: null, path: string}>
     */
    private function pathCrumbs(): array
    {
        $buffer = $this->buffers->active();
        if (null === $buffer || $buffer->isViewer() || null !== $buffer->title) {
            return [];
        }
        $relative = $this->paths->relative($buffer->path);
        if (\str_starts_with($relative, '/')) {
            return [['label' => \basename($relative), 'line' => null, 'path' => $buffer->path]];
        }
        $crumbs = [];
        $walked = '';
        foreach (\explode('/', $relative) as $segment) {
            $walked = '' === $walked ? $segment : $walked.'/'.$segment;
            $crumbs[] = ['label' => $segment, 'line' => null, 'path' => $this->paths->absolute($walked)];
        }

        return $crumbs;
    }
}
