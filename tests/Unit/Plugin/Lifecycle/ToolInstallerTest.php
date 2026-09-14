<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Lifecycle\InstallOutcome;
use Porta\Plugin\Lifecycle\ToolInstaller;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

final class ToolInstallerTest extends TestCase
{
    use PumpsLoop;

    private TempProject $project;

    /** An executable stand-in that records its argv to $logPath, then exits with $exitCode after an optional sleep. */
    private function fakeManager(int $exitCode, string $logPath, int $sleepSeconds = 0): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), prefix: 'porta-mgr');
        \file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"$@\" > '{$logPath}'\nsleep {$sleepSeconds}\nexit {$exitCode}\n");
        \chmod($path, permissions: 0o755);

        return $path;
    }

    public function testRunsTheFirstAvailableManagerAndReportsSuccess(): void
    {
        $this->project = new TempProject();
        $log = $this->project->dir.'/argv.log';
        $installer = new ToolInstaller($this->project->dir, [
            'brew' => $this->fakeManager(0, $log),
            'cargo' => null,
        ]);

        $result = null;
        $outcome = $installer->install([
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ], static function (bool $ok) use (&$result): void {
            $result = $ok;
        });
        $this->assertSame(InstallOutcome::Started, $outcome);

        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: "brew's exit");
        $this->assertTrue($result, 'a zero exit is a successful install');
        $this->assertSame("install\nphpantom-lsp\n", \file_get_contents($log), 'brew install <pkg>, brew winning over cargo');
    }

    public function testSkipsAbsentManagersAndAppendsCargosLockedFlag(): void
    {
        $this->project = new TempProject();
        $log = $this->project->dir.'/argv.log';
        $installer = new ToolInstaller($this->project->dir, [
            'brew' => null, // not on PATH — skipped
            'cargo' => $this->fakeManager(0, $log),
        ]);

        $result = null;
        $outcome = $installer->install([
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ], static function (bool $ok) use (&$result): void {
            $result = $ok;
        });
        $this->assertSame(InstallOutcome::Started, $outcome);

        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: "cargo's exit");
        $this->assertSame("install\nphpantom_lsp\n--locked\n", \file_get_contents($log), 'cargo install <pkg> --locked');
    }

    public function testComposerCandidateGetsTheDevFlag(): void
    {
        $this->project = new TempProject();
        $log = $this->project->dir.'/argv.log';
        $installer = new ToolInstaller($this->project->dir, ['composer' => $this->fakeManager(0, $log)]);

        $result = null;
        $installer->install([['composer', 'phpstan/phpstan']], static function (bool $ok) use (&$result): void {
            $result = $ok;
        });

        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: "composer's exit");
        $this->assertSame("require\n--dev\nphpstan/phpstan\n", \file_get_contents($log), 'composer require --dev <pkg>');
    }

    public function testReportsFailureWhenTheManagerExitsNonZero(): void
    {
        $this->project = new TempProject();
        $log = $this->project->dir.'/argv.log';
        $installer = new ToolInstaller($this->project->dir, ['cargo' => $this->fakeManager(1, $log)]);

        $result = null;
        $installer->install([['cargo', 'phpantom_lsp']], static function (bool $ok) use (&$result): void {
            $result = $ok;
        });

        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: "the failing manager's exit");
        $this->assertFalse($result, 'a non-zero exit is a failed install');
    }

    public function testNoManagerAvailableWhenNoneOfTheCandidatesIsOnPath(): void
    {
        $this->project = new TempProject();
        $installer = new ToolInstaller($this->project->dir, ['brew' => null, 'cargo' => null]);

        $called = false;
        $outcome = $installer->install([
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ], static function () use (&$called): void {
            $called = true;
        });

        $this->assertSame(InstallOutcome::NoManager, $outcome);
        $this->assertFalse($called, 'nothing runs, so the callback never fires');
    }

    public function testASecondInstallWhileOneRunsIsBusy(): void
    {
        $this->project = new TempProject();
        $log = $this->project->dir.'/argv.log';
        $installer = new ToolInstaller($this->project->dir, ['cargo' => $this->fakeManager(0, $log, sleepSeconds: 1)]);

        $done = false;
        $first = $installer->install([['cargo', 'phpantom_lsp']], static function () use (&$done): void {
            $done = true;
        });
        $second = $installer->install([['cargo', 'phpantom_lsp']], static function (): void {});
        $this->assertSame(InstallOutcome::Started, $first);
        $this->assertSame(InstallOutcome::Busy, $second, 'a second install is rejected while one runs');

        // Let the slow install finish before leaving. The point of the test is
        // the state DURING it, but walking out mid-flight leaves a watcher
        // armed on a stream whose process is about to be reaped — the leak
        // LoopIsolationExtension reports, and the next test inherits.
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the in-flight install to finish');
    }
}
