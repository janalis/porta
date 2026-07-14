<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceSymbolClient;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * Assembles the Search Everywhere modal: five sources (Files/Classes/Symbols/
 * Actions/Text) feeding one aggregator that drives the editor's centered
 * overlay. A factory because the per-source open/jump hooks close over the
 * buffer stack and the editor, and the sync/async source split isn't
 * expressible as plain autowiring.
 */
final class SearchEverywhereFactory
{
    public function __construct(
        private readonly CodeEditorWidget $editor,
        private readonly CommandRegistry $commands,
        private readonly BufferManager $buffers,
        private readonly BufferService $bufferService,
        private readonly LspSessionPool $lsp,
        private readonly Tui $tui,
        private readonly Scheduler $scheduler,
        private readonly string $rootDir,
    ) {}

    public function create(): SearchEverywhereController
    {
        $openFile = fn (string $path, bool $focus) => $this->bufferService->openFile($path, focusEditor: $focus);
        $jumpTo = fn (int $line, int $col) => $this->editor->jumpTo($line, $col);
        $searchSymbols = new WorkspaceSymbolClient($this->lsp, $this->rootDir)->search(...);

        return new SearchEverywhereController(
            $this->editor,
            [
                new FilesSource(new FileIndex($this->rootDir), $this->rootDir, $openFile),
                new ActionsSource($this->commands),
            ],
            [
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Classes, [5, 10, 11, 23], $this->rootDir, $openFile, $jumpTo),
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Symbols, [], $this->rootDir, $openFile, $jumpTo),
                new TextSource(new ProjectSearch($this->rootDir), $this->rootDir, $openFile, $jumpTo),
            ],
            $this->buffers,
            $this->tui,
            $this->rootDir,
            $this->bufferService,
            $this->scheduler,
        );
    }
}
