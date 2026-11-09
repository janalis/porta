<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\LatestVersions;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Plugin\Tool\ToolUpdateCheck;
use Porta\Plugin\Tool\ToolUpdates;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The background check: when it runs, what it asks about, what it says —
 * every answer scripted and every clock explicit.
 */
final class ToolUpdatesTest extends TestCase
{
    use StatusReads;

    private const int NOON = 1_800_000_000;

    private TempProject $project;

    private TempProject $home;

    private StatusBarWidget $status;

    private FakePluginLifecycle $lifecycle;

    /** @var list<string> every argv the runner was handed, joined */
    private array $ran = [];

    /** Where the fake PATH finds each binary. @var array<string, string> */
    private array $path = [];

    /** What each brew formula's newest version is. @var array<string, string> */
    private array $latest = [];

    private int $now = self::NOON;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->home = new TempProject();
        $this->status = new StatusBarWidget();
        $this->lifecycle = new FakePluginLifecycle();
    }

    /** A brew-installed tool "x" of plugin "p" that says it is $version. */
    private function brewTool(string $name, string $version): void
    {
        $bin = $this->project->write('Cellar/'.$name.'/'.$version.'/bin/'.$name, "#!/bin/sh\n");
        \chmod($bin, permissions: 0o755);
        $this->path[$name] = $bin;
    }

    /** @param list<array<string, mixed>> $tools */
    private function plugin(array $tools, string $name = 'p'): void
    {
        $this->project->write('.porta/plugins/'.$name.'/porta.php', "<?php\n");
        $this->project->write('.porta/plugins/'.$name.'/composer.json', (string) \json_encode(['extra' => ['porta' => ['tools' => $tools]]], \JSON_THROW_ON_ERROR));
        $this->lifecycle->names[] = $name;
    }

    private function tools(string $cadence = 'daily'): TestTools
    {
        $settings = new PluginSettings($this->project->dir, []);

        return new TestTools(
            $this->project->dir,
            new PluginInstaller($this->project->dir, null, new Translator('en'), null),
            new PluginManager(new EventDispatcher(), $settings),
            new Notifier($this->status, TestTui::create(160, 24)),
            $this->lifecycle,
            path: $this->path,
            run: function (array $argv, string $cwd, \Closure $onExit): void {
                $this->ran[] = \implode(' ', $argv);
                if ('brew' === $argv[0]) {
                    $formula = (string) \array_last($argv);
                    $unknown = '';
                    \array_key_exists($formula, $this->latest)
                        ? $onExit(0, (string) \json_encode(['formulae' => [['versions' => ['stable' => $this->latest[$formula]]]]], \JSON_THROW_ON_ERROR))
                        : $onExit(1, $unknown);

                    return;
                }
                // A version probe: the Cellar path names the version.
                $onExit(0, \basename(\dirname($argv[0], levels: 2)));
            },
            home: $this->home->dir,
            clock: fn (): int => $this->now,
            cadence: $cadence,
        );
    }

    private function toast(): string
    {
        return $this->statusRow($this->status, 160);
    }

    public function testANewerVersionIsSaidOnceForEveryOutdatedTool(): void
    {
        $this->plugin([['name' => 'dot', 'sources' => [['brew', 'dot']]], ['name' => 'gh', 'sources' => [['brew', 'gh']]], ['name' => 'jq', 'sources' => [['brew', 'jq']]]]);
        $this->brewTool('dot', '1.0.0');
        $this->brewTool('gh', '2.0.0');
        $this->brewTool('jq', '1.7.0');
        $this->latest = ['dot' => '1.1.0', 'gh' => '2.0.0'];
        $tools = $this->tools();

        $tools->updates->start();
        EventLoop::cancel((string) Probe::get(Probe::object($tools->updates, 'hourly'), 'timer'));

        $this->assertStringContainsString('Updates available: dot 1.1.0 — run "Plugins: Update Tools"', $this->toast(), 'gh is current; jq could not be asked');
        $dot = $tools->catalog->status(self::tool($tools, 'dot'));
        $this->assertSame('1.1.0', $tools->updates->newer($dot), 'kept for the Settings row');
        $this->assertNull($tools->updates->newer($tools->catalog->status(self::tool($tools, 'gh'))));
        $this->assertSame(['p/dot' => '1.1.0', 'p/gh' => '2.0.0'], new ToolUpdateCheck(new Filesystem(), $this->home->path('.porta'))->latest());
    }

    public function testACheckMadeTodayIsNotMadeAgain(): void
    {
        $this->plugin([['name' => 'dot', 'sources' => [['brew', 'dot']]]]);
        $this->brewTool('dot', '1.0.0');
        $this->latest = ['dot' => '1.0.0'];
        $tools = $this->tools();
        $tools->updates->checkIfDue();
        $asked = \count($this->ran);

        $this->now += ToolUpdateCheck::DAY - 1;
        $tools->updates->checkIfDue();
        $this->assertCount($asked, $this->ran, 'a second short of a day');

        ++$this->now;
        $tools->updates->checkIfDue();
        $this->assertGreaterThan($asked, \count($this->ran));
        $this->assertSame('', \trim($this->toast()), 'nothing newer, nothing said');
    }

    public function testOffChecksNothingAndWeeklyWaitsAWeek(): void
    {
        $this->plugin([['name' => 'dot', 'sources' => [['brew', 'dot']]]]);
        $this->brewTool('dot', '1.0.0');
        $tools = $this->tools('off');

        $tools->updates->checkIfDue();
        $this->assertSame([], $this->ran);

        $tools->updates->setCadence('weekly');
        $this->assertNotSame([], $this->ran, 'switched on, a due check runs at once');
        $asked = \count($this->ran);
        $this->now += 2 * ToolUpdateCheck::DAY;
        $tools->updates->checkIfDue();
        $this->assertCount($asked, $this->ran);

        $tools->updates->setCadence('sometimes');
        $this->now += ToolUpdateCheck::DAY;
        $tools->updates->checkIfDue();
        $this->assertGreaterThan($asked, \count($this->ran), 'an unknown cadence is the default, daily');
    }

    public function testOnlyToolsPortaCouldUpdateOfEnabledPluginsAreAsked(): void
    {
        $this->plugin([['name' => 'sys', 'sources' => [['brew', 'sys']]], ['name' => 'absent', 'sources' => [['brew', 'absent']]]]);
        $this->plugin([['name' => 'off', 'sources' => [['brew', 'off']]]], 'q');
        $this->lifecycle->disabledNames = ['q'];
        $this->path['sys'] = '/usr/bin/sys';
        $this->brewTool('off', '1.0.0');
        $tools = $this->tools();

        $tools->updates->checkIfDue();

        $this->assertSame([], \array_filter($this->ran, static fn (string $argv): bool => \str_starts_with(
            $argv,
            'brew',
        )), 'a system binary, a missing one, and a disabled plugin\'s are not asked about');
    }

    public function testAMachineWithNoHomeNeverChecks(): void
    {
        $updates = new ToolUpdates(
            ['toolUpdateCheck' => 'hourly'],
            TestTools::bare($this->project->dir)->catalog,
            new LatestVersions(),
            $this->lifecycle,
            new Notifier($this->status, TestTui::create(80, 24)),
            new Translator('en'),
            new ToolUpdateCheck(new Filesystem(), null),
        );
        $updates->checkIfDue();

        $this->assertSame('', \trim($this->toast()), 'no home: never due, nothing asked');
    }

    /** An IDE left open for days looks again every hour — on a timer that never holds the loop open. */
    public function testStartLooksAgainEveryHourOnOneTimer(): void
    {
        $tools = $this->tools();
        $tools->updates->start();
        $timer = Probe::get(Probe::object($tools->updates, 'hourly'), 'timer');
        $tools->updates->start();

        try {
            $this->assertIsString($timer);
            $this->assertSame($timer, Probe::get(Probe::object($tools->updates, 'hourly'), 'timer'), 'a second start adds no second timer');
            $this->assertFalse(EventLoop::isReferenced($timer));
        } finally {
            EventLoop::cancel((string) $timer);
        }
    }

    /** The IDE's teardown ends the hourly look; a second stop, or one before any start, does nothing. */
    public function testStopCancelsTheHourlyLook(): void
    {
        $tools = $this->tools();
        $tools->updates->stop();
        $tools->updates->start();
        $timer = (string) Probe::get(Probe::object($tools->updates, 'hourly'), 'timer');

        $tools->updates->stop();
        $tools->updates->stop();

        $this->assertNotContains($timer, EventLoop::getIdentifiers(), 'the timer is gone with the IDE');
        $this->assertNull(Probe::get(Probe::object($tools->updates, 'hourly'), 'timer'), 'so a later start arms a new one');
    }

    private static function tool(TestTools $tools, string $name): ToolRequirement
    {
        $tool = \array_find($tools->catalog->declared('p'), static fn (ToolRequirement $tool): bool => $name === $tool->name);
        self::assertNotNull($tool);

        return $tool;
    }
}
