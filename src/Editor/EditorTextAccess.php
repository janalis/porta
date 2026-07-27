<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Read *and* write access to the active buffer's text: the seam handed to
 * plugins through {@see \Porta\Plugin\PluginApi}. Implemented by the editor
 * widget.
 *
 * This is the public plugin surface, so it is deliberately the smallest set
 * that supports the documented plugin verbs — read the file and its text,
 * insert at the caret, jump to a position. A plugin holding the widget itself
 * could reach ninety more methods, none of them API.
 */
interface EditorTextAccess extends ActiveDocumentProvider
{
    /**
     * Insert text at the caret through the full change pipeline (undo history,
     * LSP didChange, invalidation) — the programmatic seam for plugins.
     */
    public function insertText(string $text): void;

    /**
     * Move the cursor to an LSP position and scroll it into view.
     */
    public function jumpTo(int $line, int $utf16Col): void;
}
