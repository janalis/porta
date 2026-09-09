<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorTextAccess;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\History\RecentFilesStore;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceSymbolClient;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Ui\ShortcutFormatter;
use Porta\Workbench\Menu\EditorCommandTarget;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Input\Keybindings;
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
        private readonly RecentFilesStore $recentFiles,
        private readonly Translator $translator,
        // The one shared index: both pickers and the container's TestNavigator
        // consumer used to build their own, tripling the walk and the memory.
        private readonly FileIndex $files,
        // Typed by the interface and named by id in config/services.php, not
        // by its class: the settings source lives in `Settings`, which already
        // imports this namespace. Importing it back would make the pair
        // mutually dependent for the sake of one constructor line.
        #[Autowire(service: 'porta.settings_search_source')]
        private readonly SearchSource $settings,
        // The two keymaps a palette hint has to consult, in the menu bar's own
        // order: an editor action is bound on the editor, an ide_* one on the
        // application map.
        private readonly EditorCommandTarget $editor,
        private readonly Keybindings $ideKeys,
    ) {}

    /** An action's primary key, formatted as everywhere else, or '' when it has none. */
    private function shortcutOf(string $id): string
    {
        return ShortcutFormatter::format(
            $this->editor->getKeybindings()->getBindings($id)[0] ?? $this->ideKeys->getBindings($id)[0] ?? '',
        );
    }

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
            [new FilesSource($this->files, $this->paths, $openFile)],
            [],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->recentFiles,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
            'Go to File',
            categoryCap: SearchEverywhereController::SOLO_CAP,
        );
    }

    /**
     * The centered overlay scoped to one already-built source.
     *
     * The three methods around this one each know their source's dependencies
     * and assemble it here. This one deliberately does not: it exists for
     * sources owned by a namespace this factory must not import. `Settings`
     * is the first — it already imports `Search`, and the reverse edge would
     * make the pair mutually dependent to save one caller from passing an
     * object it has already built.
     *
     * Sync-only, no recent-files tray. An async source needs its own
     * cancellation story, and a scoped picker over one is not yet a thing that
     * exists.
     */
    public function createScoped(SearchSource $source, string $title): SearchEverywhereController
    {
        return new SearchEverywhereController(
            $this->overlay,
            [$source],
            [],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->recentFiles,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
            $title,
            seedRecentFiles: false,
            categoryCap: SearchEverywhereController::SOLO_CAP,
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
            [new ActionsSource($this->commands, $this->shortcutOf(...))],
            [],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->recentFiles,
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
            $this->recentFiles,
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
                new FilesSource($this->files, $this->paths, $openFile),
                new ActionsSource($this->commands, $this->shortcutOf(...)),
                // Last of the categories (SearchCategory::Settings orders 5th):
                // a setting is never what you meant when you typed a filename,
                // and this is the one source that answers a query about the IDE
                // rather than about the project.
                $this->settings,
            ],
            [
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Classes, [5, 10, 11, 23], $this->paths, $openFile, $jumpTo),
                new WorkspaceSymbolSource($searchSymbols, SearchCategory::Symbols, [], $this->paths, $openFile, $jumpTo),
                new TextSource(new ProjectSearch($this->paths->root()), $this->paths, $openFile, $jumpTo),
            ],
            $this->buffers,
            $this->tui,
            $this->paths,
            $this->recentFiles,
            $this->bufferService,
            $this->scheduler,
            $this->translator,
        );
    }
}
