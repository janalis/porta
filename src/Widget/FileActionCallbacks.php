<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's file-lifecycle hooks: save / save-all, copy to the clipboard,
 * open the find bar and the go-to-file palette. One of the five groups
 * {@see EditorCallbacks} fans out into its flat surface.
 */
final readonly class FileActionCallbacks
{
    /**
     * @param (\Closure(): void)|null       $onSaveRequested    save the active buffer
     * @param (\Closure(): void)|null       $onSaveAllRequested save every dirty buffer
     * @param (\Closure(string): void)|null $onCopy             text copied to the clipboard (for OSC 52)
     * @param (\Closure(bool): void)|null   $onFindRequested    open the find bar (true = with replace)
     * @param (\Closure(): void)|null       $onGotoFile         open the go-to-file palette
     */
    public function __construct(
        public ?\Closure $onSaveRequested = null,
        public ?\Closure $onSaveAllRequested = null,
        public ?\Closure $onCopy = null,
        public ?\Closure $onFindRequested = null,
        public ?\Closure $onGotoFile = null,
    ) {}
}
