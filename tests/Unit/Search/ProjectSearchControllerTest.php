<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Editor\DocumentReplacer;
use Porta\Filesystem\LocalFilesystem;
use Porta\Filesystem\RemoteFilesystem;
use Porta\History\SearchHistoryStore;
use Porta\History\SearchPrefsStore;
use Porta\History\SearchScopeStore;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Search\LanguageFilter;
use Porta\Search\ProjectSearch;
use Porta\Search\ProjectSearchController;
use Porta\Search\SearchOption;
use Porta\Search\SearchOptions;
use Porta\Search\SearchScopeResolver;
use Porta\Search\SearchTabState;
use Porta\Search\TokenClassifier;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Density;
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
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ProjectSearchControllerTest extends TestCase
{
    use PumpsLoop;
    use StatusReads;

    private ResultsPanelWidget $panel;
    private CodeEditorWidget $editor;
    private BottomDock $dock;
    private BufferManager $buffers;
    private StatusBarWidget $statusBar;

    private function controller(string $rootDir, ?RemoteFilesystem $remote = null): ProjectSearchController
    {
        $this->panel = new ResultsPanelWidget();
        $this->editor = new CodeEditorWidget();
        $projectSearch = new ProjectSearch($rootDir, $remote);
        $this->buffers = new BufferManager();
        $buffers = $this->buffers;
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->panel, new DebugPanelWidget());
        $this->statusBar = new StatusBarWidget();

        return new ProjectSearchController(
            $projectSearch,
            $this->dock,
            $this->editor,
            new Notifier($this->statusBar, $tui),
            $tui,
            new Translator('en'),
            $buffers,
            new ProjectPaths($rootDir),
            new DocumentReplacer($buffers, $this->editor, new LspSessionPool($rootDir, new FixedActiveFile(), new Translator('en'))),
            new SearchHistoryStore($rootDir),
            new SearchPrefsStore($rootDir),
            new SearchScopeResolver($buffers, $this->editor, new ProjectPaths($rootDir)),
            new LanguageFilter(new TokenClassifier(), $projectSearch, new ProjectPaths($rootDir)),
            new SearchScopeStore($rootDir),
            $remote ?? new LocalFilesystem(),
        );
    }

    /** The modal's own panel text — it floats over the workspace now, not inside the editor's lines. */
    private function modal(): string
    {
        $placed = $this->editor->findModal->layout([0, 0, 40, 100], CaretVisibility::Shown, Density::Roomy);

        return null === $placed ? '' : AnsiUtils::stripAnsiCodes(\implode("\n", $placed['rows']));
    }

    private function panelText(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->panel->render(new RenderContext(100, 14))));
    }

    private function awaitModalShows(string $needle, float $timeout = 3.0): void
    {
        $this->await(fn (): bool => \str_contains($this->modal(), $needle), $timeout, "the modal to show '{$needle}'");
    }

    private function awaitPanelShows(string $needle, float $timeout = 3.0): void
    {
        $this->await(fn (): bool => \str_contains($this->panelText(), $needle), $timeout, "the results panel to show '{$needle}'");
    }

    /** An option or scope changed re-runs the search, and the modal says so until it lands — which is what holds Alt+A back. */
    public function testEveryReRunShowsTheModalSearching(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('1 result');

        $controller->toggleOption(SearchOption::WholeWord);
        $this->assertStringContainsString('searching…', $this->modal(), 'an option');
        $this->awaitModalShows('1 result');

        $controller->setScopeIndex(0);
        $this->assertStringContainsString('searching…', $this->modal(), 'the scope');
        $this->awaitModalShows('1 result');
    }

    public function testOpenShowsTheFindModal(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->open();

        $modal = $this->modal();
        $this->assertStringContainsString('Find in Files', $modal);
        $this->assertMatchesRegularExpression('/│ Whole Project[^│]*▾ │/u', $modal);
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
        $labels = Probe::get($this->editor->findModal, 'scopeLabels');

        return $labels[$index] ?? '';
    }

    public function testOpenInFolderPreselectsTheFolderScope(): void
    {
        $project = new TempProject(['src/sub/A.php' => "<?php\n"]);
        $this->controller($project->dir)->openInFolder($project->dir.'/src/sub');

        $this->assertMatchesRegularExpression('/│ src\/sub[^│]*▾ │/u', $this->modal(), 'a folder search preselects (and saves) that folder as the scope');
    }

    public function testOpenInFolderOnTheRootFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        // The project root resolves to no relative sub-path → whole-project search.
        $this->controller($project->dir)->openInFolder($project->dir);

        $this->assertMatchesRegularExpression('/│ Whole Project[^│]*▾ │/u', $this->modal());
    }

    public function testTheCommittedQueryIsRecordedInHistoryOnClose(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $controller->cancel(); // panel closed → history recorded

        $this->assertSame(['needle'], new SearchHistoryStore($project->dir)->queries);
    }

    /**
     * A search the regex engine could not finish says so, rather than offering
     * its short result list as the answer.
     *
     * PCRE reports "I gave up" exactly as it reports "nothing here", and a
     * project search skipping a handful of files among thousands is invisible.
     * The find bar learned to distinguish the two the same day; this is the
     * project-wide surface, whose only channel is the status bar.
     */
    public function testASearchTheEngineCannotFinishFlashesInstead(): void
    {
        $project = new TempProject(['src/A.php' => \str_repeat('a', times: 30)."!\nz\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->toggleOption(SearchOption::Regex);

        $controller->search("(a+)+\nz");
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'too complex'), 3.0, 'the too-complex flash');

        $this->assertStringContainsString('too complex', $this->statusRow($this->statusBar));
    }

    /**
     * And an ordinary search does not flash it.
     *
     * The floor the test above needs: asserting only that the warning APPEARS
     * for a bad pattern is satisfied by a warning that appears for everything,
     * which is how a mutation inverting that `if` survived the first run.
     */
    public function testAnOrdinarySearchDoesNotFlashTooComplex(): void
    {
        $project = new TempProject(['src/A.php' => "needle here\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();

        $controller->search('needle');
        $this->awaitModalShows('needle here');

        $this->assertStringNotContainsString('too complex', $this->statusRow($this->statusBar));
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
        $this->await(fn (): bool => !\str_contains($this->modal(), '.js'), what: 'the mask-filtered re-run to land');
        $this->assertStringContainsString('A.php', $this->modal());
        $this->assertSame('*.php', new SearchPrefsStore($project->dir)->lastMask(), 'the mask is persisted for next time');
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

        $this->await(fn (): bool => !\str_contains($this->modal(), 'needle in other'), what: 'the scope-narrowed re-run to land');
        $this->assertStringContainsString('needle in src', $this->modal());
    }

    public function testToggleOptionUpdatesTheModalAndReRuns(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$NEEDLE = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('NEEDLE');

        $controller->toggleOption(SearchOption::CaseSensitive);
        $this->await(fn (): bool => \str_contains($this->modal(), '0 results'), what: 'the case-sensitive re-run to drop the mismatched-case hit');
        $this->assertStringNotContainsString('NEEDLE', $this->modal());
        // The panel checkbox reflects the new option, independent of the re-run.
        $this->assertTrue($this->searchOptions($controller)->caseSensitive);
    }

    private function searchOptions(ProjectSearchController $controller): SearchOptions
    {
        return Probe::get($controller, 'searchOptions');
    }

    /**
     * A remote search runs on the server and finds files never fetched: their
     * placeholder is empty here, and a replace over it would skip them in
     * silence. They are fetched first, and the replace runs — once — when the
     * last one lands, reaching the server as any save does.
     */
    public function testAReplaceOverFilesNeverFetchedFetchesThemFirst(): void
    {
        $server = new TempProject(['a.php' => "<?php // needle\n", 'b.php' => "<?php // needle\n"]);
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $remote = $transport->filesystem($cache->dir);
        $remote->listed($cache->dir);
        $controller = $this->controller($cache->dir, $remote);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('b.php');
        $transport->hold = true;

        $controller->replaceAll('found');

        $this->assertSame('', \file_get_contents($cache->path('a.php')), 'not yet: nothing to replace in');
        $this->assertSame('Fetching 2 files from the server — the replace runs once they are here', $this->flashText($this->statusBar));
        $transport->release();

        $this->assertSame("<?php // found\n", \file_get_contents($server->path('a.php')), 'replaced, and sent');
        $this->assertSame("<?php // found\n", \file_get_contents($server->path('b.php')));
        $this->assertCount(1, \array_filter($transport->calls, static fn (string $call): bool => 'put a.php' === $call), 'once');
    }

    /**
     * A query across lines cannot run in the server's grep: the files here are
     * searched — in a remote project only those opened — and the user is told,
     * where a short list read as the whole answer.
     */
    public function testARemoteSearchOfTheLocalCopyOnlySaysSo(): void
    {
        $server = new TempProject(['a.php' => "<?php\n// needle\n"]);
        $cache = new TempProject();
        $remote = new FakeRemoteTransport($server->dir)->filesystem($cache->dir);
        $remote->listed($cache->dir);
        $controller = $this->controller($cache->dir, $remote);
        $controller->open();

        $controller->search("<?php\n// needle");
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'only the files opened here'), what: 'the warning');

        $controller->search('needle');
        $this->awaitModalShows('a.php');
        $this->assertFalse(Probe::get(Probe::object($controller, 'projectSearch'), 'localOnly'), 'the server answered this one');
    }

    /** A fetch that answers at once runs the replace from inside the first try — and only there, and says what it did. */
    public function testAReplaceWhoseFetchesAnswerAtOnceRunsOnce(): void
    {
        $server = new TempProject(['a.php' => "<?php // needle\n"]);
        $cache = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $remote = $transport->filesystem($cache->dir);
        $remote->listed($cache->dir);
        $controller = $this->controller($cache->dir, $remote);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('a.php');

        $controller->replaceAll('more needle');

        $this->assertSame("<?php // more needle\n", \file_get_contents($server->path('a.php')), 'once — a second pass would say "more more needle"');
        $this->assertSame('Replaced in 1 file', $this->flashText($this->statusBar), 'not a stale "fetching" over it');
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
        $this->assertStringContainsString('$needle = 1;', (string) \file_get_contents($project->path('src/A.php')), 'an open buffer is edited in memory, not written straight to disk');
    }

    /**
     * The matches are the disk's lines: a buffer with unsaved edits has other
     * text at them — here a line added on top, so disk line 1 is the buffer's 2.
     */
    public function testAReplaceLeavesABufferWithUnsavedEditsAloneAndSaysSo(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n\$needle = 2;\n"]);
        $controller = $this->controller($project->dir);
        $buffer = $this->buffers->open($project->path('src/A.php'), "<?php\n\$needle = 1;\n\$needle = 2;\n");
        $buffer->document->setCursor(1, 0);
        $buffer->document->insert("// added\n");
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');

        $controller->replaceAll('found', excluded: [1]);

        $this->assertSame("<?php\n// added\n\$needle = 1;\n\$needle = 2;\n", $buffer->document->getText(), 'nothing rewritten at the disk\'s line numbers');
        $this->assertStringContainsString('Not replaced in src/A.php: unsaved edits', $this->statusRow($this->statusBar, 200));
    }

    /**
     * A replace may not touch the occurrences the token scope excluded.
     *
     * `LanguageFilter` keeps a line when AT LEAST ONE occurrence falls in the
     * requested class, and the replace then rewrote the WHOLE line — so
     * `$foo = 'foo';` searched for `foo` "in strings" came back with its
     * VARIABLE renamed as well, by the filter that existed to protect it.
     * Silent source corruption, and the one line that shows it does both
     * directions.
     */
    public function testReplaceAllUnderATokenScopeLeavesTheExcludedOccurrencesAlone(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$foo = 'foo';\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->toggleOption(SearchOption::Tokens); // any → in comments/strings
        $controller->search('foo');
        $this->awaitModalShows("\$foo = 'foo';");

        $controller->replaceAll('bar');

        $this->assertSame("<?php\n\$foo = 'bar';\n", (string) \file_get_contents($project->path('src/A.php')), 'the string changed, the variable did not');
    }

    /** And the mirror image, so a fix that simply replaced nothing cannot pass. */
    public function testReplaceAllExceptCommentsStringsLeavesTheStringAlone(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$foo = 'foo';\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->toggleOption(SearchOption::Tokens); // → in
        $controller->toggleOption(SearchOption::Tokens); // → except
        $controller->search('foo');
        $this->awaitModalShows("\$foo = 'foo';");

        $controller->replaceAll('bar');

        $this->assertSame("<?php\n\$bar = 'foo';\n", (string) \file_get_contents($project->path('src/A.php')), 'the variable changed, the string did not');
    }

    /**
     * TWO approved occurrences on one line, replaced by something of a
     * different length — which is what makes the splice ORDER matter.
     *
     * With one span per line, right-to-left and left-to-right agree, and both
     * of the cases above have exactly one: they passed against a splice that
     * walked forwards and shifted every later offset by the length difference
     * as it went. The fixture has to hold two before it can tell them apart.
     */
    public function testTwoOccurrencesOnOneLineAreBothReplacedCorrectly(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$foo = 'foo' . 'foo';\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->toggleOption(SearchOption::Tokens); // in comments/strings
        $controller->search('foo');
        $this->awaitModalShows("\$foo = 'foo' . 'foo';");

        $controller->replaceAll('barbaz'); // longer than the match, so offsets move

        $this->assertSame(
            "<?php\n\$foo = 'barbaz' . 'barbaz';\n",
            (string) \file_get_contents($project->path('src/A.php')),
            'both strings replaced, the variable untouched',
        );
    }

    /**
     * An EMPTY replacement deletes every match across open buffers and on disk,
     * and the modal closes afterwards. It is a legitimate operation and stays
     * available — refusing would make a real verb a dead key — but it is the one
     * replace-all nothing on that screen can protect you from: the per-match
     * exclude checkboxes render only once a replacement is non-empty, and the
     * footer advertises Alt+A before anything has been typed.
     *
     * The assertion that matters is that the FILE is untouched by the first
     * ask. A flash-only check passes against code that warns and deletes
     * anyway.
     */
    public function testAnEmptyReplacementDeletesNothingUntilItIsConfirmed(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');

        $controller->replaceAll('');

        $this->assertSame("<?php\n\$needle = 1;\n", (string) \file_get_contents($project->path('src/A.php')), 'nothing was deleted');
        $this->assertStringContainsString('Alt+A again to DELETE', $this->statusRow($this->statusBar));

        $controller->replaceAll('');

        $this->assertSame("<?php\n\$ = 1;\n", (string) \file_get_contents($project->path('src/A.php')), 'the confirmed ask deletes');

        // And the arm is SPENT. Without clearing it, one confirmation would
        // stand for the rest of the session and every later empty replace-all
        // would delete on its first ask.
        $project->write('src/A.php', "<?php\n\$needle = 2;\n");
        $controller->open(); // finishReplace() closed the modal behind the first one
        $controller->search('needle');
        $this->awaitModalShows('$needle = 2;');

        $controller->replaceAll('');

        $this->assertSame("<?php\n\$needle = 2;\n", (string) \file_get_contents($project->path('src/A.php')), 'the next one asks again');
    }

    /**
     * With NOTHING to replace, the empty-replacement confirm must not speak
     * first: offering to confirm the deletion of nothing is worse than the
     * silence it replaced. The guard sits AFTER the "Nothing to replace" check
     * for that reason, and an E2e case caught it sitting before.
     */
    public function testAnEmptyReplacementWithNoResultsSaysNothingToReplace(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('nomatch');
        $this->await(fn (): bool => \str_contains($this->modal(), '0 results'), what: 'the empty search to complete');

        $controller->replaceAll('');

        $this->assertStringContainsString('Nothing to replace', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('DELETE every match', $this->statusRow($this->statusBar));
    }

    /** A NON-empty replacement needs no confirmation — the control for the case above. */
    public function testANonEmptyReplacementAppliesOnTheFirstAsk(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');

        $controller->replaceAll('found');

        $this->assertStringContainsString('$found = 1;', (string) \file_get_contents($project->path('src/A.php')));
    }

    /** A line break typed into the replacement goes into a CRLF file as CRLF — not the lone CR its split line ends with. */
    public function testABreakInTheReplacementTakesTheWholeFilesEnding(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\r\nx foo y\r\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('foo');
        $this->awaitModalShows('x foo y');

        $controller->replaceAll("a\nb");

        $this->assertSame("<?php\r\nx a\r\nb y\r\n", (string) \file_get_contents($project->path('src/A.php')));
    }

    /** And through the scoped splice, which inserts the replacement by itself. */
    public function testABreakInAScopedReplacementTakesTheFilesEndingToo(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\r\n\$foo = 'foo';\r\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->toggleOption(SearchOption::Tokens); // any → in comments/strings
        $controller->search('foo');
        $this->awaitModalShows("\$foo = 'foo';");

        $controller->replaceAll("a\nb");

        $this->assertSame("<?php\r\n\$foo = 'a\r\nb';\r\n", (string) \file_get_contents($project->path('src/A.php')));
    }

    public function testReplaceAllWritesAClosedFileToDisk(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');

        $controller->replaceAll('found');

        $this->assertStringContainsString('$found = 1;', (string) \file_get_contents($project->path('src/A.php')));
    }

    /**
     * A multiline query replaces through its own whole-file path, which has
     * its own empty-result guard. Only a racing E2e once reached it — a
     * replace-all pressed before the search landed — so it is pinned here.
     */
    #[DataProvider('emptyQueries')]
    public function testReplaceAllFlashesNothingToReplaceWhenTheResultSetIsEmpty(string $query): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search($query);
        $this->await(fn (): bool => \str_contains($this->modal(), '0 results'), what: 'the empty search to complete');

        $controller->replaceAll('anything');

        $this->assertStringContainsString('Nothing to replace', $this->statusRow($this->statusBar));
    }

    /** @return iterable<string, array{string}> */
    public static function emptyQueries(): iterable
    {
        yield 'single-line' => ['nomatch'];
        yield 'multiline' => ["no\nmatch"];
    }

    /** A confirm is for the results it was asked over: a new search, not the next Alt+A, follows it. */
    public function testAnArmedEmptyReplaceDoesNotOutliveANewSearch(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n\$other = 2;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 1;');
        $controller->replaceAll(''); // armed

        $controller->search('other');
        $this->awaitModalShows('$other = 2;');
        $controller->replaceAll('');

        $this->assertStringContainsString('$other = 2;', (string) \file_get_contents($project->path('src/A.php')), 'asked again, not deleted');

        // Armed now, over these results. Esc, and back: asked again.
        $controller->open();
        $controller->replaceAll('');
        $this->assertStringContainsString('$other = 2;', (string) \file_get_contents($project->path('src/A.php')), 'reopened, it asks again');
    }

    /** A multi-line query's empty replacement deletes as much: it asks first too. */
    public function testAnEmptyMultilineReplaceAsksFirst(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search("foo()\n{");
        $this->awaitModalShows('foo()');

        $controller->replaceAll('');

        $this->assertStringContainsString("foo()\n{", (string) \file_get_contents($project->path('src/A.php')));
        $this->assertStringContainsString('Alt+A again to DELETE', $this->statusRow($this->statusBar));
    }

    public function testReplaceAllMultilineRewritesTheWholeFile(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search("foo()\n{");
        $this->awaitModalShows('foo()');

        $controller->replaceAll("bar()\n{");

        $this->assertStringContainsString('bar()', (string) \file_get_contents($project->path('src/A.php')));
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

        $flash = $this->statusRow($this->statusBar);
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
        $content = (string) \file_get_contents($exported);

        // grep does not guarantee cross-file result order, so match either
        // ordering — the point under test is the blank-line separator itself.
        $this->assertTrue(
            \str_contains($content, "src/A.php\n  2: // needle in A\n\nsrc/B.php\n  2: // needle in B") || \str_contains($content, "src/B.php\n  2: // needle in B\n\nsrc/A.php\n  2: // needle in A"),
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

    /** `.porta/` comes with a clone: an export target that is a link would have lines the project chose written wherever it points. */
    public function testExportIsNotWrittenThroughALink(): void
    {
        $elsewhere = new TempProject(['.bashrc' => "export A=1\n"]);
        $project = new TempProject(['src/$(touch pwned).php' => "<?php\n// needle\n", '.porta/keep' => '']);
        \symlink($elsewhere->path('.bashrc'), $project->path('.porta/search-results.txt'));
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('// needle');

        $this->assertSame('', $controller->exportResults());
        $this->assertSame("export A=1\n", \file_get_contents($elsewhere->path('.bashrc')));
        $this->assertStringContainsString('Cannot create .porta/search-results.txt', $this->statusRow($this->statusBar));
    }

    public function testExportWhereDotPortaIsAFileSaysSoInsteadOfThrowing(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n", '.porta' => 'a file where the folder would go']);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('// needle');

        $this->assertSame('', $controller->exportResults());
        $this->assertStringContainsString('Cannot create .porta/search-results.txt', $this->statusRow($this->statusBar));
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
        $this->assertMatchesRegularExpression('/│ Whole Project[^│]*▾ │/u', $this->modal(), 'no saved scope was added');
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

        $this->assertMatchesRegularExpression('/│ src\/sub[^│]*▾ │/u', $this->modal());
    }

    public function testReplaceInFolderOnTheRootFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->replaceInFolder($project->dir);

        $this->assertMatchesRegularExpression('/│ Whole Project[^│]*▾ │/u', $this->modal());
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
        $content = (string) \file_get_contents($exported);
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

        $this->assertContains('kept-query', new SearchHistoryStore($project->dir)->queries, '✖ing a search tab records its query');
        $this->assertContains('*.tpl', Probe::get(new SearchPrefsStore($project->dir), 'recentMasks'), 'and its mask');
    }

    /**
     * A SAVED scope survives a close and reopen — the one scope a user has gone
     * to the trouble of saving and pinning was the only one the panel could not
     * bring back.
     *
     * `setScopeIndex()` persisted only built-in KINDS, because a saved scope
     * carries paths instead of one, and `open()` restored by searching the
     * built-ins — so it could only ever land on a built-in however the user had
     * left it.
     */
    public function testASavedScopeIsRestoredOnReopen(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n"]);
        new SearchScopeStore($project->dir)->addPinned('src', ['src']);
        $controller = $this->controller($project->dir);

        $controller->open();
        // The built-ins come first, so the saved one sits after them.
        $savedIndex = \count(Probe::getStatic(ProjectSearchController::class, 'BUILT_INS'));
        $controller->setScopeIndex($savedIndex);

        // A FRESH controller, so the stores are re-read from disk: reopening the
        // same instance would pass on its in-memory prefs alone and prove
        // nothing about persistence, which is the whole defect.
        $reopened = $this->controller($project->dir);
        $reopened->open();

        $this->assertSame($savedIndex, Probe::get($reopened, 'scopeIndex'), 'the saved scope came back');
        $this->assertSame(['src'], Probe::get($reopened, 'scope'), 'and resolved to its own paths');
    }

    /**
     * Choosing a BUILT-IN afterwards forgets the saved one.
     *
     * The two are stored side by side — the kind as the fallback for a saved
     * scope that disappears — so without clearing it, a user who went back to
     * "Project" would keep reopening on the saved scope they had moved off,
     * with no way to make it stop.
     */
    public function testChoosingABuiltInAfterASavedScopeForgetsIt(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        new SearchScopeStore($project->dir)->addPinned('src', ['src']);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->setScopeIndex(\count(Probe::getStatic(ProjectSearchController::class, 'BUILT_INS')));

        $controller->setScopeIndex(0); // back to the first built-in

        $reopened = $this->controller($project->dir);
        $reopened->open();
        $this->assertSame(0, Probe::get($reopened, 'scopeIndex'), 'the built-in stuck');
    }

    /**
     * And it falls back when the saved scope is gone — deleted or renamed
     * between sessions — which is why the built-in kind is still recorded
     * beside it rather than replaced by it.
     */
    public function testAVanishedSavedScopeFallsBackToTheBuiltInKind(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        new SearchScopeStore($project->dir)->addPinned('src', ['src']);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->setScopeIndex(\count(Probe::getStatic(ProjectSearchController::class, 'BUILT_INS')));

        // The scope is removed from the store between sessions.
        new SearchScopeStore($project->dir)->forget('src');
        $reopened = $this->controller($project->dir);
        $reopened->open();

        $this->assertSame(0, Probe::get($reopened, 'scopeIndex'), 'back to the first built-in');
    }

    /**
     * The replace flash counts every file it changed — open buffers and closed
     * files alike, all of them, each once.
     *
     * Every replace test read the edited text and none read the count, so the
     * counter could start at -1, decrement, skip the buffers, stop at the first
     * file or never be flashed, and the suite stayed green.
     */
    public function testReplaceAllCountsEveryFileItChanged(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n",
            'src/B.php' => "<?php\n\$needle = 2;\n",
            'src/C.php' => "<?php\n\$needle = 3;\n",
        ]);
        $controller = $this->controller($project->dir);
        $a = $this->buffers->open($project->path('src/A.php'), "<?php\n\$needle = 1;\n");
        $b = $this->buffers->open($project->path('src/B.php'), "<?php\n\$needle = 2;\n");
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 3;');

        $controller->replaceAll('found');

        $this->assertStringContainsString('$found = 1;', $a->document->getText());
        $this->assertStringContainsString('$found = 2;', $b->document->getText(), 'the second open buffer too');
        $this->assertStringContainsString('$found = 3;', (string) \file_get_contents($project->path('src/C.php')), 'and the closed file on disk');
        $this->assertStringContainsString('Replaced in 3 files', $this->statusRow($this->statusBar));
    }

    /** Excluding the FIRST match skips only that one: the scan goes on to the matches after it. */
    public function testExcludingTheFirstMatchStillReplacesTheOnesAfterIt(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n\$needle = 2;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->search('needle');
        $this->awaitModalShows('$needle = 2;');

        $controller->replaceAll('found', excluded: [0]);

        $this->assertSame("<?php\n\$needle = 1;\n\$found = 2;\n", (string) \file_get_contents($project->path('src/A.php')));
    }

    /** A multiline replace counts a file once however many matches it held, and edits an open buffer in memory. */
    public function testAMultilineReplaceCountsAFileOnceAndEditsTheOpenBuffers(): void
    {
        $text = "<?php\nfunction foo()\n{\n}\nfunction foo()\n{\n}\n";
        $project = new TempProject(['src/A.php' => $text, 'src/B.php' => $text, 'src/C.php' => $text]);
        $controller = $this->controller($project->dir);
        $a = $this->buffers->open($project->path('src/A.php'), $text);
        $b = $this->buffers->open($project->path('src/B.php'), $text);
        $controller->open();
        $controller->search("foo()\n{");
        $this->await(static fn (): bool => 6 === \count(Probe::get($controller, 'lastSearchResults')), what: 'both matches in all three files');

        // The replacement still CONTAINS the query, so a file visited twice
        // would be rewritten twice — visible, where an idempotent one hides it.
        $controller->replaceAll("foo()\n{ // seen");

        $this->assertSame(2, \substr_count($a->document->getText(), needle: '// seen'), 'one open buffer rewritten once, in memory');
        $this->assertSame(2, \substr_count($b->document->getText(), needle: '// seen'), 'so was the other — the scan does not stop after the first buffer');
        $this->assertSame(2, \substr_count((string) \file_get_contents($project->path('src/C.php')), needle: '// seen'), 'and the closed file on disk');
        $this->assertStringContainsString('Replaced in 3 files', $this->statusRow($this->statusBar), 'three files, six matches: counted per file');
    }

    /** replaceLines() skips an index past the end and still reaches the valid ones after it. */
    public function testReplaceLinesGoesOnPastAnOutOfRangeIndex(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        Probe::set($controller, 'lastSearchQuery', 'needle');

        $this->assertSame("a\nx", Probe::invoke($controller, 'replaceLines', "a\nneedle", [5, 1], 'x'));
    }

    /**
     * A token-scoped replace rewrites an occurrence at COLUMN 0.
     *
     * A block comment's inner line starts inside the comment, so its match is
     * approved at offset 0 — the one offset where clamping the splice start to
     * 1 instead of 0 visibly corrupts the line.
     */
    public function testATokenScopedReplaceRewritesAnOccurrenceAtColumnZero(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n/*\nfoo\nfoo\n*/\n\$foo = 1;\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->toggleOption(SearchOption::Tokens); // any → in comments/strings
        $controller->search('foo');
        $this->awaitModalShows('foo');

        $controller->replaceAll('bar');

        $this->assertSame("<?php\n/*\nbar\nbar\n*/\n\$foo = 1;\n", (string) \file_get_contents($project->path('src/A.php')), 'both approved lines, not only the first');
    }

    /** The exported file ends with exactly one newline, and an empty export says why nothing was written. */
    public function testTheExportEndsInOneNewlineAndAnEmptyOneSaysSo(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $this->assertSame('', $controller->exportResults());
        $this->assertStringContainsString('Nothing to export', $this->statusRow($this->statusBar));

        $controller->search('needle');
        $this->awaitModalShows('// needle');
        $content = (string) \file_get_contents($controller->exportResults());

        $this->assertStringEndsWith("  2: // needle\n", $content, 'one trailing newline after the last row, none before the header');
        $this->assertStringStartsWith('Search: needle', $content);
    }

    /** Adding a folder to the scopes pins it under its own path and says so. */
    public function testAddSearchScopePinsTheFolderAndFlashesIt(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);

        $controller->addSearchScope($project->dir.'/src');

        $this->assertStringContainsString('Added src to search scopes', $this->statusRow($this->statusBar));
        $this->assertSame([['label' => 'src', 'paths' => ['src'], 'pinned' => true]], new SearchScopeStore($project->dir)->scopes);
    }

    /** A folder search records the folder as a recent scope that searches that folder. */
    public function testOpenInFolderRecordsTheFolderAsARecentScopeOverItsOwnPath(): void
    {
        $project = new TempProject(['src/sub/A.php' => "<?php\n"]);
        $this->controller($project->dir)->openInFolder($project->dir.'/src/sub');

        $this->assertSame([['label' => 'src/sub', 'paths' => ['src/sub'], 'pinned' => false]], new SearchScopeStore($project->dir)->scopes);
    }

    /** An out-of-range scope index falls back to the FIRST choice, the whole project. */
    public function testAnOutOfRangeScopeIndexFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();

        $first = Probe::get($controller, 'scopeChoices')[0];
        $this->assertNotSame($first->kind, Probe::get($controller, 'scopeChoices')[1]->kind, 'sanity: the first two choices differ');

        $controller->setScopeIndex(99);

        $this->assertSame($first->kind, Probe::get($controller, 'scopeKind'), 'it fell back to choice 0, not choice 1');
        $this->assertSame(['.'], Probe::get($controller, 'scope'));
    }

    /** Of two saved scopes, the one that was last chosen is the one a reopen restores — not merely the first saved one. */
    public function testReopenRestoresTheSavedScopeByNameNotTheFirstSavedOne(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n", 'other/B.php' => "<?php\n"]);
        $store = new SearchScopeStore($project->dir);
        $store->addPinned('src', ['src']);
        $store->addPinned('other', ['other']);
        $controller = $this->controller($project->dir);
        $controller->open();
        // Whichever saved scope the list puts SECOND is the one chosen: restoring
        // "the first saved scope" must not pass by coincidence.
        $saved = \array_values(\array_filter(Probe::get($controller, 'scopeChoices'), static fn (object $choice): bool => null === $choice->kind));
        $this->assertCount(2, $saved);
        $second = $saved[1]->label;
        $index = \array_search($second, \array_map(static fn (object $choice): string => $choice->label, Probe::get($controller, 'scopeChoices')), strict: true);
        $this->assertIsInt($index);
        $controller->setScopeIndex($index);

        $reopened = $this->controller($project->dir);
        $reopened->open();

        $this->assertSame([$second], Probe::get($reopened, 'scope'));
    }

    /** Toggling an option shows it in the open modal, not only in the controller's state. */
    public function testToggleOptionIsReflectedInTheModalsOwnOptions(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();

        $controller->toggleOption(SearchOption::Regex);

        $this->assertTrue(Probe::get($this->editor->findModal, 'options')['regex']);
    }

    /** Closing the panel records the mask in use. */
    public function testCancelRecordsTheMaskInUse(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $controller = $this->controller($project->dir);
        $controller->open();
        $controller->setMask('*.tpl');

        $controller->cancel();

        $this->assertContains('*.tpl', Probe::get(new SearchPrefsStore($project->dir), 'recentMasks'));
    }
}
