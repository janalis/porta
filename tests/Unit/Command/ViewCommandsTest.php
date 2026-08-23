<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * ViewCommands wires the view/window palette commands onto a real
 * CommandRegistry. WorkbenchActions/BottomDock/BufferManager are final
 * classes the project's hard rules forbid mocking, so this boots a real IDE
 * and observes each command's real effect — matching the RunsIde/Services
 * pattern used elsewhere for DI-heavy classes.
 */
final class ViewCommandsTest extends TestCase
{
    private function boot(): Ide
    {
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n", 'c.php' => "<?php\n"]);
        new OnboardingState($project->dir)->markWelcomeSeen();

        return new Ide($project->dir, null, new VirtualTerminal(columns: 100, rows: 30));
    }

    private function closeIde(Ide $ide): void
    {
        Services::resolve($ide, ViewRegistry::class)->closeAll();
    }

    public function testMoveTabLeftAndRightShiftTheActiveBufferByExactlyOnePosition(): void
    {
        $ide = $this->boot();
        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);
            $a = 'a.php';
            $b = 'b.php';
            $c = 'c.php';

            $buffers->open($a, "<?php\n");
            $buffers->open($b, "<?php\n");
            $buffers->open($c, "<?php\n");
            $buffers->setActive($b); // land in the middle, not an edge

            $order = static fn (): array => array_map(static fn ($buf) => $buf->path, $buffers->all());
            $this->assertSame([$a, $b, $c], $order());

            $commands->run('move_tab_left');
            $this->assertSame([$b, $a, $c], $order(), 'move_tab_left must shift by exactly one position, not zero or two');

            $buffers->setActive($a); // back to the middle slot
            $commands->run('move_tab_right');
            $this->assertSame([$b, $c, $a], $order(), 'move_tab_right must shift by exactly one position, not zero or two');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testDockCycleCommandsAreDisabledWithNoVisiblePanel(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);

            $this->assertFalse($commands->isEnabled('dock_next_panel'), 'no dock panel is visible on a fresh boot');
            $this->assertFalse($commands->isEnabled('dock_prev_panel'));
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testDockNextAndPrevPanelCycleByExactlyOneStepBetweenTwoTerminalSessions(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $dock = Services::resolve($ide, BottomDock::class);

            $commands->run('ide_terminal'); // opens the terminal panel with one session
            $commands->run('ide_terminal_new'); // a second session — now 2 alive tabs to cycle between

            $this->assertTrue($commands->isEnabled('dock_next_panel'));
            $tabsBefore = $dock->aliveTabs();
            $this->assertCount(2, $tabsBefore, 'two terminal sessions must produce two alive tabs');
            $activeBefore = array_values(array_filter($tabsBefore, static fn (array $t): bool => $t['active']))[0]['key'];

            $commands->run('dock_next_panel');
            $tabsAfterNext = $dock->aliveTabs();
            $activeAfterNext = array_values(array_filter($tabsAfterNext, static fn (array $t): bool => $t['active']))[0]['key'];
            $this->assertNotSame($activeBefore, $activeAfterNext, 'dock_next_panel must move to the OTHER tab (direction 1, not 0)');

            $commands->run('dock_next_panel'); // with only 2 tabs, a second forward step must return to the start
            $tabsAfterNext2 = $dock->aliveTabs();
            $activeAfterNext2 = array_values(array_filter($tabsAfterNext2, static fn (array $t): bool => $t['active']))[0]['key'];
            $this->assertSame($activeBefore, $activeAfterNext2, 'two forward steps of 1 over 2 tabs must return to the start (direction 2 would already be back here after ONE step)');

            $commands->run('dock_prev_panel');
            $tabsAfterPrev = $dock->aliveTabs();
            $activeAfterPrev = array_values(array_filter($tabsAfterPrev, static fn (array $t): bool => $t['active']))[0]['key'];
            $this->assertNotSame($activeBefore, $activeAfterPrev, 'dock_prev_panel must move to the OTHER tab (direction -1, not -0)');
        } finally {
            $sessions = Probe::get($dock, 'sessions');
            \assert($sessions instanceof TerminalSessions, description: 'BottomDock holds its TerminalSessions collaborator under that property');
            $sessions->stopAll();
            $this->closeIde($ide);
        }
    }
}
