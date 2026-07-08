<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Editor\Document;

/**
 * The editor's completion/document-change hooks: request and resolve
 * completions, signature help, and the debounced didChange notification. One of
 * the five groups {@see EditorCallbacks} fans out into its flat surface.
 */
final readonly class CompletionCallbacks
{
    /**
     * @param (\Closure(int, int): void)|null             $onCompletionRequested request completion at (line, utf16 col)
     * @param (\Closure(array<string, mixed>): void)|null $onCompletionResolve   resolve an accepted item (deferred auto-imports)
     * @param (\Closure(int, int): void)|null             $onSignatureHelp       signature help at (line, utf16 col)
     * @param (\Closure(Document): void)|null             $onChanged             the document content changed
     */
    public function __construct(
        public ?\Closure $onCompletionRequested = null,
        public ?\Closure $onCompletionResolve = null,
        public ?\Closure $onSignatureHelp = null,
        public ?\Closure $onChanged = null,
    ) {}
}
