<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * One queue for every install and update, said in one toast — driven
 * through a runner that holds each exit until the test releases it, so a
 * second request can arrive while the first runs.
 */
final class ToolJobsTest extends TestCase
{
    use StatusReads;

    private TempProject $project;

    private TempProject $home;

    private StatusBarWidget $status;

    private FakePluginLifecycle $lifecycle;

    private CommandRegistry $commands;

    /** @var list<string> every argv the runner was handed, joined */
    private array $ran = [];

    /** @var list<\Closure(int, string): void> exits held back */
    private array $held = [];

    /** @var list<list<string>> every argv the runner was handed */
    private array $argvs = [];

    /** The output streamed from the run held last, when it streams any. */
    private ?\Closure $chunks = null;

    /** @var list<string> plugin commands that ran */
    private array $commandsRun = [];

    /** Where the fake PATH finds each binary. @var array<string, string> */
    private array $path = [];

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->home = new TempProject();
        $this->status = new StatusBarWidget();
        $this->lifecycle = new FakePluginLifecycle();
        $this->commands = new CommandRegistry();
        $this->commands->add(
            'p:install',
            function (): void {
                $this->commandsRun[] = 'p:install';
            },
            label: 'Install it',
        );
    }

    /**
     * Plugin "p", installed in the project, declaring $tools.
     *
     * @param list<array<string, mixed>> $tools
     */
    private function plugin(array $tools, string $name = 'p'): void
    {
        $this->project->write('.porta/plugins/'.$name.'/plugin.php', "<?php\n");
        $this->project->write('.porta/plugins/'.$name.'/composer.json', (string) \json_encode(['extra' => ['porta' => ['tools' => $tools]]], \JSON_THROW_ON_ERROR));
        $this->lifecycle->names[] = $name;
    }

    /** @param array<string, string|null> $executables */
    private function tools(array $executables = ['brew' => '/bin/brew', 'composer' => '/bin/composer']): TestTools
    {
        $settings = new PluginSettings($this->project->dir, []);

        return new TestTools(
            $this->project->dir,
            new PluginInstaller($this->project->dir, null, new Translator('en'), null),
            new PluginManager(new EventDispatcher(), $settings),
            new Notifier($this->status, TestTui::create(160, 24)),
            $this->lifecycle,
            $executables,
            path: $this->path,
            run: function (array $argv, string $cwd, \Closure $onExit, ?\Closure $onChunk = null): void {
                $this->ran[] = \implode(' ', $argv);
                $this->argvs[] = $argv;
                $this->chunks = $onChunk;
                // A version probe answers at once; a manager is held.
                if (\str_starts_with($argv[0], '/usr/bin/')) {
                    $answer = 'x 1.2.3';
                    $onExit(0, $answer);

                    return;
                }
                $this->held[] = $onExit;
            },
            home: $this->home->dir,
            commands: $this->commands,
        );
    }

    private function toast(): string
    {
        return $this->statusRow($this->status, 160);
    }

    /** Let the oldest held run exit with $code. */
    private function release(int $code = 0, string $output = ''): void
    {
        $exit = \array_shift($this->held);
        $this->assertNotNull($exit, 'something is running');
        $exit($code, $output);
    }

    private static function brewTool(string $name = 'dot', string $role = 'required'): array
    {
        return ['name' => $name, 'role' => $role, 'sources' => [['brew', $name]]];
    }

    private static function tool(TestTools $tools, string $name, string $plugin = 'p'): ToolRequirement
    {
        $tool = \array_find($tools->catalog->declared($plugin), static fn (ToolRequirement $tool): bool => $name === $tool->name);
        self::assertNotNull($tool);

        return $tool;
    }

    public function testOneRunsAndTheNextWaitsItsTurn(): void
    {
        $this->plugin([self::brewTool('dot'), self::brewTool('rsvg')]);
        $tools = $this->tools();
        $dot = self::tool($tools, 'dot');
        $rsvg = self::tool($tools, 'rsvg');

        $tools->jobs->install($dot);
        $this->assertStringContainsString('Installing dot…', $this->toast());
        $tools->jobs->install($rsvg);
        $this->assertStringContainsString('rsvg will install next', $this->toast());
        $this->assertTrue($tools->jobs->pending($dot));
        $this->assertTrue($tools->jobs->pending($rsvg));
        $this->assertSame(['/bin/brew install dot'], $this->ran, 'one at a time');

        $tools->jobs->install($rsvg);
        $this->assertStringContainsString('rsvg is already queued', $this->toast());

        $this->release();
        $this->assertStringContainsString('Installing rsvg…', $this->toast(), 'the next starts as the first ends');
        $this->assertSame(['p'], $this->lifecycle->serverBinaryReplaced, 'the first landed: its plugin hears');
        $this->assertFalse($tools->jobs->pending($dot));
        $this->release();
        $this->assertStringContainsString('Installed rsvg', $this->toast());
        $this->assertFalse($tools->jobs->pending($rsvg));
    }

    public function testAFailureSaysWhyAndTheQueueGoesOn(): void
    {
        $this->plugin([self::brewTool('dot')]);
        $tools = $this->tools();

        $tools->jobs->install(self::tool($tools, 'dot'));
        $this->release(1, "Error: no formula\n");

        $this->assertStringContainsString('Could not install dot: Error: no formula', $this->toast());
        $this->assertSame([], $this->lifecycle->serverBinaryReplaced);
        $this->assertSame(1, $this->lifecycle->refreshed, 'the page re-reads: its button no longer says "Working…"');
    }

    public function testWithNoUsableSourceItSaysWhatWouldBeNeeded(): void
    {
        $this->plugin([
            ['name' => 'a', 'sources' => [['brew', 'a'], ['brew-cask', 'a'], ['github', 'o/a', ['asset' => 'a_{os}', 'os' => ['Linux' => 'linux'], 'arch' => ['x86_64' => 'x64']]]]],
            ['name' => 'b'],
        ]);
        $tools = $this->tools([]);

        $this->assertFalse($tools->jobs->installable(self::tool($tools, 'a')));
        $tools->jobs->install(self::tool($tools, 'a'));
        $this->assertStringContainsString('No way to install a here (needs brew, GitHub)', $this->toast(), 'a cask is brew; each manager once');

        $tools->jobs->install(self::tool($tools, 'b'));
        $this->assertStringContainsString('Porta cannot install b', $this->toast());
        $this->assertSame([], $this->ran);
    }

    public function testAPluginsOwnInstallerRunsItsCommandAndNeverQueues(): void
    {
        $this->plugin([['name' => 'x', 'sources' => [['self', 'install']]]]);
        $tools = $this->tools();

        $tools->jobs->install(self::tool($tools, 'x'));

        $this->assertSame(['p:install'], $this->commandsRun);
        $this->assertFalse($tools->jobs->pending(self::tool($tools, 'x')));
    }

    public function testUpdateGoesThroughWhatManagesTheCopy(): void
    {
        $this->plugin([['name' => 'x', 'sources' => [['brew', 'x']]]]);
        $cellar = $this->project->write('Cellar/x/1.0/bin/x', "#!/bin/sh\n");
        \chmod($cellar, permissions: 0o755);
        $this->path['x'] = $cellar;
        $tools = $this->tools();

        $tools->jobs->update(self::tool($tools, 'x'));
        $this->assertStringContainsString('Updating x…', $this->toast());
        $this->assertSame(['/bin/brew upgrade x'], $this->ran);
        $this->release();
        $this->assertStringContainsString('Updated x', $this->toast());
    }

    public function testUpdatingWhatPortaDoesNotManageIsRefused(): void
    {
        $this->plugin([['name' => 'x', 'sources' => [['brew', 'x']]]]);
        $this->path['x'] = '/usr/bin/x';
        $tools = $this->tools();

        $tools->jobs->update(self::tool($tools, 'x'));

        $this->assertStringContainsString('x is managed outside Porta', $this->toast());
        $this->assertSame([], $this->ran);
    }

    public function testUpdatingAMissingToolInstallsIt(): void
    {
        $this->plugin([self::brewTool('dot')]);
        $tools = $this->tools();

        $tools->jobs->update(self::tool($tools, 'dot'));

        $this->assertSame(['/bin/brew install dot'], $this->ran);
    }

    public function testInstallMissingTakesRequiredToolsOnly(): void
    {
        $this->plugin([self::brewTool('dot'), self::brewTool('extra', 'suggested'), ['name' => 'lint', 'sources' => [['composer', 'a/lint']]], ['name' => 'nowhere']]);
        $tools = $this->tools();

        $this->assertSame(1, $tools->jobs->installMissing(['p'], ToolManager::Composer), 'composer excluded, suggested left alone, nowhere cannot');
        $this->assertSame(['/bin/brew install dot'], $this->ran);
        $this->assertSame(1, $tools->jobs->installMissing(['p']), 'dot is already queued; lint is the one left');
        $this->assertSame(0, $tools->jobs->installMissing(['p']));
    }

    public function testUpdateAllTakesWhatPortaManages(): void
    {
        $this->plugin([['name' => 'x', 'sources' => [['brew', 'x']]], ['name' => 'y', 'sources' => [['brew', 'y']]], self::brewTool('missing')]);
        $cellar = $this->project->write('Cellar/x/1.0/bin/x', "#!/bin/sh\n");
        \chmod($cellar, permissions: 0o755);
        $this->path['x'] = $cellar;
        $this->path['y'] = '/usr/bin/y';
        $tools = $this->tools();

        $this->assertSame(1, $tools->jobs->updateAll(['p']), 'y is the system\'s; missing is not installed');
        $this->assertSame(0, $tools->jobs->updateAll(['p']), 'already queued');
    }

    public function testEveryStepOfADownloadIsSaidInTheSameToast(): void
    {
        $this->plugin([['name' => 'x', 'sources' => [['github', 'o/x', ['asset' => 'x_{os}_{arch}', 'os' => ['Darwin' => 'darwin'], 'arch' => ['arm64' => 'arm64']]]]]]);
        $tools = $this->tools();

        $tools->jobs->install(self::tool($tools, 'x'));
        $this->assertStringContainsString('x: resolving…', $this->toast());
        $this->release(0, "location: https://github.com/o/x/releases/tag/v1.0.0\r\n\r\n");
        $this->assertStringContainsString('x: downloading…', $this->toast());
        $this->release(22);
        $this->assertStringContainsString('Could not install x: download failed: x_darwin_arm64', $this->toast());
    }

    /** A release that downloads, verifies, unpacks and runs says each step, the download with how far it is. */
    public function testADownloadThatLandsSaysEveryStepOnTheWay(): void
    {
        $this->plugin([['name' => 'x', 'sources' => [['github', 'o/x', ['asset' => 'x_{os}_{arch}.tar.gz', 'member' => 'x', 'os' => ['Darwin' => 'darwin'], 'arch' => ['arm64' => 'arm64']]]]]]);
        $tools = $this->tools();

        $tools->jobs->install(self::tool($tools, 'x'));
        $this->release(0, "location: https://github.com/o/x/releases/tag/v1.0.0\r\n\r\n");
        $this->assertInstanceOf(\Closure::class, $this->chunks, 'the download streams its meter');
        ($this->chunks)("\r#####   41.7%");
        $this->assertStringContainsString('x: downloading 41%', $this->toast());

        $tarball = (string) \array_last($this->argvs)[3];
        \file_put_contents($tarball, data: 'bytes');
        $this->release();
        $this->assertStringContainsString('x: verifying…', $this->toast());

        $this->release(0, \hash('sha256', data: 'bytes').'  '.\basename($tarball)."\n");
        $this->assertStringContainsString('x: extracting…', $this->toast());

        \file_put_contents(\array_last($this->argvs)[4].'/x', data: 'the binary');
        $this->release();
        $this->assertStringContainsString('x: checking the binary…', $this->toast());
    }
}
