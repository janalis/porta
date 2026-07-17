<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Search\ProjectSearch;
use Porta\Search\ProjectSearchController;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\BottomDock;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ProjectSearchControllerTest extends TestCase
{
    private ResultsPanelWidget $panel;
    private CodeEditorWidget $editor;
    private BottomDock $dock;

    private function controller(string $rootDir): ProjectSearchController
    {
        $this->panel = new ResultsPanelWidget();
        $this->editor = new CodeEditorWidget();
        $projectSearch = new ProjectSearch($rootDir);
        $buffers = new BufferManager();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->panel, new DebugPanelWidget());

        return new ProjectSearchController(
            $projectSearch,
            $this->dock,
            $this->editor,
            new StatusBarWidget(),
            $tui,
            new \Porta\I18n\Translator('en'),
            $buffers,
            $rootDir,
            new \Porta\Lsp\LspSessionPool($rootDir, new \Porta\Tests\Support\FixedActiveFile(), new \Porta\I18n\Translator('en')),
            new \Porta\History\SearchHistoryStore($rootDir),
            new \Porta\History\SearchPrefsStore($rootDir),
            new \Porta\Search\SearchScopeResolver($buffers, $this->editor, $rootDir),
            new \Porta\Search\LanguageFilter(new \Porta\Search\TokenClassifier(), $projectSearch, $rootDir),
            new \Porta\History\SearchScopeStore($rootDir),
        );
    }

    /** The Find-in-Files modal composites over the editor pane. */
    private function modal(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->editor->render(new RenderContext(100, 40))));
    }

    public function testOpenShowsTheFindModal(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->open();

        $modal = $this->modal();
        $this->assertStringContainsString('Find in Files', $modal);
        $this->assertStringContainsString('Scope: Whole Project', $modal);
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
        $replace = new \ReflectionMethod($controller, 'replaceLines');

        // Index 5 is past the two lines of "a\nb": it is skipped and the content
        // is returned byte-for-byte unchanged.
        $this->assertSame("a\nb", $replace->invoke($controller, "a\nb", [5], 'x'));
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
}
