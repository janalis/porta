<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's buffer-navigation hooks: cycle buffers, open the recent-files
 * switcher and close the active buffer. One of the five groups
 * {@see EditorCallbacks} fans out into its flat surface.
 */
final readonly class BufferCallbacks
{
    /**
     * @param (\Closure(int): void)|null $onBufferCycle cycle to next (+1) / previous (-1) buffer
     * @param (\Closure(): void)|null    $onRecentFiles open the recent-files switcher
     * @param (\Closure(): void)|null    $onCloseBuffer close the active buffer
     */
    public function __construct(
        public ?\Closure $onBufferCycle = null,
        public ?\Closure $onRecentFiles = null,
        public ?\Closure $onCloseBuffer = null,
    ) {}
}
