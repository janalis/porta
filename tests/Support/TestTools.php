<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginLifecycleOps;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\LatestVersions;
use Porta\Plugin\Tool\ToolCatalog;
use Porta\Plugin\Tool\ToolInstaller;
use Porta\Plugin\Tool\ToolJobs;
use Porta\Plugin\Tool\ToolLocator;
use Porta\Plugin\Tool\ToolUpdateCheck;
use Porta\Plugin\Tool\ToolUpdates;
use Porta\Plugin\Tool\ToolVersions;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The plugin-tool stack — locator, versions, catalog, installer, jobs,
 * update check — built the way the container builds it, over one project and
 * a home of the test's choosing, with nothing found on the real PATH.
 *
 * Every member is public so a test reaches the one it drives; the defaults
 * find nothing and run nothing, which is the state most tests start from.
 */
final readonly class TestTools
{
    public ToolLocator $locator;

    public ToolVersions $versions;

    public ToolCatalog $catalog;

    public ToolInstaller $installer;

    public ToolJobs $jobs;

    public ToolUpdates $updates;

    /**
     * A stack over $rootDir that finds and runs nothing — for a test that only
     * needs the Settings controllers built.
     */
    public static function bare(string $rootDir, ?PluginSettings $settings = null, ?PluginLifecycleOps $lifecycle = null): self
    {
        $settings ??= new PluginSettings($rootDir, []);

        return new self(
            $rootDir,
            new PluginInstaller($rootDir, null, new Translator('en'), null),
            new PluginManager(new EventDispatcher(), $settings),
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            $lifecycle ?? new FakePluginLifecycle(),
        );
    }

    /**
     * @param array<string, string|null> $executables a manager's executable name => a fake's path
     * @param array<string, string>      $path        a binary name => where the fake PATH has it
     * @param (\Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void)|null $run
     *        how the installer, version probe and update check spawn; null runs for real
     * @param string|null                $home        the `~` downloads land under; null is a machine without one
     * @param (\Closure(string, string): (bool|int|string|null))|null $setting (plugin, key) => what is configured
     * @param (\Closure(): int)|null       $clock       seconds since the epoch
     */
    public function __construct(
        string $rootDir,
        PluginInstaller $plugins,
        PluginManager $manager,
        Notifier $notifier,
        PluginLifecycleOps $lifecycle,
        array $executables = [],
        array $path = [],
        ?\Closure $run = null,
        ?string $home = null,
        bool $trusted = true,
        ?\Closure $setting = null,
        ?\Closure $clock = null,
        string $cadence = 'daily',
        ?CommandRegistry $commands = null,
    ) {
        $this->locator = new ToolLocator(
            $rootDir,
            $setting ?? static fn (): string => '',
            static fn (): bool => $trusted,
            ToolLocator::binDirUnder($home),
            static fn (string $name): ?string => $path[$name] ?? null,
        );
        $this->versions = new ToolVersions($run);
        $this->catalog = new ToolCatalog($plugins, $manager, $this->locator, $this->versions);
        $this->installer = new ToolInstaller($rootDir, static fn (string $name): ?string => $executables[$name] ?? null, $this->locator, $this->versions, $run, osFamily: 'Darwin', machine: 'arm64');
        $translator = new Translator('en');
        $this->jobs = new ToolJobs($this->installer, $this->catalog, $this->versions, $notifier, $translator, $lifecycle, $commands);
        $this->updates = new ToolUpdates(
            ['toolUpdateCheck' => $cadence],
            $this->catalog,
            new LatestVersions($run),
            $lifecycle,
            $notifier,
            $translator,
            new ToolUpdateCheck(new Filesystem(), null === $home ? null : $home.'/.porta'),
            $clock,
        );
    }
}
