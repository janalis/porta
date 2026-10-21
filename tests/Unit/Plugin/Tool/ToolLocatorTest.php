<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\ToolLocator;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Plugin\Tool\ToolSource;
use Porta\Tests\Support\TempProject;

/** One resolution order for every asker, and who manages what it finds. */
final class ToolLocatorTest extends TestCase
{
    private TempProject $project;

    /** @var array<string, string> bare binary => where the fake PATH has it */
    private array $path = [];

    private string $configured = '';

    private bool $trusted = true;

    private int $trustAsked = 0;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    private function locator(?string $binDir = null): ToolLocator
    {
        return new ToolLocator(
            $this->project->dir,
            fn (string $plugin, string $key): string => 'p' === $plugin && 'binary' === $key ? $this->configured : '',
            function (): bool {
                ++$this->trustAsked;

                return $this->trusted;
            },
            $binDir ?? $this->project->path('home/.porta/bin'),
            fn (string $name): ?string => $this->path[$name] ?? null,
        );
    }

    private function executable(string $relative): string
    {
        $path = $this->project->write($relative, "#!/bin/sh\n");
        \chmod($path, permissions: 0o755);

        return $path;
    }

    private static function composerTool(): ToolRequirement
    {
        return new ToolRequirement('p', 'phpstan', 'phpstan', setting: 'binary', sources: [new ToolSource(ToolManager::Composer, 'phpstan/phpstan')]);
    }

    public function testTheOrderIsSettingVendorPathThenDownloads(): void
    {
        $tool = self::composerTool();
        $locator = $this->locator();
        $this->assertNull($locator->locate($tool), 'nowhere yet');

        $downloaded = $this->executable('home/.porta/bin/phpstan');
        $this->assertSame($downloaded, $locator->locate($tool), '4: ~/.porta/bin');

        $this->path['phpstan'] = '/usr/bin/phpstan';
        $this->assertSame('/usr/bin/phpstan', $locator->locate($tool), '3: the PATH beats a download');

        $vendored = $this->executable('vendor/bin/phpstan');
        $this->assertSame($vendored, $locator->locate($tool), '2: the project shim beats the PATH');

        $this->configured = $this->executable('custom/phpstan');
        $this->assertSame($this->configured, $locator->locate($tool), '1: the setting beats everything');
    }

    public function testAConfiguredValueOnlyCountsWhileItRuns(): void
    {
        $tool = self::composerTool();
        $this->path['phpstan'] = '/usr/bin/phpstan';
        $this->path['phpstan-wrapper'] = '/opt/bin/phpstan-wrapper';
        $locator = $this->locator();

        $this->configured = '/home/teammate/bin/phpstan';
        $this->assertSame('/usr/bin/phpstan', $locator->locate($tool), 'a path that is not here falls through');

        $this->configured = $this->project->write('plantuml.jar', 'x');
        $this->assertSame($this->configured, $locator->locate($tool), 'a file that is there counts, run or not: a .jar is the plugin\'s to run');

        $this->configured = '  phpstan-wrapper ';
        $this->assertSame('/opt/bin/phpstan-wrapper', $locator->locate($tool), 'a bare name goes through the PATH');

        $this->configured = 'not-on-path';
        $this->assertSame('/usr/bin/phpstan', $locator->locate($tool));
    }

    public function testASettinglessToolIgnoresTheSettingStore(): void
    {
        $this->configured = $this->executable('custom/x');
        $tool = new ToolRequirement('p', 'x', 'x');

        $this->assertNull($this->locator()->locate($tool), 'it declared no setting to read');
    }

    public function testVendorBinIsOnlyForComposerToolsInATrustedProject(): void
    {
        $this->executable('vendor/bin/phpstan');
        $this->executable('vendor/bin/dot');
        $dot = new ToolRequirement('p', 'dot', 'dot', sources: [new ToolSource(ToolManager::Brew, 'graphviz')]);

        $this->assertNull($this->locator()->locate($dot), 'brew never puts a shim there');

        $this->trusted = false;
        $locator = $this->locator();
        $this->assertNull($locator->locate(self::composerTool()), 'an untrusted project\'s vendor dir is its own code');
        $locator->locate(self::composerTool());
        $this->assertSame(1, $this->trustAsked, 'trust is asked once per locator');
    }

    public function testNoHomeMeansNoDownloads(): void
    {
        $locator = new ToolLocator($this->project->dir, static fn (): string => '', static fn (): bool => true, null, static fn (): ?string => null);

        $this->assertNull($locator->locate(self::composerTool()));
        $this->assertNull(ToolLocator::binDirUnder(null));
        $this->assertNull(ToolLocator::binDirUnder(''));
        $this->assertSame('/h/.porta/bin', ToolLocator::binDirUnder('/h'));
    }

    public function testTheSourceIsReadOffWhereTheBinaryLives(): void
    {
        $tool = new ToolRequirement('p', 'x', 'x', sources: [
            new ToolSource(ToolManager::Composer, 'a/x'),
            new ToolSource(ToolManager::Brew, 'x'),
            new ToolSource(ToolManager::BrewCask, 'x'),
            new ToolSource(ToolManager::Cargo, 'x'),
            new ToolSource(ToolManager::Npm, 'x'),
        ]);
        $locator = $this->locator();
        $manager = static fn (?ToolSource $source): ?ToolManager => $source?->manager;

        $this->assertSame(ToolManager::Composer, $manager($locator->sourceOf($tool, $this->project->dir.'/vendor/bin/x')));
        $this->assertSame(ToolManager::Brew, $manager($locator->sourceOf($tool, '/opt/homebrew/Cellar/x/1.0/bin/x')));
        $this->assertSame(ToolManager::BrewCask, $manager($locator->sourceOf($tool, '/opt/homebrew/Caskroom/x/1.0/x')));
        $this->assertSame(ToolManager::Cargo, $manager($locator->sourceOf($tool, '/home/me/.cargo/bin/x')));
        $this->assertSame(ToolManager::Npm, $manager($locator->sourceOf($tool, '/usr/lib/node_modules/x/bin/x')));
        $this->assertNull($locator->sourceOf($tool, '/usr/bin/x'), 'a system binary is managed outside Porta');
        $this->assertNull($locator->sourceOf($tool, $this->project->path('home/.porta/bin/x')), 'a download, but no github source declared');
    }

    public function testALinkIsJudgedByItsTarget(): void
    {
        $target = $this->executable('Cellar/x/1.0/bin/x');
        \mkdir($this->project->path('bin'));
        \symlink($target, $this->project->path('bin/x'));
        $tool = new ToolRequirement('p', 'x', 'x', sources: [new ToolSource(ToolManager::Brew, 'x')]);

        $this->assertSame(ToolManager::Brew, $this->locator()->sourceOf($tool, $this->project->path('bin/x'))?->manager);
    }

    public function testDownloadsAndPluginInstallsAreTheirOwnSources(): void
    {
        $github = new ToolRequirement('p', 'x', 'x', sources: [new ToolSource(ToolManager::Github, 'o/x')]);
        $self = new ToolRequirement('p', 'y', 'y', sources: [new ToolSource(ToolManager::Plugin, 'install')]);
        $locator = $this->locator();

        $this->assertSame(ToolManager::Github, $locator->sourceOf($github, $this->project->path('home/.porta/bin/x'))?->manager);
        $this->assertSame(ToolManager::Plugin, $locator->sourceOf($self, '/anywhere/y')?->manager, 'a plugin that installs its tool also updates it');
    }

    public function testForProjectReadsThePluginSettings(): void
    {
        $custom = $this->executable('custom/x');
        $settings = new PluginSettings($this->project->dir, ['p' => ['binary' => $custom]]);
        $tool = new ToolRequirement('p', 'x', 'porta-no-such-binary', setting: 'binary');

        $this->assertSame($custom, ToolLocator::forProject($this->project->dir, $settings)->locate($tool));
    }
}
