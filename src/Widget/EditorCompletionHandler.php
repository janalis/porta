<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Editor\Document;

/**
 * The editor's completion hooks. Implemented by
 * {@see \Porta\Lsp\CompletionController}; tests inject fakes.
 */
interface EditorCompletionHandler
{
    public function requestCompletion(int $line, int $utf16Col): void;

    /**
     * Resolve an accepted item (deferred auto-imports).
     *
     * @param array<string, mixed> $item
     */
    public function resolveCompletion(array $item): void;

    public function requestSignatureHelp(int $line, int $col): void;

    /** The document content changed (debounced didChange). */
    public function scheduleDidChange(Document $doc): void;

    /**
     * The live templates in effect for a language — the built-in set with the
     * project's own `languages.<id>.templates` layered over it. A query rather
     * than a push because the answer depends on the file the caret is in, which
     * only the editor knows.
     *
     * @return array<string, string> abbreviation => LSP snippet body
     */
    public function templatesFor(string $languageId): array;

    /**
     * The viewport scrolled without an edit (wheel detach). Viewport-scoped
     * features — inlay hints — re-fetch once the scrolling settles, if the
     * view left the last range they requested.
     */
    public function viewportScrolled(): void;
}
