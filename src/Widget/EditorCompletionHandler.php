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
}
