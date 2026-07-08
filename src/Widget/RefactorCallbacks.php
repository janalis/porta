<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's refactoring hooks (routed to the LSP refactor controller):
 * rename, code actions and whole-file reformat. One of the five groups
 * {@see EditorCallbacks} fans out into its flat surface.
 */
final readonly class RefactorCallbacks
{
    /**
     * @param (\Closure(string, int, int): void)|null $onRename          rename to $newName at (line, utf16 col); LSP-free fallback
     * @param (\Closure(int, int, string): void)|null $onRenameRequested validate+begin a rename at (line, utf16 col, word)
     * @param (\Closure(int, int): void)|null         $onCodeAction      quick fixes at (line, utf16 col)
     * @param (\Closure(): void)|null                 $onReformat        reformat the whole file
     */
    public function __construct(
        public ?\Closure $onRename = null,
        public ?\Closure $onRenameRequested = null,
        public ?\Closure $onCodeAction = null,
        public ?\Closure $onReformat = null,
    ) {}
}
