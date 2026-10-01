<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\LocalOnly;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\I18n\Translator;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestDebugController;
use Porta\Tests\Support\TestRunController;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;

/**
 * What runs the project here — a run, a terminal, a debugger — refuses a
 * remote project, whose cache holds only what was opened, and says why.
 */
final class LocalOnlyTest extends TestCase
{
    use StatusReads;

    private StatusBarWidget $status;

    private function gate(?RemoteSpec $remote): LocalOnly
    {
        $this->status = new StatusBarWidget();

        return new LocalOnly(new ProjectLocation($remote), new Notifier($this->status, TestTui::create()), new Translator('en'));
    }

    public function testALocalProjectRuns(): void
    {
        $this->assertFalse($this->gate(null)->refused());
        $this->assertSame('', $this->flashText($this->status));
    }

    public function testARemoteProjectIsRefusedAndSaysWhy(): void
    {
        $this->assertTrue($this->gate(new RemoteSpec('ssh', 'example.org', '/srv/app'))->refused());
        $this->assertSame('This project is on example.org: running it needs it on this machine', $this->flashText($this->status));
    }

    /**
     * Each one stops at the gate — asserted on what it would have started, not
     * on the message: the message comes from the gate either way.
     */
    public function testRunsTerminalsAndTheDebuggerAskIt(): void
    {
        $gate = $this->gate(new RemoteSpec('ssh', 'example.org', '/srv/app'));
        $project = new TempProject(['composer.json' => '{"scripts": {"test": "phpunit"}}']);
        $sessions = new TerminalSessions();
        $editor = new CodeEditorWidget();
        $run = TestRunController::create($project->dir, sessions: $sessions, editor: $editor, localOnly: $gate);
        Probe::set($run, 'lastRun', ['label' => 'test', 'command' => ['true']]);
        $exit = null;

        $run->openRunMenu();
        $this->assertFalse(EditorState::pickerOpen($editor), 'no run menu over a remote project');
        $run->runLast();
        $run->newTerminalSession();
        $run->toggleTerminal();
        $run->runInBackground(['true'], 'bg', static function (int $code) use (&$exit): void {
            $exit = $code;
        });
        $this->assertTrue($sessions->isEmpty(), 'nothing was started');
        $this->assertSame(-1, $exit, 'a waiter hears that its run will never report');

        $debug = TestDebugController::create(localOnly: $gate);
        $debug->startForCurrentFile();
        $this->assertSame('', $this->flashText(Probe::object($debug, 'notifier', 'statusBar')), 'the debugger stopped at the gate, before saying anything of its own');
        $this->assertStringContainsString('example.org', $this->flashText($this->status));
    }
}
