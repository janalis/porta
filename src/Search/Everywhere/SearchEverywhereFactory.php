<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorTextAccess;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceSymbolClient;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Symfony\Component\Tui\Tui;

/**
 * Assembles the Search Everywhere modal: five sources (Files/Classes/Symbols/
 * Actions/Text) feeding one aggregator that drives the editor's centered
 * overlay. A factory because the per-source open/jump hooks close over the
 * buffer stack and the editor, and the sync/async source split isn't
 * expressible as plain autowiring. It needs the editor twice, once per role
 * (both resolve to the same widget): the overlay surface the controllers
 * drive, and the text seam the jump hooks close over.
 */
final class SearchEverywhereFactory
{
    public function __construct(
        private readonly SearchOverlay $overlay,
        private readonly EditorTextAccess $editorText,
        private readonly CommandRegistry $commands,
        private readonly BufferManager $buffers,
        private readonly FileOpener $bufferService,
        private readonly LspSessionPool $lsp,
        private readonly Tui $tui,
        private readonly Scheduler $scheduler,
        private readonly ProjectPaths $paths,
        private readonly Translator $translator,
    ) {}

    /**
     * The files-only picker behind Ctrl+P: the same centered overlay as Search
     * Everywhere, scoped to the file source alone (no actions/symbols/text, so no
     * async tier). Recent files seed the empty query, exactly as they do there.
     */
    public function createGoToFile(): SearchEverywhereController
    {
        $openFile = fn (string $path, OpenFocus $focus) => $this->bufferService->openFile($path, $focus);

        return new SearchEverywhereController(
            $this->overlay,
            [new FilesSource(new FileIndex($this->paths->root()), $this->paths, $openFile)],
            [],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
            'Go to File',
        );
    }

    /**
     * The command palette behind Ctrl+Shift+A: the centered overlay scoped to the
     * actions source alone. An empty query lists every command (no recent-files
     * tray), and activating a hit runs it.
     */
    public function createCommandPalette(): SearchEverywhereController
    {
        return new SearchEverywhereController(
            $this->overlay,
            [new ActionsSource($this->commands)],
            [],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
            'Find Action',
            seedRecentFiles: false,
        );
    }

    /**
     * Go to Symbol in Project: the centered overlay scoped to the LSP
     * workspace-symbol source (async), with no recent-files tray.
     */
    public function createGoToSymbol(): SearchEverywhereController
    {
        $openFile = fn (string $path, OpenFocus $focus) => $this->bufferService->openFile($path, $focus);
        $jumpTo = fn (int $line, int $col) => $this->editorText->jumpTo($line, $col);
        $searchSymbols = new WorkspaceSymbolClient($this->lsp, $this->paths)->search(...);

        return new SearchEverywhereController(
            $this->overlay,
            [],
            [new WorkspaceSymbolSource($searchSymbols, SearchCategory::Symbols, [], $this->paths, $openFile, $jumpTo)],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
            'Go to Symbol in Project',
            seedRecentFiles: false,
        );
    }

    public function create(): SearchEverywhereController
    {
        $openFile = fn (string $path, OpenFocus $focus) => $this->bufferService->openFile($path, $focus);
        $jumpTo = fn (int $line, int $col) => $this->editorText->jumpTo($line, $col);
        $searchSymbols = new WorkspaceSymbolClient($this->lsp, $this->paths)->search(...);

        return new SearchEverywhereController(
            $this->overlay,
            [
                new FilesSource(new FileIndex($this->paths->root()), $this->paths, $openFile),
                new ActionsSource($this->commands),
            ],
            [
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Classes, [5, 10, 11, 23], $this->paths, $openFile, $jumpTo),
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Symbols, [], $this->paths, $openFile, $jumpTo),
                new TextSource(new ProjectSearch($this->paths->root()), $this->paths, $openFile, $jumpTo),
            ],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
        );
    }
}
