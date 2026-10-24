<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\GithubAsset;
use Porta\Plugin\Tool\InstallStep;
use Porta\Plugin\Tool\ToolInstaller;
use Porta\Plugin\Tool\ToolLocator;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolOutcome;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Plugin\Tool\ToolResult;
use Porta\Plugin\Tool\ToolSource;
use Porta\Plugin\Tool\ToolVersions;
use Porta\Tests\Support\TempProject;

/**
 * Which source an install takes, and the argv each source is asked for —
 * through a scripted runner, so nothing here spawns or touches the network.
 */
final class ToolInstallerTest extends TestCase
{
    private const string BINARY_BYTES = 'a binary, for the purposes of this test';

    private TempProject $home;

    private TempProject $project;

    /** @var list<string> every argv the runner was handed, joined */
    private array $ran = [];

    /** What the installed binary says when asked its version. */
    private string $reports = 'x 1.0.0';

    /** The tag the release redirect names. */
    private string $latestTag = 'v2.0.0';

    private int $managerExit = 0;

    private string $managerOutput = '';

    private ?ToolResult $result = null;

    /** @var list<string> every progress report, as "Step" */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->home = new TempProject();
        $this->project = new TempProject();
    }

    /** @param array<string, string|null> $executables */
    private function installer(array $executables = [], bool $trusted = true, ?string $home = ''): ToolInstaller
    {
        $locator = new ToolLocator(
            $this->project->dir,
            static fn (): string => '',
            static fn (): bool => $trusted,
            ToolLocator::binDirUnder('' === $home ? $this->home->dir : $home),
            static fn (): ?string => null,
        );

        return new ToolInstaller(
            $this->project->dir,
            static fn (string $name): ?string => $executables[$name] ?? null,
            $locator,
            new ToolVersions($this->runner()),
            $this->runner(),
            osFamily: 'Darwin',
            machine: 'arm64',
        );
    }

    /**
     * A runner that answers like curl (release redirect, asset, checksums),
     * like a landed binary asked its version, and like a package manager.
     *
     * @return \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void
     */
    private function runner(): \Closure
    {
        return function (array $argv, string $cwd, \Closure $onExit, ?\Closure $onChunk = null): void {
            $this->ran[] = \implode(' ', $argv);
            $url = (string) \array_last($argv);
            $ok = 0;
            $noOutput = '';
            match (true) {
                'curl' === $argv[0] && \str_contains($url, 'releases/latest') => $onExit($ok, "HTTP/2 302\r\nlocation: https://github.com/o/x/releases/tag/{$this->latestTag}\r\n\r\n"),
                'curl' === $argv[0] && '-o' === ($argv[2] ?? '') => $onExit((int) (false === \file_put_contents($argv[3], self::BINARY_BYTES)), $noOutput),
                'curl' === $argv[0] => $onExit($ok, \hash('sha256', self::BINARY_BYTES).'  x_darwin_arm64'."\n"),
                \str_contains($argv[0], '/.porta/bin/') || '--version' === ($argv[1] ?? '') => $onExit($ok, $this->reports),
                default => $onExit($this->managerExit, $this->managerOutput),
            };
        };
    }

    private static function github(): ToolSource
    {
        return new ToolSource(ToolManager::Github, 'o/x', new GithubAsset('x_{os}_{arch}', ['Darwin' => 'darwin'], ['arm64' => 'arm64']));
    }

    /** @param list<ToolSource> $sources */
    private static function tool(array $sources): ToolRequirement
    {
        return new ToolRequirement('p', 'x', 'x', sources: $sources);
    }

    private function drive(ToolInstaller $installer, ToolRequirement $tool, ?ToolSource $source = null, ?string $updating = null): ToolResult
    {
        $source ??= $installer->installSource($tool);
        $this->assertInstanceOf(ToolSource::class, $source);
        $onProgress = function (InstallStep $step): void {
            $this->steps[] = $step->name;
        };
        $onDone = function (ToolResult $result): void {
            $this->result = $result;
        };
        null === $updating ? $installer->install($tool, $source, $onProgress, $onDone) : $installer->update($tool, $source, $updating, $onProgress, $onDone);
        $this->assertInstanceOf(ToolResult::class, $this->result, 'the scripted runner answers at once');

        return $this->result;
    }

    public function testTheFirstUsableSourceWins(): void
    {
        $tool = self::tool([new ToolSource(ToolManager::Brew, 'x'), new ToolSource(ToolManager::Cargo, 'x-cli')]);

        $this->assertSame(ToolManager::Cargo, $this->installer(['brew' => null, 'cargo' => '/bin/cargo'])->installSource($tool)?->manager, 'brew is not here');
        $this->assertSame(ToolManager::Brew, $this->installer(['brew' => '/bin/brew', 'cargo' => '/bin/cargo'])->installSource($tool)?->manager);
        $this->assertNull($this->installer()->installSource($tool), 'neither is here');
        $this->assertNull($this->installer()->installSource(self::tool([])), 'nothing declared');
    }

    /** A vendor binary composer put there would not be run in an untrusted project anyway. */
    public function testComposerIsSkippedInAnUntrustedProject(): void
    {
        $tool = self::tool([new ToolSource(ToolManager::Composer, 'a/x'), new ToolSource(ToolManager::Brew, 'x')]);
        $executables = ['composer' => '/bin/composer', 'brew' => '/bin/brew'];

        $this->assertSame(ToolManager::Composer, $this->installer($executables)->installSource($tool)?->manager);
        $this->assertSame(ToolManager::Brew, $this->installer($executables, trusted: false)->installSource($tool)?->manager);
    }

    public function testAGithubSourceNeedsAHomeAndABuildForThisHost(): void
    {
        $this->assertSame(ToolManager::Github, $this->installer()->installSource(self::tool([self::github()]))?->manager);
        $this->assertNull($this->installer(home: null)->installSource(self::tool([self::github()])), 'nowhere to land it');
        $linuxOnly = new ToolSource(ToolManager::Github, 'o/x', new GithubAsset('x_{os}_{arch}', ['Linux' => 'linux'], ['arm64' => 'arm64']));
        $this->assertNull($this->installer()->installSource(self::tool([$linuxOnly])), 'no build for a Mac');
    }

    public function testAPluginsOwnInstallerIsAlwaysUsable(): void
    {
        $this->assertSame(ToolManager::Plugin, $this->installer()->installSource(self::tool([new ToolSource(ToolManager::Plugin, 'install')]))?->manager);
    }

    public function testAManagerInstallsAndUpdatesInTheProject(): void
    {
        $installer = $this->installer(['cargo' => '/bin/cargo']);
        $tool = self::tool([new ToolSource(ToolManager::Cargo, 'x-cli')]);

        $this->assertSame(ToolOutcome::Installed, $this->drive($installer, $tool)->outcome);
        $this->assertSame(['/bin/cargo install x-cli --locked'], $this->ran);

        $this->assertSame(ToolOutcome::Updated, $this->drive($installer, $tool, $tool->sources[0], '/home/me/.cargo/bin/x')->outcome);
        $this->assertSame('/bin/cargo install x-cli --locked --force', $this->ran[1]);
    }

    public function testAFailingManagerSaysItsLastLine(): void
    {
        $installer = $this->installer(['brew' => '/bin/brew']);
        $tool = self::tool([new ToolSource(ToolManager::Brew, 'x')]);
        $this->managerExit = 1;
        $this->managerOutput = "==> Fetching x\nError: No available formula with the name \"x\".\n\n";

        $result = $this->drive($installer, $tool);
        $this->assertSame(ToolOutcome::Failed, $result->outcome);
        $this->assertFalse($result->succeeded());
        $this->assertSame('Error: No available formula with the name "x".', $result->why);

        $this->managerOutput = '';
        $this->assertSame('brew exited with code 1', $this->drive($installer, $tool)->why, 'a silent failure still says something');
    }

    public function testAManagerThatIsNotHereFailsInsteadOfSpawning(): void
    {
        $tool = self::tool([new ToolSource(ToolManager::Npm, 'x')]);

        $result = $this->drive($this->installer(), $tool, $tool->sources[0], '/usr/lib/node_modules/x/bin/x');
        $this->assertSame('npm is not on the PATH', $result->why);
        $this->assertSame([], $this->ran);
    }

    public function testAGithubReleaseLandsInPortasBin(): void
    {
        $result = $this->drive($this->installer(), self::tool([self::github()]));

        $this->assertSame(ToolOutcome::Installed, $result->outcome);
        $this->assertSame('1.0.0', $result->version, 'what the smoke test heard');
        $this->assertSame(self::BINARY_BYTES, \file_get_contents($this->home->path('.porta/bin/x')));
        $this->assertNotContains('tar', \array_map(static fn (string $argv): string => \explode(' ', $argv)[0], $this->ran), 'a bare-binary asset is never extracted');
        $this->assertSame(['Resolving', 'Downloading', 'Verifying', 'Checking'], \array_values(\array_unique($this->steps)));
    }

    /** Update asks which release is the latest BEFORE it downloads, and stops there when the binary already is it. */
    public function testAGithubUpdateOnTheLatestDownloadsNothing(): void
    {
        $this->reports = 'x 2.0.0';
        $path = $this->home->path('.porta/bin/x');

        $result = $this->drive($this->installer(), self::tool([self::github()]), self::github(), $path);

        $this->assertSame(ToolOutcome::UpToDate, $result->outcome);
        $this->assertSame('2.0.0', $result->version);
        $this->assertSame([], \array_filter($this->ran, static fn (string $argv): bool => \str_contains($argv, '/releases/download/')), 'no download');
    }

    public function testAGithubUpdateToANewerReleaseReplacesIt(): void
    {
        $result = $this->drive($this->installer(), self::tool([self::github()]), self::github(), $this->home->path('.porta/bin/x'));

        $this->assertSame(ToolOutcome::Updated, $result->outcome);
        $this->assertFileExists($this->home->path('.porta/bin/x'));
    }

    public function testAGithubUpdateThatCannotResolveTheReleaseFails(): void
    {
        $this->latestTag = '../../not-a-tag';

        $result = $this->drive($this->installer(), self::tool([self::github()]), self::github(), $this->home->path('.porta/bin/x'));

        $this->assertSame(ToolOutcome::Failed, $result->outcome);
        $this->assertNotSame('', $result->why);
    }
}
