<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\DependencyInstall;
use Porta\Plugin\Lifecycle\DependencyRemoval;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;

final class PluginInstallerTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $project;
    private TempProject $global;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->global = new TempProject();
    }

    private function installer(?string $globalBase = 'own', ?string $git = null, ?string $composer = null): PluginInstaller
    {
        return new PluginInstaller($this->project->dir, 'own' === $globalBase ? $this->global->dir : $globalBase, new Translator('en'), $git, null, $composer);
    }

    /**
     * A stub standing in for composer: it writes the vendor tree a real
     * `composer install` would, then exits with $exitCode. Real composer is not
     * in the suite's business — network, minutes, and a resolver we do not own.
     */
    private function composerStub(int $exitCode): string
    {
        $path = $this->project->dir.'/fake-composer';
        file_put_contents($path, '#!'.\PHP_BINARY.' -n'."\n<?php\n".<<<'PHP'
            @mkdir(getcwd().'/vendor/composer', 0o777, true);
            file_put_contents(getcwd().'/vendor/composer/autoload_psr4.php', "<?php return [];\n");
            PHP."\nexit({$exitCode});\n");
        chmod($path, permissions: 0o755);

        return $path;
    }

    /** Runs install() and pumps the loop until the callback fires; returns [error, file]. */
    private function install(PluginInstaller $installer, string $source, string $scope = 'project'): array
    {
        $result = null;
        $installer->install($source, $scope, static function (?string $error, ?string $file) use (&$result): void {
            $result = [$error, $file];
        });
        $elapsed = 0.0;
        while (null === $result && $elapsed < 5.0) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertNotNull($result, 'the install callback fired');

        return $result;
    }

    /** A plugin source dir outside any scope base. */
    private function sourceDir(string $name, Presence $withPluginPhp = Presence::With): string
    {
        $dir = $this->project->dir.'/src-plugins/'.$name;
        mkdir($dir, permissions: 0o777, recursive: true);
        if (Presence::With === $withPluginPhp) {
            file_put_contents($dir.'/plugin.php', "<?php\n// {$name}\n");
        }

        return $dir;
    }

    private function git(): string
    {
        $this->skipWithoutGit();

        return (string) $this->whichGit();
    }

    /** A local git repo usable as a clone URL. */
    private function repo(Presence $withPluginPhp): string
    {
        $dir = $this->sourceDir('repo-plugin', $withPluginPhp);
        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>/dev/null && git -C {$q} config user.email t@t && git -C {$q} config user.name t && git -C {$q} add -A && git -C {$q} commit -qm init --allow-empty 2>/dev/null");

        return $dir;
    }

    public function testCopyInstallLandsInTheChosenScope(): void
    {
        $source = $this->sourceDir('demo');
        [$error, $file] = $this->install($this->installer(), $source);
        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/demo/plugin.php', $file);
        $this->assertFileExists((string) $file);

        [$error, $file] = $this->install($this->installer(), $source, 'global');
        $this->assertNull($error);
        $this->assertSame($this->global->dir.'/.porta/plugins/demo/plugin.php', $file);
    }

    public function testCopyGuards(): void
    {
        [$error] = $this->install($this->installer(), $this->sourceDir('empty', withPluginPhp: Presence::Without));
        $this->assertStringContainsString('No plugin.php found in', (string) $error);

        $source = $this->sourceDir('demo');
        $this->install($this->installer(), $source);
        [$error] = $this->install($this->installer(), $source);
        $this->assertSame('Plugin "demo" is already installed', $error);

        [$error] = $this->install($this->installer(), $this->sourceDir('.hidden'));
        $this->assertSame('".hidden" is not a valid plugin name', $error);

        [$error] = $this->install($this->installer(globalBase: null), $source, 'global');
        $this->assertSame('Cannot install globally (no home directory)', $error);
    }

    public function testCopyFailureCleansUpTheDestination(): void
    {
        // The plugins base is an existing *file*: mirror() cannot create the dir.
        mkdir($this->project->dir.'/.porta', permissions: 0o777, recursive: true);
        file_put_contents($this->project->dir.'/.porta/plugins', data: '');
        [$error] = $this->install($this->installer(), $this->sourceDir('demo'));
        $this->assertStringContainsString('Cannot install into', (string) $error);
        $this->assertFileDoesNotExist($this->project->dir.'/.porta/plugins/demo');
    }

    public function testUrlSourcesRequireGitAndDeriveTheNameFromTheUrl(): void
    {
        [$error] = $this->install($this->installer(git: null), 'https://example.com/x/demo.git');
        $this->assertSame('git is not available', $error);

        // Name derivation: trailing slash and .git suffix are stripped before validation.
        [$error] = $this->install($this->installer(git: null), 'https://example.com/x/-bad-name.git/');
        $this->assertSame('"-bad-name" is not a valid plugin name', $error);
    }

    public function testCloneInstallsAndRejectsAConcurrentOne(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);

        // A plain local path would take the copy branch, so use file://.
        $installer = $this->installer(git: $git);
        $first = null;
        $installer->install('file://'.$repo, 'project', static function (?string $error, ?string $file) use (&$first): void {
            $first = [$error, $file];
        });
        $second = null;
        $installer->install('file://'.$repo, 'project', static function (?string $error) use (&$second): void {
            $second = $error;
        });
        $this->assertSame('Another install is already running', $second, 'single-flight while the clone runs');

        $elapsed = 0.0;
        while (null === $first && $elapsed < 5.0) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertSame([null, $this->project->dir.'/.porta/plugins/repo-plugin/plugin.php'], $first);
        $this->assertFileExists($this->project->dir.'/.porta/plugins/repo-plugin/plugin.php');
    }

    public function testCloneFailureAndPluginlessRepoAreRemoved(): void
    {
        $git = $this->git();

        [$error] = $this->install($this->installer(git: $git), 'file:///nonexistent/nowhere');
        $this->assertStringContainsString('git clone failed (exit code', (string) $error);
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/nowhere');

        $repo = $this->repo(withPluginPhp: Presence::Without);
        [$error] = $this->install($this->installer(git: $git), 'file://'.$repo);
        $this->assertSame('The repository contains no plugin.php', $error);
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/repo-plugin');
    }

    public function testRemoveAndItsErrors(): void
    {
        $installer = $this->installer();
        $this->install($installer, $this->sourceDir('demo'));
        $this->assertNull($installer->remove('demo', 'project'));
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/demo');

        $this->assertNull($installer->remove('gone', 'project'), 'removing a missing dir is a quiet success');
        $this->assertSame('Cannot remove "x"', $this->installer(globalBase: null)->remove('x', 'global'));
    }

    public function testEmbeddedPluginsListNonRemovableAndRefuseRemoval(): void
    {
        $embedded = new TempProject(['bundled/plugin.php' => "<?php\n"]);
        $installer = new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $embedded->dir);
        $this->install($installer, $this->sourceDir('mine'));

        $this->assertSame(
            [
                ['name' => 'mine', 'scope' => 'project', 'dir' => $this->project->dir.'/.porta/plugins/mine'],
                ['name' => 'bundled', 'scope' => 'embedded', 'dir' => $embedded->dir.'/bundled'],
            ],
            $installer->installed(),
        );

        $this->assertSame('Built-in plugins cannot be removed', $installer->remove('bundled', 'embedded'));
        $this->assertDirectoryExists($embedded->dir.'/bundled');
    }

    public function testACoincidingEmbeddedDirClassifiesItsPluginsAsEmbedded(): void
    {
        // PORTA_EMBEDDED_PLUGINS pointed at the project's own plugin dir: the
        // realpath-equality guard keeps those entries non-removable.
        mkdir($this->project->dir.'/.porta/plugins/bundled', permissions: 0o777, recursive: true);
        file_put_contents($this->project->dir.'/.porta/plugins/bundled/plugin.php', data: "<?php\n");
        $installer = new PluginInstaller($this->project->dir, null, new Translator('en'), null, $this->project->dir.'/.porta/plugins');

        $this->assertSame(
            [['name' => 'bundled', 'scope' => 'embedded', 'dir' => $this->project->dir.'/.porta/plugins/bundled']],
            $installer->installed(),
            'listed once, as embedded — not as a removable project plugin',
        );
    }

    public function testInstalledListsBothScopesProjectFirst(): void
    {
        $installer = $this->installer();
        $this->assertSame([], $installer->installed());

        $source = $this->sourceDir('both');
        $this->install($installer, $source);
        $this->install($installer, $source, 'global');
        $this->install($installer, $this->sourceDir('worldwide'), 'global');

        $this->assertSame(
            [
                ['name' => 'both', 'scope' => 'project', 'dir' => $this->project->dir.'/.porta/plugins/both'],
                ['name' => 'both', 'scope' => 'global', 'dir' => $this->global->dir.'/.porta/plugins/both'],
                ['name' => 'worldwide', 'scope' => 'global', 'dir' => $this->global->dir.'/.porta/plugins/worldwide'],
            ],
            $installer->installed(),
        );

        $this->assertSame(
            [['name' => 'both', 'scope' => 'project', 'dir' => $this->project->dir.'/.porta/plugins/both']],
            $this->installer(globalBase: null)->installed(),
            'a null global base lists only the project scope',
        );
    }

    public function testInstallingAPluginInstallsItsComposerDependencies(): void
    {
        // In the PLUGIN's directory, never the user's project — that tree is
        // ToolInstaller's, and a plugin's libraries have no business in it.
        $source = $this->sourceDir('needy');
        file_put_contents($source.'/composer.json', data: '{"require":{"vend/lib":"^1.0"}}');

        [$error, $file] = $this->install($this->installer(composer: $this->composerStub(0)), $source);

        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/needy/plugin.php', $file);
        $this->assertFileExists($this->project->dir.'/.porta/plugins/needy/vendor/composer/autoload_psr4.php');
        $this->assertDirectoryDoesNotExist($this->project->dir.'/vendor', 'nothing was installed into the project');
    }

    public function testAFailedDependencyInstallKeepsThePluginAndSaysSo(): void
    {
        // The directory is what the user asked for; the next boot declines the
        // plugin by name with the command to run. Removing a good clone over a
        // transient network error would be the worse trade.
        $source = $this->sourceDir('flaky');
        file_put_contents($source.'/composer.json', data: '{"require":{"vend/lib":"^1.0"}}');

        [$error, $file] = $this->install($this->installer(composer: $this->composerStub(3)), $source);

        $this->assertNotNull($error);
        $this->assertStringContainsString('composer install failed (exit code 3)', (string) $error);
        $this->assertNull($file);
        $this->assertFileExists($this->project->dir.'/.porta/plugins/flaky/plugin.php', 'the install itself stands');
    }

    public function testWithoutComposerADependentPluginInstallsButSaysWhatIsMissing(): void
    {
        $source = $this->sourceDir('needy');
        file_put_contents($source.'/composer.json', data: '{"require":{"vend/lib":"^1.0"}}');

        [$error, $file] = $this->install($this->installer(), $source);

        $this->assertStringContainsString('composer is not available', (string) $error);
        $this->assertNull($file);
    }

    public function testAPluginThatShippedItsVendorIsNotReinstalled(): void
    {
        // A copied source that already has vendor/ skips the step entirely — no
        // subprocess, so an install stays synchronous. It must declare a REAL
        // package, or the predicate answers no on the requirement alone and the
        // vendor/ half of this test proves nothing.
        $source = $this->sourceDir('prevendored');
        file_put_contents($source.'/composer.json', data: '{"require":{"vend/lib":"^1.0"}}');
        mkdir($source.'/vendor', permissions: 0o777, recursive: true);

        [$error, $file] = $this->install($this->installer(composer: '/nonexistent/composer'), $source);

        $this->assertNull($error, 'composer was never needed');
        $this->assertSame($this->project->dir.'/.porta/plugins/prevendored/plugin.php', $file);
    }

    public function testAPluginWhoseOnlyRequirementIsThePlatformNeedsNoInstall(): void
    {
        // composer.json is on its way to being every plugin's manifest, so
        // "has a composer.json" cannot mean "owes an install" — shelling out
        // for `php: >=8.4` would be a subprocess to answer no.
        $source = $this->sourceDir('platform');
        file_put_contents($source.'/composer.json', data: '{"require":{"php":">=8.4"}}');

        [$error, $file] = $this->install($this->installer(composer: '/nonexistent/composer'), $source);

        $this->assertNull($error, 'composer was never needed');
        $this->assertSame($this->project->dir.'/.porta/plugins/platform/plugin.php', $file);
    }

    /** A plugin directory with $json as its composer.json. */
    private function pluginDir(string $name, string $json): string
    {
        $dir = $this->project->dir.'/.porta/plugins/'.$name;
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', data: "<?php\n");
        file_put_contents($dir.'/composer.json', data: $json);

        return $dir;
    }

    public function testInstallingDependenciesAnswersSynchronouslyWhatItDid(): void
    {
        // The caller has to know whether to wait BEFORE the subprocess could
        // possibly finish — that is what the return value is for.
        $installer = $this->installer(composer: $this->composerStub(0));
        $never = static function (int $code): void {
            self::fail('composer ran for a plugin that owes nothing');
        };

        $this->assertSame(DependencyInstall::NotNeeded, $installer->installDependencies($this->pluginDir('nodeps', '{"require":{"php":">=8.4"}}'), $never));
        $this->assertSame(DependencyInstall::NotNeeded, $installer->installDependencies($this->pluginDir('nomanifest', '{}'), $never));
        $this->assertSame(
            DependencyInstall::NoComposer,
            $this->installer()->installDependencies($this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}'), $never),
            'no composer on this machine',
        );
    }

    public function testInstallingDependenciesRunsComposerAndReportsItsExitCode(): void
    {
        $installer = $this->installer(composer: $this->composerStub(0));
        $dir = $this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}');

        $code = null;
        $this->assertSame(DependencyInstall::Started, $installer->installDependencies($dir, static function (int $exit) use (&$code): void {
            $code = $exit;
        }));
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertSame(0, $code);
        $this->assertFileExists($dir.'/vendor/composer/autoload_psr4.php', 'composer ran in the PLUGIN directory');

        // A second call now answers NotNeeded — the vendor tree is there.
        $this->assertSame(DependencyInstall::NotNeeded, $installer->installDependencies($dir, static fn (int $exit) => null));
    }

    public function testAFailingComposerReportsItsCodeToTheCaller(): void
    {
        $installer = $this->installer(composer: $this->composerStub(3));

        $code = null;
        $installer->installDependencies($this->pluginDir('flaky', '{"require":{"vend/lib":"^1.0"}}'), static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertSame(3, $code, 'the wording is the caller\'s; the code is ours');
    }

    public function testInstallingDependenciesIsRefusedWhileAnotherInstallRuns(): void
    {
        // One slot for the whole class, shared with the clone — a caller that
        // wants several installs has to serialize them itself.
        $installer = $this->installer(git: $this->git(), composer: $this->composerStub(0));
        $repo = $this->repo(Presence::With);
        $done = false;
        $installer->install('file://'.$repo, 'project', static function (?string $error, ?string $file) use (&$done): void {
            $done = true;
        });

        $this->assertSame(
            DependencyInstall::Busy,
            $installer->installDependencies($this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}'), static fn (int $exit) => null),
        );

        // …and a plugin that owes nothing is NOT refused, because NotNeeded is
        // answered before the slot is ever consulted.
        $this->assertSame(
            DependencyInstall::NotNeeded,
            $installer->installDependencies($this->pluginDir('nodeps', '{}'), static fn (int $exit) => null),
        );

        // Let the clone finish rather than ending on an armed stream watcher.
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the clone that held the slot');
    }

    public function testASuccessfulInstallMarksTheTreeAsOurs(): void
    {
        // The marker is the only thing that authorises deleting a vendor tree
        // later, so it is written on success and only on success: a
        // half-installed tree is not one we should claim we made.
        $installer = $this->installer(composer: $this->composerStub(0));
        $dir = $this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}');

        $code = null;
        $installer->installDependencies($dir, static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertFileExists($dir.'/'.PluginInstaller::MARKER);
        $this->assertSame(DependencyRemoval::Removed, $installer->removeDependencies($dir));
        $this->assertDirectoryDoesNotExist($dir.'/vendor');
    }

    public function testAFailedInstallLeavesNoClaimOnTheTree(): void
    {
        $installer = $this->installer(composer: $this->composerStub(3));
        $dir = $this->pluginDir('flaky', '{"require":{"vend/lib":"^1.0"}}');

        $code = null;
        $installer->installDependencies($dir, static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertFileDoesNotExist($dir.'/'.PluginInstaller::MARKER);
        $this->assertSame(DependencyRemoval::NotOurs, $installer->removeDependencies($dir), 'we did not finish it, so we do not delete it');
        $this->assertDirectoryExists($dir.'/vendor', 'and what composer did write stays');
    }

    public function testAVendorTreeThePluginShippedIsNeverRemoved(): void
    {
        // The case the marker exists for: a plugin that commits its vendor/
        // must not lose tracked files to a checkbox.
        $installer = $this->installer();
        $dir = $this->pluginDir('shipped', '{"require":{"vend/lib":"^1.0"}}');
        mkdir($dir.'/vendor/vend', permissions: 0o777, recursive: true);
        file_put_contents($dir.'/vendor/vend/Thing.php', data: "<?php\n");

        $this->assertSame(DependencyRemoval::NotOurs, $installer->removeDependencies($dir));
        $this->assertFileExists($dir.'/vendor/vend/Thing.php');

        $this->assertSame(
            DependencyRemoval::NotOurs,
            $installer->removeDependencies($this->pluginDir('bare', '{}')),
            'and no vendor at all is not a failure either',
        );
    }

    public function testRemovingDependenciesIsRefusedWhileComposerRuns(): void
    {
        // Deleting a tree composer is writing into leaves a half-vendor that
        // needsInstall() would then call complete.
        $installer = $this->installer(git: $this->git(), composer: $this->composerStub(0));
        $dir = $this->pluginDir('marked', '{"require":{"vend/lib":"^1.0"}}');
        mkdir($dir.'/vendor', permissions: 0o777, recursive: true);
        file_put_contents($dir.'/'.PluginInstaller::MARKER, data: "x\n");

        $done = false;
        $installer->install('file://'.$this->repo(Presence::With), 'project', static function (?string $error, ?string $file) use (&$done): void {
            $done = true;
        });

        $this->assertSame(DependencyRemoval::Busy, $installer->removeDependencies($dir));
        $this->assertDirectoryExists($dir.'/vendor');

        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the clone that held the slot');
    }

    public function testAVendorTreeThatWillNotDeleteIsReportedNotSwallowed(): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('root deletes anything');
        }
        $installer = $this->installer();
        $dir = $this->pluginDir('stuck', '{"require":{"vend/lib":"^1.0"}}');
        mkdir($dir.'/vendor', permissions: 0o777, recursive: true);
        file_put_contents($dir.'/'.PluginInstaller::MARKER, data: "x\n");
        // No write bit on the PLUGIN directory, so its `vendor` entry cannot be
        // unlinked however writable the tree below it is.
        chmod($dir, permissions: 0o555);

        try {
            $this->assertSame(DependencyRemoval::Failed, $installer->removeDependencies($dir));
            $this->assertDirectoryExists($dir.'/vendor');
        } finally {
            chmod($dir, permissions: 0o777);
        }
    }
}
