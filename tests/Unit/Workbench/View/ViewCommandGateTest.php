<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\ScriptedView;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewCommandGate;
use Porta\Workbench\View\ViewRegistry;

/**
 * A command reaching past an open view window: the view's own commands run
 * over it, everything else closes it first so its effect is seen.
 */
final class ViewCommandGateTest extends TestCase
{
    private ViewRegistry $views;

    private MenuContributions $contributions;

    protected function setUp(): void
    {
        $this->views = new ViewRegistry();
        $this->contributions = new MenuContributions();
        foreach (['settings', 'git', 'welcome'] as $id) {
            $this->views->register(self::view($id));
        }
        $this->contributions->register('git', new MenuContribution(title: 'Git', after: 'Run', items: [], viewId: 'git', viewSafe: ['git_commit']));
    }

    private function gate(): ViewCommandGate
    {
        return new ViewCommandGate($this->views, $this->contributions);
    }

    private static function view(string $id): ContributedView
    {
        return new ScriptedView($id, hostsWindow: 'welcome' !== $id); // the welcome view floats
    }

    public function testAnEditorCommandClosesTheOpenViewFirst(): void
    {
        $this->views->open('settings');

        $this->gate()->before('i', 'ide_save');

        $this->assertFalse($this->views->isOpen('settings'));
    }

    public function testAnEditorActionClosesTheViewEvenIfItsIdIsOnTheList(): void
    {
        $this->views->open('settings');

        $this->gate()->before('e', 'ide_settings');

        $this->assertFalse($this->views->isOpen('settings'), 'only IDE commands can be a view\'s own');
    }

    public function testAViewsOwnCommandRunsOverIt(): void
    {
        $this->views->open('settings');
        $this->gate()->before('i', 'ide_command_palette');
        $this->assertTrue($this->views->isOpen('settings'), "core's list for settings");

        $this->views->open('git');
        $this->gate()->before('i', 'git_commit');
        $this->assertTrue($this->views->isOpen('git'), 'the git menu contributed its own');
    }

    public function testNothingHappensWithNoViewOpen(): void
    {
        $this->gate()->before('i', 'ide_save');

        $this->assertNull($this->views->activeId());
    }

    public function testAnotherViewsCommandIsNotThisOnes(): void
    {
        $this->assertTrue($this->gate()->keepsOpen('git', 'git_commit'));
        $this->assertFalse($this->gate()->keepsOpen('settings', 'git_commit'));
        $this->assertFalse($this->gate()->keepsOpen('review', 'git_commit'), 'a view with no list of its own keeps only the workbench\'s');
    }

    /** Every core list lets the palette and Welcome open over its view: the ways to reach past it without losing it. */
    public function testEveryCoreListKeepsThePaletteAndWelcome(): void
    {
        /** @var array<string, list<string>> $lists */
        $lists = Probe::getStatic(ViewCommandGate::class, 'KEEPS_OPEN');

        $this->assertSame(['settings', 'welcome', 'help'], \array_keys($lists));
        foreach ($lists as $id => $own) {
            $this->assertContains('ide_command_palette', $own, $id);
            $this->assertContains('ide_welcome', $own, $id);
        }
    }

    /** Reaching the menu bar, the palette or the next pane never closes the window it is done over. */
    public function testTheWorkbenchsOwnCommandsKeepEveryViewOpen(): void
    {
        foreach (['settings', 'git', 'review'] as $view) {
            foreach (['ide_menu', 'ide_command_palette', 'focus_next', 'focus_previous', 'show_context_menu', 'ide_dismiss_toast', 'ide_quit'] as $id) {
                $this->assertTrue($this->gate()->keepsOpen($view, $id), "{$id} over {$view}");
            }
        }
    }
}
