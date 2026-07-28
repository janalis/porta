<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\LogEntry;

/**
 * The history pane's outbound hooks, wired in one call instead of four fluent
 * setters — the {@see DiffViewCallbacks} shape. Every slot is nullable: a bare
 * widget fires nothing.
 */
final readonly class HistoryListCallbacks
{
    /**
     * @param (callable(LogEntry): void)|null         $onSelectCommit selection moved to this commit
     * @param (callable(LogEntry, string): void)|null $onSelectFile   commit + file path
     * @param (callable(): void)|null                 $onShowChanges  `1`: switch back to the Changes tab
     * @param (callable(): void)|null                 $onLoadMore     fired once when the selection reaches the last loaded commit
     */
    public function __construct(
        public mixed $onSelectCommit = null,
        public mixed $onSelectFile = null,
        public mixed $onShowChanges = null,
        public mixed $onLoadMore = null,
    ) {}
}
