<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * WorkbenchCommands wires the key-bound-only workbench chords onto a real
 * CommandRegistry. Every collaborator here (FocusCoordinator, PaneResizer,
 * MenuBarWidget, FindBarWidget, ...) is a final class the project's hard
 * rules forbid mocking, so this boots a real IDE and observes each
 * command's real effect — matching the RunsIde/Services pattern used
 * elsewhere for DI-heavy classes.
 */
final class WorkbenchCommandsTest extends TestCase
{
    private function boot(): Ide
    {
        $project = new TempProject();
        new OnboardingState($project->dir)->markWelcomeSeen();

        return new Ide($project->dir, null, new VirtualTerminal(columns: 100, rows: 30));
    }

    private function closeIde(Ide $ide): void
    {
        Services::resolve($ide, ViewRegistry::class)->closeAll();
    }

    public function testAllChordsAreRegistered(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            foreach ([
                'ide_quit',
                'ide_toggle_focus',
                'focus_next',
                'focus_previous',
                'show_context_menu',
                'resize_pane_grow',
                'resize_pane_shrink',
                'ide_command_palette',
                'ide_search_everywhere',
                'ide_run_last',
                'ide_menu',
            ] as $id) {
                $this->assertTrue($commands->has($id), "{$id} must be registered");
            }
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testFocusNextAdvancesForwardThroughTheRingByExactlyOneStep(): void
    {
        $ide = $this->boot();
        try {
            $tui = Services::resolve($ide, Tui::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $tui->setFocus($tree);
            $commands->run('focus_next');

            $this->assertNotSame($tree, $tui->getFocus(), 'focus_next must move off the tree, not stay (direction 0) or overshoot back onto it (direction 2)');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testFocusPreviousRetreatsBackwardThroughTheRingByExactlyOneStep(): void
    {
        $ide = $this->boot();
        try {
            $tui = Services::resolve($ide, Tui::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            // Land on the tree first via a forward step from the editor side,
            // then confirm one backward step returns to a DIFFERENT member —
            // not a no-op (direction -0) and not an overshoot (direction -2).
            $commands->run('focus_next'); // wherever it starts, moves once
            $afterNext = $tui->getFocus();
            $commands->run('focus_previous');

            $this->assertNotSame($afterNext, $tui->getFocus(), 'focus_previous must move off its current member');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testFocusNextAndPreviousAreExactInversesOfEachOther(): void
    {
        // The decisive check for the direction-magnitude mutants: with only
        // two ring members (tree, editor) at boot, a genuine +1 then -1 must
        // land back exactly where it started; +2 or -2 would not.
        $ide = $this->boot();
        try {
            $tui = Services::resolve($ide, Tui::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $tui->setFocus($tree);
            $commands->run('focus_next');
            $commands->run('focus_previous');

            $this->assertSame($tree, $tui->getFocus(), 'next then previous must be a round trip');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testFocusNextAndPreviousAreDisabledWhileTheMenuBarIsOpen(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $menuBar = Services::resolve($ide, MenuBarWidget::class);

            $this->assertTrue($commands->isEnabled('focus_next'));
            $this->assertTrue($commands->isEnabled('focus_previous'));

            $menuBar->open();
            $this->assertFalse($commands->isEnabled('focus_next'), 'menu bar open must disable focus_next');
            $this->assertFalse($commands->isEnabled('focus_previous'), 'menu bar open must disable focus_previous');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testFocusNextAndPreviousAreDisabledWhileTheFindBarIsOpen(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $findBar = Services::resolve($ide, FindBarWidget::class);

            Probe::set($findBar, 'open', true);

            $this->assertFalse($commands->isEnabled('focus_next'), 'find bar open must disable focus_next');
            $this->assertFalse($commands->isEnabled('focus_previous'), 'find bar open must disable focus_previous');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testShowContextMenuIsDisabledOnlyWhileTheMenuBarIsOpen(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);
            $menuBar = Services::resolve($ide, MenuBarWidget::class);
            $findBar = Services::resolve($ide, FindBarWidget::class);

            $this->assertTrue($commands->isEnabled('show_context_menu'));

            // The find bar being open must NOT gate show_context_menu — only the menu bar does.
            Probe::set($findBar, 'open', true);
            $this->assertTrue($commands->isEnabled('show_context_menu'), 'show_context_menu ignores the find bar');
            Probe::set($findBar, 'open', false);

            $menuBar->open();
            $this->assertFalse($commands->isEnabled('show_context_menu'), 'menu bar open must disable show_context_menu');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testResizePaneGrowAndShrinkMoveTheTreeSidebarByExactlyTwoColumnsEachWay(): void
    {
        $ide = $this->boot();
        try {
            $tui = Services::resolve($ide, Tui::class);
            $tree = Services::resolve($ide, FileTreeWidget::class);
            $renderer = Services::resolve($ide, Renderer::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $tui->setFocus($tree);
            $root = Probe::object($tui, 'root');
            $this->assertInstanceOf(ContainerWidget::class, $root);
            $renderer->renderFrame($root, 100, 30);
            $before = $renderer->getWidgetRect($tree)?->columns;
            $this->assertNotNull($before, 'the tree must have a rendered rect to measure against');

            $this->assertTrue($commands->isEnabled('resize_pane_grow'));
            $commands->run('resize_pane_grow');
            $renderer->renderFrame($root, 100, 30);
            $afterGrow = $renderer->getWidgetRect($tree)?->columns;

            $this->assertSame($before + 2, $afterGrow, 'resize_pane_grow must widen the tree by exactly 2 columns (nudgeSidebar(2*direction) with direction=1)');

            $commands->run('resize_pane_shrink');
            $renderer->renderFrame($root, 100, 30);
            $afterShrink = $renderer->getWidgetRect($tree)?->columns;

            $this->assertSame($before, $afterShrink, 'resize_pane_shrink must exactly undo the grow (direction=-1)');
        } finally {
            $this->closeIde($ide);
        }
    }
}
