<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * StartupWiring::wire() flashes config-parse warnings as toasts during boot
 * — Ide's constructor runs it, so a config warning surfacing as a toast is
 * the observable proof both the config-warnings half of the combined loop
 * and the loop itself actually ran.
 */
final class StartupWiringTest extends TestCase
{
    public function testAConfigParseWarningSurfacesAsAToastDuringBoot(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.porta.json' => (string) \json_encode(['settings' => ['bogusSetting' => true]]),
        ]);
        new OnboardingState($project->dir)->markWelcomeSeen();

        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
        try {
            $this->assertStringContainsString('bogusSetting', Toasts::last($ide));
        } finally {
            Services::resolve($ide, ViewRegistry::class)->closeAll();
            Services::resolve($ide, Notifier::class)->dismissAll();
        }
    }
}
