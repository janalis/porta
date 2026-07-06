<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Editor\Document;

/**
 * The editor widget's outbound callbacks, built once by the coordinator's
 * wiring (and by tests, with only the hooks a scenario needs). Grouping the
 * two dozen hooks into one readonly value keeps CodeEditorWidget from
 * doubling as an event bus of individual setters.
 */
final readonly class EditorCallbacks
{
    /**
     * @param (\Closure(): void)|null                    $onSaveRequested
     * @param (\Closure(): void)|null                    $onSaveAllRequested
     * @param (\Closure(Document): void)|null            $onChanged
     * @param (\Closure(int, int): void)|null            $onCompletionRequested   request completion at (line, utf16 col)
     * @param (\Closure(array<string, mixed>): void)|null $onCompletionResolve    resolve an accepted completion item (for deferred auto-imports)
     * @param (\Closure(int, int): void)|null            $onDefinitionRequested   request definition at (line, utf16 col)
     * @param (\Closure(int, int): void)|null            $onImplementationRequested request implementation at (line, utf16 col)
     * @param (\Closure(int, int): void)|null            $onTypeDefinitionRequested request type definition at (line, utf16 col)
     * @param (\Closure(): void)|null                    $onNavigateBack          navigate back after a definition jump
     * @param (\Closure(string): void)|null              $onCopy                  text copied to the clipboard (for OSC 52)
     * @param (\Closure(int): void)|null                 $onBufferCycle           cycle to next (+1) / previous (-1) buffer
     * @param (\Closure(): void)|null                    $onRecentFiles           open the recent-files switcher
     * @param (\Closure(): void)|null                    $onCloseBuffer           close the active buffer
     * @param (\Closure(bool): void)|null                $onFindRequested         open the find bar (true = with replace)
     * @param (\Closure(): void)|null                    $onGotoFile              open the go-to-file palette
     * @param (\Closure(int, int): void)|null            $onQuickDoc              quick documentation at (line, utf16 col)
     * @param (\Closure(string, int, int): void)|null    $onRename                rename symbol to $newName at (line, utf16 col); LSP-free fallback
     * @param (\Closure(int, int, string): void)|null    $onRenameRequested       validate+begin a rename at (line, utf16 col, word)
     * @param (\Closure(int, int): void)|null            $onFindUsages            find usages at (line, utf16 col)
     * @param (\Closure(int, int): void)|null            $onSignatureHelp         signature help at (line, utf16 col)
     * @param (\Closure(int, int): void)|null            $onCodeAction            quick fixes at (line, utf16 col)
     * @param (\Closure(): void)|null                    $onFileStructure         file structure popup
     * @param (\Closure(int, int): void)|null            $onPeek                  peek definition at (line, utf16 col)
     * @param (\Closure(): void)|null                    $onReformat              reformat the whole file
     */
    public function __construct(
        public ?\Closure $onSaveRequested = null,
        public ?\Closure $onSaveAllRequested = null,
        public ?\Closure $onChanged = null,
        public ?\Closure $onCompletionRequested = null,
        public ?\Closure $onCompletionResolve = null,
        public ?\Closure $onDefinitionRequested = null,
        public ?\Closure $onImplementationRequested = null,
        public ?\Closure $onTypeDefinitionRequested = null,
        public ?\Closure $onNavigateBack = null,
        public ?\Closure $onCopy = null,
        public ?\Closure $onBufferCycle = null,
        public ?\Closure $onRecentFiles = null,
        public ?\Closure $onCloseBuffer = null,
        public ?\Closure $onFindRequested = null,
        public ?\Closure $onGotoFile = null,
        public ?\Closure $onQuickDoc = null,
        public ?\Closure $onRename = null,
        public ?\Closure $onRenameRequested = null,
        public ?\Closure $onFindUsages = null,
        public ?\Closure $onSignatureHelp = null,
        public ?\Closure $onCodeAction = null,
        public ?\Closure $onFileStructure = null,
        public ?\Closure $onPeek = null,
        public ?\Closure $onReformat = null,
    ) {}
}
