<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

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

    public function testSelectOpenedFileRevealsTheActiveBufferInTheTreeAndFocusesIt(): void
    {
        // Held for the whole test: TempProject's destructor removes the dir.
        $project = new TempProject(['top.php' => "<?php\n", 'sub/deep/nested.php' => "<?php\n"]);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 100, rows: 30));

        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $tui = Services::resolve($ide, Tui::class);

            $buffers->open($project->dir.'/sub/deep/nested.php', "<?php\n");
            $this->assertNotSame('nested.php', $tree->selectedNode()?->name, 'the collapsed tree cannot already be on it');

            $commands->run('tree_select_opened_file');

            $this->assertSame('nested.php', $tree->selectedNode()?->name, 'the ancestors were expanded and the file selected');
            $this->assertSame($tree, $tui->getFocus(), 'focus moved to the tree so navigation can continue there');
        } finally {
            $this->closeIde($ide);
        }
    }

    /** With nothing open there is nothing to reveal, but the chord must still land you in the tree. */
    public function testSelectOpenedFileWithNoActiveBufferStillFocusesTheTree(): void
    {
        $project = new TempProject(['top.php' => "<?php\n"]);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 100, rows: 30));

        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $tui = Services::resolve($ide, Tui::class);

            $commands->run('tree_select_opened_file');

            $this->assertSame($tree, $tui->getFocus());
        } finally {
            $this->closeIde($ide);
        }
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

            $order = static fn (): array => \array_map(static fn (Buffer $buf): string => $buf->path, $buffers->all());
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
            $activeBefore = \array_first(\array_filter($tabsBefore, static fn (array $t): bool => $t['active']))['key'];

            $commands->run('dock_next_panel');
            $tabsAfterNext = $dock->aliveTabs();
            $activeAfterNext = \array_first(\array_filter($tabsAfterNext, static fn (array $t): bool => $t['active']))['key'];
            $this->assertNotSame($activeBefore, $activeAfterNext, 'dock_next_panel must move to the OTHER tab (direction 1, not 0)');

            $commands->run('dock_next_panel'); // with only 2 tabs, a second forward step must return to the start
            $tabsAfterNext2 = $dock->aliveTabs();
            $activeAfterNext2 = \array_first(\array_filter($tabsAfterNext2, static fn (array $t): bool => $t['active']))['key'];
            $this->assertSame($activeBefore, $activeAfterNext2, 'two forward steps of 1 over 2 tabs must return to the start (direction 2 would already be back here after ONE step)');

            $commands->run('dock_prev_panel');
            $tabsAfterPrev = $dock->aliveTabs();
            $activeAfterPrev = \array_first(\array_filter($tabsAfterPrev, static fn (array $t): bool => $t['active']))['key'];
            $this->assertNotSame($activeBefore, $activeAfterPrev, 'dock_prev_panel must move to the OTHER tab (direction -1, not -0)');
        } finally {
            $sessions = Probe::get($dock, 'sessions');
            $this->assertInstanceOf(TerminalSessions::class, $sessions, 'BottomDock holds its TerminalSessions collaborator under that property');
            $sessions->stopAll();
            $this->closeIde($ide);
        }
    }

    /** Alt+1 lands focus in the tree from wherever it was, PhpStorm's Project tool window. */
    public function testFocusProjectTreeMovesFocusToTheTree(): void
    {
        $project = new TempProject(['top.php' => "<?php\n"]);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 100, rows: 30));

        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $tui = Services::resolve($ide, Tui::class);
            $this->assertNotSame($tree, $tui->getFocus(), 'focus starts in the editor area');

            $commands->run('ide_project_tree');

            $this->assertSame($tree, $tui->getFocus());
        } finally {
            $this->closeIde($ide);
        }
    }

    /**
     * The two view toggles, driven the way the palette drives them. Code
     * vision asks for its lenses the moment it is turned ON — otherwise
     * nothing appears until the next save, which reads as a dead key — and
     * clears the rows when turned off, so they go with the setting.
     */
    public function testTheSoftWrapAndCodeVisionTogglesFlipTheirOptions(): void
    {
        $project = new TempProject(['a.php' => "<?php\nclass A {}\n"]);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, $project->path('a.php'), new VirtualTerminal(columns: 100, rows: 30));

        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $editor = Services::resolve($ide, CodeEditorWidget::class);
            $display = $editor->display;

            $this->assertFalse($display->softWrap(), 'both are off by default');
            $this->assertFalse($display->codeLenses());

            $commands->run('toggle_soft_wrap');
            $this->assertTrue($display->softWrap());
            $commands->run('toggle_soft_wrap');
            $this->assertFalse($display->softWrap(), 'and the same key turns it back off');

            // Lenses on with no language server: the option flips, the request
            // is simply never sent, and nothing is painted.
            $editor->decorations->setCodeLenses([1 => [['title' => '3 usages', 'command' => 'x', 'arguments' => []]]]);
            $commands->run('toggle_code_lenses');
            $this->assertTrue($display->codeLenses());

            $commands->run('toggle_code_lenses');
            $this->assertFalse($display->codeLenses());
            $this->assertSame([], $editor->decorations->codeLensesAt(1), 'turning it off took the rows with it');
        } finally {
            $this->closeIde($ide);
        }
    }
}
