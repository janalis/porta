<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DapSession;
use Porta\Debug\DebugState;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Support\AsyncProcess;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\FakeDapClient;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingDebugObserver;
use Porta\Tests\Support\RecordingProjectFiles;
use Porta\Tests\Support\StoreFixture;
use Porta\Tests\Support\TestDebugController;
use Porta\Workbench\UpdateReadiness;

final class UpdateReadinessTest extends TestCase
{
    public function testProcessesInstallsAndLiveTerminalsDeferRestart(): void
    {
        $store = new StoreFixture();
        $runs = new RunningProcesses();
        $sessions = new TerminalSessions();
        $files = new RecordingProjectFiles();
        $debug = TestDebugController::create($store->project->dir);
        $readiness = new UpdateReadiness($sessions, $runs, $store->installer, $files, $debug);
        $this->assertFalse($readiness->busy());
        $session = new DapSession(new FakeDapClient(), 'php', static fn (): array => [], new RecordingDebugObserver(static function (string $event): void {}), static fn (): array => []);
        Probe::set($debug, 'session', $session);
        Probe::set($session, 'state', DebugState::Running);
        $this->assertTrue($readiness->busy());
        Probe::set($session, 'state', DebugState::Terminated);
        $this->assertFalse($readiness->busy());
        $process = new AsyncProcess([\PHP_BINARY, '-r', ''], $store->project->dir);
        $runs->keep($process);
        $this->assertTrue($readiness->busy());
        $runs->forget($process);
        Probe::set($store->installer, 'running', $process);
        $this->assertTrue($readiness->busy());
        Probe::set($store->installer, 'running', null);
        $files->pendingWrites = 1;
        $this->assertTrue($readiness->busy());
        $files->pendingWrites = 0;
        $session = $sessions->create('shell', 'Shell');
        $this->assertFalse($readiness->busy());
        $pty = new PtyProcess([\PHP_BINARY], $store->project->dir);
        $session->attach($pty);
        Probe::set($pty, 'running', true);
        $this->assertTrue($readiness->busy());
        Probe::set($pty, 'running', false);
        $this->assertFalse($readiness->busy());
    }
}
