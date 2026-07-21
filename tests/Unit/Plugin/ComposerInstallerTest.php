<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\ComposerInstaller;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;

final class ComposerInstallerTest extends TestCase
{
    private TempProject $project;

    private function await(callable $done, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    /** An executable stand-in for `composer` that exits with $exitCode after an optional sleep. */
    private function fakeComposer(int $exitCode, int $sleepSeconds = 0): string
    {
        $path = $this->project->dir.'/fake-composer';
        file_put_contents($path, "#!/bin/sh\nsleep {$sleepSeconds}\nexit {$exitCode}\n");
        chmod($path, permissions: 0o755);

        return $path;
    }

    public function testRunsComposerRequireAndReportsSuccess(): void
    {
        $this->project = new TempProject();
        $installer = new ComposerInstaller($this->project->dir, $this->fakeComposer(exitCode: 0));

        $result = null;
        $this->assertTrue($installer->require('phpstan/phpstan', static function (bool $ok) use (&$result): void {
            $result = $ok;
        }));

        $this->await(static fn (): bool => null !== $result);
        $this->assertTrue($result, 'a zero exit is a successful install');
    }

    public function testReportsFailureWhenComposerExitsNonZero(): void
    {
        $this->project = new TempProject();
        $installer = new ComposerInstaller($this->project->dir, $this->fakeComposer(exitCode: 1));

        $result = null;
        $installer->require('phpstan/phpstan', static function (bool $ok) use (&$result): void {
            $result = $ok;
        });

        $this->await(static fn (): bool => null !== $result);
        $this->assertFalse($result, 'a non-zero exit is a failed install');
    }

    public function testMissingComposerReportsFailureImmediately(): void
    {
        $this->project = new TempProject();
        $installer = new ComposerInstaller($this->project->dir, null);

        $result = null;
        $this->assertTrue(
            $installer->require('phpstan/phpstan', static function (bool $ok) use (&$result): void {
                $result = $ok;
            }),
            'the call is accepted (nothing is running)',
        );
        $this->assertFalse($result, 'no composer binary → an immediate, synchronous failure');
    }

    public function testASecondRequireWhileOneRunsIsRejected(): void
    {
        $this->project = new TempProject();
        $installer = new ComposerInstaller($this->project->dir, $this->fakeComposer(exitCode: 0, sleepSeconds: 1));

        $this->assertTrue($installer->require('phpstan/phpstan', static function (): void {}), 'the first require starts');
        $this->assertFalse($installer->require('vimeo/psalm', static function (): void {}), 'a second require is rejected while one runs');
    }
}
