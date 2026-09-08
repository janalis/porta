<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * A booted Ide over a temp project, for unit tests that reach into its
 * services rather than driving its loop.
 *
 * Built rather than doubled because the thing under test is usually the boot
 * TAIL — what the constructor wires — and a hand-assembled graph proves the
 * assembly a test wrote, not the one the IDE has.
 *
 * The welcome is marked seen because a first-run modal opens over everything
 * and every later assertion then reads it instead. And the surfaces are closed
 * afterwards because these tests never `run()` the Ide, so nothing else ever
 * unwinds them: a view left open or a toast left standing is process-global
 * state that paratest hands to the next method in the worker.
 *
 * An attribute-hook trait like {@see PreservesPath}, so a class keeps its own
 * tearDown.
 */
trait BootsIde
{
    private TempProject $project;

    private Ide $ide;

    /** Whether {@see bootIde()} ran — a typed property cannot be asked. */
    private bool $booted = false;

    /** @param array<string, string> $files the project fixture; the composer.json makes it a project root */
    private function bootIde(array $files = ['composer.json' => '{}']): Ide
    {
        $this->project = new TempProject($files);
        new OnboardingState($this->project->dir)->markWelcomeSeen();

        $this->booted = true;

        return $this->ide = new Ide($this->project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
    }

    /** Guarded: a setUp that threw before booting must not fail again in the hook. */
    #[After]
    protected function closeWhatTheBootLeftOpen(): void
    {
        if (!$this->booted) {
            return;
        }
        Services::resolve($this->ide, ViewRegistry::class)->closeAll();
        Services::resolve($this->ide, Notifier::class)->dismissAll();
    }
}
