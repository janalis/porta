<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsGitController;
use Porta\Config\SettingsRowKind;
use Porta\Editor\BufferManager;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Git\GitSettings;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsGitControllerTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        putenv('PATH=/nonexistent'); // keep git out of these unit runs
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    private GitSettings $git;
    private CodeEditorWidget $editor;
    private StatusBarWidget $status;

    /** @param array<string, int|bool|string> $settings */
    private function controller(string $rootDir, array $settings = []): SettingsGitController
    {
        $this->git = new GitSettings($settings + IdeConfig::DEFAULT_SETTINGS);
        $this->editor = new CodeEditorWidget();
        $this->status = new StatusBarWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $refresher = new GitRefresher(new GitInfo($rootDir), new BufferManager(), $this->editor, $this->status, $tui, $this->git);

        return new SettingsGitController(
            $settings + IdeConfig::DEFAULT_SETTINGS,
            $this->git,
            $this->editor,
            $refresher,
            $this->status,
            $tui,
            new Notifier($this->status, $tui),
            new Translator('en'),
            $rootDir,
        );
    }

    private function flashText(): string
    {
        $flash = new \ReflectionProperty($this->status, 'flash')->getValue($this->status);

        return (string) ($flash['text'] ?? '');
    }

    public function testRowsExposeAllEightGitSettings(): void
    {
        $project = new TempProject([]);
        $rows = $this->controller($project->dir)->rows();

        $keys = array_map(static fn ($r): string => $r->key, $rows);
        $this->assertSame([
            'gitShowBranch', 'gitGutterMarks', 'gitBlameDefault', 'gitSignCommits',
            'gitNoVerify', 'gitSignoff', 'gitRefreshInterval', 'gitPath',
        ], $keys);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->key] = $row;
        }
        $this->assertSame(SettingsRowKind::Bool, $byKey['gitShowBranch']->kind);
        $this->assertTrue($byKey['gitShowBranch']->value, 'branch on by default');
        $this->assertSame(SettingsRowKind::Int, $byKey['gitRefreshInterval']->kind);
        $this->assertSame(2, $byKey['gitRefreshInterval']->value);
        $this->assertSame(1, $byKey['gitRefreshInterval']->min);
        $this->assertSame(60, $byKey['gitRefreshInterval']->max);
        $this->assertSame(SettingsRowKind::Text, $byKey['gitPath']->kind);
        $this->assertTrue($byKey['gitPath']->restart, 'the git path applies on restart');
        $this->assertNotNull($byKey['gitPath']->hint);
    }

    public function testShowBranchTogglesLiveAndPersists(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);

        $controller->change('gitShowBranch', false);
        $this->assertFalse($this->git->showBranch());
        $this->assertStringContainsString('off', $this->flashText());
        $this->assertStringNotContainsString('restart', $this->flashText(), 'a display toggle is live');
        $this->assertStringContainsString('"gitShowBranch": false', (string) file_get_contents($project->path('.porta.json')));
    }

    public function testGutterMarksToggleGrowsAndShrinksTheEditorGutter(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);
        $this->assertTrue($this->editor->hasGitGutter());

        $controller->change('gitGutterMarks', false);
        $this->assertFalse($this->git->gutterMarks());
        $this->assertFalse($this->editor->hasGitGutter());

        $controller->change('gitGutterMarks', true);
        $this->assertTrue($this->git->gutterMarks());
        $this->assertTrue($this->editor->hasGitGutter());
    }

    public function testCommitBoolsAndBlameMutateTheHolder(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);

        $controller->change('gitBlameDefault', true);
        $controller->change('gitSignCommits', true);
        $controller->change('gitNoVerify', true);
        $controller->change('gitSignoff', true);

        $this->assertTrue($this->git->blameDefault());
        $this->assertTrue($this->git->signCommits());
        $this->assertTrue($this->git->noVerify());
        $this->assertTrue($this->git->signoff());
    }

    public function testRefreshIntervalMutatesTheHolderAndPersists(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);

        $controller->change('gitRefreshInterval', 15);
        $this->assertSame(15, $this->git->refreshInterval());
        $this->assertStringContainsString('"gitRefreshInterval": 15', (string) file_get_contents($project->path('.porta.json')));
    }

    public function testGitPathPersistsAndFlashesRestart(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);

        $controller->change('gitPath', '/opt/git/bin/git');
        $this->assertStringContainsString('restart', $this->flashText(), 'the git path applies on restart');
        $this->assertStringContainsString('/opt/git/bin/git', (string) file_get_contents($project->path('.porta.json')));
    }

    public function testUnknownKeyFlashesAndTouchesNothing(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);

        $controller->change('gitNope', true);
        $this->assertStringContainsString('Unknown setting', $this->flashText());
        $this->assertFalse(is_file($project->path('.porta.json')), 'an unknown key persists nothing');
    }

    public function testFlashesNotSavedWhenPersistFails(): void
    {
        $controller = $this->controller('/nonexistent-root'); // unwritable → persist fails
        $controller->change('gitShowBranch', false);
        $this->assertStringContainsString('not saved', $this->flashText());
    }
}
