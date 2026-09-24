<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\EditorPanes;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Event\ResultRowActivated;
use Porta\Event\ResultsExportRequested;
use Porta\Event\ResultsRerunRequested;
use Porta\History\SearchHistoryStore;
use Porta\History\SearchPrefsStore;
use Porta\History\SearchScopeStore;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Search\LanguageFilter;
use Porta\Search\ProjectSearch;
use Porta\Search\ProjectSearchController;
use Porta\Search\ResultContextProvider;
use Porta\Search\SearchOptions;
use Porta\Search\SearchScopeResolver;
use Porta\Search\SearchTabState;
use Porta\Search\SearchUiWiring;
use Porta\Search\TokenClassifier;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\BidiTui;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Density;
use Porta\Ui\ScopeKind;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockPanelWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolHandlers;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\ListboxOpener;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SearchUiWiringTest extends TestCase
{
    use PreservesPath;

    private CodeEditorWidget $editor;
    private BottomDock $dock;
    private ResultsPanelWidget $panel;
    private ProjectSearchController $search;
    /** The context menu the scope select's list opens in. */
    private ContextMenu $scopeList;
    /** @var list<string> */
    private array $opened = [];
    private int $renders = 0;

    protected function setUp(): void
    {
        // No grep on PATH → ProjectSearch takes its synchronous PHP fallback,
        // so a search's results are available immediately, deterministically.
        \putenv('PATH=/nonexistent');
    }

    private function wiring(string $rootDir): SearchUiWiring
    {
        $this->editor = new CodeEditorWidget();
        $this->panel = new ResultsPanelWidget();
        $tui = new BidiTui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->panel, new DebugPanelWidget());
        $buffers = new BufferManager();
        $paths = new ProjectPaths($rootDir);
        $projectSearch = new ProjectSearch($rootDir);

        $this->search = new ProjectSearchController(
            $projectSearch,
            $this->dock,
            $this->editor,
            new Notifier(new StatusBarWidget(), $tui),
            $tui,
            new Translator('en'),
            $buffers,
            $paths,
            new DocumentReplacer($buffers, $this->editor, new LspSessionPool($rootDir, new FixedActiveFile(), new Translator('en'))),
            new SearchHistoryStore($rootDir),
            new SearchPrefsStore($rootDir),
            new SearchScopeResolver($buffers, $this->editor, $paths),
            new LanguageFilter(new TokenClassifier(), $projectSearch, $paths),
            new SearchScopeStore($rootDir),
        );

        $this->opened = [];
        $editor = $this->editor;
        $opener = new class($this->opened, $editor) implements FileOpener {
            /** @param list<string> $opened */
            public function __construct(
                private array &$opened,
                private readonly CodeEditorWidget $editor,
            ) {}

            public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
            {
                $this->opened[] = $path;
                $this->editor->showDocument($path, new Document((string) \file_get_contents($path)));
            }
        };

        $this->renders = 0;

        return new SearchUiWiring(
            new FindBarWidget(new Translator('en')),
            $this->panel,
            $this->editor,
            new EditorArea($buffers, $this->editor, new EditorPanes(), $tui),
            $tui,
            $this->dock,
            $this->search,
            new ResultContextProvider($buffers, $paths),
            $opener,
            new FakeScheduler(),
            $paths,
            new ListboxOpener(
                $tui,
                $renderer = new Renderer(new StyleSheet()),
                $menuBar = new MenuBarWidget(),
                new MenuOverlayController($renderer, $menuBar, $this->scopeList = new ContextMenu(), new MenuOverlayPanes([], static fn (): array => [])),
                $this->scopeList,
            ),
        );
    }

    public function testRowActivatedWithAContributedActivateHandlerDelegatesToIt(): void
    {
        $project = new TempProject();
        $wiring = $this->wiring($project->dir);
        $activated = null;
        $handlers = new DockToolHandlers('plugin.tool', activate: static function (string $path, int $line) use (&$activated): void {
            $activated = [$path, $line];
        });
        $this->dock->showTool($handlers, 'Plugin Tool', []);

        $wiring->onRowActivated(new ResultRowActivated('some/file.php', 3));

        $this->assertSame(['some/file.php', 3], $activated, 'the contributed handler ran');
        $this->assertSame([], $this->opened, 'the built-in open-and-jump did not also run');
    }

    public function testRowActivatedWithAContributedToolButNoActivateHandlerFallsBackToOpenAndJump(): void
    {
        $project = new TempProject(['a.php' => "<?php\n// l2\n// l3\n"]);
        $wiring = $this->wiring($project->dir);
        $handlers = new DockToolHandlers('plugin.tool'); // no activate closure
        $this->dock->showTool($handlers, 'Plugin Tool', []);

        $wiring->onRowActivated(new ResultRowActivated('a.php', 2));

        $this->assertSame([$project->dir.'/a.php'], $this->opened, 'core opened the file itself');
        $this->assertSame(2, $this->editor->getDocument()->cursorLine);
    }

    public function testRowActivatedOnACoreLogTabIsInert(): void
    {
        // A core tab (no handlers) bound to LspLog/Notifications carries rows
        // with no real file location — Enter on one must not try to open
        // "a.php" relative to nothing.
        $project = new TempProject(['a.php' => "<?php\n"]);
        $wiring = $this->wiring($project->dir);
        $tools = Probe::get($this->dock, 'tools');
        $tab = $tools->openOrReuse(ResultsPanelMode::LspLog, 'LSP Log', ResultsPanelFields::resultsOnly());
        $this->dock->show($tab);

        $wiring->onRowActivated(new ResultRowActivated('a.php', 0));

        $this->assertSame([], $this->opened, 'a log row never opens a file');
    }

    public function testRowActivatedWithNoActiveToolAtAllFallsBackToOpenAndJump(): void
    {
        $project = new TempProject(['a.php' => "<?php\n// l2\n"]);
        $wiring = $this->wiring($project->dir);

        $wiring->onRowActivated(new ResultRowActivated('a.php', 1));

        $this->assertSame([$project->dir.'/a.php'], $this->opened);
        $this->assertSame(1, $this->editor->getDocument()->cursorLine);
    }

    public function testRerunRequestedWithAContributedRerunHandlerDelegatesToIt(): void
    {
        $project = new TempProject();
        $wiring = $this->wiring($project->dir);
        $ran = false;
        $handlers = new DockToolHandlers('plugin.tool', rerun: static function () use (&$ran): void {
            $ran = true;
        });
        $this->dock->showTool($handlers, 'Plugin Tool', []);

        $wiring->onRerunRequested(new ResultsRerunRequested());

        $this->assertTrue($ran, 'the contributed rerun handler ran');
    }

    public function testRerunRequestedWithAContributedToolButNoRerunHandlerIsInert(): void
    {
        $project = new TempProject();
        $wiring = $this->wiring($project->dir);
        $handlers = new DockToolHandlers('plugin.tool'); // no rerun closure: F5 stays inert

        $this->dock->showTool($handlers, 'Plugin Tool', []);

        // Must not throw calling the (missing) rerun closure.
        $wiring->onRerunRequested(new ResultsRerunRequested());
        $this->addToAssertionCount(1);
    }

    public function testRerunRequestedWithoutAContributedToolReRunsTheCoreSearch(): void
    {
        $project = new TempProject(['a.php' => "<?php\n\$needle = 1;\n"]);
        $wiring = $this->wiring($project->dir);
        $searchController = Probe::get($wiring, 'projectSearch');
        $searchController->search('needle');
        $searchController->promoteToPane(); // binds a real, active Search-mode dock tab
        $this->assertCount(1, Probe::get($searchController, 'lastSearchResults'), 'the query matched once before the rerun');

        // A new matching file appears on disk after the first run: a real
        // rerun must pick it up, proving it re-scanned rather than replayed.
        $project->write('b.php', "<?php\n\$needle = 2;\n");
        $wiring->onRerunRequested(new ResultsRerunRequested());

        $this->assertCount(2, Probe::get($searchController, 'lastSearchResults'), 'the rerun re-scanned the project and found the new match too');
    }

    public function testExportRequestedWithNoResultsIsANoOp(): void
    {
        $project = new TempProject();
        $wiring = $this->wiring($project->dir);

        $wiring->onExportRequested(new ResultsExportRequested());

        $this->assertSame([], $this->opened, 'nothing exported, nothing opened');
    }

    public function testExportRequestedWithResultsWritesAFileAndOpensIt(): void
    {
        $project = new TempProject();
        $wiring = $this->wiring($project->dir);
        $searchController = Probe::get($wiring, 'projectSearch');
        Probe::set($searchController, 'lastSearchQuery', 'needle');
        Probe::set($searchController, 'lastSearchResults', [
            ['path' => 'a.php', 'line' => 1, 'text' => '$needle = 1;', 'ranges' => []],
        ]);

        // Bound to a find-in-project tab, which is what an export is FOR. The
        // handler is routed by the tab, exactly as the rerun handler is.
        $tab = $this->dock->showList(ResultsPanelMode::Search, 'Find', [], ResultsPanelFields::searchResults());
        $tab->searchState = new SearchTabState('needle', new SearchOptions(), [], '', ScopeKind::Project);

        $wiring->onExportRequested(new ResultsExportRequested());

        $this->assertCount(1, $this->opened, 'the exported file was opened');
        $this->assertStringContainsString('needle', (string) \file_get_contents($this->opened[0]));
    }

    public function testExportRequestedFromAPanelThatIsNOTASearchDoesNothing(): void
    {
        // Alt+X is offered by every GROUPED panel, and the Problems list is
        // grouped so it can be read by file. Unrouted, the key exported the
        // last grep, closed the panel being read and opened that file over it.
        $project = new TempProject();
        $wiring = $this->wiring($project->dir);
        $searchController = Probe::get($wiring, 'projectSearch');
        Probe::set($searchController, 'lastSearchQuery', 'needle');
        Probe::set($searchController, 'lastSearchResults', [
            ['path' => 'a.php', 'line' => 1, 'text' => '$needle = 1;', 'ranges' => []],
        ]);

        // A grouped, non-search tab — no searchState, which is what says "this
        // panel has no results of its own to write".
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', [], ResultsPanelFields::problemList());

        $wiring->onExportRequested(new ResultsExportRequested());

        $this->assertSame([], $this->opened, 'nothing was written and nothing was opened');
        $this->assertInstanceOf(DockPanelWidget::class, $this->dock->visiblePanel(), 'and the panel being read is still there');
    }

    /**
     * Alt+S drops the scope select's list — the context menu, as a listbox on
     * the scope the modal is searching — and a pick is an ordinary scope
     * choice, down to the search.
     */
    public function testAltSDropsTheScopeListAndAPickSetsTheScope(): void
    {
        $project = new TempProject();
        $this->wiring($project->dir)->wire();
        $modal = $this->editor->findModal;
        $this->search->open(); // publishes the scopes, on Whole Project
        $modal->layout([0, 0, 40, 120], CaretVisibility::Shown, Density::Roomy); // drawn: the select has a place to hang from

        $modal->handleInput("\x1bs", $this->editor->getKeybindings());

        $this->assertTrue($this->scopeList->isListbox(), 'the list is open');
        $this->scopeList->handleInput("\x1b[B");
        $this->scopeList->handleInput("\r");
        $this->assertSame(1, Probe::get($this->search, 'scopeIndex'), 'the pick is the search\'s scope now');
    }
}
