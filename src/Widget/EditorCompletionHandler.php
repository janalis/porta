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

    /**
     * Ask for an item's type, for the servers that do not send one until asked.
     *
     * Separate from {@see resolveCompletion()} because they answer different
     * questions at different times: that one runs when an item is ACCEPTED and
     * applies the auto-import edits it comes back with, this one runs while the
     * popup is open and only reads the type. One call doing both would apply
     * edits for an item the user has not chosen.
     *
     * @param array<string, mixed>   $item     the raw LSP CompletionItem
     * @param \Closure(string): void $onDetail called only when a type comes back
     */
    public function resolveCompletionDetail(array $item, \Closure $onDetail): void;

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
