<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Git\GitSettings;
use Porta\I18n\Translator;
use Porta\Settings\SettingsGitController;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsGitControllerTest extends TestCase
{
    use PreservesPath;
    use StatusReads;

    protected function setUp(): void
    {
        putenv('PATH=/nonexistent'); // keep git out of these unit runs
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

        return new SettingsGitController(
            $settings + IdeConfig::DEFAULT_SETTINGS,
            $this->git,
            $this->editor,
            new EventDispatcher(),
            $this->status,
            $tui,
            new Notifier($this->status, $tui),
            new Translator('en'),
            $rootDir,
        );
    }

    public function testRowsExposeAllEightGitSettings(): void
    {
        $project = new TempProject([]);
        $rows = $this->controller($project->dir)->rows();

        $keys = array_map(static fn ($r): string => $r->key, $rows);
        $this->assertSame(
            [
                'gitShowBranch',
                'gitGutterMarks',
                'gitBlameDefault',
                'gitSignCommits',
                'gitNoVerify',
                'gitSignoff',
                'gitRefreshInterval',
                'gitPath',
            ],
            $keys,
        );

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
        $this->assertStringContainsString('off', $this->flashText($this->status));
        $this->assertStringNotContainsString('restart', $this->flashText($this->status), 'a display toggle is live');
        $this->assertStringContainsString('"gitShowBranch": false', (string) file_get_contents($project->path('.porta.json')));
    }

    public function testGutterMarksToggleGrowsAndShrinksTheEditorGutter(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);
        $this->assertTrue(EditorState::display($this->editor)->vcsGutter());

        $controller->change('gitGutterMarks', false);
        $this->assertFalse($this->git->gutterMarks());
        $this->assertFalse(EditorState::display($this->editor)->vcsGutter());

        $controller->change('gitGutterMarks', true);
        $this->assertTrue($this->git->gutterMarks());
        $this->assertTrue(EditorState::display($this->editor)->vcsGutter());
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
        $this->assertStringContainsString('restart', $this->flashText($this->status), 'the git path applies on restart');
        $this->assertStringContainsString('/opt/git/bin/git', (string) file_get_contents($project->path('.porta.json')));
    }

    public function testUnknownKeyFlashesAndTouchesNothing(): void
    {
        $project = new TempProject([]);
        $controller = $this->controller($project->dir);

        $controller->change('gitNope', true);
        $this->assertStringContainsString('Unknown setting', $this->flashText($this->status));
        $this->assertFalse(is_file($project->path('.porta.json')), 'an unknown key persists nothing');
    }

    public function testFlashesNotSavedWhenPersistFails(): void
    {
        $controller = $this->controller('/nonexistent-root'); // unwritable → persist fails
        $controller->change('gitShowBranch', false);
        $this->assertStringContainsString('not saved', $this->flashText($this->status));
    }
}
