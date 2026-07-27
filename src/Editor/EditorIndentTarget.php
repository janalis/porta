<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\LanguageIndent;

/**
 * Receives the per-language indent configuration. Implemented by the editor
 * widget, which re-applies it to the current document immediately and to every
 * document shown afterwards.
 *
 * Separate from {@see EditorViewOptions} because this is language *policy* fed
 * by the language settings page, not a display toggle — and its one caller has
 * no business with the render flags.
 */
interface EditorIndentTarget
{
    /**
     * Set the per-language indent config (languageId => {style, size}); re-applies
     * to the current document immediately and to every document shown afterwards.
     *
     * @param array<string, LanguageIndent> $indents
     */
    public function setLanguageIndents(array $indents): void;
}
