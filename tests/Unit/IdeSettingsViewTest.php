<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Layout\WorkspaceLayout;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\SettingsViewController;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\View\ContributedView;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The settings view's shell integration: the ide_settings command opens/closes
 * the full-screen view and is mutually exclusive with the git view.
 */
final class IdeSettingsViewTest extends TestCase
{
    use GitBinary;
    use UsesBundledPlugins;

    private Ide $ide;
    private CommandRegistry $commands;
    private ContributedView $gitView;
    private SettingsViewController $settingsView;
    private ContainerWidget $main;

    private function boot(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');

        new OnboardingState($project->dir)->markWelcomeSeen();
        $this->ide = new Ide($project->dir, $project->path('a.php'), new VirtualTerminal(columns: 100, rows: 24));
        $this->commands = Services::resolve($this->ide, CommandRegistry::class);
        $views = Services::resolve($this->ide, \Porta\Workbench\View\ViewRegistry::class);
        // find(), not reflection: the registry no longer keeps a concrete
        // field per view, which is what dissolved the seven-class cycle.
        $git = $views->find('git');
        self::assertNotNull($git, 'the git plugin registered its view');
        $this->gitView = $git;
        $this->settingsView = $views->find('settings');
        $this->main = Services::resolve($this->ide, WorkspaceLayout::class)->main();
    }

    /** @return list<string> */
    private function mainIds(): array
    {
        return \array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->main->all());
    }

    public function testSettingsCommandOpensAndTogglesTheView(): void
    {
        $this->boot();

        $this->commands->run('ide_settings');
        $this->assertTrue($this->settingsView->isOpen());
        $this->assertContains('settings-area', $this->mainIds());

        $this->commands->run('ide_settings'); // toggle closed
        $this->assertFalse($this->settingsView->isOpen());
        $this->assertNotContains('settings-area', $this->mainIds(), 'the editor is restored');
    }

    public function testTheSettingsAndGitViewsAreMutuallyExclusive(): void
    {
        $this->boot();

        $this->commands->run('ide_git');
        $this->assertTrue($this->gitView->isOpen());

        // Opening settings closes git.
        $this->commands->run('ide_settings');
        $this->assertTrue($this->settingsView->isOpen());
        $this->assertFalse($this->gitView->isOpen(), 'git closed when settings opened');
        $this->assertContains('settings-area', $this->mainIds());

        // Opening git closes settings.
        $this->commands->run('ide_git');
        $this->assertTrue($this->gitView->isOpen());
        $this->assertFalse($this->settingsView->isOpen(), 'settings closed when git opened');

        $this->commands->run('ide_git'); // close the git view (cancels its poll)
        $this->assertFalse($this->gitView->isOpen());
    }
}
