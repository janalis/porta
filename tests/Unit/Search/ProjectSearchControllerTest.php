<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Search\ProjectSearch;
use Porta\Search\ProjectSearchController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ProjectSearchControllerTest extends TestCase
{
    private ResultsPanelWidget $panel;

    private function controller(string $rootDir): ProjectSearchController
    {
        $this->panel = new ResultsPanelWidget();

        return new ProjectSearchController(
            new ProjectSearch($rootDir),
            $this->panel,
            new CodeEditorWidget(),
            new StatusBarWidget(),
            new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20)),
            new \Porta\I18n\Translator('en'),
            new BufferManager(),
            $rootDir,
            static fn (string $path, Document $document): mixed => null,
            new \Porta\History\SearchHistoryStore($rootDir),
            new \Porta\History\SearchPrefsStore($rootDir),
            static fn (\Porta\Ui\ScopeKind $kind): array => ['.'],
        );
    }

    private function header(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->panel->render(new RenderContext(100, 20))));
    }

    public function testOpenUsesTheWholeProjectTitle(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->open();

        $this->assertStringContainsString('Find in Project', $this->header());
    }

    public function testOpenInFolderScopesTheTitleToTheRelativeDir(): void
    {
        $project = new TempProject(['src/sub/A.php' => "<?php\n"]);
        $this->controller($project->dir)->openInFolder($project->dir.'/src/sub');

        $header = $this->header();
        $this->assertStringContainsString('Find in src/sub', $header);
        $this->assertStringContainsString('Scope: Directory', $header, 'a folder search preselects the Directory scope');
    }

    public function testOpenInFolderOnTheRootFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        // The project root resolves to no relative sub-path → whole-project search.
        $this->controller($project->dir)->openInFolder($project->dir);

        $this->assertStringContainsString('Find in Project', $this->header());
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

    public function testReplaceInFolderScopesTheTitleToTheRelativeDir(): void
    {
        $project = new TempProject(['src/sub/A.php' => "<?php\n"]);
        $this->controller($project->dir)->replaceInFolder($project->dir.'/src/sub');

        $this->assertStringContainsString('Replace in src/sub', $this->header());
    }

    public function testReplaceInFolderOnTheRootFallsBackToTheWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $this->controller($project->dir)->replaceInFolder($project->dir);

        $this->assertStringContainsString('Find in Project', $this->header());
    }
}
