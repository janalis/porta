<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's navigation hooks (all routed to the LSP navigation controller):
 * definition/implementation/type-definition jumps, back-navigation, quick doc,
 * find-usages, the file-structure popup and peek-definition. One of the five
 * groups {@see EditorCallbacks} fans out into its flat surface.
 */
final readonly class NavigationCallbacks
{
    /**
     * @param (\Closure(int, int): void)|null $onDefinitionRequested     definition at (line, utf16 col)
     * @param (\Closure(int, int): void)|null $onImplementationRequested implementation at (line, utf16 col)
     * @param (\Closure(int, int): void)|null $onTypeDefinitionRequested type definition at (line, utf16 col)
     * @param (\Closure(): void)|null         $onNavigateBack            navigate back after a definition jump
     * @param (\Closure(int, int): void)|null $onQuickDoc                quick documentation at (line, utf16 col)
     * @param (\Closure(int, int): void)|null $onFindUsages              find usages at (line, utf16 col)
     * @param (\Closure(): void)|null         $onFileStructure           file structure popup
     * @param (\Closure(int, int): void)|null $onPeek                    peek definition at (line, utf16 col)
     */
    public function __construct(
        public ?\Closure $onDefinitionRequested = null,
        public ?\Closure $onImplementationRequested = null,
        public ?\Closure $onTypeDefinitionRequested = null,
        public ?\Closure $onNavigateBack = null,
        public ?\Closure $onQuickDoc = null,
        public ?\Closure $onFindUsages = null,
        public ?\Closure $onFileStructure = null,
        public ?\Closure $onPeek = null,
    ) {}
}
