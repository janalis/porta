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

    /** The `refactor` kinds alone — PhpStorm's "Refactor This". */
    public function requestRefactorings(int $line, int $col): void;

    /** `refactor.inline`, applied straight away when the server offers exactly one. */
    public function requestInline(int $line, int $col): void;

    /** Reformat the whole file. */
    public function requestReformat(): void;
}
