<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's refactoring hooks. Implemented by
 * {@see \Porta\Lsp\RefactorController}, which owns the whole rename flow
 * (prepareRename validation → prompt → rename); tests inject fakes.
 */
interface EditorRefactorHandler
{
    /** Validate + begin a rename at (line, utf16 col) of $word. */
    public function requestPrepareRename(int $line, int $col, string $word): void;

    /** Quick fixes at (line, utf16 col). */
    public function requestCodeActions(int $line, int $col): void;

    /** Reformat the whole file. */
    public function requestReformat(): void;
}
