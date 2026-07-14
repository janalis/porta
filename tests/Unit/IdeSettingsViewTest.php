<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Git\GitViewController;
use Porta\Ide;
use Porta\Settings\SettingsViewController;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The settings view's shell integration: the ide_settings command opens/closes
 * the full-screen view and is mutually exclusive with the git view.
 */
final class IdeSettingsViewTest extends TestCase
{
    private static function findGit(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }

    private Ide $ide;
    private CommandRegistry $commands;
    private GitViewController $gitView;
    private SettingsViewController $settingsView;
    private ContainerWidget $main;

    private function boot(): void
    {
        if (null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $project = new TempProject(['a.php' => "<?php\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init");

        $this->ide = new Ide($project->dir, $project->path('a.php'), new VirtualTerminal(columns: 100, rows: 24));
        $this->commands = new \ReflectionProperty($this->ide, 'commands')->getValue($this->ide);
        $views = new \ReflectionProperty($this->ide, 'views')->getValue($this->ide);
        $this->gitView = new \ReflectionProperty($views, 'git')->getValue($views);
        $this->settingsView = new \ReflectionProperty($views, 'settings')->getValue($views);
        $this->main = new \ReflectionProperty($this->ide, 'main')->getValue($this->ide);
    }

    /** @return list<string> */
    private function mainIds(): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $this->main->all());
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
