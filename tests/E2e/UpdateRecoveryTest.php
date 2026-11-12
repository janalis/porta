<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Ide;
use Porta\Install\IdeUpdates;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Workbench\UpdateRestart;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class UpdateRecoveryTest extends TestCase
{
    use RunsIde;

    public function testRestartRequestSurvivesTeardownAndTheNextBootRecoversIt(): void
    {
        $project = new TempProject(['a.php' => 'disk', '.porta.json' => '{"enabledPlugins":[]}']);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $terminal = new VirtualTerminal();
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal, lspDisabled: true);
        $tui = Services::resolve($ide, Tui::class);
        $this->runSteps(
            [0 => static function () use ($ide): void {
                $buffers = Services::resolve($ide, BufferManager::class);
                $buffers->active()?->document->replaceAll('unsaved');
                $restart = Services::resolve($ide, UpdateRestart::class);
                $restart->plugins();
                $restart->attempt(static fn (): bool => false);
            }],
            $tui,
            $terminal,
            $ide,
        );
        $directory = $ide->restartDirectory();
        $this->assertNotNull($directory);
        try {
            $this->assertFalse($ide->updatesCore());
            $terminal = new VirtualTerminal();
            $next = new Ide($project->dir, terminal: $terminal, lspDisabled: true, restartDirectory: $directory);
            $tui = Services::resolve($next, Tui::class);
            $this->runSteps(
                [0 => function () use ($next, $tui, $directory): void {
                    $active = Services::resolve($next, BufferManager::class)->active();
                    $this->assertSame('unsaved', $active?->document->getText());
                    $this->assertTrue($active?->document->isDirty());
                    $this->assertDirectoryDoesNotExist($directory);
                    $tui->stop();
                }],
                $tui,
                $terminal,
                $next,
            );
            $this->assertSame('disk', \file_get_contents($project->path('a.php')));
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    public function testMalformedRecoveryRemainsAvailableAndNormalStartupContinues(): void
    {
        $project = new TempProject(['a.php' => 'disk', 'handoff/session.json' => '{}', '.porta.json' => '{"enabledPlugins":[]}']);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $terminal = new VirtualTerminal();
        $ide = new Ide($project->dir, terminal: $terminal, lspDisabled: true, restartDirectory: $project->path('handoff'));
        $tui = Services::resolve($ide, Tui::class);
        $this->runSteps(
            [0 => function () use ($project, $tui): void {
                $this->assertFileExists($project->path('handoff/session.json'));
                $tui->stop();
            }],
            $tui,
            $terminal,
            $ide,
        );
    }

    public function testDiskConflictIsReportedWithoutDiscardingRecoveredText(): void
    {
        $project = new TempProject(['a.php' => 'old', '.porta.json' => '{"enabledPlugins":[]}']);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $buffers = new BufferManager();
        $buffers->open($project->path('a.php'), 'old')->document->replaceAll('unsaved');
        $directory = $project->path('handoff');
        $this->assertTrue(new RestartSnapshot()->save($directory, $project->dir, $buffers));
        $project->write('a.php', 'external');
        $project->write('handoff/update-failed', '1');
        $terminal = new VirtualTerminal();
        $ide = new Ide($project->dir, terminal: $terminal, lspDisabled: true, restartDirectory: $directory);
        $tui = Services::resolve($ide, Tui::class);
        $this->runSteps(
            [0 => function () use ($ide, $tui): void {
                $this->assertSame('unsaved', Services::resolve($ide, BufferManager::class)->active()?->document->getText());
                $this->assertStringContainsString('Files changed while restarting', \implode("\n", Toasts::all($ide)));
                $this->assertStringContainsString('The update failed', \implode("\n", Toasts::all($ide)));
                $tui->stop();
            }],
            $tui,
            $terminal,
            $ide,
        );
        $this->assertSame('external', \file_get_contents($project->path('a.php')));
    }

    public function testUpdateSettingsApplyLiveAndInvalidCadencesFallBack(): void
    {
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":[],"settings":{"ideUpdateCheck":"off"}}']);
        $this->runIde($project, null, [0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
            $settings = Services::resolve($ide, SettingsController::class);
            $updates = Services::resolve($ide, IdeUpdates::class);
            $settings->changeSetting('ideUpdateCheck', 'weekly');
            $this->assertSame('weekly', Probe::get($updates, 'cadence'));
            $settings->changeSetting('ideUpdateCheck', 'invalid');
            $this->assertSame('daily', Probe::get($updates, 'cadence'));
            $settings->changeSetting('ideAutoUpdate', true);
            $this->assertTrue(Probe::get($updates, 'auto'));
            $settings->changeSetting('ideAutoUpdate', false);
            $this->assertFalse(Probe::get($updates, 'auto'));
        }]);
    }
}
