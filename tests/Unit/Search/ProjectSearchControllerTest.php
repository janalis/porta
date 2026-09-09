<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Search\ProjectSearch;
use Porta\Search\ProjectSearchController;
use Porta\Search\SearchOptions;
use Porta\Search\SearchTabState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\ScopeKind;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolTab;
use Porta\Workbench\Dock\ResultsPanelMode;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ProjectSearchControllerTest extends TestCase
{
    use PumpsLoop;

    private ResultsPanelWidget $panel;
    private CodeEditorWidget $editor;
    private BottomDock $dock;
    private BufferManager $buffers;
    private StatusBarWidget $statusBar;

    private function controller(string $rootDir): ProjectSearchController
    {
        $this->panel = new ResultsPanelWidget();
        $this->editor = new CodeEditorWidget();
        $projectSearch = new ProjectSearch($rootDir);
        $this->buffers = new BufferManager();
        $buffers = $this->buffers;
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->panel, new DebugPanelWidget());
        $this->statusBar = new StatusBarWidget();

        return new ProjectSearchController(
            $projectSearch,
            $this->dock,
            $this->editor,
            $this->statusBar,
            $tui,
            new \Porta\I18n\Translator('en'),
            $buffers,
            new \Porta\Support\ProjectPaths($rootDir),
            new \Porta\Editor\DocumentReplacer($buffers, $this->editor, new \Porta\Lsp\LspSessionPool($rootDir, new \Porta\Tests\Support\FixedActiveFile(), new \Porta\I18n\Translator('en'))),
            new \Porta\History\SearchHistoryStore($rootDir),
            new \Porta\History\SearchPrefsStore($rootDir),
            new \Porta\Search\SearchScopeResolver($buffers, $this->editor, new \Porta\Support\ProjectPaths($rootDir)),
            new \Porta\Search\LanguageFilter(new \Porta\Search\TokenClassifier(), $projectSearch, new \Porta\Support\ProjectPaths($rootDir)),
            new \Porta\History\SearchScopeStore($rootDir),
        );
    }

    /** The modal's own panel text — it floats over the workspace now, not inside the editor's lines. */
    private function modal(): string
    {
        $placed = $this->editor->findModal()->layout([0, 0, 40, 100], \Porta\Ui\CaretVisibility::Shown);

        return null === $placed ? '' : AnsiUtils::stripAnsiCodes(implode("\n", $placed['rows']));
    }

    private function panelText(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->panel->render(new \Symfony\Component\Tui\Render\RenderContext(100, 14))));
    }

    private function awaitModalShows(string $needle, float $timeout = 3.0): void
    {
        $this->await(fn (): bool => str_contains($this->modal(), $needle), $timeout, "the modal to show '{$needle}'");
    }

    private function awaitPanelShows(string $needle, float $timeout = 3.0): void
    {
        $this->await(fn (): bool => str_contains($this->panelText(), $needle), $timeout, "the results panel to show '{$needle}'");
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(implode('', $this->statusBar->render(new \Symfony\Component\Tui\Render\RenderContext(100, 1))));
    }

    public function testOpenShowsTheFindModal(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->open();

        $modal = $this->modal();
        $this->assertStringContainsString('Find in Files', $modal);
        $this->assertStringContainsString('Scope: Whole Project', $modal);
    }

    /**
     * `Module` means a different search from one buffer to the next, and has a
     * fallback that means the OPPOSITE of what it promises: with no
     * composer.json above the file it is the whole project. In a repo whose
     * packages live under `plugins/`, editing anything in `src/` gets that
     * fallback — and the selector said `Module` either way.
     */
    public function testTheModuleScopeSaysWhichModuleItLandedOn(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            'plugins/git/composer.json' => '{}',
            'plugins/git/src/B.php' => "<?php\n",
        ]);
        $controller = $this->controller($project->dir);
        $this->showFile($project, 'plugins/git/src/B.php');

        $controller->open();

        $this->assertSame('Module (plugins/git)', $this->scopeLabel(4));
    }

    /** The fallback, said out loud: nothing above this file is a package, so this is everything. */
    public function testTheModuleScopeAdmitsWhenItIsTheWholeProject(): void
    {
        $project = new TempProject(['composer.json' => '{}', 'src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $this->showFile($project, 'src/A.php');

        $controller->open();

        $this->assertSame('Module (Whole Project)', $this->scopeLabel(4));
    }

    /** Directory is relative to the buffer too, and never said which one. */
    public function testTheDirectoryScopeNamesTheDirectory(): void
    {
        $project = new TempProject(['src/Deep/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $this->showFile($project, 'src/Deep/A.php');

        $controller->open();

        $this->assertSame('Directory (src/Deep)', $this->scopeLabel(1));
    }

    /**
     * With nothing open, Open Files matches nothing at all — and a search that
     * returns no results because its scope was empty looks exactly like one
     * that searched properly and found none.
     */
    public function testOpenFilesCarriesItsCountIncludingZero(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);

        $controller->open();
        $this->assertSame('Open Files (0)', $this->scopeLabel(3), 'empty, and it says so');

        $this->buffers->open($project->path('src/A.php'), "<?php\n");
        $controller->open();

        $this->assertSame('Open Files (1)', $this->scopeLabel(3));
    }

    /** Whole Project and Current File name themselves; repeating the path back would be noise. */
    public function testTheScopesThatNameThemselvesAreLeftAlone(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $this->showFile($project, 'src/A.php');

        $controller->open();

        $this->assertSame('Whole Project', $this->scopeLabel(0));
        $this->assertSame('Current File', $this->scopeLabel(2));
    }

    /** Make $relative the editor's active file, which is what Module and Directory resolve against. */
    private function showFile(TempProject $project, string $relative): void
    {
        $this->editor->showDocument($project->path($relative), new Document("<?php\n"));
    }

    /** The Alt+S choice label the controller handed the modal at index $index. */
    private function scopeLabel(int $index): string
    {
        /** @var list<string> $labels */
        $labels = Probe::get($this->editor->findModal(), 'scopeLabels');

        return $labels[$index] ?? '';
    }

    public function testOpenInFolderPreselectsTheFolderScope(): void
    {
        $project = new TempProject(['src/sub/A.php' => "<?php\n"]);
        $this->controller($project->dir)->openInFolder($project->dir.'/src/sub');

        $this->assertStringContainsString('Scope: src/sub', $this->modal(), 'a folder search preselects (and saves) that folder as the scope');
    }

    public function testOpenInFolderOnTheRootFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        // The project root resolves to no relative sub-path → whole-project search.
        $this->controller($project->dir)->openInFolder($project->dir);

        $this->assertStringContainsString('Scope: Whole Project', $this->modal());
    }

    public function testTheCommittedQueryIsRecordedInHistoryOnClose(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $controller->cancel(); // panel closed → history recorded

        $this->assertSame(['needle'], new \Porta\History\SearchHistoryStore($project->dir)->all());
    }

    public function testSearchDeliversResultsToTheOpenModal(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle here\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();

        $controller->search('needle');
        $this->awaitModalShows('needle here');
        $this->assertStringContainsString('needle here', $this->modal());
    }

    public function testSearchRefreshesTheLivePaneOnceTheModalIsClosed(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle here\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('needle here');
        $controller->promoteToPane(); // closes the modal, hands rows to the dock tab

        // A re-run (F5) with the modal now closed must still reach the live pane.
        $project->write('src/A.php', "<?php\n// needle here\n// needle again\n");
        $controller->rerun();

        $this->awaitPanelShows('needle again');
        $this->assertStringContainsString('needle again', $this->panelText());
    }

    public function testSetMaskPersistsAndReRunsTheCurrentQuery(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            'src/B.js' => "// needle\n",
        ]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('.js');

        $controller->setMask('*.php');
        $this->await(fn (): bool => !str_contains($this->modal(), '.js'), what: 'the mask-filtered re-run to land');
        $this->assertStringContainsString('A.php', $this->modal());
        $this->assertSame('*.php', new \Porta\History\SearchPrefsStore($project->dir)->lastMask(), 'the mask is persisted for next time');
    }

    public function testSetScopeIndexResolvesAndReRuns(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in src\n",
            'other/B.php' => "<?php\n// needle in other\n",
        ]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('other');

        // OpenFiles (index 3 in BUILT_INS) narrows to exactly the buffers open
        // right now — the most deterministic built-in scope to select here.
        $this->buffers->open($project->path('src/A.php'), "<?php\n// needle in src\n");
        $controller->setScopeIndex(3);

        $this->await(fn (): bool => !str_contains($this->modal(), 'needle in other'), what: 'the scope-narrowed re-run to land');
        $this->assertStringContainsString('needle in src', $this->modal());
    }

    public function testToggleOptionUpdatesTheModalAndReRuns(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$NEEDLE = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('NEEDLE');

        $controller->toggleOption('case');
        $this->await(fn (): bool => str_contains($this->modal(), '0 results'), what: 'the case-sensitive re-run to drop the mismatched-case hit');
        $this->assertStringNotContainsString('NEEDLE', $this->modal());
        // The panel checkbox reflects the new option, independent of the re-run.
        $this->assertTrue($this->searchOptions($controller)->caseSensitive);
    }

    private function searchOptions(ProjectSearchController $controller): SearchOptions
    {
        return Probe::get($controller, 'searchOptions');
    }

    public function testReplaceAllRewritesAnOpenBufferInMemoryWithoutTouchingExcludedMatches(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n\$needle = 2;\n"]);
        $controller = $this->controller($project->dir);
        $buffer = $this->buffers->open($project->path('src/A.php'), "<?php\n\$needle = 1;\n\$needle = 2;\n");
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');

        $controller->replaceAll('found', excluded: [1]); // exclude the second match (index 1)

        $this->assertStringContainsString('$found = 1;', $buffer->document->getText(), 'the kept match is replaced');
        $this->assertStringContainsString('$needle = 2;', $buffer->document->getText(), 'the excluded match is left untouched');
        $this->assertStringContainsString('$needle = 1;', (string) file_get_contents($project->path('src/A.php')), 'an open buffer is edited in memory, not written straight to disk');
    }

    public function testReplaceAllWritesAClosedFileToDisk(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');

        $controller->replaceAll('found');

        $this->assertStringContainsString('$found = 1;', (string) file_get_contents($project->path('src/A.php')));
    }

    public function testReplaceAllFlashesNothingToReplaceWhenTheResultSetIsEmpty(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('nomatch');
        $this->await(fn (): bool => str_contains($this->modal(), '0 results'), what: 'the empty search to complete');

        $controller->replaceAll('anything');

        $this->assertStringContainsString('Nothing to replace', $this->flashed());
    }

    public function testReplaceAllMultilineRewritesTheWholeFile(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search("foo()\n{");
        $this->awaitModalShows('foo()');

        $controller->replaceAll("bar()\n{");

        $this->assertStringContainsString('bar()', (string) file_get_contents($project->path('src/A.php')));
    }

    /**
     * The export says so, and says how much.
     *
     * Every other export test reads the FILE, so a mutation sweep could delete
     * the flash outright and nothing noticed — and the flash is the only thing
     * telling the user the export happened at all, since it writes to
     * `.porta/search-results.txt` rather than opening anything.
     *
     * The count is asserted in the singular with the plural denied: one result
     * is the case the wording used to get wrong, and
     * `assertStringContainsString('1 result')` is satisfied by "1 results".
     */
    public function testExportingFlashesWhatItWroteAndHowManyRowsWereInIt(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle in A\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('needle in A');

        $controller->exportResults();

        $flash = $this->flashed();
        $this->assertStringContainsString('Exported 1 result', $flash);
        $this->assertStringNotContainsString('Exported 1 results', $flash, 'one row is not "1 results"');
    }

    public function testExportResultsGroupsMultipleFilesWithABlankLineBetweenThem(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in A\n",
            'src/B.php' => "<?php\n// needle in B\n",
        ]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('needle in B');

        $exported = $controller->exportResults();
        $content = (string) file_get_contents($exported);

        // grep does not guarantee cross-file result order, so match either
        // ordering — the point under test is the blank-line separator itself.
        $this->assertTrue(
            str_contains($content, "src/A.php\n  2: // needle in A\n\nsrc/B.php\n  2: // needle in B") || str_contains($content, "src/B.php\n  2: // needle in B\n\nsrc/A.php\n  2: // needle in A"),
            'a blank line separates each file group: '.$content,
        );
        $this->assertStringContainsString('(2 results)', $content);
    }

    public function testPromotingWithoutAQueryTitlesTheToolWindowGenerically(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->promoteToPane(); // Alt+Enter before typing anything

        $this->assertSame('Find in Project', $this->panel->getTitle(), 'an empty query titles the dock tab generically');
    }

    public function testExportWithNoResultsReturnsEmpty(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();

        $this->assertSame('', $controller->exportResults(), 'nothing to export → no file, empty path');
        $this->assertFileDoesNotExist($project->dir.'/.porta/search-results.txt');
    }

    public function testAddSearchScopeIgnoresTheProjectRoot(): void
    {
        $project = new TempProject(['A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->addSearchScope($project->dir); // the root resolves to no sub-path → ignored
        $controller->open();

        // Only the five built-in kinds remain; cycling through them wraps to Whole Project.
        for ($i = 0; $i < 5; ++$i) {
            $this->editor->handleFindModalInput("\x1bs");
        }
        $this->assertStringContainsString('Scope: Whole Project', $this->modal(), 'no saved scope was added');
    }

    public function testReplaceLinesSkipsOutOfRangeIndices(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        // Index 5 is past the two lines of "a\nb": it is skipped and the content
        // is returned byte-for-byte unchanged.
        $this->assertSame("a\nb", Probe::invoke($controller, 'replaceLines', "a\nb", [5], 'x'));
    }

    public function testReplaceInFolderPreselectsTheFolderScope(): void
    {
        $project = new TempProject(['src/sub/A.php' => "<?php\n"]);
        $this->controller($project->dir)->replaceInFolder($project->dir.'/src/sub');

        $this->assertStringContainsString('Scope: src/sub', $this->modal());
    }

    public function testReplaceInFolderOnTheRootFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->replaceInFolder($project->dir);

        $this->assertStringContainsString('Scope: Whole Project', $this->modal());
    }

    public function testAdoptRehydratesATabsFrozenDefinition(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);

        $tab = new DockToolTab(1, ResultsPanelMode::Search, 'Find in Project: needle', ResultsPanelFields::searchResults());
        $tab->searchState = new SearchTabState('needle', new SearchOptions(), ['.'], '*.php', ScopeKind::Project);
        $tab->rows = [['path' => 'src/A.php', 'line' => 0, 'text' => 'needle here', 'ranges' => []]];
        $controller->adopt($tab);

        $exported = $controller->exportResults();
        $content = (string) file_get_contents($exported);
        $this->assertStringContainsString('Search: needle', $content, 'the adopted query drives export');
        $this->assertStringContainsString('needle here', $content, 'the adopted rows drive export');
    }

    public function testAdoptIgnoresAFreshTabWithoutState(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);

        $tab = new DockToolTab(1, ResultsPanelMode::Search, 'Usages', ResultsPanelFields::resultsOnly());
        $tab->rows = [['path' => 'src/A.php', 'line' => 0, 'text' => 'x', 'ranges' => []]];
        $controller->adopt($tab);

        $this->assertSame('', $controller->exportResults(), 'a state-less list tab never becomes the export source');
    }

    public function testCloseSearchRecordsQueryAndMask(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);

        $controller->closeSearch(new SearchTabState('kept-query', new SearchOptions(), ['.'], '*.tpl', ScopeKind::Project));

        $this->assertContains('kept-query', new \Porta\History\SearchHistoryStore($project->dir)->all(), '×ing a search tab records its query');
        $this->assertContains('*.tpl', Probe::get(new \Porta\History\SearchPrefsStore($project->dir), 'recentMasks'), 'and its mask');
    }
}
