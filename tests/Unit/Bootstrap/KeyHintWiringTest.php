<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The key hints read the keymap the IDE actually booted with: a `.porta.json`
 * rebind reaches the status bar's legend and the debugger's step legend. The
 * widgets default to an empty keymap for direct construction, so a wiring
 * that forgot to inject the real one would show no legend at all, silently.
 */
final class KeyHintWiringTest extends TestCase
{
    public function testARebindInTheProjectKeymapReachesEveryLegend(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.porta.json' => (string) \json_encode(['keymap' => ['ide_quit' => ['ctrl+w'], 'debug_step_over' => ['f10']]]),
        ]);
        new OnboardingState($project->dir)->markWelcomeSeen();

        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 120, rows: 24));
        try {
            // What the bar is fed, before any width decides what of it fits.
            $right = Probe::get(Services::resolve($ide, StatusBarWidget::class), 'provider')()['right'];
            $this->assertStringContainsString('Ctrl+W quit', $right);
            $this->assertStringNotContainsString('Ctrl+Q quit', $right);

            $this->assertStringContainsString('F10 over · F7 into', Services::resolve($ide, DebugPanelWidget::class)->statusText());
        } finally {
            Services::resolve($ide, ViewRegistry::class)->closeAll();
            Services::resolve($ide, Notifier::class)->dismissAll();
        }
    }
}
