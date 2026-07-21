<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Boots a real Ide and drives a terminal resize through the live render loop:
 * the file-tree sidebar auto-collapses below the responsive threshold and
 * restores — with the user's tree width intact — when the terminal widens.
 */
final class IdeResponsiveTest extends TestCase
{
    /** @return list<string> */
    private function mainIds(Tui $tui): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $tui->getById('main')->all());
    }

    public function testSidebarAutoCollapsesInNarrowTerminalsAndRestores(): void
    {
        $project = new TempProject(['src/Demo.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 100, rows: 30);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('src/Demo.php'), $terminal, lspCommand: null);
        $tui = $ide->getTui();

        EventLoop::delay(0.3, function () use ($tui, $terminal): void {
            $tui->tick();
            $this->assertSame(['tree', 'separator', 'editor-pane'], $this->mainIds($tui), 'wide boot shows the sidebar');

            $terminal->simulateResize(50, 30);
            $tui->requestRender();
        });
        EventLoop::delay(0.6, function () use ($tui, $terminal): void {
            $tui->tick();
            $this->assertSame(['editor-pane'], $this->mainIds($tui), 'narrow: the sidebar collapsed');

            $terminal->simulateResize(100, 30);
            $tui->requestRender();
        });
        EventLoop::delay(0.9, function () use ($tui): void {
            $tui->tick();
            $this->assertSame(['tree', 'separator', 'editor-pane'], $this->mainIds($tui), 'wide again: the sidebar restored');
            $tui->stop();
        });

        $ide->run();
    }
}
